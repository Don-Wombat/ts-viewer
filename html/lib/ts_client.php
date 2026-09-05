<?php
require_once __DIR__ . '/ts_protocol.php';
require_once __DIR__ . '/ts_transport.php';
require_once __DIR__ . '/ts_transport_ssh.php';
require_once __DIR__ . '/ts_transport_raw.php';

// Fragt den TS-Server per konfiguriertem Transport ab und liefert die
// geparsten serverinfo/channellist/clientlist/servergrouplist-Daten
// (oder ['error' => ['key' => ..., 'vars' => [...]]]).
// Fehler werden als Uebersetzungs-Key statt fertigem Text zurueckgegeben,
// damit render.php sie sprachabhaengig anzeigen kann (siehe lib/i18n.php) -
// das ueberlebt auch den JSON-Cache-Roundtrip in cache.php problemlos.
function ts_fetch_from_server(array $config): array {
    foreach (['host' => 'TS_HOST', 'user' => 'TS_USER', 'pass' => 'TS_PASS'] as $key => $var) {
        if (($config[$key] ?? '') === '') {
            return ['error' => ['key' => 'err_not_configured', 'vars' => ['var' => $var]]];
        }
    }

    $commands = "use port=" . $config['vport'] . "\n"
        . "clientupdate client_nickname=" . ts_escape($config['query_nickname']) . "\n"
        . "serverinfo\n"
        . "channellist -topic -flags -limits\n"
        . "clientlist -uid -away -voice -groups\n"
        . "servergrouplist\n"
        . "quit\n";

    try {
        $transport = ts_create_transport($config);
        $out = $transport->query($commands);
    } catch (TsTransportException $e) {
        error_log('ts-viewer: Transport-Fehler: ' . $e->getMessage());
        return ['error' => ['key' => 'err_unreachable']];
    }

    if (strpos($out, 'virtualserver_name') === false) {
        // Rohe ServerQuery-Ausgabe nur ins Log, nicht an anonyme Besucher
        // (kann interne Hostnamen/Banner enthalten).
        error_log('ts-viewer: keine Antwort vom TS-Server: ' . strip_tags($out));
        return ['error' => ['key' => 'err_unreachable']];
    }

    $serverinfo     = '';
    $channellist    = '';
    $clientlist     = '';
    $servergrouplist = '';

    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, 'error ') === 0 || strpos($line, 'Welcome') === 0) continue;
        if (strpos($line, 'virtualserver_name=') !== false) { $serverinfo = $line; continue; }
        if (strpos($line, 'channel_name=') !== false) { $channellist = $line; continue; }
        if (strpos($line, 'client_nickname=') !== false) { $clientlist = $line; continue; }
        if (strpos($line, 'sgid=') !== false) { $servergrouplist = $line; continue; }
    }

    return [
        'serverinfo'      => ts_parse_single($serverinfo),
        'channellist'     => ts_parse_list($channellist),
        'clientlist'      => ts_parse_list($clientlist),
        'servergrouplist' => ts_parse_list($servergrouplist),
    ];
}
