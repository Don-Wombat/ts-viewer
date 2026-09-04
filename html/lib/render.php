<?php
require_once __DIR__ . '/ts_protocol.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/ts_client.php';

function ts_render_tree(array $config): string {
    $data = ts_get_cached_or_fetch($config, fn() => ts_fetch_from_server($config));
    if (isset($data['error'])) return '<div class="error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> ' . htmlspecialchars($data['error']) . '</div>';

    $info     = $data['serverinfo']  ?? [];
    $channels = $data['channellist'] ?? [];
    $clients  = array_values(array_filter($data['clientlist'] ?? [], fn($c) => ($c['client_type'] ?? '0') === '0'));

    $by_ch = [];
    foreach ($clients as $c) $by_ch[$c['cid'] ?? '0'][] = $c;

    $online  = count($clients);
    $max     = $info['virtualserver_maxclients'] ?? '?';
    $name    = ts_unescape($info['virtualserver_name'] ?? 'TeamSpeak');
    $uptime  = isset($info['virtualserver_uptime']) ? ts_uptime((int)$info['virtualserver_uptime']) : '—';
    $updated = (new DateTime('@' . $data['updated']))->setTimezone(new DateTimeZone($config['timezone']))->format('H:i:s');

    $h  = '<div class="server-card">';
    $h .= '<div class="server-header"><div class="server-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></div>';
    $h .= '<div class="server-meta"><span class="server-name">' . htmlspecialchars($name) . '</span><span class="server-online"><span class="dot"></span>Online</span></div></div>';
    $h .= '<div class="stats"><div class="stat"><span class="stat-val">' . $online . ' / ' . htmlspecialchars($max) . '</span><span class="stat-label">Clients</span></div>';
    $h .= '<div class="stat"><span class="stat-val">' . count($channels) . '</span><span class="stat-label">Channels</span></div>';
    $h .= '<div class="stat"><span class="stat-val">' . htmlspecialchars($uptime) . '</span><span class="stat-label">Uptime</span></div></div></div>';

    $children = []; $cmap = [];
    foreach ($channels as $ch) { $id = $ch['cid']??'0'; $pid = $ch['pid']??'0'; $cmap[$id]=$ch; $children[$pid][]=$id; }

    $h .= '<div class="tree">';
    $h .= ts_render_channels($children, $cmap, $by_ch, '0', 0, $config['max_depth']);
    $h .= '</div>';
    $h .= '<div class="footer">Aktualisiert ' . htmlspecialchars($updated) . ' · Refresh alle ' . $config['ttl'] . 's</div>';
    return $h;
}

function ts_render_channels(array $ch, array $cmap, array $by_ch, string $pid, int $depth, int $maxDepth): string {
    if ($depth > $maxDepth) return ''; // Schutz gegen Endlos-Rekursion bei zyklischer Channel-Struktur
    if (!isset($ch[$pid])) return '';
    $h = '';
    foreach ($ch[$pid] as $cid) {
        $c    = $cmap[$cid] ?? [];
        $raw_name = $c['channel_name'] ?? '?';
        $name = ts_unescape($raw_name);
        // [cspacer] Tag entfernen und als Überschrift behandeln
        $name = preg_replace('/^\[c?spacer[^\]]*\]\s*/i', '', $name);
        if (preg_match('/^\[spacer\d*\][\s_]*$/i', $raw_name) && $cid !== '2') {
            if (!empty($by_ch[$cid])) {
                // Clients anzeigen aber Channel-Name ausblenden
                foreach ($by_ch[$cid] as $cl) {
                    $nick = ts_unescape($cl['client_nickname'] ?? '?');
                    $away = ($cl['client_away'] ?? '0') === '1';
                    $h .= '<div class="client' . ($away ? ' away' : '') . '" style="padding-left:' . (28 + $depth * 16) . 'px">';
                    $h .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="cl-icon"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
                    $h .= '<span class="cl-name">' . htmlspecialchars($nick) . '</span>';
                    if ($away) $h .= '<span class="away-badge">Away</span>';
                    $h .= '</div>';
                }
            }
            $h .= ts_render_channels($ch, $cmap, $by_ch, $cid, $depth, $maxDepth);
            continue;
        }
        $here = $by_ch[$cid] ?? [];
        $active = !empty($here) ? ' active' : '';
        $indent = $depth * 16;
        $h .= '<div class="channel' . $active . '" style="padding-left:' . (12 + $indent) . 'px">';
        $h .= '<div class="ch-row"><span class="ch-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg></span>';
        $h .= '<span class="ch-name">' . htmlspecialchars($name) . '</span>';
        if (!empty($here)) $h .= '<span class="ch-count">' . count($here) . '</span>';
        $h .= '</div>';
        foreach ($here as $cl) {
            $nick = ts_unescape($cl['client_nickname'] ?? '?');
            $away = ($cl['client_away'] ?? '0') === '1';
            $h .= '<div class="client' . ($away ? ' away' : '') . '" style="padding-left:' . (28 + $indent) . 'px">';
            $h .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="cl-icon"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
            $h .= '<span class="cl-name">' . htmlspecialchars($nick) . '</span>';
            if ($away) $h .= '<span class="away-badge">Away</span>';
            $h .= '</div>';
        }
        $h .= ts_render_channels($ch, $cmap, $by_ch, $cid, $depth + 1, $maxDepth);
        $h .= '</div>';
    }
    return $h;
}
