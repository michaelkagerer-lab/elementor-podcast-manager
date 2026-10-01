/**
 * Browser tests for the 1.3.0 frontend components: share menu, timestamp
 * links (?t=), episode embeds, the click-to-load video facade, the sticky
 * bar for lists and chapters, remote audio loading, and the design system
 * on real pages (dark designs, Elementor Kit rules, row alignment).
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
	assert(/<iframe src="[^"]+\/embed\/" title="[^"]+" width="640" height="200"/.test(embed) && /^<p><a href=/.test(embed), `Copy embed code includes a fallback link and iframe (${embed.slice(0, 90)}…)`);

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
	const playLabel = () => page.getAttribute('[data-epm-player] [data-epm-play]', 'aria-label');
	assert(await playLabel() === 'Play episode, Starts at 1:05', `the play button names the start position (${await playLabel()})`);
	await page.click('.epm-player__play');
	await page.waitForTimeout(700);
	const playing = await page.evaluate(() => window.epmPlayerEngine.getController(document.querySelector('[data-epm-player]').dataset.epmEpisodeId).audio.currentTime);
	assert(playing >= 65, `the first press plays from there (${playing.toFixed(1)}s)`);
	assert(await playLabel() === 'Pause episode', `while playing it reads "Pause episode" (${await playLabel()})`);
	assert(await page.locator('[data-epm-sticky]').isVisible(), 'the automatic episode page brings the sticky bar');
	await page.click('.epm-player__play');
	await page.waitForTimeout(200);
	assert(await playLabel() === 'Play episode', `after the first play the start hint is gone (${await playLabel()})`);

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
	// The title link's focus ring is not clipped by the two-line clamp.
	const ringClipped = () => page.evaluate(() => {
		const link = document.activeElement;
		const style = getComputedStyle(link);
		const grow = parseFloat(style.outlineWidth) + parseFloat(style.outlineOffset);
		const r = link.getBoundingClientRect();
		const ring = { left: r.left - grow, top: r.top - grow, right: r.right + grow, bottom: r.bottom + grow };
		const clippers = [];
		for (let n = link.parentElement; n && n !== document.documentElement; n = n.parentElement) {
			if (getComputedStyle(n).overflow === 'visible') {
				continue;
			}
			const c = n.getBoundingClientRect();
			if (ring.left < c.left || ring.top < c.top || ring.right > c.right || ring.bottom > c.bottom) {
				clippers.push(n.className);
			}
		}
		return { link: link.className, clamp: style.webkitLineClamp, clippers };
	});
	await page.keyboard.press('Tab');
	let ring = await ringClipped();
	assert(ring.link === 'epm-player__title-link' && ring.clamp === '2' && ring.clippers.length === 0, `the title link's focus ring shows in full (${JSON.stringify(ring)})`);
	await page.evaluate(() => { document.querySelector('.epm-player__title-link').textContent = 'Ölförderung, Ärger & Überfluss: ein sehr langer Titel, der auf drei Zeilen umbrechen würde, wenn nichts ihn begrenzt'; });
	await page.setViewportSize({ width: 390, height: 200 });
	ring = await ringClipped();
	assert(ring.clippers.length === 0 && await page.evaluate(() => document.querySelector('.epm-player__title-link').getBoundingClientRect().height <= 2 * parseFloat(getComputedStyle(document.querySelector('.epm-player__title-link')).lineHeight) + 1), `a long title stays two lines and keeps the full ring (${JSON.stringify(ring)})`);
	await page.setViewportSize({ width: 600, height: 200 });
	await page.reload();
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
		document.body.innerHTML = `<iframe sandbox="allow-scripts" src="${src}#?secret=oldsecret1#?secret=abcdefghij" width="600" height="200"></iframe>`;
		setTimeout(() => resolve(null), 5000);
	}), embedUrl);
	assert(message && message.secret === 'abcdefghij' && message.value === 200, `the frame reports its height to a WordPress host (${JSON.stringify(message)})`);
	const embedFrame = page.frames().find((frame) => frame.url().includes('/embed/'));
	const clickIntercepted = () => embedFrame.evaluate(() => {
		const link = document.querySelector('.epm-player__title a');
		let intercepted;
		document.addEventListener('click', (event) => {
			intercepted = event.defaultPrevented;
			event.preventDefault(); // Keep this test on the same document.
		}, { once: true });
		link.click();
		return intercepted;
	});
	assert(!(await clickIntercepted()), 'UX-N1: without a handshake the title link keeps its native behavior');
	await page.evaluate(() => document.querySelector('iframe').contentWindow.postMessage({ message: 'ready', secret: 'oldsecret1' }, '*'));
	await embedFrame.evaluate(() => new Promise((resolve) => setTimeout(resolve, 100)));
	assert(!(await clickIntercepted()), 'an obsolete secret cannot enable link interception');
	await page.evaluate(() => document.querySelector('iframe').contentWindow.postMessage({ message: 'ready', secret: 'abcdefghij' }, '*'));
	await embedFrame.evaluate(() => new Promise((resolve) => setTimeout(resolve, 100)));
	assert(await clickIntercepted(), 'a matching parent handshake enables WordPress link messages');
	await embedFrame.evaluate(() => { window.location.hash = '?secret=newsecret1'; });
	await embedFrame.evaluate(() => new Promise((resolve) => setTimeout(resolve, 100)));
	assert(!(await clickIntercepted()), 'changing the secret requires a new handshake');
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
	const noScripts = await newPage({ width: 320, height: 200 }, { javaScriptEnabled: false });
	await noScripts.goto(embedUrl);
	assert(await noScripts.locator('noscript audio[controls]').isVisible(), 'UX-N13: the embed offers native audio with scripts disabled');
	assert(await noScripts.locator('.epm-player--embed').isHidden(), 'inactive scripted controls are hidden when scripts are disabled');
	await noScripts.context().close();
}

// ---------------------------------------------------------------------------
console.log('Card buttons and sticky bar');
{
	// The Elementor page has a sticky player and a card list.
	const page = await newPage({ width: 390, height: 844 });
	await page.goto(`${BASE}/epm-elementor/`);
	await page.evaluate(() => localStorage.clear());
	const card = page.locator(`[data-epm-card-play="${fixtures.ep2}"]`).first();
	const cardWidth = (await card.boundingBox()).width;
	await card.click();
	await page.waitForTimeout(900);
	assert(await card.getAttribute('aria-label') === 'Pause Episode Two' && (await card.innerText()).trim() === 'Pause', 'a playing card button is named "Pause <title>"');
	assert(Math.abs((await card.boundingBox()).width - cardWidth) < 0.5, `the button keeps its width between Play and Pause (${cardWidth})`);
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
	assert(await broken.getAttribute('aria-label') === 'Retry Episode Three (bonus)' && (await broken.innerText()).trim() === 'Retry', `a failed source turns the button into "Retry" (${await broken.getAttribute('aria-label')})`);
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
		const ringShadow = await page.evaluate(() => getComputedStyle(document.querySelector('.epm-video__button')).boxShadow);
		assert(/rgb\(11, 11, 12\) 0px 0px 0px 7px/.test(ringShadow), `the white focus ring has a dark band behind it for pale artwork (${ringShadow})`);
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

/** ID printed by `wp post create --porcelain` (other plugins may add notices). */
const createPage = (title, content) => {
	const out = wp(['post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${title}`, `--post_content=${content}`, '--porcelain']);
	return out.split('\n').map((l) => l.trim()).find((l) => /^\d+$/.test(l));
};

/**
 * WCAG contrast of each selector's text against the background it sits on
 * (the first opaque background up the tree; white when none).
 */
const contrastOf = (page, selectors) => page.evaluate((selectors) => {
	const parse = (c) => {
		const m = /rgba?\(([^)]+)\)/.exec(c);
		if (!m) {
			return null;
		}
		const p = m[1].split(/[\s,/]+/).filter(Boolean).map(Number);
		return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
	};
	const lum = (c) => [c.r, c.g, c.b].map((v) => {
		v /= 255;
		return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
	}).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
	const background = (el) => {
		for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
			const c = parse(getComputedStyle(n).backgroundColor);
			if (c && c.a >= 1) {
				return c;
			}
		}
		return { r: 255, g: 255, b: 255, a: 1 };
	};
	const out = {};
	selectors.forEach((selector) => {
		const el = document.querySelector(selector);
		if (!el) {
			out[selector] = 0;
			return;
		}
		const a = lum(parse(getComputedStyle(el).color));
		const b = lum(background(el));
		out[selector] = Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100;
	});
	return out;
}, selectors);

const noOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);

// ---------------------------------------------------------------------------
console.log('Sticky bar for lists and chapters');
{
	// A page with nothing but a chapter list: the bar is its only pause.
	const chaptersPage = createPage('EPM Chapters Only', `[podcast_chapters id="${fixtures.ep1}"]`);
	try {
		const page = await newPage({ width: 1280, height: 900 });
		await page.goto(`${BASE}/epm-shortcodes/`);
		const sticky = page.locator('[data-epm-sticky]');
		assert(await sticky.count() === 1 && await sticky.isHidden(), 'a card list brings the sticky shell, hidden until playback');
		await page.locator(`[data-epm-card-play="${fixtures.ep3}"]`).first().click();
		await page.waitForTimeout(900);
		assert(await sticky.isVisible() && (await page.textContent('[data-epm-sticky-title]')) === 'Episode Three (bonus)', 'playing from a card opens the bar');

		await page.goto(permalink(chaptersPage));
		assert(await page.locator('[data-epm-player]').count() === 0, 'the chapters page has no player');
		await page.click('.epm-chapters__seek >> nth=1');
		await page.waitForTimeout(900);
		assert(await sticky.isVisible(), 'a chapter press opens the bar');
		await page.click('[data-epm-sticky] [data-epm-play]');
		await page.waitForTimeout(300);
		assert(await page.evaluate((id) => !window.epmPlayerEngine.getController(String(id)).isPlaying(), fixtures.ep1), 'and the bar pauses it');
		assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
		await page.context().close();
	} finally {
		wp(['post', 'delete', String(chaptersPage), '--force']);
	}
}

// ---------------------------------------------------------------------------
console.log('Remote audio');
{
	// A podcast host behind a tracking prefix: nothing may reach either
	// before the visitor presses play.
	const id = String(fixtures.ep2);
	const remote = 'https://dts.podtrac.com/redirect.mp3/media.example.org/ep2.mp3';
	wp(['post', 'meta', 'delete', id, '_epm_audio_id']);
	wp(['post', 'meta', 'update', id, '_epm_audio_url', remote]);
	// Imported episodes carry the feed's itunes:duration.
	wp(['post', 'meta', 'update', id, '_epm_duration', '2:30']);
	try {
		const page = await newPage();
		await page.goto(permalink(fixtures.ep2));
		await page.waitForTimeout(800);
		const audio = await page.evaluate(() => {
			// The element that plays: the player's own <audio>, which the
			// engine keeps outside the player's DOM once bound.
			const player = document.querySelector('[data-epm-player]');
			const el = window.epmPlayerEngine.getController(player.dataset.epmEpisodeId).audio;
			return { preload: el.getAttribute('preload'), src: el.getAttribute('src'), total: player.querySelector('[data-epm-total]').textContent };
		});
		assert(audio.src === remote && audio.preload === 'none', `remote audio is preload="none" (${JSON.stringify(audio)})`);
		assert(!page.thirdParty.some((u) => u.includes('podtrac')), `no request to the host or tracking prefix before play (${page.thirdParty.join(', ')})`);
		assert(audio.total === '2:30', `the duration shows without loading the file (${audio.total})`);
		await page.click('.epm-player__play');
		await page.waitForTimeout(800);
		assert(page.thirdParty.some((u) => u.startsWith(remote)), 'pressing play loads it');
		await page.context().close();
	} finally {
		wp(['post', 'meta', 'update', id, '_epm_audio_id', String(fixtures.audio_2)]);
		wp(['post', 'meta', 'delete', id, '_epm_audio_url']);
		wp(['post', 'meta', 'delete', id, '_epm_duration']);
	}
}

// ---------------------------------------------------------------------------
console.log('Design system on the page');
{
	const listPage = createPage('EPM Row List', '[podcast_episodes limit="10" layout="list"]\n\n[podcast_episodes topic="epm-no-such-topic"]');
	const germanList = createPage('EPM German Row List', '[podcast_episodes limit="10" layout="editorial-rows" show_episode_number="yes"]');
	try {
		const page = await newPage({ width: 1280, height: 900 });

		// Light designs: sections stay as they were (no surface, no padding).
		await page.goto(EP1);
		const light = await page.evaluate(() => ['.epm-show-notes', '.epm-chapters', '.epm-guest-block', '.epm-transcript'].map((s) => {
			const cs = getComputedStyle(document.querySelector(s));
			return `${cs.backgroundColor} ${cs.paddingTop}`;
		}));
		assert(light.every((v) => v === 'rgba(0, 0, 0, 0) 0px'), `light designs add no section surface (${light.join(', ')})`);

		// Elementor Kit typography and link colors (0,1,1), printed last.
		const kitRules = () => page.evaluate(() => {
			const kit = (document.body.className.match(/elementor-kit-\d+/) || [])[0] || 'elementor-kit-epm';
			document.body.classList.add(kit);
			const style = document.createElement('style');
			style.textContent = `.${kit} h1{color:#1a1a1a;font-size:48px}.${kit} h2{color:#1a1a1a;font-size:40px}.${kit} h3{color:#1a1a1a;font-size:32px}.${kit} a{color:#cc3366}`;
			document.body.appendChild(style);
		});
		const typeOf = (selectors) => page.evaluate((selectors) => selectors.map((s) => {
			const el = document.querySelector(s);
			return el ? `${s}: ${getComputedStyle(el).fontSize} ${getComputedStyle(el).color}` : `${s}: missing`;
		}), selectors);
		for (const [url, selectors] of [
			[EP1, ['.epm-player__download', '.epm-chapters__link']],
			[`${BASE}/epm-elementor/`, ['.epm-episode-card__title', '.epm-episode-card__title a', '.epm-podcast-hero__title', '.epm-episode-header__title']],
			[permalink(listPage), ['.epm-episode-row__title', '.epm-episode-row__title a', '.epm-episode-list__empty-link']],
		]) {
			await page.goto(url);
			const before = await typeOf(selectors);
			await kitRules();
			const after = await typeOf(selectors);
			assert(!before.join().includes('missing') && before.join() === after.join(), `Elementor Kit heading and link rules leave podcast titles and links alone (${after.join('; ')})`);
		}

		// Elementor page: widget backgrounds pad; "…" is no chip; long words break.
		await page.goto(`${BASE}/epm-elementor/`);
		const boxes = await page.evaluate(() => {
			const hero = document.querySelector('.epm-podcast-hero');
			const latest = document.querySelector('.epm-latest');
			const before = `${getComputedStyle(hero).paddingTop} ${getComputedStyle(latest).paddingTop}`;
			hero.style.cssText = '--epm-hero-background: #123456; --epm-hero-padding: 36px';
			latest.style.cssText = '--epm-latest-background: #123456; --epm-latest-padding: 24px';
			return { before, hero: `${getComputedStyle(hero).paddingTop} ${getComputedStyle(hero).backgroundColor}`, latest: `${getComputedStyle(latest).paddingTop} ${getComputedStyle(latest).backgroundColor}` };
		});
		assert(boxes.before === '0px 0px' && boxes.hero === '36px rgb(18, 52, 86)' && boxes.latest === '24px rgb(18, 52, 86)', `hero and latest-episode backgrounds come with inner padding (${JSON.stringify(boxes)})`);
		const dots = await page.evaluate(() => {
			document.querySelector('.epm-pagination ul').insertAdjacentHTML('beforeend', '<li><span class="page-numbers dots">…</span></li>');
			return { dots: getComputedStyle(document.querySelector('.epm-pagination .dots')).borderTopColor, link: getComputedStyle(document.querySelector('.epm-pagination a')).borderTopColor };
		});
		assert(dots.dots === 'rgba(0, 0, 0, 0)' && dots.link !== 'rgba(0, 0, 0, 0)', `pagination "…" has no chip border (${JSON.stringify(dots)})`);
		await page.setViewportSize({ width: 390, height: 844 });
		await page.evaluate(() => {
			document.querySelector('.epm-podcast-hero__title').textContent = 'Kreislaufwirtschaftsgesetzänderungsverordnung';
			document.querySelector('.epm-episode-header__title').textContent = 'Arbeitnehmerüberlassungsgesetzänderung erklärt';
		});
		assert(await noOverflow(page), 'a long single word in a title breaks instead of widening the page at 390px');

		// Row list: one baseline per row, a stable Play button, a marked link.
		await page.setViewportSize({ width: 1280, height: 900 });
		await page.goto(permalink(listPage));
		const rows = await page.evaluate(() => [...document.querySelectorAll('.epm-episode-row')].filter((row) => row.querySelector('button')).map((row) => {
			const top = row.getBoundingClientRect().top;
			const baseline = (el) => {
				if (!el || !el.getClientRects().length) {
					return null;
				}
				const probe = document.createElement('span');
				probe.style.cssText = 'display:inline-block;width:0;height:0;vertical-align:baseline';
				el.insertBefore(probe, el.firstChild);
				const y = Math.round(probe.getBoundingClientRect().bottom - top);
				probe.remove();
				return y;
			};
			// The first text line: the guest eyebrow when there is one.
			return {
				title: baseline(row.querySelector('.epm-episode-row__guest') || row.querySelector('.epm-episode-row__title a')),
				date: baseline(row.querySelector('.epm-meta__item')),
				play: baseline(row.querySelector('.epm-list-play__text--play')),
				number: baseline(row.querySelector('.epm-episode-row__number')),
			};
		}));
		const aligned = rows.length > 0 && rows.every((r) => [r.date, r.play, r.number].every((y) => y === null || Math.abs(y - r.title) <= 1));
		assert(aligned, `number, title, date and Play share one baseline (${JSON.stringify(rows)})`);
		const rowPlay = page.locator('.epm-episode-row__play').first();
		const rowWidth = (await rowPlay.boundingBox()).width;
		await rowPlay.click();
		await page.waitForTimeout(700);
		assert((await rowPlay.innerText()).trim() === 'Pause' && Math.abs((await rowPlay.boundingBox()).width - rowWidth) < 0.5, 'the row button reads "Pause" at the same width');
		await rowPlay.click();
		const empty = await page.evaluate(() => getComputedStyle(document.querySelector('.epm-episode-list__empty-link')).textDecorationLine);
		assert(empty === 'underline', `the empty-state link is underlined, not marked by color alone (${empty})`);
		await page.setViewportSize({ width: 390, height: 844 });
		assert(await noOverflow(page), 'the row list fits 390px');
		const originalTitle = wp(['post', 'get', String(fixtures.ep1), '--field=post_title']).trim();
		try {
			wp(['post', 'update', String(fixtures.ep1), '--post_title=Folge 12: Nachhaltigkeit und Digitalisierung im Mittelstand']);
			wp(['post', 'meta', 'update', String(fixtures.ep1), '_epm_episode_number', '12']);
			for (const preset of ['business-tuning', 'editorial', 'warm-paper', 'neutral']) {
				wp(['eval', `epm()->design->apply_preset( "${preset}" );`]);
				await page.setViewportSize({ width: 320, height: 740 });
				await page.goto(permalink(germanList));
				const phoneRows = await page.evaluate(() => [...document.querySelectorAll('.epm-episode-row')].map((row) => {
					const title = row.querySelector('.epm-episode-row__title');
					const content = row.querySelector('.epm-episode-row__content');
					return { width: content.clientWidth, height: title.clientHeight, title: title.innerText };
				}));
				assert(phoneRows.every((row) => row.width >= 150 && row.height > 0) && await noOverflow(page), `${preset} numbered German rows keep a usable full-width title at 320px (${JSON.stringify(phoneRows)})`);
				await page.setViewportSize({ width: 390, height: 844 });
				assert(await noOverflow(page), `${preset} numbered rows fit 390px`);
			}
		} finally {
			wp(['post', 'update', String(fixtures.ep1), `--post_title=${originalTitle}`]);
			wp(['post', 'meta', 'update', String(fixtures.ep1), '_epm_episode_number', '1']);
		}
		wp(['option', 'delete', 'epm_design_settings']);
		await page.setViewportSize({ width: 1280, height: 900 });

		// Dark design on the light theme page: sections get the design surface.
		wp(['eval', 'epm()->design->apply_preset( "night-studio" );']);
		wp(['post', 'meta', 'update', String(fixtures.ep2), '_epm_youtube_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
		try {
			for (const [url, selectors] of [
				[EP1, ['.epm-show-notes__heading', '.epm-show-notes__content', '.epm-chapters__title', '.epm-chapters__time', '.epm-guest__name', '.epm-guest__details', '.epm-transcript__heading']],
				[`${BASE}/epm-elementor/`, ['.epm-podcast-hero__title', '.epm-episode-header__title', '.epm-subscribe__link', '.epm-pagination a', '.epm-guest__name', '.epm-meta--standalone']],
				[permalink(listPage), ['.epm-episode-row__title', '.epm-episode-row .epm-meta', '.epm-episode-list__empty']],
				[permalink(fixtures.ep2), ['.epm-video__note']],
			]) {
				await page.goto(url);
				const ratios = await contrastOf(page, selectors);
				const low = Object.entries(ratios).filter(([, ratio]) => ratio < 4.5);
				assert(low.length === 0, `dark design on a light page: section text is readable (${JSON.stringify(ratios)})`);
			}
			await page.screenshot({ path: 'screenshots/dark-design-rows.png', fullPage: true });
			assert(await noOverflow(page), 'section padding adds no overflow');
		} finally {
			wp(['option', 'delete', 'epm_design_settings']);
			wp(['post', 'meta', 'delete', String(fixtures.ep2), '_epm_youtube_url']);
		}
		assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
		await page.context().close();
	} finally {
		wp(['post', 'delete', String(listPage), '--force']);
		wp(['post', 'delete', String(germanList), '--force']);
	}
}

await browser.close();
console.log(failures ? `\n${failures} browser check(s) failed.` : '\nAll browser checks passed.');
process.exit(failures ? 1 : 0);
