<?php
require_once __DIR__ . '/ts_protocol.php';
require_once __DIR__ . '/ts_transport.php';
require_once __DIR__ . '/ts_transport_ssh.php';
require_once __DIR__ . '/ts_transport_raw.php';

// Fragt den TS-Server per konfiguriertem Transport ab und liefert die
// geparsten serverinfo/channellist/clientlist-Daten (oder ['error' => ...]).
function ts_fetch_from_server(array $config): array {
    foreach (['host' => 'TS_HOST', 'user' => 'TS_USER', 'pass' => 'TS_PASS'] as $key => $var) {
        if (($config[$key] ?? '') === '') {
            return ['error' => "$var ist nicht konfiguriert."];
        }
    }

    $commands = "use port=" . $config['vport'] . "\n"
        . "clientupdate client_nickname=" . ts_escape($config['query_nickname']) . "\n"
        . "serverinfo\n"
        . "channellist -topic -flags -limits\n"
        . "clientlist -uid -away -groups\n"
        . "quit\n";

    try {
        $transport = ts_create_transport($config);
        $out = $transport->query($commands);
    } catch (TsTransportException $e) {
        error_log('ts-viewer: Transport-Fehler: ' . $e->getMessage());
        return ['error' => 'TeamSpeak-Server aktuell nicht erreichbar.'];
    }

    if (strpos($out, 'virtualserver_name') === false) {
        // Rohe ServerQuery-Ausgabe nur ins Log, nicht an anonyme Besucher
        // (kann interne Hostnamen/Banner enthalten).
        error_log('ts-viewer: keine Antwort vom TS-Server: ' . strip_tags($out));
        return ['error' => 'TeamSpeak-Server aktuell nicht erreichbar.'];
    }

    $serverinfo  = '';
    $channellist = '';
    $clientlist  = '';

    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, 'error ') === 0 || strpos($line, 'Welcome') === 0) continue;
        if (strpos($line, 'virtualserver_name=') !== false) { $serverinfo = $line; continue; }
        if (strpos($line, 'channel_name=') !== false) { $channellist = $line; continue; }
        if (strpos($line, 'client_nickname=') !== false) { $clientlist = $line; continue; }
    }

    return [
        'serverinfo'  => ts_parse_single($serverinfo),
        'channellist' => ts_parse_list($channellist),
        'clientlist'  => ts_parse_list($clientlist),
    ];
}
