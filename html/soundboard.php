<?php
require __DIR__ . '/config.php';
require __DIR__ . '/lib/i18n.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/sounds.php';

$config = ts_load_config();

$lang = $config['default_lang'];
if (isset($_COOKIE['ts_lang']) && in_array($_COOKIE['ts_lang'], TS_SUPPORTED_LANGS, true)) $lang = $_COOKIE['ts_lang'];
if (!in_array($lang, TS_SUPPORTED_LANGS, true)) $lang = 'de';
ts_set_lang($lang);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
// The rendered grid (or lack of it) depends on the auth cookie - never let
// an intermediary cache serve one visitor's authenticated page to another.
header('Cache-Control: private, no-store');

// Single choke point (see lib/auth.php) - $groups only gets populated, and
// the grid below only renders, once this is true. Nothing about a
// filename/label ever reaches the response before this check passes.
$enabled = !empty($config['sounds_dir']) && !empty($config['sounds_password']);
$authed  = $enabled && ts_soundboard_authenticated($config);
$groups  = $authed ? ts_sounds_list($config['sounds_dir']) : [];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(ts_t('soundboard_title')) ?> · <?= htmlspecialchars($config['brand_title']) ?></title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/fonts.css">
<link rel="stylesheet" href="assets/style.css">
<?php if (!empty($config['theme_css_override'])): ?>
<style><?= $config['theme_css_override'] ?></style>
<?php endif; ?>
</head>
<body<?= ($authed && !empty($groups)) ? ' class="has-soundbar"' : '' ?>>
<div class="wrap<?= ($authed && !empty($groups)) ? ' wide' : '' ?>" id="top">
  <div class="hero">
    <a class="hero-home" href="index.php" aria-label="<?= htmlspecialchars(ts_t('soundboard_back')) ?>" title="<?= htmlspecialchars(ts_t('soundboard_back')) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    </a>
    <svg class="hero-mark" width="40" height="40" viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path d="M14 2C7.37 2 2 7.37 2 14s5.37 12 12 12 12-5.37 12-12S20.63 2 14 2z"/>
      <path d="M9 11c0-2.76 2.24-5 5-5s5 2.24 5 5v3c0 2.76-2.24 5-5 5s-5-2.24-5-5v-3z"/>
      <path d="M6 14h2M20 14h2M14 22v-2"/>
    </svg>
    <div class="hero-brand">
      <div class="hero-title"><?= htmlspecialchars(ts_t('soundboard_title')) ?></div>
      <div class="hero-sub"><?= htmlspecialchars($config['brand_title']) ?></div>
    </div>
  </div>

  <?php if (!$enabled): ?>
  <div class="error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> <?= htmlspecialchars(ts_t('soundboard_disabled')) ?></div>
  <?php elseif (!$authed): ?>
  <div class="server-card">
    <form method="post" action="soundboard_login.php" class="soundboard-login">
      <label for="sb-pw"><?= htmlspecialchars(ts_t('soundboard_password_label')) ?></label>
      <input type="password" id="sb-pw" name="password" autofocus required>
      <button type="submit" class="connect-btn"><?= htmlspecialchars(ts_t('soundboard_unlock')) ?></button>
    </form>
  </div>
  <?php elseif (empty($groups)): ?>
  <div class="error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> <?= htmlspecialchars(ts_t('soundboard_empty')) ?></div>
  <?php else: ?>
    <div class="toggle-all-row">
      <button type="button" class="toggle-all-btn" id="toggle-all-sections"><?= htmlspecialchars(ts_t('soundboard_collapse_all')) ?></button>
    </div>
    <?php foreach ($groups as $groupName => $files): ?>
    <details class="sound-group" open>
      <summary class="sound-group-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="sound-group-chevron"><polyline points="9 6 15 12 9 18"/></svg>
        <?= htmlspecialchars($groupName !== '' ? $groupName : ts_t('soundboard_general')) ?>
      </summary>
      <div class="soundboard-grid">
        <?php foreach ($files as $file): ?>
        <button type="button" class="sound-btn" data-src="sound.php?f=<?= rawurlencode($file) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="sound-icon"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          <span class="sound-label"><?= htmlspecialchars(ts_sound_label($file)) ?></span>
        </button>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endforeach; ?>
  <?php endif; ?>

  <a href="#top" class="scroll-top" aria-label="<?= htmlspecialchars(ts_t('soundboard_scroll_top')) ?>" title="<?= htmlspecialchars(ts_t('soundboard_scroll_top')) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
  </a>
</div>

<?php if ($authed && !empty($groups)): ?>
<div class="soundbar" id="soundbar">
  <button type="button" class="soundbar-stop" id="soundbar-stop" aria-label="<?= htmlspecialchars(ts_t('soundboard_stop')) ?>" title="<?= htmlspecialchars(ts_t('soundboard_stop')) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="6" width="12" height="12"/></svg>
  </button>
  <span class="soundbar-label" id="soundbar-label"><?= htmlspecialchars(ts_t('soundboard_nothing_playing')) ?></span>
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="soundbar-volume-icon" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>
  <input type="range" class="soundbar-volume" id="soundbar-volume" min="0" max="100" value="100" aria-label="<?= htmlspecialchars(ts_t('soundboard_volume')) ?>">
  <button type="button" class="soundbar-close" id="soundbar-close" aria-label="<?= htmlspecialchars(ts_t('soundboard_hide_bar')) ?>" title="<?= htmlspecialchars(ts_t('soundboard_hide_bar')) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>
</div>
<?php endif; ?>

<?php if ($authed && !empty($groups)): ?>
<script>
(function() {
  // Toggle-all button above the section list: label reflects the current
  // aggregate state (all open -> offers to collapse, otherwise -> expand),
  // kept in sync via each <details>'s own native "toggle" event too, so
  // manually opening/closing the last remaining section flips the label
  // just the same as clicking the button itself would have.
  var toggleAllBtn = document.getElementById('toggle-all-sections');
  var groups = document.querySelectorAll('.sound-group');
  var collapseAllLabel = <?= json_encode(ts_t('soundboard_collapse_all')) ?>;
  var expandAllLabel = <?= json_encode(ts_t('soundboard_expand_all')) ?>;
  function allOpen() {
    return Array.prototype.every.call(groups, function(g) { return g.open; });
  }
  function updateToggleAllLabel() {
    toggleAllBtn.textContent = allOpen() ? collapseAllLabel : expandAllLabel;
  }
  if (toggleAllBtn) {
    groups.forEach(function(g) { g.addEventListener('toggle', updateToggleAllLabel); });
    toggleAllBtn.addEventListener('click', function() {
      var expand = !allOpen();
      groups.forEach(function(g) { g.open = expand; });
      updateToggleAllLabel();
    });
  }

  var bar = document.getElementById('soundbar');
  var stopBtn = document.getElementById('soundbar-stop');
  var closeBtn = document.getElementById('soundbar-close');
  var label = document.getElementById('soundbar-label');
  var volumeSlider = document.getElementById('soundbar-volume');
  var idleLabel = <?= json_encode(ts_t('soundboard_nothing_playing')) ?>;
  var current = null;
  var currentBtn = null;
  var volume = 1;

  function stop() {
    if (current) {
      current.pause();
      current.currentTime = 0;
    }
    if (currentBtn) currentBtn.classList.remove('playing');
    current = null;
    currentBtn = null;
    label.textContent = idleLabel;
  }
  stopBtn.addEventListener('click', stop);
  // Manually dismissible, unlike Stop above - hides the bar entirely rather
  // than just resetting it to its idle state. Comes back on its own the
  // next time any sound is clicked (see bar.hidden = false below), so
  // dismissing it is never a one-way trip.
  closeBtn.addEventListener('click', function() { bar.hidden = true; });

  // Applies to the currently playing sound immediately, and is remembered
  // for whichever sound gets clicked next - without this, lowering the
  // volume would silently reset back to full on the very next click.
  volumeSlider.addEventListener('input', function() {
    volume = volumeSlider.value / 100;
    if (current) current.volume = volume;
  });

  document.querySelectorAll('.sound-btn').forEach(function(btn) {
    var audio = null;
    btn.addEventListener('click', function() {
      if (!audio) {
        audio = new Audio(btn.dataset.src);
        audio.addEventListener('play', function() { btn.classList.add('playing'); });
        audio.addEventListener('pause', function() { btn.classList.remove('playing'); });
        // A clip finishing on its own (not stopped via the bar) resets the
        // bar to its idle state too, same as clicking Stop.
        audio.addEventListener('ended', function() {
          btn.classList.remove('playing');
          if (current === audio) { current = null; currentBtn = null; label.textContent = idleLabel; }
        });
      }
      if (current && current !== audio) {
        current.pause();
        current.currentTime = 0;
        if (currentBtn) currentBtn.classList.remove('playing');
      }
      // Restart from the beginning on every click, even mid-playback -
      // never toggles pause on a second click of the same button.
      audio.currentTime = 0;
      audio.volume = volume;
      audio.play();
      current = audio;
      currentBtn = btn;
      label.textContent = btn.querySelector('.sound-label').textContent;
      bar.hidden = false;
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
