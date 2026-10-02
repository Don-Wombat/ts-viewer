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
        'quotes_title'      => 'Zitate',
        'quotes_empty'      => 'Noch keine Zitate.',
        'leaderboard_title' => 'Bestenliste · Online-Zeit',
        'leaderboard_empty' => 'Noch keine Daten.',
        'leaderboard_note'  => 'Zählung läuft seit September 2026.',
        'hero_since'        => '· seit {year}',
        'soundboard_title'          => 'Soundboard',
        'soundboard_button'        => 'Soundboard',
        'soundboard_password_label'=> 'Passwort',
        'soundboard_unlock'        => 'Entsperren',
        'soundboard_wrong_password'=> 'Falsches Passwort.',
        'soundboard_too_many_attempts'=> 'Zu viele Versuche. Bitte in ein paar Minuten erneut versuchen.',
        'soundboard_disabled'      => 'Das Soundboard ist nicht aktiviert.',
        'soundboard_empty'         => 'Keine Sounds gefunden.',
        'soundboard_general'       => 'Allgemein',
        'soundboard_back'          => 'Zurück',
        'soundboard_stop'          => 'Stopp',
        'soundboard_volume'        => 'Lautstärke',
        'soundboard_nothing_playing'=> 'Kein Sound ausgewählt',
        'soundboard_scroll_top'    => 'Nach oben',
        'soundboard_collapse_all'  => 'Alle einklappen',
        'soundboard_expand_all'    => 'Alle ausklappen',
        'soundboard_hide_bar'      => 'Leiste ausblenden',
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
        'quotes_title'      => 'Quotes',
        'quotes_empty'      => 'No quotes yet.',
        'leaderboard_title' => 'Leaderboard · Online Time',
        'leaderboard_empty' => 'No data yet.',
        'leaderboard_note'  => 'Tracking since September 2026.',
        'hero_since'        => '· since {year}',
        'soundboard_title'          => 'Soundboard',
        'soundboard_button'        => 'Soundboard',
        'soundboard_password_label'=> 'Password',
        'soundboard_unlock'        => 'Unlock',
        'soundboard_wrong_password'=> 'Wrong password.',
        'soundboard_too_many_attempts'=> 'Too many attempts. Please try again in a few minutes.',
        'soundboard_disabled'      => 'The soundboard is not enabled.',
        'soundboard_empty'         => 'No sounds found.',
        'soundboard_general'       => 'General',
        'soundboard_back'          => 'Back',
        'soundboard_stop'          => 'Stop',
        'soundboard_volume'        => 'Volume',
        'soundboard_nothing_playing'=> 'No sound selected',
        'soundboard_scroll_top'    => 'Scroll to top',
        'soundboard_collapse_all'  => 'Collapse all',
        'soundboard_expand_all'    => 'Expand all',
        'soundboard_hide_bar'      => 'Hide bar',
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
