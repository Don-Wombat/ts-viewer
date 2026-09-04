<?php
// ─── Konfiguration ────────────────────────────────────────────────────────────
// Alles kommt ausschliesslich aus Umgebungsvariablen - nichts Projektspezifisches
// darf im Code landen. TS_HOST/TS_USER/TS_PASS haben bewusst KEINEN Default:
// ohne gesetzte Variablen zeigt die App einen Konfigurationsfehler statt
// stillschweigend gegen einen falschen Server zu laufen.

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
        'connect_timeout'    => (int)ts_env('TS_CONNECT_TIMEOUT', '5'),

        'cache_dir'          => ts_env('TS_CACHE_DIR', '/var/cache/ts-viewer'),
        'ttl'                => 30, // Cache-Gültigkeit bei Erfolg (Sekunden)
        'error_ttl'          => 10, // Cache-Gültigkeit bei Fehlern (kürzer, aber verhindert Verbindungssturm)
        'max_depth'          => 32, // Schutz gegen Endlos-Rekursion bei zyklischer Channel-Struktur
        'timezone'           => ts_env('TS_TIMEZONE', 'Europe/Berlin'),

        'brand_title'        => ts_env('TS_BRAND_TITLE', 'TeamSpeak Viewer'),
        'brand_subtitle'     => ts_env('TS_BRAND_SUBTITLE', 'TeamSpeak Server'),
        'connect_url'        => ts_env('TS_CONNECT_URL'), // leer = Connect-Button ausgeblendet
        'theme_css_override' => ts_env('TS_THEME_CSS_OVERRIDE'),
    ];

    $config['cache_file']       = $config['cache_dir'] . '/ts_cache.json';
    $config['lock_file']        = $config['cache_dir'] . '/ts_cache.lock';
    $config['known_hosts_file'] = $config['cache_dir'] . '/known_hosts';

    return $config;
}
