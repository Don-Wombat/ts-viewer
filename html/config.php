<?php
// ─── Configuration ────────────────────────────────────────────────────────────
// Everything comes exclusively from environment variables - nothing project-
// specific may live in the code. TS_HOST/TS_USER/TS_PASS deliberately have
// NO default: without the variables set, the app shows a configuration error
// instead of silently running against the wrong server.

function ts_env(string $name, ?string $default = null): ?string {
    $v = getenv($name);
    return ($v !== false && $v !== '') ? $v : $default;
}

function ts_load_config(): array {
    $transport   = ts_env('TS_TRANSPORT', 'ssh');
    $defaultPort = $transport === 'raw' ? 10011 : 10022;

    $config = [
        'transport'          => $transport,
        'host'               => ts_env('TS_HOST', ''),
        'port'               => (int)ts_env('TS_PORT', (string)$defaultPort),
        'user'               => ts_env('TS_USER', ''),
        'pass'               => ts_env('TS_PASS', ''),
        'vport'              => (int)ts_env('TS_VPORT', '9987'),
        'query_nickname'     => ts_env('TS_QUERY_NICKNAME', 'TS-Viewer'),
        // max(1, ...): a typo like TS_CONNECT_TIMEOUT=abc casts to 0, which
        // stream_socket_client()/stream_set_timeout() (raw transport) and
        // ssh's ConnectTimeout (ssh transport) would otherwise take as
        // "block indefinitely" against an unreachable host.
        'connect_timeout'    => max(1, (int)ts_env('TS_CONNECT_TIMEOUT', '5')),

        'cache_dir'          => ts_env('TS_CACHE_DIR', '/var/cache/ts-viewer'),
        // max(1, ...): a typo like TS_CACHE_TTL=abc casts to 0 - without a
        // floor that would effectively disable the cache AND produce a
        // setInterval(fn, 0) in the frontend (constant polling against itself).
        'ttl'                => max(1, (int)ts_env('TS_CACHE_TTL', '30')),       // cache validity on success (seconds)
        'error_ttl'          => max(1, (int)ts_env('TS_CACHE_ERROR_TTL', '10')), // cache validity on errors (shorter, but prevents a connection storm)
        'max_depth'          => 32, // guard against infinite recursion on a cyclic channel structure
        'timezone'           => ts_env('TS_TIMEZONE', 'Europe/Berlin'),

        'brand_title'        => ts_env('TS_BRAND_TITLE', 'TeamSpeak Viewer'),
        'brand_subtitle'     => ts_env('TS_BRAND_SUBTITLE', 'TeamSpeak Server'),
        'founded_year'       => ts_env('TS_FOUNDED_YEAR'), // empty = not shown in the hero
        'connect_url'        => ts_env('TS_CONNECT_URL'), // empty = connect button hidden
        'theme_css_override' => ts_env('TS_THEME_CSS_OVERRIDE'),

        // Default language if neither ?lang= nor the ts_lang cookie is set.
        // Default "de" doesn't change behavior for existing deployments.
        'default_lang'       => ts_env('TS_DEFAULT_LANG', 'de'),

        // cid of a channel whose description holds quotes (one per
        // paragraph, separated by a blank line) - shown next to the tree.
        // Empty = feature off (default single-column layout, no extra
        // command in the ServerQuery bundle). A cid, not a name: resolving
        // by name would need its own extra connection (channellist has no
        // -description flag), and two connections back-to-back in the same
        // request tripped this server's flood protection in testing.
        'quote_channel_id'   => ts_env('TS_QUOTE_CHANNEL_ID'),

        // Online-time leaderboard, shown below the quote box. Empty = off
        // (no leaderboard rendered, and - checked directly via getenv() in
        // docker/entrypoint.sh, not through this array - the background
        // poller in cli/track_online_time.php doesn't start at all). The
        // poller writes cache_dir/online_time.json independently of any web
        // request; render.php just reads it.
        'track_online_time'  => ts_env('TS_TRACK_ONLINE_TIME'),

        // Comma-separated exact channel names to hide from the tree entirely
        // (channel + its clients/subchannels). Matched against the effective
        // display name - a spacer's label (e.g. "[cspacer]Special Channels"
        // matches "Special Channels"), or the raw name for a normal channel.
        // Hiding a channel also swallows one immediately-following plain
        // spacer/divider, so a divider left dangling only because of the
        // channel above it being hidden doesn't linger on its own. Empty =
        // off (no filtering, matches previous behavior).
        'hidden_channels'    => array_values(array_filter(array_map('trim', explode(',', ts_env('TS_HIDDEN_CHANNELS', '') ?? '')))),

        // Static TeamSpeak rules text, shown as a numbered list next to the
        // tree - an alternative to the quote box above for servers that'd
        // rather show house rules than crowd-sourced quotes (both can be on
        // at once; they just stack). One rule per line; "\n" (literal
        // backslash-n, not an actual newline - env files don't reliably
        // support those) is unescaped to a real line break. Empty = off.
        'rules_text'         => str_replace('\\n', "\n", ts_env('TS_RULES_TEXT', '') ?? ''),
    ];

    $config['cache_file']       = $config['cache_dir'] . '/ts_cache.json';
    $config['lock_file']        = $config['cache_dir'] . '/ts_cache.lock';
    $config['known_hosts_file'] = $config['cache_dir'] . '/known_hosts';

    return $config;
}
