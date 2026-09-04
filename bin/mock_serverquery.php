<?php
// Standalone-TCP-Mock eines Raw-ServerQuery-Servers fuer bin/test_raw_transport.php.
// Reines Test-Fixture, nicht Teil der App.
// Aufruf: php bin/mock_serverquery.php <port> [ok|fail]

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

// Signalisiert dem Testtreiber, dass der Mock bereit ist, statt dass dieser
// auf gut Glueck eine feste Zeit schlafen muss.
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
fwrite(STDERR, "mock: got login: " . ($loginLine !== false ? $loginLine : "(nichts)\n"));

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

fwrite($conn, "virtualserver_name=MockServer virtualserver_maxclients=32 virtualserver_uptime=100\n");
fwrite($conn, "cid=1 pid=0 channel_name=Lobby\n");
fwrite($conn, "clid=1 cid=1 client_nickname=Alice client_type=0 client_away=0\n");
fwrite($conn, "error id=0 msg=ok\n");
fclose($conn);
fclose($server);
