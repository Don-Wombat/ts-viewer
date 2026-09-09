<?php
// ─── Translations ─────────────────────────────────────────────────────────────
// Simple key-value approach instead of gettext/Intl - sufficient for this
// project's manageable set of UI strings and doesn't need a PHP extension.
// Note: the 'de' array below is the actual German language pack shown to
// end users when they pick German - its values are content, not comments,
// and must stay in German.

$GLOBALS['TS_TRANSLATIONS'] = [
    'de' => [
        'online'            => 'Online',
        'clients'           => 'Nutzer',
        'channels'          => 'Kanäle',
        'uptime'            => 'Laufzeit',
        'away'              => 'Abwesend',
        'mic_muted'         => 'Mikrofon stumm',
        'speaker_muted'     => 'Lautsprecher stumm',
        'footer'            => 'Aktualisiert {time} · Refresh alle {sec}s',
        'connect_button'    => 'Mit TeamSpeak verbinden',
        'err_not_configured'=> '{var} ist nicht konfiguriert.',
        'err_unreachable'   => 'TeamSpeak-Server aktuell nicht erreichbar.',
        'err_cache_dir'     => 'Cache-Verzeichnis nicht beschreibbar.',
    ],
    'en' => [
        'online'            => 'Online',
        'clients'           => 'Clients',
        'channels'          => 'Channels',
        'uptime'            => 'Uptime',
        'away'              => 'Away',
        'mic_muted'         => 'Microphone muted',
        'speaker_muted'     => 'Speaker muted',
        'footer'            => 'Updated {time} · Refreshes every {sec}s',
        'connect_button'    => 'Connect with TeamSpeak',
        'err_not_configured'=> '{var} is not configured.',
        'err_unreachable'   => 'TeamSpeak server currently unreachable.',
        'err_cache_dir'     => 'Cache directory is not writable.',
    ],
];

// Supported languages, used e.g. for the ?lang= parameter whitelist check in index.php.
const TS_SUPPORTED_LANGS = ['de', 'en'];

$GLOBALS['ts_current_lang'] = 'de';

function ts_set_lang(string $lang): void {
    $GLOBALS['ts_current_lang'] = in_array($lang, TS_SUPPORTED_LANGS, true) ? $lang : 'de';
}

// Translates $key in the currently set language, with {placeholder}
// substitution from $vars. Fallback for a missing key: the key itself (never
// an empty/broken output), fallback for a missing language: German.
function ts_t(string $key, array $vars = []): string {
    $lang = $GLOBALS['ts_current_lang'] ?? 'de';
    $str = $GLOBALS['TS_TRANSLATIONS'][$lang][$key]
        ?? $GLOBALS['TS_TRANSLATIONS']['de'][$key]
        ?? $key;
    foreach ($vars as $k => $v) {
        $str = str_replace('{' . $k . '}', (string)$v, $str);
    }
    return $str;
}
