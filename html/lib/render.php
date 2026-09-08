<?php
require_once __DIR__ . '/ts_protocol.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/ts_client.php';

function ts_render_tree(array $config): string {
    $data = ts_get_cached_or_fetch($config, fn() => ts_fetch_from_server($config));
    if (isset($data['error'])) {
        $err = $data['error'];
        // Abwaertskompatibel zum alten Cache-Format (Fehler als fertiger String
        // statt Uebersetzungs-Key+Vars): kann fuer bis zu error_ttl Sekunden nach
        // einem Upgrade noch im persistenten Cache-Volume liegen. Ohne diesen
        // Fallback wirft PHP 8 hier einen TypeError (String-Offset-Zugriff mit
        // nicht-numerischem Key) - eine echte Fatal-Error-Seite fuer Besucher.
        $msg = is_array($err) ? ts_t($err['key'] ?? 'err_unreachable', $err['vars'] ?? []) : (string)$err;
        return '<div class="error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> ' . htmlspecialchars($msg) . '</div>';
    }

    $info     = $data['serverinfo']  ?? [];
    $channels = $data['channellist'] ?? [];
    $clients  = array_values(array_filter($data['clientlist'] ?? [], fn($c) => ($c['client_type'] ?? '0') === '0'));

    // Server-Gruppen-ID -> Name, fuer den Rollen-Badge (z.B. "Server Admin").
    $sgMap = [];
    foreach ($data['servergrouplist'] ?? [] as $sg) {
        if (isset($sg['sgid'])) $sgMap[$sg['sgid']] = ts_unescape($sg['name'] ?? '');
    }
    $defaultSgid = $info['virtualserver_default_server_group'] ?? null;

    $by_ch = [];
    foreach ($clients as $c) $by_ch[$c['cid'] ?? '0'][] = $c;

    $online  = count($clients);
    $max     = $info['virtualserver_maxclients'] ?? '?';
    $name    = ts_unescape($info['virtualserver_name'] ?? 'TeamSpeak');
    $uptime  = isset($info['virtualserver_uptime']) ? ts_uptime((int)$info['virtualserver_uptime']) : '—';
    $updated = (new DateTime('@' . $data['updated']))->setTimezone(new DateTimeZone($config['timezone']))->format('H:i:s');

    $h  = '<div class="server-card">';
    $h .= '<div class="server-header"><div class="server-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></div>';
    $h .= '<div class="server-meta"><span class="server-name">' . htmlspecialchars($name) . '</span><span class="server-online"><span class="dot"></span>' . htmlspecialchars(ts_t('online')) . '</span></div></div>';
    $h .= '<div class="stats"><div class="stat"><span class="stat-val">' . $online . ' / ' . htmlspecialchars($max) . '</span><span class="stat-label">' . htmlspecialchars(ts_t('clients')) . '</span></div>';
    $h .= '<div class="stat"><span class="stat-val">' . count($channels) . '</span><span class="stat-label">' . htmlspecialchars(ts_t('channels')) . '</span></div>';
    $h .= '<div class="stat"><span class="stat-val">' . htmlspecialchars($uptime) . '</span><span class="stat-label">' . htmlspecialchars(ts_t('uptime')) . '</span></div></div></div>';

    // Erst alle Channels indizieren, DANN die Eltern-Kind-Zuordnung aufbauen:
    // verwaiste pid-Referenzen (Parent existiert nicht/kommt in der Liste
    // erst spaeter) werden so zuverlaessig erkannt und an die Root gehaengt,
    // statt den Channel stillschweigend aus dem Baum zu verlieren.
    $cmap = [];
    foreach ($channels as $ch) { $cmap[$ch['cid'] ?? '0'] = $ch; }
    $children = [];
    foreach ($channels as $ch) {
        $id = $ch['cid'] ?? '0';
        $pid = $ch['pid'] ?? '0';
        if ($pid !== '0' && !isset($cmap[$pid])) $pid = '0';
        $children[$pid][] = $id;
    }

    $h .= '<div class="tree">';
    $h .= ts_render_channels($children, $cmap, $by_ch, '0', 0, $config['max_depth'], $sgMap, $defaultSgid);
    $h .= '</div>';
    $h .= '<div class="footer">' . htmlspecialchars(ts_t('footer', ['time' => $updated, 'sec' => $config['ttl']])) . '</div>';
    return $h;
}

function ts_render_channels(array $ch, array $cmap, array $by_ch, string $pid, int $depth, int $maxDepth, array $sgMap, ?string $defaultSgid): string {
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
                    $h .= ts_render_client($cl, $depth, $sgMap, $defaultSgid);
                }
            }
            $h .= ts_render_channels($ch, $cmap, $by_ch, $cid, $depth, $maxDepth, $sgMap, $defaultSgid);
            continue;
        }
        $here = $by_ch[$cid] ?? [];
        $active = !empty($here) ? ' active' : '';
        $indent = $depth * 16;
        $topic = trim(ts_unescape($c['channel_topic'] ?? ''));
        $h .= '<div class="channel' . $active . '" style="padding-left:' . (12 + $indent) . 'px">';
        $h .= '<div class="ch-row"><span class="ch-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg></span>';
        $h .= '<span class="ch-name">' . htmlspecialchars($name) . '</span>';
        if (!empty($here)) $h .= '<span class="ch-count">' . count($here) . '</span>';
        $h .= '</div>';
        // strlen (nicht mb_strlen - mbstring ist keine Abhaengigkeit dieses
        // Projekts) > 1: blendet triviale/Platzhalter-Topics wie "1" aus
        // (haeufiges Ueberbleibsel aus Channel-Vorlagen/Kopiervorgaengen) - ein
        // einzelnes Zeichen ist praktisch nie ein absichtlich gesetztes Topic.
        // Faelschlich nicht gefilterte Ein-Zeichen-Mehrbyte-Topics (z.B. ein
        // Emoji) sind ein harmloser Grenzfall, kein echtes Problem.
        if (strlen($topic) > 1) {
            $h .= '<div class="ch-topic" style="padding-left:' . (34 + $indent) . 'px">' . htmlspecialchars($topic) . '</div>';
        }
        foreach ($here as $cl) {
            $h .= ts_render_client($cl, $depth, $sgMap, $defaultSgid);
        }
        $h .= ts_render_channels($ch, $cmap, $by_ch, $cid, $depth + 1, $maxDepth, $sgMap, $defaultSgid);
        $h .= '</div>';
    }
    return $h;
}

// Rendert eine einzelne Client-Zeile (Icon, Name, Mute-Icons, Rollen-Badge,
// Away-Badge) - ausgelagert, weil vorher an zwei Stellen identisch dupliziert.
function ts_render_client(array $cl, int $depth, array $sgMap, ?string $defaultSgid): string {
    $nick  = ts_unescape($cl['client_nickname'] ?? '?');
    $away  = ($cl['client_away'] ?? '0') === '1';
    $inMuted  = ($cl['client_input_muted'] ?? '0') === '1';
    $outMuted = ($cl['client_output_muted'] ?? '0') === '1';

    // Erste Server-Gruppe, die nicht der Standard-Gruppe entspricht, als
    // Rollen-Badge (z.B. "Server Admin") - Normal-/Guest-User bekommen so
    // keinen unnoetigen Badge.
    $groupBadge = '';
    foreach (explode(',', $cl['client_servergroups'] ?? '') as $sgid) {
        if ($sgid !== '' && $sgid !== $defaultSgid && isset($sgMap[$sgid])) {
            $groupBadge = $sgMap[$sgid];
            break;
        }
    }

    $h = '<div class="client' . ($away ? ' away' : '') . '" style="padding-left:' . (28 + $depth * 16) . 'px">';
    $h .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="cl-icon"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    $h .= '<span class="cl-name">' . htmlspecialchars($nick) . '</span>';
    if ($inMuted) $h .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="status-icon" title="' . htmlspecialchars(ts_t('mic_muted')) . '"><path d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3z"/><path d="M19 10v2a7 7 0 01-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    if ($outMuted) $h .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="status-icon" title="' . htmlspecialchars(ts_t('speaker_muted')) . '"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';
    if ($groupBadge !== '') $h .= '<span class="group-badge">' . htmlspecialchars($groupBadge) . '</span>';
    if ($away) $h .= '<span class="away-badge">' . htmlspecialchars(ts_t('away')) . '</span>';
    $h .= '</div>';
    return $h;
}
