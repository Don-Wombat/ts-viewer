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
        . "servergrouplist\n";
    // Folded into the same connection/bundle instead of a second one opened
    // afterwards - two SSH connections back-to-back within the same request
    // tripped this server's flood protection in testing (the second one got
    // dropped mid-handshake). channellist has no -description flag, so the
    // only way to get it is channelinfo, which needs a cid - hardcoded via
    // config instead of looked up by name every cycle, since that lookup
    // would itself need the channellist response back first.
    if (!empty($config['quote_channel_id'])) {
        $commands .= "channelinfo cid=" . (int)$config['quote_channel_id'] . "\n";
    }
    $commands .= "quit\n";

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

    $lines = ts_classify_bundle_lines($out);

    $result = [
        'serverinfo'      => ts_parse_single($lines['serverinfo']),
        'channellist'     => ts_parse_list($lines['channellist']),
        'clientlist'      => ts_parse_list($lines['clientlist']),
        'servergrouplist' => ts_parse_list($lines['servergrouplist']),
    ];

    if (!empty($config['quote_channel_id'])) {
        $result['quotes'] = ts_parse_quotes($lines['channeldescription']);
    }

    return $result;
}

// Splits the raw multi-command bundle response into one raw line per
// command (serverinfo/channellist/clientlist/servergrouplist/channelinfo -
// channelinfo only present if it was actually requested). Extracted into
// its own function so it's unit-testable without a live/mock transport -
// see bin/selftest_parser.php for the regression test covering the ordering
// note below.
function ts_classify_bundle_lines(string $out): array {
    $lines = ['serverinfo' => '', 'channellist' => '', 'clientlist' => '', 'servergrouplist' => '', 'channeldescription' => ''];

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
        if (preg_match('/(?:^|\s)virtualserver_name=/', $line)) { $lines['serverinfo'] = $line; continue; }
        // Checked before channel_name=: the channelinfo response line (if
        // requested) also contains "channel_name=" as its second field, and
        // must not overwrite channellist with just that one channel.
        if (preg_match('/(?:^|\s)channel_description=/', $line)) { $lines['channeldescription'] = $line; continue; }
        if (preg_match('/(?:^|\s)channel_name=/', $line)) { $lines['channellist'] = $line; continue; }
        if (preg_match('/(?:^|\s)client_nickname=/', $line)) { $lines['clientlist'] = $line; continue; }
        if (preg_match('/(?:^|\s)sgid=/', $line)) { $lines['servergrouplist'] = $line; continue; }
    }

    return $lines;
}

// Splits a channelinfo response line's channel_description into individual
// quotes - one per paragraph (blank-line separated); a single "\n" inside
// one quote (a multi-line quote) is kept as-is. Returns [] if the channel
// had no description, or if $line is empty (channelinfo wasn't requested,
// or its response didn't come back - e.g. the configured cid no longer
// exists - never fatal, the rest of the page is unaffected either way).
function ts_parse_quotes(string $line): array {
    if ($line === '') return [];
    $description = ts_parse_item($line)['channel_description'] ?? '';
    if ($description === '') return [];
    $blocks = preg_split('/\n\s*\n/', trim($description));
    return array_values(array_filter(array_map('trim', $blocks), fn($b) => $b !== ''));
}
