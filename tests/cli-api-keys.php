<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The api:key:* commands: a key is made for a named admin and printed once, a moderator's key
 * holds only moderator scopes, a public key only reads, the list never prints a secret, and a
 * revoked key stops verifying. The commands are registered with the CLI router.
 *
 * Needs a database (the scratch container by default). Usage:  php tests/cli-api-keys.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_cli_api_keys');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';

if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}

use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\Scopes;
use mindstellar\cli\ApiKeyCommands;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

/** Run one command and hand back its exit code, what it printed, and what it said on stderr. */
function api_cli(string $command, array $args): array
{
    $out  = '';
    $err  = '';
    $cmds = new ApiKeyCommands(
        static function (string $t) use (&$out): void {
            $out .= $t;
        },
        static function (string $t) use (&$err): void {
            $err .= $t;
        }
    );
    $code = $cmds->$command($args);

    return array($code, $out, $err);
}

/** The token a create printed, or ''. */
function printed_token(string $out): string
{
    return preg_match('/^(sc[kp]_[0-9A-Za-z]{16}\.[0-9a-f]{64})$/m', $out, $m) === 1 ? $m[1] : '';
}

$keys  = new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock());
$table = DB_TABLE_PREFIX . 't_api_credential';
$count = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM $table")->fetch_row()[0];

$fullId = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('Full', 'full', ?, 'full@x.test', 0)", 's', [str_repeat('x', 60)]);
seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('Mod', 'mod', ?, 'mod@x.test', 1)", 's', [str_repeat('x', 60)]);

harness_section('registered');

$cli = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/cli/Cli.php');
foreach (array('api:key:create', 'api:key:list', 'api:key:revoke') as $name) {
    check($name . ' is a core command', str_contains($cli, "'" . $name . "'"));
}
check('help says how to name an admin by id', str_contains($cli, '--admin=<username>|id:<n>'));

harness_section('create');

[$code, , $err] = api_cli('create', array('name' => 'CI'));
pin('no --admin is a usage error', 2, $code);
check('...that says how', str_contains($err, 'Usage: api:key:create'));
[$code] = api_cli('create', array('admin' => 'full', 'name' => true));
pin('a --name with no value is a usage error', 2, $code);
[$code, , $err] = api_cli('create', array('admin' => 'nobody', 'name' => 'CI', 'scopes' => 'admin:users'));
pin('an unknown admin is refused', 1, $code);

[$code, $out] = api_cli('create', array('admin' => 'full', 'name' => 'CI', 'scopes' => 'admin:users,listings:read', 'expires' => '30d'));
$token = printed_token($out);
pin('an admin key is made', 0, $code);
check('...and printed', str_starts_with($token, 'sck_'));
check('...with a note that it is shown once', str_contains($out, 'not shown again'));
pin('it verifies with the scopes asked for', array('listings:read', 'admin:users'), $keys->verify($token)?->scopes());
$stored = (new ApiCredential())->findByTokenId(substr($token, 4, 16));
check('the expiry is about 30 days out', $stored !== null && abs($stored->expiresAt() - (time() + 30 * 86400)) < 60);

[$code, $out] = api_cli('create', array('admin' => 'id:' . $fullId, 'name' => 'By id', 'scopes' => 'admin:keys'));
pin('the owner can be named as id:<n>', array(0, "Key "), array($code, substr($out, 0, 4)));
check("...and it is that admin's key", str_contains($out, "for admin 'full'"));

// An admin whose username is another admin's id: a bare number is always the username.
$numericId = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('Num', ?, ?, 'num@x.test', 1)", 'ss', [(string) $fullId, str_repeat('x', 60)]);
[$code, $out] = api_cli('create', array('admin' => (string) $fullId, 'name' => 'Bare number', 'scopes' => 'admin:listings'));
check('a bare number names the admin with that username, not that id', $code === 0 && str_contains($out, "for admin '" . $fullId . "'"));
pin('...so the key is a moderator key, owned by them', $numericId, (new ApiCredential())->findByTokenId(substr(printed_token($out), 4, 16))?->owner()?->adminId());
[$code] = api_cli('create', array('admin' => 'id:999', 'name' => 'Nobody', 'scopes' => 'admin:keys'));
pin('an unknown id is refused', 1, $code);

$before = $count();
[$code, , $err] = api_cli('create', array('admin' => 'mod', 'name' => 'Bot', 'scopes' => 'admin:listings admin:users'));
pin("a moderator's key cannot hold admin:users", array(1, $before), array($code, $count()));
check('...and the reason is printed', str_contains($err, 'admin:users'));
[$code, $out] = api_cli('create', array('admin' => 'mod', 'name' => 'Bot', 'scopes' => 'admin:listings'));
pin('a moderator key with moderator scopes is made', array('admin:listings'), $keys->verify(printed_token($out))?->scopes());

[$code, $out] = api_cli('create', array('admin' => 'full', 'name' => 'App', 'kind' => 'public'));
$public = printed_token($out);
check('a public key starts with scp_', str_starts_with($public, 'scp_'));
pin('...and only reads public data', array(Scopes::PUBLIC_READ), $keys->verify($public)?->scopes());
[$code] = api_cli('create', array('admin' => 'full', 'name' => 'App', 'kind' => 'public', 'scopes' => 'listings:write'));
pin('a public key asking to write is refused', 1, $code);
[$code] = api_cli('create', array('admin' => 'full', 'name' => 'Old', 'scopes' => 'admin:users', 'expires' => '2001-01-01'));
pin('a past expiry is refused', 1, $code);
[$code] = api_cli('create', array('admin' => 'full', 'name' => 'Odd', 'scopes' => 'admin:users', 'expires' => 'tomorrow'));
pin('an expiry that is not a date is refused', 1, $code);

harness_section('list');

[$code, $out] = api_cli('list', array());
pin('lists', 0, $code);
check('...each key by name', str_contains($out, 'CI') && str_contains($out, 'Bot') && str_contains($out, 'App'));
check('...with its owner', str_contains($out, 'mod'));
check('...and never a secret', !str_contains($out, substr($token, 21)) && !str_contains($out, substr($public, 21)));

harness_section('revoke');

$id = (int) $admin->query("SELECT pk_i_id FROM $table WHERE s_name = 'CI'")->fetch_row()[0];
[$code] = api_cli('revoke', array());
pin('no id is a usage error', 2, $code);
[$code, $out] = api_cli('revoke', array('_' => array((string) $id)));
pin('revokes', array(0, "Key $id revoked.\n"), array($code, $out));
pin('a revoked key no longer verifies', null, $keys->verify($token));
[$code] = api_cli('revoke', array('_' => array((string) $id)));
pin('revoking twice is an error', 1, $code);
[, $out] = api_cli('list', array());
check('the list shows it revoked', preg_match('/^' . $id . '\s+admin\s+revoked/m', $out) === 1);

exit(harness_result());
