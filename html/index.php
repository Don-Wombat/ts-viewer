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
require __DIR__ . '/lib/auth.php';

$config = ts_load_config();

// ─── Language selection ───────────────────────────────────────────────────────
// Priority: ?lang= parameter (also sets the cookie) > existing cookie >
// TS_DEFAULT_LANG. Applies to the full page AND ?ajax=1, since ts_render_tree()
// uses ts_t() internally and the language is set here before any branching.
$lang = $config['default_lang'];
if (isset($_COOKIE['ts_lang']) && in_array($_COOKIE['ts_lang'], TS_SUPPORTED_LANGS, true)) $lang = $_COOKIE['ts_lang'];
if (isset($_GET['lang']) && in_array($_GET['lang'], TS_SUPPORTED_LANGS, true)) {
    $lang = $_GET['lang'];
    setcookie('ts_lang', $lang, time() + 60 * 60 * 24 * 365, '/');
}
// If TS_DEFAULT_LANG itself is set to an invalid value: same German fallback
// as in ts_set_lang(), but already here - otherwise <html lang="..."> and the
// active DE/EN marker would show an invalid value even though the actual
// rendering (ts_t()) already falls back to German internally.
if (!in_array($lang, TS_SUPPORTED_LANGS, true)) $lang = 'de';
ts_set_lang($lang);

// Applies to the full page AND ?ajax=1/?health=1 - set centrally here before
// the branching instead of in each branch individually.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");

// ─── Health check ─────────────────────────────────────────────────────────────
// Deliberately no TS server roundtrip/cache access - only checks that
// PHP/Apache are running (for the Dockerfile HEALTHCHECK / external monitoring).
if (isset($_GET['health'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
    exit;
}

// ─── Soundboard gate (sidebar, under the quote box) ──────────────────────────
// Pure markup built here (not in render.php, which never touches $_GET/
// $_COOKIE directly) and handed to ts_render_tree() to place. The button
// itself never links anywhere - it only reveals the password field; actual
// navigation only happens after soundboard_login.php sets the auth cookie.
$soundboardGateHtml = '';
if (!empty($config['sounds_dir']) && !empty($config['sounds_password'])) {
    $fieldsVisible = isset($_GET['soundboard_error']);
    $soundboardGateHtml .= '<form method="post" action="soundboard_login.php" class="soundboard-gate">';
    $soundboardGateHtml .= '<button type="button" class="connect-btn soundboard-toggle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>' . htmlspecialchars(ts_t('soundboard_button')) . '</button>';
    $soundboardGateHtml .= '<span class="soundboard-fields"' . ($fieldsVisible ? '' : ' hidden') . '>';
    $soundboardGateHtml .= '<input type="password" name="password" placeholder="' . htmlspecialchars(ts_t('soundboard_password_label')) . '" required>';
    $soundboardGateHtml .= '<button type="submit" class="connect-btn">' . htmlspecialchars(ts_t('soundboard_unlock')) . '</button>';
    $soundboardGateHtml .= '</span>';
    if ($fieldsVisible) {
        $errorKey = ($_GET['soundboard_error'] ?? '') === 'throttled' ? 'soundboard_too_many_attempts' : 'soundboard_wrong_password';
        $soundboardGateHtml .= '<div class="soundboard-error">' . htmlspecialchars(ts_t($errorKey)) . '</div>';
    }
    $soundboardGateHtml .= '</form>';
}

// ─── AJAX refresh ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo ts_render_tree($config, $soundboardGateHtml);
    exit;
}

$page_content = ts_render_tree($config, $soundboardGateHtml);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($config['brand_title']) ?></title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/fonts.css">
<link rel="stylesheet" href="assets/style.css">
<?php if (!empty($config['theme_css_override'])): ?>
<style><?= $config['theme_css_override'] ?></style>
<?php endif; ?>
</head>
<body>
<div class="wrap<?= (!empty($config['quote_channel_id']) || !empty($config['track_online_time'])) ? ' wide' : '' ?>">
  <div class="hero">
    <svg class="hero-mark" width="40" height="40" viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path d="M14 2C7.37 2 2 7.37 2 14s5.37 12 12 12 12-5.37 12-12S20.63 2 14 2z"/>
      <path d="M9 11c0-2.76 2.24-5 5-5s5 2.24 5 5v3c0 2.76-2.24 5-5 5s-5-2.24-5-5v-3z"/>
      <path d="M6 14h2M20 14h2M14 22v-2"/>
    </svg>
    <div class="hero-brand">
      <div class="hero-title"><?= htmlspecialchars($config['brand_title']) ?></div>
      <div class="hero-sub">
        <?= htmlspecialchars($config['brand_subtitle']) ?><?php if (!empty($config['founded_year'])): ?><span class="hero-since"> <?= htmlspecialchars(ts_t('hero_since', ['year' => $config['founded_year']])) ?></span><?php endif; ?>
      </div>
    </div>
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
  // The refresh replaces #ts-content's innerHTML wholesale, which would
  // otherwise reset .quote-list's scroll position to the top every cycle -
  // save it beforehand and restore it on the freshly rendered element.
  var quoteBox = document.querySelector('.quote-list');
  var quoteScroll = quoteBox ? quoteBox.scrollTop : null;

  // Same problem for the soundboard password field: the server always
  // renders it hidden by default (this ajax request carries none of the
  // original page's query string, so it never sees ?soundboard_error=...
  // either) - without this, a visitor who opened the field would have it
  // silently vanish, password they'd already typed and all, on the next
  // refresh tick.
  var pwField = document.querySelector('.soundboard-fields input[name=password]');
  var pwWasOpen = pwField && !pwField.closest('.soundboard-fields').hidden;
  var pwValue = pwField ? pwField.value : '';
  var pwHadFocus = pwField === document.activeElement;

  fetch('?ajax=1')
    .then(r => r.text())
    .then(html => {
      document.getElementById('ts-content').innerHTML = html;
      if (quoteScroll !== null) {
        var newQuoteBox = document.querySelector('.quote-list');
        if (newQuoteBox) newQuoteBox.scrollTop = quoteScroll;
      }
      if (pwWasOpen) {
        var newFields = document.querySelector('.soundboard-fields');
        var newToggle = document.querySelector('.soundboard-toggle');
        var newPw = newFields ? newFields.querySelector('input[name=password]') : null;
        if (newFields) newFields.hidden = false;
        if (newToggle) newToggle.hidden = true;
        if (newPw) {
          newPw.value = pwValue;
          if (pwHadFocus) newPw.focus({preventScroll: true});
        }
      }
    });
}, <?= $config['ttl'] * 1000 ?>);

// Delegated (not bound directly to .soundboard-toggle): the ajax refresh
// above replaces #ts-content's innerHTML wholesale, which would otherwise
// silently drop a directly-bound listener on every refresh cycle.
document.addEventListener('click', function(e) {
  var toggle = e.target.closest('.soundboard-toggle');
  if (!toggle) return;
  var fields = toggle.parentElement.querySelector('.soundboard-fields');
  if (!fields) return;
  fields.hidden = false;
  toggle.hidden = true;
  var pw = fields.querySelector('input[name=password]');
  if (pw) pw.focus();
});
</script>
</body>
</html>
