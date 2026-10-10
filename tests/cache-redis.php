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
 * Pins the Redis/Valkey cache: the built-in client's protocol over a socket pair, and the driver
 * against a real server through every client this PHP has (built-in, and phpredis when loaded).
 * The server part runs when OSC_TEST_REDIS names one (host:port), as CI does.
 * Usage: OSC_TEST_REDIS=127.0.0.1:6379 php tests/cache-redis.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\cache\PhpRedisClient;
use mindstellar\cache\RespClient;

if (!function_exists('__')) {
    function __($text)
    {
        return $text;
    }
}

harness_section('the built-in client speaks the protocol');

[$near, $far] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$client       = new RespClient([], $near);
$reply        = static function (string $bytes) use ($far): void {
    fwrite($far, $bytes);
};

$reply("+OK\r\n");
pin('a status reply is true', true, $client->command('SET', 'k', "two\r\nlines"));
pin('and the command went out as a counted list', "*3\r\n\$3\r\nSET\r\n\$1\r\nk\r\n\$10\r\ntwo\r\nlines\r\n", fread($far, 4096));
$reply(":42\r\n");
pin('a number reply is an int', 42, $client->command('INCR', 'n'));
$reply("\$-1\r\n");
pin('a missing value is null', null, $client->command('GET', 'gone'));
$reply("\$10\r\ntwo\r\nlines\r\n");
pin('a value with line breaks in it reads whole', "two\r\nlines", $client->command('GET', 'k'));
$reply("*2\r\n\$1\r\n0\r\n*1\r\n\$3\r\nabc\r\n");
pin('a nested list reads as arrays', ['0', ['abc']], $client->command('SCAN', '0'));
$reply("-ERR wrong number of arguments\r\n");
try {
    $client->command('GET');
    $threw = '';
} catch (UnexpectedValueException $e) {
    $threw = $e->getMessage();
}
pin('an error reply is a refusal, not a dead server', 'Redis refused the command: ERR wrong number of arguments', $threw);
fclose($far);
try {
    $client->command('PING');
    $threw = false;
} catch (RuntimeException $e) {
    $threw = !$e instanceof UnexpectedValueException;
}
check('a closed connection is a dead server', $threw);

harness_section('a server that does not answer');

$GLOBALS['_cache_config'] = [['default_host' => '127.0.0.1', 'default_port' => 1]];
$dead  = new Object_Cache_redis();
$start = microtime(true);
$found = null;
pin('a get is a miss', false, $dead->get('anything', $found));
pin('and says so', false, $found);
pin('a set fails', false, $dead->set('anything', 1));
check('and the request is not held up', microtime(true) - $start < 2);

$calls  = 0;
$broken = new class ($calls) implements mindstellar\cache\RedisClient {
    public function __construct(private int &$calls)
    {
    }

    public function command(string ...$args): mixed
    {
        $this->calls++;

        throw new RuntimeException('down');
    }
};
$skips = new Object_Cache_redis($broken);
$skips->get('a');
$skips->set('b', 1);
$skips->increment('c');
pin('after the first failure the request stops asking the server', 1, $calls);

$server = getenv('OSC_TEST_REDIS');
if (!$server) {
    echo "  (no OSC_TEST_REDIS server: the server checks were not run)\n";
    exit(harness_result());
}
[$host, $port] = explode(':', $server) + [1 => '6379'];
$config        = ['host' => $host, 'port' => (int) $port];

$clients = ['built-in client' => new RespClient($config)];
if (class_exists('Redis')) {
    $clients['phpredis'] = new PhpRedisClient($config);
} else {
    echo "  (phpredis is not loaded: only the built-in client was checked)\n";
}

foreach ($clients as $label => $raw) {
    harness_section('the driver over ' . ($label === 'phpredis' ? 'phpredis' : 'the built-in client'));

    $GLOBALS['_cache_config'] = [['default_host' => $host, 'default_port' => (int) $port]];
    $cache                    = new Object_Cache_redis($raw);
    $fresh                    = static fn (): Object_Cache_redis => new Object_Cache_redis($raw);
    $cache->flush();

    $row = ['pk_i_id' => 7, 's_name' => 'Bike', 'tags' => ['a', 'b'], 'price' => null];
    check('a set is stored', $cache->set('row', $row, 30));
    $found = null;
    pin('a later request reads it back whole', $row, $fresh()->get('row', $found));
    pin('and calls it a hit', true, $found);
    $ttl = $raw->command('TTL', $cache->site_prefix . 'row');
    check('it expires when asked', is_int($ttl) && $ttl > 0 && $ttl <= 30);
    $cache->set('short', 'x');
    $ttl = $raw->command('TTL', $cache->site_prefix . 'short');
    check('and after the default time when not', is_int($ttl) && $ttl > 0 && $ttl <= 60);

    $found = null;
    pin('a missing key is a miss', false, $fresh()->get('nope', $found));
    pin('and says so', false, $found);
    $cache->set('falsy', false);
    $found = null;
    pin('a stored false is a hit, not a miss', false, $fresh()->get('falsy', $found));
    pin('and says so', true, $found);

    check('add stores a new key', $cache->add('once', 1, 30));
    check('but not over an existing one', !$fresh()->add('once', 2, 30));
    pin('which keeps its value', 1, $fresh()->get('once'));

    check('delete removes a key', $cache->delete('once'));
    pin('so the next request misses it', false, $fresh()->get('once'));

    pin('increment creates a counter at its first value', 1, $cache->increment('hits', 1, 1, 30));
    pin('and counts up from there', 3, $fresh()->increment('hits', 2, 1, 30));
    pin('a counter reads back as a number', 3, $fresh()->get('hits'));
    check('a number added first is counted on top of', $cache->add('limit', 5, 30) && $fresh()->increment('limit', 1, 0, 30) === 6);
    $ttl = $raw->command('TTL', $cache->site_prefix . 'hits');
    check('and expires like any other value', is_int($ttl) && $ttl > 0 && $ttl <= 30);

    $raw->command('SET', 'other_site_key', 'theirs');
    check('flush clears this site', $cache->flush());
    pin('so its keys are gone', false, $fresh()->get('row'));
    pin('but another site keeps its own', 'theirs', $raw->command('GET', 'other_site_key'));
    $raw->command('DEL', 'other_site_key');
    for ($i = 0; $i < 1200; $i++) {
        $raw->command('SET', $cache->site_prefix . 'bulk' . $i, '1');
    }
    $cache->flush();
    pin('flush keeps going past the first page of keys', [], $raw->command('KEYS', $cache->site_prefix . '*'));

    $stats = $cache->statsData();
    check('stats read the server', is_array($stats) && isset($stats['uptime'], $stats['memory_used']));
    check('and name the client', str_contains((string) ($stats['server'] ?? ''), '(' . ($label === 'phpredis' ? 'phpredis' : 'built-in client') . ')'));
}

harness_section('the database number');

$one = new RespClient($config + ['database' => 1]);
$one->command('SET', 'in_db_one', '1');
pin('a key lands in the chosen database', 1, (new RespClient($config + ['database' => 1]))->command('EXISTS', 'in_db_one'));
pin('and not in database 0', 0, (new RespClient($config))->command('EXISTS', 'in_db_one'));
$one->command('DEL', 'in_db_one');

harness_section('a server that refuses the sign-in');

$refused = new Object_Cache_redis(new RespClient($config + ['password' => 'not-the-password']));
$found   = null;
pin('a get is a miss, not an error', false, $refused->get('row', $found));
pin('a set fails', false, $refused->set('row', 1));

exit(harness_result());
