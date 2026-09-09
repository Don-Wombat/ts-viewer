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
        'connect_url'        => ts_env('TS_CONNECT_URL'), // empty = connect button hidden
        'theme_css_override' => ts_env('TS_THEME_CSS_OVERRIDE'),

        // Default language if neither ?lang= nor the ts_lang cookie is set.
        // Default "de" doesn't change behavior for existing deployments.
        'default_lang'       => ts_env('TS_DEFAULT_LANG', 'de'),
    ];

    $config['cache_file']       = $config['cache_dir'] . '/ts_cache.json';
    $config['lock_file']        = $config['cache_dir'] . '/ts_cache.lock';
    $config['known_hosts_file'] = $config['cache_dir'] . '/known_hosts';

    return $config;
}
