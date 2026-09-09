<?php
// Integration test for TsRawTransport against bin/mock_serverquery.php - covers
// the actual connect/login/parsing path, not just the plain protocol syntax
// like bin/selftest_parser.php.
// Usage: php bin/test_raw_transport.php
require __DIR__ . '/../html/lib/ts_protocol.php';
require __DIR__ . '/../html/lib/ts_transport.php';
require __DIR__ . '/../html/lib/ts_transport_raw.php';

$failures = 0;

function check(string $label, bool $ok): void {
    global $failures;
    if ($ok) {
        echo "ok - $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

function start_mock(int $port, string $mode): array {
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = sprintf('php %s %d %s', escapeshellarg(__DIR__ . '/mock_serverquery.php'), $port, escapeshellarg($mode));
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Could not start the mock\n");
        exit(1);
    }
    // Wait for the "listening" line instead of sleeping for a fixed time (avoids flakiness in CI).
    stream_set_timeout($pipes[1], 5);
    $line = fgets($pipes[1]);
    if (trim((string)$line) !== 'listening') {
        fwrite(STDERR, "Mock did not come up in time (output: " . var_export($line, true) . ")\n");
        exit(1);
    }
    return [$proc, $pipes];
}

function stop_mock(array $procAndPipes): void {
    [$proc, $pipes] = $procAndPipes;
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    proc_terminate($proc);
    proc_close($proc);
}

$basePort = 20011 + random_int(0, 5000);

// --- Happy path: login with a password containing special characters + full data receipt ---
$mock = start_mock($basePort, 'ok');
try {
    $config = [
        'host' => '127.0.0.1',
        'port' => $basePort,
        'user' => 'testuser',
        'pass' => 'te\\st pass|word', // backslash, space, pipe all at once
        'connect_timeout' => 5,
    ];
    $transport = new TsRawTransport($config);
    $out = $transport->query("use port=9987\nserverinfo\nchannellist\nclientlist\nquit\n");

    // Same filter logic as html/lib/ts_client.php - this also tests that the
    // transport and the existing parsers actually fit together.
    $serverinfo = '';
    $channellist = '';
    $clientlist = '';
    $servergrouplist = '';
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, 'error ') === 0 || strpos($line, 'Welcome') === 0) continue;
        if (strpos($line, 'virtualserver_name=') !== false) { $serverinfo = $line; continue; }
        if (strpos($line, 'channel_name=') !== false) { $channellist = $line; continue; }
        if (strpos($line, 'client_nickname=') !== false) { $clientlist = $line; continue; }
        if (strpos($line, 'sgid=') !== false) { $servergrouplist = $line; continue; }
    }
    $info = ts_parse_single($serverinfo);
    $channels = ts_parse_list($channellist);
    $clients = ts_parse_list($clientlist);
    $servergroups = ts_parse_list($servergrouplist);

    check('serverinfo contains the expected server name', ($info['virtualserver_name'] ?? null) === 'MockServer');
    check('channellist contains Lobby', ($channels[0]['channel_name'] ?? null) === 'Lobby');
    check('channellist contains the topic', ($channels[0]['channel_topic'] ?? null) === 'Welcome');
    check('clientlist contains Alice', ($clients[0]['client_nickname'] ?? null) === 'Alice');
    check('clientlist contains mute status', ($clients[0]['client_input_muted'] ?? null) === '1');
    check('servergrouplist contains Server Admin (escaped)', ($servergroups[0]['name'] ?? null) === 'Server Admin');
} finally {
    stop_mock($mock);
}

// --- Failure case: server rejects the login -> TsAuthException expected ---
$mock2 = start_mock($basePort + 1, 'fail');
try {
    $config2 = [
        'host' => '127.0.0.1',
        'port' => $basePort + 1,
        'user' => 'testuser',
        'pass' => 'wrong',
        'connect_timeout' => 5,
    ];
    $transport2 = new TsRawTransport($config2);
    $threw = false;
    try {
        $transport2->query("use port=9987\nquit\n");
    } catch (TsAuthException $e) {
        $threw = true;
    }
    check('TsAuthException on failed login', $threw);
} finally {
    stop_mock($mock2);
}

if ($failures > 0) {
    fwrite(STDERR, "\n$failures test(s) failed.\n");
    exit(1);
}
echo "\nAll integration tests passed.\n";
