<?php
// Polls the TS server once and credits every currently connected real
// client (client_type=0) with one interval's worth of online time, keyed
// by their stable client_database_id. Meant to be looped externally (see
// docker/entrypoint.sh's "while true; sleep $TS_TRACK_INTERVAL" wrapper)
// rather than looping itself, so one failed poll can't wedge the tracker -
// each run is independent and just skips (exit 0) on any failure, to be
// picked up again next cycle.
//
// Not shipped from bin/ (dev/test-only, not copied into the image) - this
// actually needs to run inside the container, so it lives in its own
// top-level cli/ instead, copied by the Dockerfile alongside html/.
//
// Usage: php cli/track_online_time.php
require __DIR__ . '/../html/lib/ts_protocol.php';
require __DIR__ . '/../html/lib/ts_transport.php';
require __DIR__ . '/../html/lib/ts_transport_ssh.php';
require __DIR__ . '/../html/lib/ts_transport_raw.php';
require __DIR__ . '/../html/config.php';
require __DIR__ . '/../html/lib/ts_client.php';
require __DIR__ . '/../html/lib/ts_online_time.php';

$config = ts_load_config();
$interval = max(1, (int)ts_env('TS_TRACK_INTERVAL', '60'));

foreach (['host', 'user', 'pass'] as $key) {
    if (($config[$key] ?? '') === '') {
        fwrite(STDERR, "track_online_time: TS_HOST/TS_USER/TS_PASS not configured, skipping\n");
        exit(0);
    }
}

try {
    $transport = ts_create_transport($config);
    $out = $transport->query("use port=" . $config['vport'] . "\nclientlist\nquit\n");
} catch (TsTransportException $e) {
    fwrite(STDERR, "track_online_time: transport error: " . $e->getMessage() . "\n");
    exit(0);
}

$lines = ts_classify_bundle_lines($out);
$clients = array_values(array_filter(ts_parse_list($lines['clientlist']), fn($c) => ($c['client_type'] ?? '0') === '0'));

if (!is_dir($config['cache_dir'])) @mkdir($config['cache_dir'], 0700, true);
$lockFp = @fopen($config['cache_dir'] . '/online_time.lock', 'c');
if ($lockFp === false) {
    fwrite(STDERR, "track_online_time: could not open lock file in {$config['cache_dir']}\n");
    exit(0);
}
flock($lockFp, LOCK_EX);
$data = ts_online_time_read($config['cache_dir']);
$data = ts_online_time_apply($data, $clients, $interval);
ts_online_time_write($config['cache_dir'], $data);
flock($lockFp, LOCK_UN);
fclose($lockFp);
