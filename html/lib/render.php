<?php
require_once __DIR__ . '/ts_protocol.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/ts_client.php';

function ts_render_tree(array $config): string {
    $data = ts_get_cached_or_fetch($config, fn() => ts_fetch_from_server($config));
    if (isset($data['error'])) {
        $err = $data['error'];
        // Backwards compatible with the old cache format (error as a
        // ready-made string instead of a translation key+vars): can still be
        // sitting in the persistent cache volume for up to error_ttl seconds
        // after an upgrade. Without this fallback PHP 8 throws a TypeError
        // here (string offset access with a non-numeric key) - a real
        // fatal-error page for visitors.
        $msg = is_array($err) ? ts_t($err['key'] ?? 'err_unreachable', $err['vars'] ?? []) : (string)$err;
        return '<div class="error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> ' . htmlspecialchars($msg) . '</div>';
    }

    $info     = $data['serverinfo']  ?? [];
    $channels = $data['channellist'] ?? [];
    $clients  = array_values(array_filter($data['clientlist'] ?? [], fn($c) => ($c['client_type'] ?? '0') === '0'));

    // Server group ID -> name, for the role badge (e.g. "Server Admin").
    $sgMap = [];
    foreach ($data['servergrouplist'] ?? [] as $sg) {
        // Already unescaped by ts_parse_item() (called from ts_parse_list())
        // when the raw response was parsed - unescaping again here would
        // corrupt values containing a literal backslash.
        if (isset($sg['sgid'])) $sgMap[$sg['sgid']] = $sg['name'] ?? '';
    }
    $defaultSgid = $info['virtualserver_default_server_group'] ?? null;

    $by_ch = [];
    foreach ($clients as $c) $by_ch[$c['cid'] ?? '0'][] = $c;

    $online  = count($clients);
    $max     = $info['virtualserver_maxclients'] ?? '?';
    // Already unescaped by ts_parse_single() - see the note on $sgMap above.
    $name    = $info['virtualserver_name'] ?? 'TeamSpeak';
    $uptime  = isset($info['virtualserver_uptime']) ? ts_uptime((int)$info['virtualserver_uptime']) : '—';
    try {
        $tz = new DateTimeZone($config['timezone']);
    } catch (Exception $e) {
        // An invalid TS_TIMEZONE would otherwise throw here uncaught and
        // take down every render - fall back to UTC and keep the page working.
        error_log('ts-viewer: invalid TS_TIMEZONE "' . $config['timezone'] . '", falling back to UTC');
        $tz = new DateTimeZone('UTC');
    }
    $updated = (new DateTime('@' . $data['updated']))->setTimezone($tz)->format('H:i:s');

    $h  = '<div class="server-card">';
    $h .= '<div class="server-header"><div class="server-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></div>';
    $h .= '<div class="server-meta"><span class="server-name">' . htmlspecialchars($name) . '</span><span class="server-online"><span class="dot"></span>' . htmlspecialchars(ts_t('online')) . '</span></div></div>';
    $h .= '<div class="stats"><div class="stat"><span class="stat-val">' . $online . ' / ' . htmlspecialchars($max) . '</span><span class="stat-label">' . htmlspecialchars(ts_t('clients')) . '</span></div>';
    $h .= '<div class="stat"><span class="stat-val">' . count($channels) . '</span><span class="stat-label">' . htmlspecialchars(ts_t('channels')) . '</span></div>';
    $h .= '<div class="stat"><span class="stat-val">' . htmlspecialchars($uptime) . '</span><span class="stat-label">' . htmlspecialchars(ts_t('uptime')) . '</span></div></div></div>';

    // Index all channels first, THEN build the parent-child mapping: orphaned
    // pid references (parent doesn't exist / appears later in the list) are
    // thereby reliably detected and attached to the root, instead of silently
    // losing the channel from the tree.
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

function ts_render_channels(array $ch, array $cmap, array $by_ch, string $pid, int $depth, int $maxDepth, array $sgMap, ?string $defaultSgid, int $recDepth = 0): string {
    // $recDepth guards recursion itself and always increments, even for
    // spacer channels (which intentionally keep $depth - the visual
    // indentation - unchanged). Using $depth alone for the guard let a chain
    // of nested spacer channels recurse without bound, since $depth never
    // grew on that path.
    if ($recDepth > $maxDepth) return '';
    if (!isset($ch[$pid])) return '';
    $h = '';
    foreach ($ch[$pid] as $cid) {
        $c    = $cmap[$cid] ?? [];
        $raw_name = $c['channel_name'] ?? '?';
        // Already unescaped by ts_parse_item() - see the note on $sgMap in ts_render_tree().
        $name = $raw_name;
        // Strip the [cspacer] tag and treat it as a heading
        $name = preg_replace('/^\[c?spacer[^\]]*\]\s*/i', '', $name);
        if (preg_match('/^\[spacer\d*\][\s_]*$/i', $raw_name) && $cid !== '2') {
            if (!empty($by_ch[$cid])) {
                // Show clients but hide the channel name
                foreach ($by_ch[$cid] as $cl) {
                    $h .= ts_render_client($cl, $depth, $sgMap, $defaultSgid);
                }
            }
            $h .= ts_render_channels($ch, $cmap, $by_ch, $cid, $depth, $maxDepth, $sgMap, $defaultSgid, $recDepth + 1);
            continue;
        }
        $here = $by_ch[$cid] ?? [];
        $active = !empty($here) ? ' active' : '';
        $indent = $depth * 16;
        $topic = trim($c['channel_topic'] ?? '');
        $h .= '<div class="channel' . $active . '" style="padding-left:' . (12 + $indent) . 'px">';
        $h .= '<div class="ch-row"><span class="ch-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg></span>';
        $h .= '<span class="ch-name">' . htmlspecialchars($name) . '</span>';
        if (!empty($here)) $h .= '<span class="ch-count">' . count($here) . '</span>';
        $h .= '</div>';
        // strlen (not mb_strlen - mbstring is not a dependency of this
        // project) > 1: hides trivial/placeholder topics like "1" (a common
        // leftover from channel templates/copy operations) - a single
        // character is practically never an intentionally set topic. A
        // single-character multi-byte topic (e.g. an emoji) that isn't
        // filtered by mistake is a harmless edge case, not a real problem.
        if (strlen($topic) > 1) {
            $h .= '<div class="ch-topic" style="padding-left:' . (34 + $indent) . 'px">' . htmlspecialchars($topic) . '</div>';
        }
        foreach ($here as $cl) {
            $h .= ts_render_client($cl, $depth, $sgMap, $defaultSgid);
        }
        $h .= ts_render_channels($ch, $cmap, $by_ch, $cid, $depth + 1, $maxDepth, $sgMap, $defaultSgid, $recDepth + 1);
        $h .= '</div>';
    }
    return $h;
}

// Renders a single client row (icon, name, mute icons, role badge, away
// badge) - extracted because it used to be duplicated identically in two places.
function ts_render_client(array $cl, int $depth, array $sgMap, ?string $defaultSgid): string {
    // Already unescaped by ts_parse_item() - see the note on $sgMap in ts_render_tree().
    $nick  = $cl['client_nickname'] ?? '?';
    $away  = ($cl['client_away'] ?? '0') === '1';
    $inMuted  = ($cl['client_input_muted'] ?? '0') === '1';
    $outMuted = ($cl['client_output_muted'] ?? '0') === '1';

    // First server group that isn't the default group, shown as the role
    // badge (e.g. "Server Admin") - Normal/Guest users get no unnecessary badge this way.
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
