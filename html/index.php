<?php
require __DIR__ . '/config.php';
require __DIR__ . '/lib/ts_protocol.php';
require __DIR__ . '/lib/i18n.php';
require __DIR__ . '/lib/ts_transport.php';
require __DIR__ . '/lib/ts_transport_ssh.php';
require __DIR__ . '/lib/ts_transport_raw.php';
require __DIR__ . '/lib/ts_client.php';
require __DIR__ . '/lib/cache.php';
require __DIR__ . '/lib/render.php';

$config = ts_load_config();

// ─── Sprachauswahl ────────────────────────────────────────────────────────────
// Prioritaet: ?lang=-Parameter (setzt gleichzeitig das Cookie) > vorhandenes
// Cookie > TS_DEFAULT_LANG. Gilt fuer Vollseite UND ?ajax=1, da ts_render_tree()
// intern ts_t() nutzt und die Sprache hier vor jeder Verzweigung gesetzt wird.
$lang = $config['default_lang'];
if (isset($_COOKIE['ts_lang']) && in_array($_COOKIE['ts_lang'], TS_SUPPORTED_LANGS, true)) $lang = $_COOKIE['ts_lang'];
if (isset($_GET['lang']) && in_array($_GET['lang'], TS_SUPPORTED_LANGS, true)) {
    $lang = $_GET['lang'];
    setcookie('ts_lang', $lang, time() + 60 * 60 * 24 * 365, '/');
}
// Falls TS_DEFAULT_LANG selbst falsch gesetzt ist: dasselbe Deutsch-Fallback
// wie in ts_set_lang(), aber schon hier - sonst wuerde <html lang="..."> und
// die aktive DE/EN-Markierung einen ungueltigen Wert zeigen, obwohl intern
// (ts_t()) bereits auf Deutsch gerendert wird.
if (!in_array($lang, TS_SUPPORTED_LANGS, true)) $lang = 'de';
ts_set_lang($lang);

// Gilt fuer Vollseite UND ?ajax=1/?health=1 - deshalb hier zentral vor der
// Verzweigung statt in jedem Zweig einzeln gesetzt.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");

// ─── Health-Check ─────────────────────────────────────────────────────────────
// Bewusst ohne TS-Server-Roundtrip/Cache-Zugriff - prueft nur, dass PHP/Apache
// laufen (fuer Dockerfile HEALTHCHECK / externes Monitoring).
if (isset($_GET['health'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
    exit;
}

// ─── AJAX Refresh ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo ts_render_tree($config);
    exit;
}

$page_content = ts_render_tree($config);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($config['brand_title']) ?></title>
<link rel="stylesheet" href="assets/style.css">
<?php if (!empty($config['theme_css_override'])): ?>
<style><?= $config['theme_css_override'] ?></style>
<?php endif; ?>
</head>
<body>
<div class="wrap">
  <div class="logo">
    <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M14 2C7.37 2 2 7.37 2 14s5.37 12 12 12 12-5.37 12-12S20.63 2 14 2z"/>
      <path d="M9 11c0-2.76 2.24-5 5-5s5 2.24 5 5v3c0 2.76-2.24 5-5 5s-5-2.24-5-5v-3z"/>
      <path d="M6 14h2M20 14h2M14 22v-2"/>
    </svg>
    <div><div class="logo-text"><?= htmlspecialchars($config['brand_title']) ?></div><div class="logo-sub"><?= htmlspecialchars($config['brand_subtitle']) ?></div></div>
    <div class="lang-switch">
      <a href="?lang=de"<?= $lang === 'de' ? ' class="active"' : '' ?>>DE</a> · <a href="?lang=en"<?= $lang === 'en' ? ' class="active"' : '' ?>>EN</a>
    </div>
  </div>

  <div id="ts-content"><?php echo $page_content; ?></div>

  <?php if (!empty($config['connect_url'])): ?>
  <a class="connect-btn" href="<?= htmlspecialchars($config['connect_url']) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6M10 14L21 3M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/></svg>
    <?= htmlspecialchars(ts_t('connect_button')) ?>
  </a>
  <?php endif; ?>
</div>

<script>
setInterval(function() {
  fetch('?ajax=1')
    .then(r => r.text())
    .then(html => { document.getElementById('ts-content').innerHTML = html; });
}, <?= $config['ttl'] * 1000 ?>);
</script>
</body>
</html>
