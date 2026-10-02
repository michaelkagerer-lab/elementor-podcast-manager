/** QA-01: real player, chapter, share and widget rendering in Firefox/WebKit. */
import { chromium, firefox, webkit } from 'playwright';
import { BASE, assert, finish, newPage, fixtures } from './lib.mjs';
const name = process.env.EPM_BROWSER || 'chromium';
const engine = { chromium, firefox, webkit }[name];
if (!engine) throw new Error(`Unsupported browser: ${name}`);
const browser = await engine.launch({ headless: true, ...(process.env.EPM_BROWSER_PATH ? { executablePath: process.env.EPM_BROWSER_PATH } : {}) });
try {
 const page = await newPage(browser, { width: 390, height: 844 });
 const id = fixtures().ep1;
 await page.goto(`${BASE}/?p=${id}`);
 // Decode and advance real audio without requiring a sound device on CI.
 await page.evaluate(id => { window.epmPlayerEngine.getController(id).audio.muted = true; }, String(id));
 await page.locator('[data-epm-player] [data-epm-play]').first().click();
 await page.waitForFunction(id => window.epmPlayerEngine?.getController(id)?.isPlaying(), String(id), { timeout: 10000 });
 assert(await page.locator('[data-epm-player]').first().evaluate(el => el.classList.contains('is-playing')), `${name}: real audio plays and updates the controls`);
 const chapter = page.locator('.epm-chapters__seek').nth(1);
 await chapter.focus();
 await page.keyboard.press('Enter');
 const cue = await page.evaluate(id => window.epmPlayerEngine.getController(id).audio.currentTime, String(id));
 assert(cue >= 29 && cue < 36, `${name}: keyboard chapter selection seeks (${cue.toFixed(1)}s)`);
 const share = page.locator('.epm-player [data-epm-share-toggle]').first();
 await share.focus();
 await page.keyboard.press('Enter');
 assert(await share.getAttribute('aria-expanded') === 'true', `${name}: the keyboard opens the share menu`);
 await page.keyboard.press('Escape');
 assert(await share.getAttribute('aria-expanded') === 'false', `${name}: Escape closes the share menu`);
 await page.goto(`${BASE}/epm-elementor/`);
 assert(await page.locator('[data-epm-player]').count() > 0, `${name}: Elementor player widgets render`);
 assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), `${name}: the page fits a 390px viewport`);
 assert(page.problems.length === 0, `${name}: no browser errors ${page.problems.join('; ')}`);
 await page.context().close();
} finally { await browser.close(); }
finish(`${name} compatibility`);
