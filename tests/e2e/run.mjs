/**
 * Browser tests (Playwright/Chromium) against a site seeded with
 * tests/fixtures/seed.php.
 *
 *   cd tests/e2e && npm install && WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp node run.mjs
 *
 * Suites: frontend player engine, Elementor editor, episode admin.
 * Screenshots land in tests/e2e/screenshots/.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const BASE = process.env.WP_URL || 'http://localhost:8889';
const WP = process.env.WP_CLI || 'wp';
// WP-CLI output may carry notices printed by other plugins: keep only the
// line we asked for.
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

async function newPage(viewport = { width: 1280, height: 900 }) {
	const context = await browser.newContext({ viewport });
	// Hermetic: no third-party requests (fonts, avatars).
	await context.route((url) => url.origin !== new URL(BASE).origin, (route) => route.abort('blockedbyclient'));
	const page = await context.newPage();
	page.problems = [];
	page.on('pageerror', (e) => page.problems.push(`pageerror: ${e.message} ${(e.stack || '').split('\n').slice(1, 4).join(' | ')}`));
	page.on('console', (m) => {
		if (m.type() !== 'error') {
			return;
		}
		const where = `${m.text()} ${(m.location() && m.location().url) || ''}`;
		// Uncaught exceptions always fail (pageerror above). Console errors
		// only count when they come from this plugin: WordPress and
		// Elementor log their own development warnings (React prop types).
		if (/ERR_BLOCKED_BY_CLIENT|net::ERR_FAILED/.test(where) || !/elementor-podcast-manager|epm/i.test(where)) {
			return;
		}
		page.problems.push(`console: ${where}`);
	});
	return page;
}

async function login(page) {
	await page.goto(`${BASE}/wp-login.php`);
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'admin');
	await Promise.all([page.waitForURL(/\/wp-admin\//, { waitUntil: 'domcontentloaded', timeout: 60000 }), page.click('#wp-submit')]);
}

/** Silent MPEG-1 Layer III (32 kbps, 32 kHz, mono): 144-byte frames, 36 ms each. */
function silentMp3Base64(seconds) {
	const frame = Buffer.concat([Buffer.from([0xff, 0xfb, 0x18, 0xc0]), Buffer.alloc(140)]);
	return Buffer.concat(Array(Math.ceil(seconds / 0.036)).fill(frame)).toString('base64');
}

// ---------------------------------------------------------------------------
console.log('Frontend player');
{
	const page = await newPage();
	await page.goto(permalink(fixtures.ep1));
	await page.evaluate(() => localStorage.clear());
	await page.reload();
	assert(await page.locator('[data-epm-player]').count() === 1, 'episode page renders exactly one player');
	assert(await page.getAttribute('[data-epm-player]', 'data-epm-initialized') === '1', 'player initialized');

	await page.click('.epm-player__play');
	await page.waitForTimeout(1200);
	assert(await page.evaluate(() => document.querySelector('[data-epm-player]').classList.contains('is-playing')), 'play starts playback');

	await page.click('.epm-chapters__time >> nth=1');
	await page.waitForTimeout(1200);
	const chapter = await page.evaluate(() => ({
		active: [...document.querySelectorAll('.epm-chapters__item')].findIndex((li) => li.classList.contains('is-active')),
		time: document.querySelector('[data-epm-player] [data-epm-current]').textContent,
	}));
	assert(chapter.active === 1 && chapter.time.startsWith('0:3'), `chapter click seeks the episode and highlights it (${JSON.stringify(chapter)})`);

	const before = Number(await page.getAttribute('[data-epm-timeline]', 'aria-valuenow'));
	await page.focus('.epm-player [data-epm-timeline]');
	await page.keyboard.press('ArrowRight');
	await page.keyboard.press('ArrowRight');
	await page.waitForTimeout(400);
	const after = Number(await page.getAttribute('[data-epm-timeline]', 'aria-valuenow'));
	assert(after - before >= 9 && after - before <= 12, `keyboard seeking moves the slider and updates aria-valuenow (${before} → ${after})`);

	await page.click('.epm-player__speed');
	await page.click('.epm-player__skip >> nth=0');
	const skipBg = await page.evaluate(() => getComputedStyle(document.querySelector('.epm-player__skip')).backgroundColor);
	assert(skipBg === 'rgba(0, 0, 0, 0)', `focused skip button ignores theme button styles (${skipBg})`);
	await page.click('.epm-player__play');
	await page.waitForTimeout(300);
	await page.screenshot({ path: 'screenshots/episode-page.png', fullPage: true });
	await page.reload();
	await page.waitForTimeout(1500);
	const resumed = await page.evaluate(() => ({
		time: document.querySelector('[data-epm-player] [data-epm-current]').textContent,
		speed: document.querySelector('.epm-player__speed [data-epm-speed-value]').textContent,
	}));
	assert(resumed.time !== '0:00', `position resumes after reload (${resumed.time})`);
	assert(resumed.speed === '1.25×', `speed preference persists (${resumed.speed})`);

	await page.goto(`${BASE}/epm-shortcodes/`);
	await page.click(`[data-epm-card-play="${fixtures.ep1}"]`);
	await page.waitForTimeout(1200);
	const shared = await page.evaluate((id) => ({
		card: document.querySelector(`[data-epm-card-play="${id}"]`).classList.contains('is-playing'),
		player: document.querySelector(`[data-epm-player][data-epm-episode-id="${id}"]`).classList.contains('is-playing'),
	}), fixtures.ep1);
	assert(shared.card === true && shared.player, 'card and full player of one episode share playback state');
	await page.click(`[data-epm-card-play="${fixtures.ep2}"]`);
	await page.waitForTimeout(1000);
	const others = await page.evaluate((ids) => ({
		first: document.querySelector(`[data-epm-player][data-epm-episode-id="${ids[0]}"]`).classList.contains('is-playing'),
		second: document.querySelector(`[data-epm-card-play="${ids[1]}"]`).classList.contains('is-playing'),
	}), [fixtures.ep1, fixtures.ep2]);
	assert(!others.first && others.second === true, 'starting another episode pauses the first');

	await page.evaluate(async () => {
		const html = await (await fetch(location.href)).text();
		const player = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-epm-player]');
		player.id = 'epm-injected';
		document.body.appendChild(player);
	});
	await page.waitForTimeout(300);
	assert(await page.getAttribute('#epm-injected', 'data-epm-initialized') === '1', 'players inserted later (AJAX) initialize');

	await page.setViewportSize({ width: 390, height: 800 });
	await page.goto(permalink(fixtures.ep1));
	const box = await page.locator('.epm-player').boundingBox();
	const timeline = await page.locator('.epm-player__timeline').boundingBox();
	assert(box.height < 700 && timeline.width > 250, `mobile player keeps a full-width timeline (${Math.round(timeline.width)}px)`);
	await page.screenshot({ path: 'screenshots/episode-page-mobile.png', fullPage: true });
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
if (fixtures.elementor_page) {
	console.log('Elementor editor');
	const page = await newPage({ width: 1500, height: 1000 });
	await login(page);
	await page.goto(`${BASE}/wp-admin/post.php?post=${fixtures.elementor_page}&action=elementor`);
	await page.waitForSelector('#elementor-preview-iframe', { timeout: 90000 });
	const frame = page.frameLocator('#elementor-preview-iframe');
	await frame.locator('.elementor-widget-epm-podcast-player').first().waitFor({ timeout: 90000 });
	await page.waitForTimeout(2500);
	const preview = page.frames().find((f) => f.url().includes('elementor-preview'));
	const state = await preview.evaluate(() => ({
		widgets: document.querySelectorAll('[data-widget_type^="epm-"]').length,
		players: [...document.querySelectorAll('[data-epm-player]')].every((p) => p.dataset.epmInitialized === '1'),
	}));
	assert(state.widgets === 11, `all widgets render in the editor preview (${state.widgets})`);
	assert(state.players, 'players initialize in the editor preview');

	// Select the widget through Elementor's command API (a click can land
	// on onboarding overlays on a fresh install); fall back to clicking.
	await page.keyboard.press('Escape');
	const widgetId = await preview.evaluate(() => document.querySelector('.elementor-widget-epm-podcast-player').dataset.id);
	const selected = await page.evaluate((id) => {
		try {
			window.$e.run('document/elements/select', { container: window.elementor.getContainer(id) });
			return true;
		} catch (e) {
			return false;
		}
	}, widgetId);
	if (!selected) {
		await frame.locator('.elementor-widget-epm-podcast-player').first().click({ position: { x: 5, y: 5 } });
	}
	await page.locator('.elementor-control-source select >> visible=true').waitFor({ timeout: 20000 });
	await page.click('.elementor-control-section_player >> visible=true');
	await page.selectOption('.elementor-control-layout select >> visible=true', 'editorial');
	await page.waitForTimeout(3000);
	const rerendered = await preview.evaluate(() => {
		const p = document.querySelector('.elementor-widget-epm-podcast-player [data-epm-player]');
		return p.classList.contains('epm-player--editorial') && p.dataset.epmInitialized === '1';
	});
	assert(rerendered, 'changing a control re-renders and re-initializes the player');

	await frame.locator('.elementor-widget-epm-podcast-player').first().locator('.epm-player__play').click();
	await page.waitForTimeout(1200);
	assert(await preview.evaluate(() => document.querySelector('.elementor-widget-epm-podcast-player [data-epm-player]').classList.contains('is-playing')), 'playback works in the editor preview');

	await page.click('.elementor-control-section_episode_source >> visible=true');
	const search = page.locator('.epm-episode-select__search >> visible=true');
	await search.fill('Two');
	await page.waitForFunction(() => {
		const options = [...document.querySelectorAll('.epm-episode-select__results li[role="option"]')];
		return options.length > 0 && options.every((o) => o.textContent.includes('Two'));
	}, null, { timeout: 15000 });
	await page.click('.epm-episode-select__results li[role="option"] >> nth=0');
	await page.waitForTimeout(3000);
	const picked = await preview.evaluate(() => document.querySelector('.elementor-widget-epm-podcast-player [data-epm-player]').dataset.epmEpisodeId);
	assert(String(picked) === String(fixtures.ep2), 'episode picker searches and selects an episode');
	await page.screenshot({ path: 'screenshots/elementor-editor.png' });
	assert(page.problems.length === 0, `no editor errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
console.log('Episode admin');
{
	const page = await newPage({ width: 1400, height: 1000 });
	await login(page);
	await page.goto(`${BASE}/wp-admin/post-new.php?post_type=podcast_episode`);
	await page.waitForSelector('#title');
	const ordered = await page.evaluate(() => {
		const top = (id) => document.getElementById(id).getBoundingClientRect().top;
		return top('titlediv') < top('epm-audio') && top('epm-audio') < top('postdivrich');
	});
	assert(ordered, 'audio upload sits between title and description');
	await page.fill('#title', 'E2E Episode');
	await page.click('#content-html');
	await page.fill('#content', 'Created by the browser test.');
	await page.evaluate((b64) => {
		const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
		const dt = new DataTransfer();
		dt.items.add(new File([bytes], 'e2e-episode.mp3', { type: 'audio/mpeg' }));
		document.querySelector('[data-epm-drop]').dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
	}, silentMp3Base64(45));
	await page.waitForFunction(() => Number(document.querySelector('[data-epm-audio-id]').value) > 0 && document.querySelector('.epm-upload__file'), null, { timeout: 30000 });
	assert(/0:4[45]/.test(await page.textContent('.epm-upload__file')), 'drag-and-drop upload shows file details in place');

	await page.fill('#epm-episode-number', '9');
	const add = page.locator('[data-epm-repeat="chapters"] [data-epm-repeat-add]');
	await add.click();
	await add.click();
	const rows = page.locator('[data-epm-repeat="chapters"] [data-epm-repeat-rows] [data-epm-repeat-row]');
	await rows.nth(0).locator('.epm-repeat__time').fill('0:00');
	await rows.nth(0).locator('input[name$="[title]"]').fill('Start');
	await rows.nth(1).locator('[data-epm-repeat-remove]').click();
	await page.click('#epm_show_notes-html');
	await page.fill('#epm_show_notes', '<ul><li><a href="https://example.org">Ref</a></li></ul>');
	await page.fill('#epm-duration', 'not-a-duration');
	await Promise.all([page.waitForURL(/message=6/, { timeout: 60000 }), page.click('#publish')]);
	await page.waitForSelector('#message', { timeout: 30000 });
	const notices = await page.$$eval('.notice:not(.hidden) p', (ps) => ps.map((p) => p.textContent.trim()));
	assert(notices.some((n) => /Episode published/.test(n)), 'episode-specific publish message');
	assert(notices.some((n) => /manual duration was not saved/.test(n)), 'validation notice survives the save redirect');
	const postId = await page.inputValue('#post_ID');
	await page.screenshot({ path: 'screenshots/episode-admin.png', fullPage: true });

	const feed = await (await page.request.get(`${BASE}/podcast/feed/`)).text();
	assert(feed.includes('<title>E2E Episode</title>') && feed.includes('<itunes:episode>9</itunes:episode>'), 'published episode appears in the feed');
	const saved = JSON.parse(line(wp(['eval', `echo wp_json_encode( epm()->episodes->get_data( ${Number(postId)} ) ), "\\n";`]), '{'));
	assert(saved.duration === '0:45' || saved.duration === '0:44', `duration detected from the file (${saved.duration})`);
	assert(saved.show_notes.includes('<ul><li><a href="https://example.org">Ref</a></li></ul>'), 'show notes keep their HTML');
	assert(saved.chapters.length === 1 && saved.chapters[0].title === 'Start', 'chapters saved without the removed row');
	execFileSync(WP, ['post', 'delete', String(postId), '--force'], { stdio: 'ignore' });
	assert(page.problems.length === 0, `no admin errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
if (fixtures.elementor_page) {
	console.log('Elementor page and sticky player');
	const page = await newPage();
	await page.goto(`${BASE}/epm-elementor/`);
	const custom = await page.evaluate(() => {
		const button = document.querySelector('.elementor-widget-epm-podcast-player .epm-player__play');
		const style = getComputedStyle(button);
		return { background: style.backgroundColor, width: style.width };
	});
	assert(custom.background === 'rgb(10, 125, 51)' && custom.width === '72px', `custom Elementor play button color and size apply (${JSON.stringify(custom)})`);
	assert(await page.locator('[data-epm-sticky]').isHidden(), 'sticky bar hidden until playback');
	await page.locator('.elementor-widget-epm-podcast-player .epm-player__play').click();
	await page.waitForTimeout(1500);
	const sticky = await page.evaluate(() => {
		const bar = document.querySelector('[data-epm-sticky]');
		return { hidden: bar.hidden, title: bar.querySelector('[data-epm-sticky-title]').textContent, artwork: !!bar.querySelector('img') };
	});
	assert(!sticky.hidden && sticky.title.startsWith('Episode One') && sticky.artwork, 'sticky bar follows the active episode');
	await page.click('[data-epm-sticky] [data-epm-speed]');
	const rate = await page.evaluate(() => window.epmPlayerEngine.getController(document.querySelector('.elementor-widget-epm-podcast-player [data-epm-player]').dataset.epmEpisodeId).audio.playbackRate);
	assert(rate !== 1, `sticky speed button changes the real playback rate (${rate})`);
	await page.click('[data-epm-sticky] [data-epm-play]');
	await page.waitForTimeout(300);
	assert(!(await page.evaluate(() => document.querySelector('.elementor-widget-epm-podcast-player [data-epm-player]').classList.contains('is-playing'))), 'sticky pause pauses the widget player');
	await page.click('[data-epm-sticky] [data-epm-sticky-close]');
	// The bar slides out (200ms) before it is removed from the layout.
	await page.locator('[data-epm-sticky]').waitFor({ state: 'hidden', timeout: 2000 }).catch(() => {});
	assert(await page.locator('[data-epm-sticky]').isHidden(), 'close hides the sticky bar');
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
console.log('Design (presets, export, import)');
{
	const page = await newPage();
	await login(page);
	const applyPreset = async (id) => {
		await page.goto(`${BASE}/wp-admin/admin.php?page=epm-design`);
		// Preset tiles are a radio group; applying asks for confirmation.
		await page.check(`input[name="epm_preset"][value="${id}"]`);
		await page.click('[data-epm-preset-apply]');
		await Promise.all([page.waitForURL(/epm_design=preset-applied/), page.click('[data-epm-dialog-confirm]')]);
	};
	await applyPreset('business-tuning');
	assert((await page.textContent('.epm-design-summary')).includes('Business Tuning'), 'preset applied');
	const [download] = await Promise.all([page.waitForEvent('download'), page.click('input[name="action"][value="epm_design_export"] ~ input[type=submit]')]);
	const exported = JSON.parse(fs.readFileSync(await download.path(), 'utf8'));
	assert(exported.format === 'epm-design' && exported.design.accent === '#b9ff22', 'export contains the design tokens');
	assert(!JSON.stringify(exported).match(/https?:|_id"/), 'export holds no URLs or IDs');
	const file = 'screenshots/design-import.json';
	fs.writeFileSync(file, JSON.stringify({ ...exported, design: { ...exported.design, accent: '#ff00aa' } }));
	await applyPreset('neutral');
	await page.setInputFiles('input[name="epm_design_file"]', file);
	await Promise.all([page.waitForURL(/epm_design=imported/), page.click('form[enctype="multipart/form-data"] input[type=submit]')]);
	await page.goto(permalink(fixtures.ep1));
	const accent = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--epm-accent').trim());
	assert(accent === '#ff00aa', `imported design applies on the site (${accent})`);
	await applyPreset('neutral');
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

await browser.close();
console.log(failures ? `\n${failures} browser check(s) failed.` : '\nAll browser checks passed.');
process.exit(failures ? 1 : 0);
