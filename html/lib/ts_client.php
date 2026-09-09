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
        // Anchored to "start of line or preceded by a space" instead of a
        // plain substring search: a client nickname or channel name is
        // attacker-controlled (any TS user can set their own nickname) and
        // could contain e.g. "channel_name=" as literal text, which would
        // otherwise misclassify a whole clientlist line as the channellist
        // response. The anchor is safe because ServerQuery always escapes a
        // literal space in a value to "\s" - a real, unescaped space in the
        // raw response line can therefore only be an actual field separator
        // inserted by the server, never attacker-supplied content.
        if (preg_match('/(?:^|\s)virtualserver_name=/', $line)) { $serverinfo = $line; continue; }
        if (preg_match('/(?:^|\s)channel_name=/', $line)) { $channellist = $line; continue; }
        if (preg_match('/(?:^|\s)client_nickname=/', $line)) { $clientlist = $line; continue; }
        if (preg_match('/(?:^|\s)sgid=/', $line)) { $servergrouplist = $line; continue; }
    }

    return [
        'serverinfo'      => ts_parse_single($serverinfo),
        'channellist'     => ts_parse_list($channellist),
        'clientlist'      => ts_parse_list($clientlist),
        'servergrouplist' => ts_parse_list($servergrouplist),
    ];
}
