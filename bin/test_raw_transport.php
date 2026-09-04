<?php
// Integrationstest fuer TsRawTransport gegen bin/mock_serverquery.php - deckt
// den tatsaechlichen Verbindungs-/Login-/Parsing-Pfad ab, nicht nur die reine
// Protokoll-Syntax wie bin/selftest_parser.php.
// Aufruf: php bin/test_raw_transport.php
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
        fwrite(STDERR, "Mock konnte nicht gestartet werden\n");
        exit(1);
    }
    // Auf die "listening"-Zeile warten statt fix zu schlafen (vermeidet Flakiness in CI).
    stream_set_timeout($pipes[1], 5);
    $line = fgets($pipes[1]);
    if (trim((string)$line) !== 'listening') {
        fwrite(STDERR, "Mock ist nicht rechtzeitig hochgekommen (Ausgabe: " . var_export($line, true) . ")\n");
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

// --- Happy Path: Login mit Sonderzeichen-Passwort + vollstaendiger Datenempfang ---
$mock = start_mock($basePort, 'ok');
try {
    $config = [
        'host' => '127.0.0.1',
        'port' => $basePort,
        'user' => 'testuser',
        'pass' => 'te\\st pass|word', // Backslash, Leerzeichen, Pipe gleichzeitig
        'connect_timeout' => 5,
    ];
    $transport = new TsRawTransport($config);
    $out = $transport->query("use port=9987\nserverinfo\nchannellist\nclientlist\nquit\n");

    // Gleiche Filterlogik wie html/lib/ts_client.php - testet damit auch, dass
    // Transport + bestehende Parser tatsaechlich zusammenpassen.
    $serverinfo = '';
    $channellist = '';
    $clientlist = '';
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, 'error ') === 0 || strpos($line, 'Welcome') === 0) continue;
        if (strpos($line, 'virtualserver_name=') !== false) { $serverinfo = $line; continue; }
        if (strpos($line, 'channel_name=') !== false) { $channellist = $line; continue; }
        if (strpos($line, 'client_nickname=') !== false) { $clientlist = $line; continue; }
    }
    $info = ts_parse_single($serverinfo);
    $channels = ts_parse_list($channellist);
    $clients = ts_parse_list($clientlist);

    check('serverinfo enthaelt erwarteten Servernamen', ($info['virtualserver_name'] ?? null) === 'MockServer');
    check('channellist enthaelt Lobby', ($channels[0]['channel_name'] ?? null) === 'Lobby');
    check('clientlist enthaelt Alice', ($clients[0]['client_nickname'] ?? null) === 'Alice');
} finally {
    stop_mock($mock);
}

// --- Fehlerfall: Server lehnt Login ab -> TsAuthException erwartet ---
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
    check('TsAuthException bei fehlgeschlagenem Login', $threw);
} finally {
    stop_mock($mock2);
}

if ($failures > 0) {
    fwrite(STDERR, "\n$failures Test(s) fehlgeschlagen.\n");
    exit(1);
}
echo "\nAlle Integrationstests erfolgreich.\n";
