// Playwright runner for `php artisan docs:screenshot`.
// Logs in once, then captures a stabilized, full-content image of every page in the
// manifest as WebP (needs cwebp on the PATH). Pages with Filament tabs near the top get
// one image per tab.
import { execFile } from 'node:child_process';
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { promisify } from 'node:util';

const manifestPath = process.argv[2];

if (!manifestPath) {
    console.error('Usage: node screenshot-docs.mjs <manifest.json>');
    process.exit(2);
}

const { appRoot, baseUrl, loginUrl, panelPath, viewport, perPage, quality, resultsPath, shots, imageRoot } = JSON.parse(await readFile(manifestPath, 'utf8'));

// Playwright is a dev dependency of the app, not of this package: resolve it from the
// app's root, which also works when the package is a symlink outside the app.
const { chromium } = createRequire(join(appRoot, 'package.json'))('playwright');

const run = promisify(execFile);

// No animations, caret or scrollbars, so two runs of the same page produce the
// same pixels. Hide debug tooling and the environment badge and border: they are
// noise in a manual.
const STABILIZE_CSS = `*, *::before, *::after { transition: none !important; animation: none !important; caret-color: transparent !important; }
*::-webkit-scrollbar { width: 0 !important; height: 0 !important; }
.phpdebugbar, .environment-indicator, .debug-mode-indicator { display: none !important; }
.fi-topbar, body { border-top: 0 !important; }
.fi-sidebar-nav { overflow: hidden !important; }`;

// A very long table must not produce a multi-thousand-pixel image.
const MAX_HEIGHT = 4000;

// Per-tab shots only when the tab bar is near the top (the tabs ARE the content, as
// on a settings page). Lower down (relation-manager tabs) they would repeat the page.
const ABOVE_TABS_LIMIT = 400;

const slugify = (text) => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

// Every table on the page to `perPage` rows, when it offers that option. Filament keeps
// the choice in this browser's session, so later pages of the same table start there.
async function shortenTables(page) {
    if (!perPage) {
        return;
    }

    for (const select of await page.$$('.fi-pagination-records-per-page-select select')) {
        const offers = await select.evaluate((el, value) => el.offsetParent !== null
            && el.value !== value
            && Array.from(el.options).some((option) => option.value === value), String(perPage));

        if (offers) {
            await select.selectOption(String(perPage));
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(300);
        }
    }
}

// Playwright only writes PNG or JPEG: shoot a lossless PNG and let cwebp make the WebP,
// so the image is compressed once.
async function writeWebp(page, outAbs) {
    const png = join(tmpdir(), `docs-screenshot-${process.pid}.png`);

    await page.screenshot({ path: png, type: 'png' });
    await mkdir(dirname(outAbs), { recursive: true });
    await run('cwebp', ['-quiet', '-q', String(quality ?? 80), png, '-o', outAbs]);
    await rm(png, { force: true });
}

// Measure the real content height, stretch the sidebar to it (no empty gap under
// it), size the viewport to fit, run the click interactions, then write the WebP.
async function capture(page, outAbs, interactions = []) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });

    await page.evaluate(() => {
        // A sidebar pinned by a previous, taller tab would inflate the measurement.
        document.querySelectorAll('.fi-sidebar').forEach((el) => { el.style.height = ''; });
        // Filament scrolls the menu to the active item; a manual shows it from the top.
        document.querySelectorAll('.fi-sidebar-nav').forEach((el) => { el.scrollTop = 0; });
        window.scrollTo(0, 0);
    });

    // Tall enough for the content and for the whole menu, so a short page does not
    // crop the sidebar.
    const height = Math.min(MAX_HEIGHT, await page.evaluate(() => {
        const main = document.querySelector('.fi-main') ?? document.body;
        const nav = document.querySelector('.fi-sidebar-nav');
        const navBottom = nav ? nav.getBoundingClientRect().top + nav.scrollHeight : 0;

        return Math.ceil(Math.max(main.getBoundingClientRect().bottom, navBottom) + window.scrollY);
    }));

    await page.evaluate((h) => {
        document.querySelectorAll('.fi-sidebar').forEach((el) => { el.style.height = `${h}px`; });
    }, height);
    await page.setViewportSize({ width: viewport.width, height });
    await page.waitForLoadState('networkidle');

    // Clicked at the final size, so a resize cannot close or move an opened dropdown.
    for (const selector of interactions) {
        try {
            await page.click(selector, { timeout: 2000 });
            await page.waitForTimeout(300);
        } catch {
            console.error(`WARN interaction failed: ${selector}`);
        }
    }

    await writeWebp(page, outAbs);
}

// Only schema tabs (.fi-sc-tabs); list-page status tabs are not per-tab content.
const readTabs = (page) => page.evaluate(() => Array.from(document.querySelectorAll('.fi-sc-tabs .fi-tabs-item[role="tab"]'))
    .filter((el) => el.offsetParent !== null)
    .map((el) => ({ label: (el.querySelector('.fi-tabs-item-label')?.textContent ?? el.textContent ?? '').trim() })));

const tabsArePrimary = (page) => page.evaluate((limit) => {
    const main = document.querySelector('.fi-main');
    const nav = document.querySelector('.fi-sc-tabs .fi-tabs');

    return !!main && !!nav && nav.getBoundingClientRect().top - main.getBoundingClientRect().top < limit;
}, ABOVE_TABS_LIMIT);

// Livewire sections that load on intersection only render once scrolled to.
async function triggerLazyContent(page) {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForLoadState('networkidle');
}

const browser = await chromium.launch();
const context = await browser.newContext({
    viewport: { width: viewport.width, height: viewport.height },
    deviceScaleFactor: viewport.deviceScaleFactor ?? 1,
    // Local dev domains use a self-signed certificate Chromium does not trust.
    ignoreHTTPSErrors: true,
});
const page = await context.newPage();

const results = [];
let ok = 0;
let failed = 0;

async function finish(code) {
    await context.close();
    await browser.close();

    if (resultsPath) {
        await writeFile(resultsPath, JSON.stringify(results, null, 2));
    }

    console.log(`\nDone: ${ok} captured, ${failed} failed.`);
    process.exit(code);
}

// Filament's login page: where a browser without a session ends up.
const onLoginPage = () => new URL(page.url()).pathname.endsWith('/login');

/**
 * Either a local-only URL that logs the browser in (loginUrl), or the panel's login
 * form filled from env vars. Neither the URL nor the credentials live in the repo.
 * Either way the browser must end up signed in, or nothing is captured.
 */
async function login() {
    if (loginUrl) {
        await page.goto(loginUrl, { waitUntil: 'networkidle' });

        if (onLoginPage()) {
            throw new Error(`${loginUrl} did not sign the browser in. --login-path is a local URL that logs a user in and redirects (not the login form); otherwise leave it out and set DOCS_SCREENSHOT_EMAIL and DOCS_SCREENSHOT_PASSWORD.`);
        }

        return;
    }

    const email = process.env.DOCS_SCREENSHOT_EMAIL;
    const password = process.env.DOCS_SCREENSHOT_PASSWORD;

    if (!email || !password) {
        throw new Error('Set DOCS_SCREENSHOT_LOGIN_PATH, or DOCS_SCREENSHOT_EMAIL and DOCS_SCREENSHOT_PASSWORD.');
    }

    await page.goto(`${baseUrl}${panelPath}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login')), page.click('button[type="submit"]')]);
}

try {
    await login();
} catch (error) {
    console.error(`FAIL login: ${error.message}`);
    await finish(1);
}

for (const shot of shots) {
    try {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await page.goto(`${baseUrl}${shot.url}`, { waitUntil: 'networkidle' });

        // A shot of the login form instead of the screen must never reach a doc.
        if (onLoginPage()) {
            throw new Error('redirected to the login page; the session was lost or this user cannot open the screen');
        }

        await page.addStyleTag({ content: STABILIZE_CSS });
        await shortenTables(page);
        await triggerLazyContent(page);

        const tabs = await readTabs(page);

        if (tabs.length >= 2 && await tabsArePrimary(page)) {
            const triggers = await page.$$('.fi-sc-tabs .fi-tabs-item[role="tab"]');

            for (let i = 0; i < tabs.length; i++) {
                const file = shot.out.replace(/\.webp$/, `-${slugify(tabs[i].label) || `tab-${i + 1}`}.webp`);

                await triggers[i].click();
                await page.waitForLoadState('networkidle');
                await capture(page, resolve(imageRoot, file), shot.interactions ?? []);

                results.push({ slug: shot.slug, screen: shot.screen, file, label: tabs[i].label });
                console.log(`OK   ${shot.slug}/${shot.screen} [${tabs[i].label}]`);
                ok++;
            }
        } else {
            await capture(page, resolve(imageRoot, shot.out), shot.interactions ?? []);

            results.push({ slug: shot.slug, screen: shot.screen, file: shot.out, label: null });
            console.log(`OK   ${shot.slug}/${shot.screen}`);
            ok++;
        }
    } catch (error) {
        console.error(`FAIL ${shot.slug}/${shot.screen} (${shot.url}): ${error.message}`);
        failed++;
    }
}

await finish(ok === 0 && failed > 0 ? 1 : 0);
