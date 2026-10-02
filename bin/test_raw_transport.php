<?php
// Integration test for TsRawTransport against bin/mock_serverquery.php - covers
// the actual connect/login/parsing path, not just the plain protocol syntax
// like bin/selftest_parser.php.
// Usage: php bin/test_raw_transport.php
require __DIR__ . '/../html/lib/ts_protocol.php';
require __DIR__ . '/../html/lib/ts_transport.php';
require __DIR__ . '/../html/lib/ts_transport_raw.php';
require __DIR__ . '/../html/lib/ts_transport_ssh.php';
require __DIR__ . '/../html/lib/ts_client.php';

$failures = 0;

function check(string $label, bool $ok): void {
    global $failures;
    if ($ok) {
        echo "ok - $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

function start_mock(int $port, string $mode): array {
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = sprintf('php %s %d %s', escapeshellarg(__DIR__ . '/mock_serverquery.php'), $port, escapeshellarg($mode));
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Could not start the mock\n");
        exit(1);
    }
    // Wait for the "listening" line instead of sleeping for a fixed time (avoids flakiness in CI).
    stream_set_timeout($pipes[1], 5);
    $line = fgets($pipes[1]);
    if (trim((string)$line) !== 'listening') {
        fwrite(STDERR, "Mock did not come up in time (output: " . var_export($line, true) . ")\n");
        exit(1);
    }
    return [$proc, $pipes];
}

function stop_mock(array $procAndPipes): void {
    [$proc, $pipes] = $procAndPipes;
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    proc_terminate($proc);
    proc_close($proc);
}

$basePort = 20011 + random_int(0, 5000);

// --- Happy path: login with a password containing special characters + full data receipt ---
$mock = start_mock($basePort, 'ok');
try {
    $config = [
        'host' => '127.0.0.1',
        'port' => $basePort,
        'user' => 'testuser',
        'pass' => 'te\\st pass|word', // backslash, space, pipe all at once
        'connect_timeout' => 5,
    ];
    $transport = new TsRawTransport($config);
    $out = $transport->query("use port=9987\nserverinfo\nchannellist\nclientlist\nquit\n");

    // Uses the actual shared classifier from html/lib/ts_client.php now
    // (used to be a separate copy here, which once drifted out of sync with
    // a security fix made only to the original) - this also tests that the
    // transport and the existing parsers actually fit together.
    $lines = ts_classify_bundle_lines($out);
    $info = ts_parse_single($lines['serverinfo']);
    $channels = ts_parse_list($lines['channellist']);
    $clients = ts_parse_list($lines['clientlist']);
    $servergroups = ts_parse_list($lines['servergrouplist']);

    check('serverinfo contains the expected server name', ($info['virtualserver_name'] ?? null) === 'MockServer');
    check('channellist contains Lobby', ($channels[0]['channel_name'] ?? null) === 'Lobby');
    check('channellist contains the topic', ($channels[0]['channel_topic'] ?? null) === 'Welcome');
    check('clientlist contains Alice', ($clients[0]['client_nickname'] ?? null) === 'Alice');
    check('clientlist contains mute status', ($clients[0]['client_input_muted'] ?? null) === '1');
    check('clientlist contains client_database_id (used by the online-time tracker)', ($clients[0]['client_database_id'] ?? null) === '1');
    check('servergrouplist contains Server Admin (escaped)', ($servergroups[0]['name'] ?? null) === 'Server Admin');
} finally {
    stop_mock($mock);
}

// --- Quote box: channelinfo folded into the same bundle must not corrupt
// channellist (its response line also contains "channel_name=") ---
$mock3 = start_mock($basePort + 2, 'ok');
try {
    $config3 = [
        'host' => '127.0.0.1',
        'port' => $basePort + 2,
        'user' => 'testuser',
        'pass' => 'testpass',
        'connect_timeout' => 5,
    ];
    $transport3 = new TsRawTransport($config3);
    $out3 = $transport3->query("use port=9987\nserverinfo\nchannellist\nclientlist\nservergrouplist\nchannelinfo cid=33\nquit\n");
    $lines3 = ts_classify_bundle_lines($out3);

    check('channellist still contains Lobby (not overwritten by channelinfo)',
        (ts_parse_list($lines3['channellist'])[0]['channel_name'] ?? null) === 'Lobby');
    $quotes = ts_parse_quotes($lines3['channeldescription']);
    check('quote box: two quotes parsed from the channel description', count($quotes) === 2);
    check('quote box: first quote text', ($quotes[0] ?? null) === '"Test quote" - Mock 2020');
    check('quote box: second quote (year-only attribution) text', ($quotes[1] ?? null) === '"Second quote" - 2021');
} finally {
    stop_mock($mock3);
}

// --- Failure case: server rejects the login -> TsAuthException expected ---
$mock2 = start_mock($basePort + 1, 'fail');
try {
    $config2 = [
        'host' => '127.0.0.1',
        'port' => $basePort + 1,
        'user' => 'testuser',
        'pass' => 'wrong',
        'connect_timeout' => 5,
    ];
    $transport2 = new TsRawTransport($config2);
    $threw = false;
    try {
        $transport2->query("use port=9987\nquit\n");
    } catch (TsAuthException $e) {
        $threw = true;
    }
    check('TsAuthException on failed login', $threw);
} finally {
    stop_mock($mock2);
}

// --- Quote box: text/attribution splitting, including formats found on the
// real server (dash directly against the name, year-only attribution, an
// embedded newline inside one quote) ---
require __DIR__ . '/../html/lib/i18n.php';
require __DIR__ . '/../html/lib/cache.php';
require __DIR__ . '/../html/lib/render.php';

$attributionCases = [
    ['"Quote" - Name 2020', '"Quote"', '— Name 2020'],
    ['"Quote" -Name 2022', '"Quote"', '— Name 2022'],      // no space before the name
    ['Dialogue with no named author. -2022', 'Dialogue with no named author.', '— 2022'],
    ["Multi\nline quote text. - Name 2021", "Multi\nline quote text.", '— Name 2021'],
    ['No attribution at all here', 'No attribution at all here', ''],
];
foreach ($attributionCases as $i => [$input, $expectedText, $expectedAttr]) {
    [$text, $attr] = ts_split_quote_attribution($input);
    check("quote attribution split #$i: text", $text === $expectedText);
    check("quote attribution split #$i: attribution", $attr === $expectedAttr);
}

// --- Rule box: an alternative to the quote box, static numbered list from
// TS_RULES_TEXT (already \n-unescaped by config.php by the time it reaches
// here - see the comment there) ---
$ruleHtml = ts_render_rulebox("Rule one\nRule two\n\nRule three");
check('rule box: renders exactly 3 list items (blank line skipped)',
    substr_count($ruleHtml, '<li>') === 3);
check('rule box: first rule text present', str_contains($ruleHtml, '<li>Rule one</li>'));
check('rule box: third rule text present (blank line between 2 and 3 doesn\'t break the rest)',
    str_contains($ruleHtml, '<li>Rule three</li>'));
check('rule box: user-supplied rule text is escaped',
    str_contains(ts_render_rulebox('<script>alert(1)</script>'), '&lt;script&gt;'));

// --- Spacer-channel rendering: matches the TeamSpeak client's own
// "[<align>spacerN]label" convention (l/c/r = aligned heading, */none =
// plain divider line) instead of the app's earlier approximation, which
// hid bare spacers entirely and rendered labeled ones ("[cspacer] Talk
// Channels") as completely normal, icon-bearing channels. Cases below are
// all names actually seen on the live server, plus the removed hardcoded
// "cid=2" exception (present, unexplained, since the project's very first
// commit - excluded cid=2 from spacer handling, so "[spacer5]___" at
// cid=2 rendered as a normal channel literally named "___"). ---
$spacerCmap = [
    '3' => ['cid' => '3', 'pid' => '0', 'channel_name' => '[cspacer]Section Title'],
    '2' => ['cid' => '2', 'pid' => '0', 'channel_name' => '[spacer5]___'],
    '5' => ['cid' => '5', 'pid' => '0', 'channel_name' => '[cspacer0]'],
    '9' => ['cid' => '9', 'pid' => '0', 'channel_name' => '[lspacer3]Left Label'],
    '11' => ['cid' => '11', 'pid' => '0', 'channel_name' => '[rspacer2]Right Label'],
    '13' => ['cid' => '13', 'pid' => '0', 'channel_name' => 'Not A Spacer'],
];
$spacerChildren = ['0' => ['3', '2', '5', '9', '11', '13']];
$spacerHtml = ts_render_channels($spacerChildren, $spacerCmap, [], '0', 0, 32, [], null);
// (checks the heading is a distinct ch-spacer-label element, not that
// "ch-icon" is absent anywhere in the output - the batch below also
// includes an ordinary channel, which legitimately has one)
check('"[cspacer]Section Title" renders as a centered heading, not a normal channel',
    str_contains($spacerHtml, 'ch-spacer-label ch-spacer-center"') && str_contains($spacerHtml, '>Section Title</div>'));
check('"[spacer5]___" (cid=2, the old hardcoded exception) renders as a plain divider',
    !str_contains($spacerHtml, '___'));
check('bare "[cspacer0]" (no label) renders as a plain divider, not a blank channel row',
    !str_contains($spacerHtml, 'ch-name"></span>'));
check('"[lspacer3]Left Label" is left-aligned',
    str_contains($spacerHtml, 'ch-spacer-label ch-spacer-left"') && str_contains($spacerHtml, '>Left Label</div>'));
check('"[rspacer2]Right Label" is right-aligned',
    str_contains($spacerHtml, 'ch-spacer-label ch-spacer-right"') && str_contains($spacerHtml, '>Right Label</div>'));
check('a real channel that just happens to not match the spacer tag still renders normally',
    str_contains($spacerHtml, 'ch-name">Not A Spacer</span>'));

$onlySpacersHtml = ts_render_channels(['0' => ['3', '2', '5', '9', '11']], $spacerCmap, [], '0', 0, 32, [], null);
check('spacers never get a folder icon or click-styled channel row',
    !str_contains($onlySpacersHtml, 'ch-icon') && !str_contains($onlySpacersHtml, 'class="channel'));
check('exactly 2 plain divider lines rendered (spacer5 and cspacer0)',
    substr_count($spacerHtml, 'ch-spacer-line') === 2);

// --- Hidden channels (TS_HIDDEN_CHANNELS): matches names actually seen on
// the live server - "[cspacer]Special Channels" is itself a section-heading
// spacer, so hiding "Special Channels" must match on its label, not the raw
// "[cspacer]..." tag. Hiding "Quote Box" must also swallow the plain
// "[spacer7]___" divider directly after it (dangling otherwise), but must
// NOT touch the unrelated "[spacer6]___" above the section or the
// "[cspacer]Temporary Channels" heading that follows. ---
$hiddenCmap = [
    '31' => ['cid' => '31', 'pid' => '0', 'channel_name' => '[spacer6]___'],
    '32' => ['cid' => '32', 'pid' => '0', 'channel_name' => '[cspacer]Special Channels'],
    '5'  => ['cid' => '5',  'pid' => '0', 'channel_name' => "\u{2191} Data Share \u{2193}"],
    '33' => ['cid' => '33', 'pid' => '0', 'channel_name' => 'Quote Box'],
    '34' => ['cid' => '34', 'pid' => '0', 'channel_name' => '[spacer7]___'],
    '35' => ['cid' => '35', 'pid' => '0', 'channel_name' => '[cspacer]Temporary Channels'],
];
$hiddenChildren = ['0' => ['31', '32', '5', '33', '34', '35']];
$hiddenNames = ['Special Channels', "\u{2191} Data Share \u{2193}", 'Quote Box'];
$hiddenHtml = ts_render_channels($hiddenChildren, $hiddenCmap, [], '0', 0, 32, [], null, $hiddenNames);
check('hidden spacer heading "Special Channels" does not appear',
    !str_contains($hiddenHtml, 'Special Channels'));
check('hidden channel "Data Share" does not appear',
    !str_contains($hiddenHtml, 'Data Share'));
check('hidden channel "Quote Box" does not appear',
    !str_contains($hiddenHtml, 'Quote Box'));
check('exactly 1 divider line left (the unrelated one above the section, cid=31 kept; cid=34 below the hidden "Quote Box" is swallowed)',
    substr_count($hiddenHtml, 'ch-spacer-line') === 1);
check('the following section heading "Temporary Channels" is kept',
    str_contains($hiddenHtml, '>Temporary Channels</div>'));

// Without any hidden names configured, nothing changes (default/off behavior).
$unfilteredHtml = ts_render_channels($hiddenChildren, $hiddenCmap, [], '0', 0, 32, [], null, []);
check('TS_HIDDEN_CHANNELS empty -> nothing is filtered',
    str_contains($unfilteredHtml, 'Special Channels') && str_contains($unfilteredHtml, 'Quote Box'));

if ($failures > 0) {
    fwrite(STDERR, "\n$failures test(s) failed.\n");
    exit(1);
}
echo "\nAll integration tests passed.\n";
