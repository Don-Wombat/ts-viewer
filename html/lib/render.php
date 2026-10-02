<?php
require_once __DIR__ . '/ts_protocol.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/ts_client.php';
require_once __DIR__ . '/ts_online_time.php';

function ts_render_tree(array $config, string $extraSidebarHtml = ''): string {
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
    $h .= '<div class="server-header"><div class="server-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"><path d="M3 19h18L15 7l-3.5 5L9 8l-6 11z"/></svg></div>';
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
    $h .= ts_render_channels($children, $cmap, $by_ch, '0', 0, $config['max_depth'], $sgMap, $defaultSgid, $config['hidden_channels'] ?? []);
    $h .= '</div>';
    $h .= '<div class="footer">' . htmlspecialchars(ts_t('footer', ['time' => $updated, 'sec' => $config['ttl']])) . '</div>';

    $showQuotes = !empty($config['quote_channel_id']);
    $showLeaderboard = !empty($config['track_online_time']);
    if (!$showQuotes && !$showLeaderboard && $extraSidebarHtml === '') {
        return $h;
    }

    $sidebar = '';
    if ($showLeaderboard) $sidebar .= ts_render_leaderboard(ts_online_time_top(ts_online_time_read($config['cache_dir']), 10));
    if ($showQuotes) $sidebar .= ts_render_quotebox($data['quotes'] ?? []);
    // Appended last - currently always lands directly under the quote box
    // (see the render order right above), which is exactly where the
    // soundboard button/password gate below is meant to sit.
    $sidebar .= $extraSidebarHtml;

    $out  = '<div class="layout">';
    $out .= '<div class="col-tree">' . $h . '</div>';
    $out .= '<div class="col-quotes">' . $sidebar . '</div>';
    $out .= '</div>';
    return $out;
}

// $entries: already sorted/limited, each ['nickname' =>, 'seconds' =>] - see
// ts_online_time_top() in ts_online_time.php.
function ts_render_leaderboard(array $entries): string {
    $h = '<div class="quote-box leaderboard">';
    $h .= '<div class="quote-box-header">' . htmlspecialchars(ts_t('leaderboard_title')) . '</div>';
    if (empty($entries)) {
        $h .= '<div class="quote-empty">' . htmlspecialchars(ts_t('leaderboard_empty')) . '</div>';
    } else {
        $h .= '<div class="leaderboard-list">';
        foreach ($entries as $i => $entry) {
            $h .= '<div class="lb-row">';
            $h .= '<span class="lb-rank">' . ($i + 1) . '</span>';
            $h .= '<span class="lb-name">' . htmlspecialchars($entry['nickname']) . '</span>';
            $h .= '<span class="lb-time">' . htmlspecialchars(ts_uptime($entry['seconds'])) . '</span>';
            $h .= '</div>';
        }
        $h .= '</div>';
    }
    $h .= '<div class="leaderboard-note">' . htmlspecialchars(ts_t('leaderboard_note')) . '</div>';
    $h .= '</div>';
    return $h;
}

function ts_render_quotebox(array $quotes): string {
    $h = '<div class="quote-box">';
    $h .= '<div class="quote-box-header">' . htmlspecialchars(ts_t('quotes_title')) . '</div>';
    if (empty($quotes)) {
        $h .= '<div class="quote-empty">' . htmlspecialchars(ts_t('quotes_empty')) . '</div>';
    } else {
        $h .= '<div class="quote-list">';
        // New quotes get appended at the end of the channel description, so
        // reverse for display - newest first, no scrolling needed to see it.
        foreach (array_reverse($quotes) as $quote) {
            [$text, $attribution] = ts_split_quote_attribution($quote);
            $h .= '<div class="quote-card">';
            $h .= '<div class="quote-text">' . nl2br(htmlspecialchars($text)) . '</div>';
            if ($attribution !== '') {
                $h .= '<div class="quote-attribution">' . htmlspecialchars($attribution) . '</div>';
            }
            $h .= '</div>';
        }
        $h .= '</div>';
    }
    $h .= '</div>';
    return $h;
}

// Best-effort split of "<quote text> - <name> <year>" (name optional, dash
// spacing inconsistent in practice) into separate text/attribution. Falls
// back to showing the whole entry as plain text if it doesn't match - not
// every past or future entry necessarily follows this convention, and a
// failed split just means one plain-looking card instead of a broken page.
function ts_split_quote_attribution(string $quote): array {
    if (preg_match('/^(.*?)\s*-\s*(?:([\p{L}][\p{L} .]*?)\s+)?(\d{4})\s*$/us', $quote, $m) && trim($m[1]) !== '') {
        $name = trim($m[2] ?? '');
        $attribution = '— ' . ($name !== '' ? $name . ' ' : '') . $m[3];
        return [trim($m[1]), $attribution];
    }
    return [$quote, ''];
}

function ts_render_channels(array $ch, array $cmap, array $by_ch, string $pid, int $depth, int $maxDepth, array $sgMap, ?string $defaultSgid, array $hiddenNames = [], int $recDepth = 0): string {
    // $recDepth guards recursion itself and always increments, even for
    // spacer channels (which intentionally keep $depth - the visual
    // indentation - unchanged). Using $depth alone for the guard let a chain
    // of nested spacer channels recurse without bound, since $depth never
    // grew on that path.
    if ($recDepth > $maxDepth) return '';
    if (!isset($ch[$pid])) return '';
    $ids = ts_filter_hidden_channel_ids($ch[$pid], $cmap, $hiddenNames);
    $h = '';
    foreach ($ids as $cid) {
        $c    = $cmap[$cid] ?? [];
        $raw_name = $c['channel_name'] ?? '?';
        // Already unescaped by ts_parse_item() - see the note on $sgMap in ts_render_tree().
        $name = $raw_name;
        // TeamSpeak's own client-side spacer convention: "[<align>spacerN]label",
        // where <align> is l/c/r (left/center/right-aligned label) or * (fill -
        // a plain divider line, no readable text) - "*" is also what a bare
        // "[spacerN]" with no align letter effectively behaves as. This used to
        // be approximated as "hide the whole row" (for a no-label spacer) or,
        // for anything with a label like "[cspacer] Talk Channels", fell
        // through to being rendered as a completely normal, icon-bearing
        // channel - neither matches what the actual TS client shows, which is
        // the whole reason these tags exist: dividers and section headings in
        // the channel list, never a real, joinable-looking channel row.
        if (preg_match('/^\[([lcr*]?)spacer\d*\](.*)$/is', $raw_name, $spacerMatch)) {
            $h .= ts_render_spacer(strtolower($spacerMatch[1]), trim($spacerMatch[2]), $depth);
            if (!empty($by_ch[$cid])) {
                foreach ($by_ch[$cid] as $cl) {
                    $h .= ts_render_client($cl, $depth, $sgMap, $defaultSgid);
                }
            }
            $h .= ts_render_channels($ch, $cmap, $by_ch, $cid, $depth, $maxDepth, $sgMap, $defaultSgid, $hiddenNames, $recDepth + 1);
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
        $h .= ts_render_channels($ch, $cmap, $by_ch, $cid, $depth + 1, $maxDepth, $sgMap, $defaultSgid, $hiddenNames, $recDepth + 1);
        $h .= '</div>';
    }
    return $h;
}

// A channel's effective display name for TS_HIDDEN_CHANNELS matching: a
// spacer's label (e.g. "[cspacer]Special Channels" -> "Special Channels"),
// or the raw name for a normal channel - matches how an admin actually reads
// the name off their TS client, not the raw wire-format tag.
function ts_channel_effective_name(string $rawName): string {
    if (preg_match('/^\[([lcr*]?)spacer\d*\](.*)$/is', $rawName, $m)) {
        return trim($m[2]);
    }
    return $rawName;
}

// Drops channels whose effective name is in $hiddenNames, and - right after
// a dropped channel - one immediately-following plain spacer/divider (not a
// labeled heading), so removing e.g. "Quote Box" doesn't leave its trailing
// "___" divider dangling with nothing above it to separate from. Only ever
// swallows the single next entry, so a labeled section heading further down
// (e.g. the next section's own "[cspacer]...") is never affected.
function ts_filter_hidden_channel_ids(array $ids, array $cmap, array $hiddenNames): array {
    if (empty($hiddenNames)) return $ids;
    $filtered = [];
    $swallowNextSpacer = false;
    foreach ($ids as $cid) {
        $rawName = $cmap[$cid]['channel_name'] ?? '';
        $isSpacer = (bool)preg_match('/^\[[lcr*]?spacer\d*\]/i', $rawName);
        if ($swallowNextSpacer) {
            $swallowNextSpacer = false;
            if ($isSpacer) continue;
        }
        if (in_array(ts_channel_effective_name($rawName), $hiddenNames, true)) {
            $swallowNextSpacer = true;
            continue;
        }
        $filtered[] = $cid;
    }
    return $filtered;
}

// A TeamSpeak spacer channel: $align is 'l'/'c'/'r' (a labeled section
// heading, text-aligned accordingly) or '*'/'' (a plain divider line, no
// icon, no count, not click-styled - matching how the actual TS client
// shows these). A label-less l/c/r spacer (empty $label) also falls back to
// the plain divider, since there's nothing to align.
function ts_render_spacer(string $align, string $label, int $depth): string {
    $indent = 12 + $depth * 16;
    if ($label === '' || !in_array($align, ['l', 'c', 'r'], true)) {
        return '<div class="ch-spacer-line" style="padding-left:' . $indent . 'px"><span></span></div>';
    }
    $alignClass = ['l' => 'left', 'c' => 'center', 'r' => 'right'][$align];
    return '<div class="ch-spacer-label ch-spacer-' . $alignClass . '" style="padding-left:' . $indent . 'px">' . htmlspecialchars($label) . '</div>';
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
