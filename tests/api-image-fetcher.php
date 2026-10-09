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
 * The photo URL downloader's real cURL path: every option a handle gets, and downloads from a local
 * `php -S` server for the pin, the size cap, redirects, the batch deadline and the concurrency cap.
 * Usage: php tests/api-image-fetcher.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\write\ImageFetcher;

harness_section('the options of one download');
$out  = fopen('php://memory', 'wb');
$opts = ImageFetcher::curlOptions('https://photos.test:8443/a.jpg', '93.184.216.34', 1000, 15, $out);
$keys = array_keys($opts);
sort($keys);
$want = [
    CURLOPT_URL, CURLOPT_FILE, CURLOPT_RESOLVE, CURLOPT_PROXY, CURLOPT_NOPROXY, CURLOPT_PROTOCOLS, CURLOPT_FOLLOWLOCATION,
    CURLOPT_CONNECTTIMEOUT, CURLOPT_TIMEOUT, CURLOPT_LOW_SPEED_LIMIT, CURLOPT_LOW_SPEED_TIME, CURLOPT_MAXFILESIZE,
    CURLOPT_NOPROGRESS, CURLOPT_XFERINFOFUNCTION, CURLOPT_USERAGENT,
];
sort($want);
pin('a handle gets exactly these options', $want, $keys);
$xfer = $opts[CURLOPT_XFERINFOFUNCTION];
pin('each option holds the checked IP, no proxy, http(s) only, no redirects, the size cap and the timeouts', [
    'https://photos.test:8443/a.jpg', true, ['photos.test:8443:93.184.216.34'], '', '*', CURLPROTO_HTTP | CURLPROTO_HTTPS, false,
    5, 15, ImageFetcher::LOW_SPEED, ImageFetcher::LOW_SPEED_TIME, 1000, false, [0, 1], true,
], [
    $opts[CURLOPT_URL], $opts[CURLOPT_FILE] === $out, $opts[CURLOPT_RESOLVE], $opts[CURLOPT_PROXY], $opts[CURLOPT_NOPROXY],
    $opts[CURLOPT_PROTOCOLS], $opts[CURLOPT_FOLLOWLOCATION], $opts[CURLOPT_CONNECTTIMEOUT], $opts[CURLOPT_TIMEOUT],
    $opts[CURLOPT_LOW_SPEED_LIMIT], $opts[CURLOPT_LOW_SPEED_TIME], $opts[CURLOPT_MAXFILESIZE], $opts[CURLOPT_NOPROGRESS],
    [$xfer(null, 0, 1000, 0, 0), $xfer(null, 0, 1001, 0, 0)], str_starts_with((string) $opts[CURLOPT_USERAGENT], 'Shopclass/'),
]);
pin('a short timeout caps the connect time too', [3, 3], (static fn (array $o): array => [$o[CURLOPT_CONNECTTIMEOUT], $o[CURLOPT_TIMEOUT]])(ImageFetcher::curlOptions('http://a.test/', '1.2.3.4', 10, 3)));
check('without a file it writes nowhere', !isset(ImageFetcher::curlOptions('http://a.test/', '1.2.3.4', 10)[CURLOPT_FILE]));

harness_section('no cURL');
$noCurl = [];
exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=curl_multi_init,curl_init -r ' . escapeshellarg(
    'require ' . var_export(dirname(__DIR__) . '/oc-includes/vendor/autoload.php', true) . ';'
    . ' echo json_encode(mindstellar\api\write\ImageFetcher::curl([3 => ["url" => "http://a.test/", "ip" => "1.2.3.4", "file" => "/dev/null"], 1 => ["url" => "http://b.test/", "ip" => "1.2.3.4", "file" => "/dev/null"]], 10));'
) . ' 2>&1', $noCurl);
pin('every URL is refused cleanly when the server has no cURL', '{"3":"' . ImageFetcher::NO_CURL . '","1":"' . ImageFetcher::NO_CURL . '"}', implode("\n", $noCurl));

harness_section('real downloads from a local server');
$dir = sys_get_temp_dir() . '/osc-image-fetcher-' . getmypid() . '/';
@mkdir($dir, 0700, true);
/** @var resource[] $procs */
$procs = [];
register_shutdown_function(static function () use (&$procs, $dir): void {
    foreach ($procs as $proc) {
        proc_terminate($proc);
        proc_close($proc);
    }
    array_map('unlink', glob($dir . '*') ?: []);
    @rmdir($dir);
});
file_put_contents($dir . 'router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/small') {
    header('Content-Length: 500');
    echo str_repeat('s', 500);
} elseif ($path === '/big') {
    header('Content-Length: 5000');
    echo str_repeat('b', 5000);
} elseif ($path === '/stream') {
    for ($i = 0; $i < 10; $i++) {
        echo str_repeat('x', 500);
        flush();
        usleep(20000);
    }
} elseif ($path === '/sleep') {
    sleep(3);
} elseif ($path === '/redirect') {
    header('Location: /small', true, 302);
} else {
    http_response_code(404);
}
PHP);
// Holds each round of connections for a moment, so the most open at once is the client's limit.
file_put_contents($dir . 'hold.php', <<<'PHP'
<?php
[, $port, $file] = $argv;
$srv  = stream_socket_server('tcp://127.0.0.1:' . $port);
$peak = 0;
touch($file . '.ready');
while (true) {
    $conns = [];
    $until = microtime(true) + 0.5;
    while (microtime(true) < $until) {
        $r = [$srv];
        $w = $e = null;
        if (stream_select($r, $w, $e, 0, 50000) > 0 && ($c = @stream_socket_accept($srv, 0)) !== false) {
            $conns[] = $c;
        }
    }
    $peak = max($peak, count($conns));
    file_put_contents($file, (string) $peak);
    foreach ($conns as $c) {
        stream_set_timeout($c, 1);
        fread($c, 8192);
        fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
        fclose($c);
    }
}
PHP);
$freePort = static function (): int {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port  = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    return $port;
};
$listening = static function (int $port): bool {
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

    return $sock !== false && fclose($sock);
};
$start = static function (array $cmd, callable $ready) use ($dir, &$procs) {
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir);
    if ($proc === false) {
        return null;
    }
    $procs[] = $proc;
    for ($i = 0; $i < 100; $i++) {
        if ($ready()) {
            return $proc;
        }
        usleep(50000);
    }

    return null;
};
$port   = $freePort();
$server = $start([PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . 'router.php'], static fn (): bool => $listening($port));
check('the local server started', $server !== null);
$job = static fn (string $path, int $n): array => ['url' => 'http://photos.test:' . $port . $path, 'ip' => '127.0.0.1', 'file' => $dir . 'out' . $n];
$got = ImageFetcher::curl([
    1 => $job('/small', 1), 2 => $job('/big', 2), 3 => $job('/stream', 3), 4 => $job('/redirect', 4), 5 => $job('/missing', 5),
], 1000);
ksort($got);
pin('a file under the cap downloads through the pinned IP; over the cap, streamed past it, a redirect or a 404 fails', [
    1 => null, 2 => ImageFetcher::FAILED, 3 => ImageFetcher::FAILED, 4 => ImageFetcher::FAILED, 5 => ImageFetcher::FAILED,
], $got);
pin('the downloaded file is whole', str_repeat('s', 500), (string) @file_get_contents($dir . 'out1'));
pin('a host pinned to an address with nothing listening fails', [7 => ImageFetcher::FAILED], ImageFetcher::curl([7 => ['ip' => '127.0.0.2', 'file' => $dir . 'out7'] + $job('/small', 7)], 1000, 3));
$began = microtime(true);
$got   = ImageFetcher::curl([20 => $job('/sleep', 20), 21 => $job('/small', 21), 22 => $job('/small', 22), 23 => $job('/small', 23), 24 => $job('/small', 24)], 1000, 15, 1);
ksort($got);
pin('past the batch deadline what is open or still queued fails', array_fill_keys(range(20, 24), ImageFetcher::FAILED), $got);
check('...and the batch stops at the deadline', microtime(true) - $began < 2.5);

$port = $freePort();
$hold = $start([PHP_BINARY, $dir . 'hold.php', (string) $port, $dir . 'peak'], static fn (): bool => is_file($dir . 'peak.ready'));
check('the hold server started', $hold !== null);
$slow = [];
for ($n = 10; $n < 18; $n++) {
    $slow[$n] = ['url' => 'http://127.0.0.1:' . $port . '/', 'ip' => '127.0.0.1', 'file' => $dir . 'out' . $n];
}
$got = ImageFetcher::curl($slow, 1000);
ksort($got);
$peak = (int) @file_get_contents($dir . 'peak');
pin('eight downloads all finish', array_fill_keys(array_keys($slow), null), $got);
check('at most ' . ImageFetcher::CONCURRENT . ' open at once, and more than one (peak ' . $peak . ')', $peak <= ImageFetcher::CONCURRENT && $peak >= 2);

exit(harness_result());
