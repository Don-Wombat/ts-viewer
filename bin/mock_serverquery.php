<?php
// Standalone TCP mock of a raw ServerQuery server for bin/test_raw_transport.php.
// Test fixture only, not part of the app.
// Usage: php bin/mock_serverquery.php <port> [ok|fail]

$port = (int)($argv[1] ?? 0);
$mode = $argv[2] ?? 'ok';
if ($port <= 0) {
    fwrite(STDERR, "Usage: mock_serverquery.php <port> [ok|fail]\n");
    exit(1);
}

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "server failed: $errstr\n");
    exit(1);
}

// Signals the test driver that the mock is ready, instead of it having to
// sleep for a fixed time and hope for the best.
echo "listening\n";
flush();

$conn = stream_socket_accept($server, 30);
if (!$conn) {
    fwrite(STDERR, "no connection\n");
    exit(1);
}
stream_set_timeout($conn, 5);

fwrite($conn, "TS3\n");
fwrite($conn, "Welcome to the TeamSpeak ServerQuery interface, type \"help\" for a list of commands.\n");

$loginLine = fgets($conn);
fwrite(STDERR, "mock: got login: " . ($loginLine !== false ? $loginLine : "(none)\n"));

if ($mode === 'fail') {
    fwrite($conn, "error id=520 msg=invalid\\sloginname\\sor\\spassword\n");
    fclose($conn);
    fclose($server);
    exit(0);
}

fwrite($conn, "error id=0 msg=ok\n");

$rest = '';
while (!feof($conn)) {
    $chunk = fread($conn, 8192);
    if ($chunk === '' || $chunk === false) break;
    $rest .= $chunk;
    if (str_contains($rest, "quit\n")) break;
}
fwrite(STDERR, "mock: got bundle:\n$rest");

fwrite($conn, "virtualserver_name=MockServer virtualserver_maxclients=32 virtualserver_uptime=100 virtualserver_default_server_group=8\n");
fwrite($conn, "cid=1 pid=0 channel_name=Lobby channel_topic=Welcome\n");
fwrite($conn, "clid=1 cid=1 client_nickname=Alice client_type=0 client_away=0 client_input_muted=1 client_output_muted=0 client_servergroups=6\n");
fwrite($conn, "sgid=6 name=Server\\sAdmin|sgid=8 name=Guest\n");
fwrite($conn, "error id=0 msg=ok\n");
fclose($conn);
fclose($server);
