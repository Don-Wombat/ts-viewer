// Browser-based end-to-end check with Playwright: loads the actual rendered
// page (not just curl'd HTML) against a running ts-viewer container backed
// by bin/mock_serverquery.php, and verifies the DOM, the language switch and
// the error state a real visitor would see. Complements the existing PHP
// unit/integration tests (bin/selftest_parser.php, bin/test_raw_transport.php),
// which never render or execute anything in a browser.
//
// Usage: node bin/e2e_playwright.mjs
// Env:
//   TS_VIEWER_URL       base URL of a running instance backed by the mock in
//                        "ok" mode (default: http://127.0.0.1/)
//   TS_VIEWER_ERROR_URL  base URL of a running instance pointed at an
//                        unreachable host, for the error-state check
//                        (default: skip that check if unset)
//   E2E_SCREENSHOT_DIR   if set, saves a screenshot from each check here
//                        (CI uploads this directory as an artifact)

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const baseUrl = process.env.TS_VIEWER_URL || 'http://127.0.0.1/';
const errorUrl = process.env.TS_VIEWER_ERROR_URL || '';
const screenshotDir = process.env.E2E_SCREENSHOT_DIR || '';
if (screenshotDir) mkdirSync(screenshotDir, { recursive: true });

let failures = 0;
function check(label, ok) {
    if (ok) {
        console.log(`ok - ${label}`);
    } else {
        failures++;
        console.error(`FAIL: ${label}`);
    }
}

async function screenshot(page, name) {
    if (!screenshotDir) return;
    await page.screenshot({ path: join(screenshotDir, `${name}.png`), fullPage: true });
}

const browser = await chromium.launch();
try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const consoleErrors = [];
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
    page.on('pageerror', (err) => consoleErrors.push(String(err)));

    // --- Happy path (German default) ---
    const response = await page.goto(baseUrl, { waitUntil: 'networkidle' });
    check('page loads with HTTP 200', response !== null && response.status() === 200);
    check('title is "TeamSpeak Viewer"', (await page.title()) === 'TeamSpeak Viewer');

    const headers = response.headers();
    check('X-Content-Type-Options: nosniff header present', headers['x-content-type-options'] === 'nosniff');
    check('X-Frame-Options: DENY header present', headers['x-frame-options'] === 'DENY');
    check("Content-Security-Policy header present", (headers['content-security-policy'] || '').includes("frame-ancestors 'none'"));

    const bodyText = await page.locator('body').innerText();
    check('shows the mock server name', bodyText.includes('MockServer'));
    check('shows the Lobby channel', bodyText.includes('Lobby'));
    check('shows the channel topic', bodyText.includes('Welcome'));
    check('shows the Alice client', bodyText.includes('Alice'));
    check('shows the Server Admin role badge', bodyText.includes('Server Admin'));
    check('German UI strings shown by default', bodyText.includes('Nutzer') && bodyText.includes('Kanäle'));
    await screenshot(page, 'happy-path-de');

    // --- Language switch ---
    await page.getByRole('link', { name: 'EN', exact: true }).click();
    await page.waitForLoadState('networkidle');
    const enText = await page.locator('body').innerText();
    check('switches to English via the language toggle', enText.includes('Clients') && enText.includes('Channels'));
    await screenshot(page, 'happy-path-en');

    check('no browser console errors on the happy path', consoleErrors.length === 0);
    if (consoleErrors.length > 0) console.error(consoleErrors.join('\n'));

    // --- Error state (server unreachable), if a second instance was provided ---
    if (errorUrl) {
        const errPage = await context.newPage();
        const errResponse = await errPage.goto(errorUrl, { waitUntil: 'networkidle' });
        check('error-state page still loads with HTTP 200', errResponse !== null && errResponse.status() === 200);
        const errText = await errPage.locator('body').innerText();
        check('error state shows the "unreachable" message', /unreachable|nicht erreichbar/i.test(errText));
        await screenshot(errPage, 'error-state');
        await errPage.close();
    }
} finally {
    await browser.close();
}

if (failures > 0) {
    console.error(`\n${failures} test(s) failed.`);
    process.exit(1);
}
console.log('\nAll Playwright E2E tests passed.');
