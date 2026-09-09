<?php
require_once __DIR__ . '/ts_protocol.php';
require_once __DIR__ . '/ts_transport.php';
require_once __DIR__ . '/ts_transport_ssh.php';
require_once __DIR__ . '/ts_transport_raw.php';

// Queries the TS server via the configured transport and returns the parsed
// serverinfo/channellist/clientlist/servergrouplist data
// (or ['error' => ['key' => ..., 'vars' => [...]]]).
// Errors are returned as a translation key instead of ready-made text, so
// render.php can display them in the current language (see lib/i18n.php) -
// this also survives the JSON cache roundtrip in cache.php without issues.
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
        error_log('ts-viewer: transport error: ' . $e->getMessage());
        return ['error' => ['key' => 'err_unreachable']];
    }

    if (strpos($out, 'virtualserver_name') === false) {
        // Raw ServerQuery output only goes to the log, not to anonymous
        // visitors (may contain internal hostnames/banners).
        error_log('ts-viewer: no response from the TS server: ' . strip_tags($out));
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
