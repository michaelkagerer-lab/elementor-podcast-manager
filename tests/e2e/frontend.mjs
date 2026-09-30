/**
 * Browser tests for the 1.3.0 frontend components: share menu, timestamp
 * links (?t=), episode embeds and the click-to-load video facade.
 * Runs against a site seeded with tests/fixtures/seed.php:
 *
 *   cd tests/e2e && WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp node frontend.mjs
 *
 * Screenshots land in tests/e2e/screenshots/.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const BASE = process.env.WP_URL || 'http://localhost:8889';
const WP = process.env.WP_CLI || 'wp';
const wp = (args) => execFileSync(WP, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
const line = (output, prefix) => output.split('\n').find((l) => l.startsWith(prefix)) || '';
const fixtures = JSON.parse(line(wp(['option', 'get', 'epm_test_fixtures', '--format=json']), '{'));
const permalink = (id) => line(wp(['post', 'list', '--post_type=any', `--post__in=${id}`, '--field=url']), 'http').trim();

fs.mkdirSync('screenshots', { recursive: true });

let failures = 0;
const assert = (condition, message) => {
	console.log(`  ${condition ? '✓' : '✗'} ${message}`);
	if (!condition) failures++;
};

const browser = await chromium.launch({
	headless: true,
	args: ['--autoplay-policy=no-user-gesture-required'],
	...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}),
});

/**
 * Hermetic page: third-party requests are refused but recorded, so tests
 * can prove that nothing went to a video platform.
 */
async function newPage(viewport = { width: 1280, height: 900 }, options = {}) {
	const context = await browser.newContext({ viewport, ...options });
	const origin = new URL(BASE).origin;
	const thirdParty = [];
	await context.route((url) => url.origin !== origin, (route) => {
		thirdParty.push(route.request().url());
		route.abort('blockedbyclient');
	});
	const page = await context.newPage();
	page.thirdParty = thirdParty;
	page.problems = [];
	page.on('pageerror', (e) => page.problems.push(`pageerror: ${e.message}`));
	page.on('console', (m) => {
		if (m.type() !== 'error') {
			return;
		}
		const where = `${m.text()} ${(m.location() && m.location().url) || ''}`;
		if (/ERR_BLOCKED_BY_CLIENT|net::ERR_FAILED/.test(where) || !/elementor-podcast-manager|epm/i.test(where)) {
			return;
		}
		page.problems.push(`console: ${where}`);
	});
	return page;
}

const active = (page) => page.evaluate(() => {
	const el = document.activeElement;
	return { cls: el.className || '', text: (el.textContent || '').trim(), tag: el.tagName };
});

const EP1 = permalink(fixtures.ep1);

// ---------------------------------------------------------------------------
console.log('Share menu');
{
	const page = await newPage({ width: 1280, height: 900 }, { permissions: ['clipboard-read', 'clipboard-write'] });
	await page.goto(EP1);
	const toggle = page.locator('.epm-player [data-epm-share-toggle]');
	assert(await toggle.getAttribute('aria-expanded') === 'false', 'menu button starts collapsed');

	await toggle.focus();
	await page.keyboard.press('Enter');
	let state = await active(page);
	assert(await toggle.getAttribute('aria-expanded') === 'true' && state.text === 'Copy link', `Enter opens the menu on its first item (${state.text})`);
	await page.keyboard.press('ArrowDown');
	state = await active(page);
	assert(state.text === 'Copy embed code', `ArrowDown skips hidden items (${state.text})`);
	await page.keyboard.press('ArrowDown');
	assert((await active(page)).text === 'Copy link', 'ArrowDown wraps around');
	await page.keyboard.press('End');
	assert((await active(page)).text === 'Copy embed code', 'End moves to the last item');
	const tabbable = await page.$$eval('[data-epm-share-menu] [role=menuitem]', (items) => items.filter((i) => i.tabIndex === 0).length);
	assert(tabbable === 1, 'roving focus: one item in the tab order');
	await page.keyboard.press('Escape');
	state = await active(page);
	assert(await toggle.getAttribute('aria-expanded') === 'false' && /epm-share__toggle/.test(state.cls), 'Escape closes and returns focus to the button');

	await page.keyboard.press('ArrowUp');
	assert((await active(page)).text === 'Copy embed code', 'ArrowUp on the button opens on the last item');
	await page.keyboard.press('Tab');
	assert(await page.locator('[data-epm-share-menu]').isHidden(), 'Tab closes the menu');

	await toggle.click();
	await page.mouse.click(5, 5);
	assert(await page.locator('[data-epm-share-menu]').isHidden(), 'a click outside closes the menu');

	await toggle.click();
	await page.click('[data-epm-share-action="copy"]');
	await page.waitForTimeout(200);
	const copied = await page.evaluate(() => navigator.clipboard.readText());
	assert(copied === EP1, `Copy link copies the episode URL (${copied})`);
	assert(await toggle.evaluate((b) => b.classList.contains('is-copied')), 'the button confirms the copy');
	assert(/epm-share__toggle/.test((await active(page)).cls), 'focus returns to the button after copying');
	const shareWidth = await toggle.evaluate((b) => b.getBoundingClientRect().width);
	await page.waitForTimeout(2300);
	assert(!(await toggle.evaluate((b) => b.classList.contains('is-copied'))), 'the confirmation ends after two seconds');
	assert(Math.abs(shareWidth - await toggle.evaluate((b) => b.getBoundingClientRect().width)) < 0.5, 'the button keeps its width while confirming');

	await toggle.click();
	await page.click('[data-epm-share-action="embed"]');
	await page.waitForTimeout(200);
	const embed = await page.evaluate(() => navigator.clipboard.readText());
	assert(/^<iframe src="[^"]+\/embed\/" title="[^"]+" width="640" height="200"/.test(embed), `Copy embed code copies an iframe (${embed.slice(0, 90)}…)`);

	// Copying blocked: the text is offered, selected, in a labelled field.
	await page.evaluate(() => {
		navigator.clipboard.writeText = () => Promise.reject(new Error('blocked'));
		document.execCommand = () => false;
	});
	await toggle.click();
	await page.click('[data-epm-share-action="copy"]');
	await page.waitForTimeout(200);
	const manual = await page.evaluate(() => {
		const field = document.querySelector('[data-epm-share-manual-field]');
		return { visible: !document.querySelector('[data-epm-share-manual]').hidden, focused: document.activeElement === field, value: field.value, selected: field.selectionEnd - field.selectionStart, label: !!field.labels.length };
	});
	assert(manual.visible && manual.focused && manual.value === EP1 && manual.selected === EP1.length && manual.label, `blocked copying offers the selected link (${JSON.stringify(manual)})`);
	await page.keyboard.press('Escape');
	assert(await page.locator('[data-epm-share-manual]').isHidden() && /epm-share__toggle/.test((await active(page)).cls), 'Escape closes the field and returns focus to the button');
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
console.log('Timestamp links');
{
	const page = await newPage({ width: 1280, height: 900 }, { permissions: ['clipboard-read', 'clipboard-write'] });
	await page.goto(EP1);
	await page.evaluate(() => localStorage.clear());
	await page.goto(`${EP1}?t=1m5s`);
	await page.waitForTimeout(800);
	const cued = await page.evaluate(() => {
		const player = document.querySelector('[data-epm-player]');
		return {
			current: player.querySelector('[data-epm-current]').textContent,
			value: player.querySelector('[data-epm-timeline]').getAttribute('aria-valuenow'),
			playing: player.classList.contains('is-playing'),
			chapter: [...document.querySelectorAll('.epm-chapters__item')].findIndex((li) => li.classList.contains('is-active')),
		};
	});
	assert(cued.current === '1:05' && cued.value === '65', `?t=1m5s shows the position (${JSON.stringify(cued)})`);
	assert(!cued.playing, '?t= never starts playback on its own');
	assert(cued.chapter === 1, 'the chapter at that position is marked');
	await page.click('.epm-player__play');
	await page.waitForTimeout(700);
	const playing = await page.evaluate(() => window.epmPlayerEngine.getController(document.querySelector('[data-epm-player]').dataset.epmEpisodeId).audio.currentTime);
	assert(playing >= 65, `the first press plays from there (${playing.toFixed(1)}s)`);
	await page.click('.epm-player__play');

	await page.click('.epm-player [data-epm-share-toggle]');
	const label = await page.textContent('[data-epm-share-action="copy-time"]');
	assert(/1:0[5-7]/.test(label), `the menu offers the current position (${label.trim()})`);
	await page.click('[data-epm-share-action="copy-time"]');
	await page.waitForTimeout(200);
	const url = await page.evaluate(() => navigator.clipboard.readText());
	assert(new RegExp(`^${EP1.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}\\?t=1m[0-9]+s$`).test(url), `Copy link at … adds ?t= (${url})`);

	await page.goto(`${EP1}?t=90`);
	await page.waitForTimeout(600);
	assert(await page.textContent('[data-epm-player] [data-epm-current]') === '1:30', 'plain seconds work too');
	await page.goto(`${permalink(fixtures.ep2)}?t=30`);
	await page.waitForTimeout(600);
	assert(await page.textContent('[data-epm-player] [data-epm-current]') === '0:30', 'every episode page takes ?t=');
	// Clear the remembered position first (resume is a separate feature).
	await page.evaluate(() => localStorage.clear());
	await page.goto(`${BASE}/epm-shortcodes/?t=30`);
	await page.waitForTimeout(600);
	assert(await page.textContent('[data-epm-player] [data-epm-current]') === '0:00', 'players on other pages ignore ?t=');
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
console.log('Embed');
{
	const embedUrl = `${EP1}embed/`;
	const page = await newPage({ width: 600, height: 200 });
	const response = await page.goto(embedUrl);
	assert(response.status() === 200, 'embed page answers 200');
	const doc = await page.evaluate(() => ({
		players: document.querySelectorAll('[data-epm-player]').length,
		initialized: document.querySelector('[data-epm-player]').dataset.epmInitialized,
		styles: [...document.querySelectorAll('link[rel=stylesheet]')].map((l) => l.id),
		title: document.querySelector('.epm-player__title-link').getAttribute('href'),
		height: document.body.getBoundingClientRect().height,
		overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
	}));
	assert(doc.players === 1 && doc.initialized === '1', 'the embed renders one initialized player');
	assert(doc.styles.length === 1 && doc.styles[0] === 'epm-frontend-css', `only the podcast stylesheet loads (${doc.styles.join(', ')})`);
	assert(doc.title === EP1, 'the title links back to the episode');
	assert(doc.height === 200 && doc.overflow === 0, `the card fills a 200px frame without overflow (${doc.height})`);
	await page.click('.epm-player__play');
	await page.waitForTimeout(900);
	assert(await page.evaluate(() => document.querySelector('[data-epm-player]').classList.contains('is-playing')), 'the embedded player plays');
	await page.screenshot({ path: 'screenshots/embed-600.png' });
	await page.setViewportSize({ width: 320, height: 200 });
	await page.waitForTimeout(100);
	const narrow = await page.evaluate(() => ({
		height: document.documentElement.scrollHeight,
		overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
		timeline: document.querySelector('.epm-player__timeline').getBoundingClientRect().width,
	}));
	assert(narrow.height <= 200 && narrow.overflow === 0 && narrow.timeline >= 100, `at 320px it still fits one frame (${JSON.stringify(narrow)})`);
	await page.screenshot({ path: 'screenshots/embed-320.png' });

	// WordPress-style host: a sandboxed frame with a secret in the address.
	await page.setViewportSize({ width: 800, height: 400 });
	await page.goto(`${BASE}/wp-login.php`);
	const message = await page.evaluate((src) => new Promise((resolve) => {
		window.addEventListener('message', (e) => {
			if (e.data && e.data.message === 'height') {
				resolve(e.data);
			}
		});
		document.body.innerHTML = `<iframe sandbox="allow-scripts" src="${src}#?secret=abcdefghij" width="600" height="200"></iframe>`;
		setTimeout(() => resolve(null), 5000);
	}), embedUrl);
	assert(message && message.secret === 'abcdefghij' && message.value === 200, `the frame reports its height to a WordPress host (${JSON.stringify(message)})`);
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
console.log('Card buttons and sticky bar');
{
	// The Elementor page has a sticky player and a card list.
	const page = await newPage({ width: 390, height: 844 });
	await page.goto(`${BASE}/epm-elementor/`);
	await page.evaluate(() => localStorage.clear());
	const card = page.locator(`[data-epm-card-play="${fixtures.ep2}"]`).first();
	await card.click();
	await page.waitForTimeout(900);
	assert(await card.getAttribute('aria-label') === 'Pause Episode Two' && (await card.textContent()).trim() === 'Pause', 'a playing card button is named "Pause <title>"');
	const sticky = page.locator('[data-epm-sticky]');
	assert(await sticky.isVisible(), 'the sticky bar opens');
	await page.focus('[data-epm-sticky] [data-epm-sticky-close]');
	await page.keyboard.press('Enter');
	await sticky.waitFor({ state: 'hidden', timeout: 2000 }).catch(() => {});
	const focused = await page.evaluate(() => document.activeElement.getAttribute('data-epm-card-play') || document.activeElement.className);
	assert(await sticky.isHidden() && String(focused) === String(fixtures.ep2), `closing with the keyboard returns focus to the episode (${focused})`);
	assert(await card.getAttribute('aria-label') === 'Play Episode Two', 'closing pauses: the button reads "Play <title>" again');

	// A source that fails: the button offers a retry, in words.
	await page.evaluate((id) => {
		document.querySelectorAll(`[data-epm-card-play="${id}"]`).forEach((b) => { b.dataset.epmSrc = '/wp-content/uploads/epm-missing.mp3'; });
	}, fixtures.ep3);
	const broken = page.locator(`[data-epm-card-play="${fixtures.ep3}"]`).first();
	await broken.click();
	await page.waitForTimeout(1200);
	assert(await broken.getAttribute('aria-label') === 'Retry Episode Three (bonus)' && (await broken.textContent()).trim() === 'Retry', `a failed source turns the button into "Retry" (${await broken.getAttribute('aria-label')})`);
	const live = await page.evaluate(() => [...document.querySelectorAll('[aria-live]')].map((n) => n.textContent).join(' '));
	assert(/could not be loaded/.test(live), 'the failure is announced');
	await page.context().close();
}

// ---------------------------------------------------------------------------
console.log('Video facade');
{
	const meta = '_epm_youtube_url';
	wp(['post', 'meta', 'update', String(fixtures.ep2), meta, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
	try {
		const page = await newPage({ width: 390, height: 844 });
		await page.goto(permalink(fixtures.ep2));
		await page.waitForTimeout(500);
		const before = page.thirdParty.filter((u) => /youtube|ytimg|googlevideo/.test(u));
		assert(before.length === 0, `nothing loads from the video platform before play (${before.join(', ')})`);
		assert(await page.locator('.epm-video iframe').count() === 0, 'no iframe before play');
		const facade = page.locator('[data-epm-video-play]');
		assert(/^Play video: Episode Two$/.test(await facade.getAttribute('aria-label')), 'the play button names the video');
		await facade.focus();
		await page.keyboard.press('Enter');
		await page.waitForTimeout(300);
		const after = await page.evaluate(() => ({ src: (document.querySelector('.epm-video iframe') || {}).src || '', focus: document.activeElement.tagName }));
		assert(/^https:\/\/www\.youtube-nocookie\.com\/embed\/dQw4w9WgXcQ\?autoplay=1/.test(after.src), `play loads the privacy-enhanced player (${after.src})`);
		assert(after.focus === 'IFRAME', 'focus moves into the video');
		assert(page.thirdParty.some((u) => u.startsWith('https://www.youtube-nocookie.com/embed/')) && !page.thirdParty.some((u) => /\/\/www\.youtube\.com/.test(u)), 'only youtube-nocookie.com is contacted');
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		assert(overflow === 0, 'no horizontal overflow at 390px');
		await page.screenshot({ path: 'screenshots/video-390.png', fullPage: true });
		assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
		await page.context().close();
	} finally {
		wp(['post', 'meta', 'delete', String(fixtures.ep2), meta]);
	}
}

await browser.close();
console.log(failures ? `\n${failures} browser check(s) failed.` : '\nAll browser checks passed.');
process.exit(failures ? 1 : 0);
