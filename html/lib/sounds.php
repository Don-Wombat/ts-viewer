<?php
// Soundboard: lists/serves files from TS_SOUNDS_DIR (see config.php) - a
// plain directory of short clips, one subfolder per person (matches how the
// archive is already organized on disk; the root itself also holds files
// directly, grouped as one section with no folder name).

const TS_SOUND_EXTENSIONS = ['mp3', 'wav', 'm4a'];

// Recursively lists playable files under $dir, grouped by their immediate
// subfolder (root-level files grouped under '' ), each group's own subarray
// holding dir-relative paths (forward-slash separated, e.g.
// "Benny/Benny AK geissler gert.mp3"). Groups are ordered root-first, then
// alphabetically. Returns [] if $dir is empty/missing/unreadable - the
// soundboard button/page then just stay hidden/empty instead of a fatal error.
function ts_sounds_list(?string $dir): array {
    if ($dir === null || $dir === '' || !is_dir($dir)) return [];
    $paths = [];
    ts_sounds_scan($dir, '', $paths);
    natcasesort($paths);
    $paths = array_values($paths);

    $groups = [];
    foreach ($paths as $rel) {
        $slash = strrpos($rel, '/');
        $group = $slash === false ? '' : substr($rel, 0, $slash);
        $groups[$group][] = $rel;
    }
    uksort($groups, function (string $a, string $b): int {
        if ($a === $b) return 0;
        if ($a === '') return -1;
        if ($b === '') return 1;
        return strcasecmp($a, $b);
    });
    return $groups;
}

function ts_sounds_scan(string $dir, string $relBase, array &$out): void {
    $entries = @scandir($dir);
    if ($entries === false) return;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dir . '/' . $entry;
        $rel  = $relBase === '' ? $entry : $relBase . '/' . $entry;
        if (is_dir($full)) {
            ts_sounds_scan($full, $rel, $out);
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($ext, TS_SOUND_EXTENSIONS, true)) $out[] = $rel;
    }
}

// Button label: the filename without its extension, uppercased - plain
// strtoupper() (not mb_strtoupper(): mbstring is not a dependency of this
// project, see the similar note on strlen() in render.php), so non-ASCII
// letters keep their original case. Folder prefix stripped - the folder is
// already shown as that file's section heading.
function ts_sound_label(string $relPath): string {
    return strtoupper(pathinfo(basename($relPath), PATHINFO_FILENAME));
}

// Resolves a requested dir-relative path to a real, in-bounds file, or null
// if it's empty, contains a "." /".." segment or a NUL byte, has a
// disallowed extension, isn't a regular file, or - via realpath(), defense
// in depth on top of the segment check - would resolve outside $dir.
function ts_sound_resolve(string $dir, string $requested): ?string {
    $requested = str_replace('\\', '/', $requested);
    if ($requested === '' || str_contains($requested, "\0")) return null;
    foreach (explode('/', $requested) as $part) {
        if ($part === '' || $part === '.' || $part === '..') return null;
    }
    $ext = strtolower(pathinfo($requested, PATHINFO_EXTENSION));
    if (!in_array($ext, TS_SOUND_EXTENSIONS, true)) return null;

    $realDir = realpath($dir);
    $real    = realpath($dir . '/' . $requested);
    if ($real === false || $realDir === false) return null;
    if (strpos($real, $realDir . DIRECTORY_SEPARATOR) !== 0) return null;
    if (!is_file($real)) return null;
    return $real;
}
