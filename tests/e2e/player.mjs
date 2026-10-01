/**
 * Browser tests for the player engine (assets/js/epm-player.js) and its
 * Elementor integration:
 *
 * - Elementor's element_ready hooks on their own (the engine is served with
 *   its MutationObserver fallback switched off) and together with the
 *   observer, in the real editor: insert, switch episode, duplicate,
 *   delete, undo/redo, repeated re-renders; one listener per control and
 *   one click = one playback;
 * - cloned DOM (Swiper 8 loop duplicates, as bundled with Elementor);
 * - a changed audio source, a fixed broken source, re-renders of a playing
 *   player (frontend and editor), views and controllers released;
 * - volume and mute across views, a read-only volume (iOS), touch targets;
 * - preferred speed, resume position, timestamp links out of range,
 *   Media Session position, slider keys;
 * - the sticky bar: safe areas, a shell that appears later, the widget's
 *   sticky option next to a list.
 *
 * Runs against a site seeded with tests/fixtures/seed.php; the pages and
 * episodes it needs are created here and removed at the end.
 *
 *   cd tests/e2e && WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp node player.mjs
 */
import fs from 'node:fs';
import { BASE, php, fixtures as readFixtures, assert, finish, launch, login } from './lib.mjs';

const fx = readFixtures();
const ORIGIN = new URL(BASE).origin;
// "Another host" for remote audio: same server, different host name, so
// the player renders it with preload="none".
const OTHER = ORIGIN.replace('//localhost', '//127.0.0.1');

// The engine as the browser gets it, with the MutationObserver fallback
// switched off: only Elementor's hooks (and the first init) can bind.
const ENGINE = fs.readFileSync(new URL('../../assets/js/epm-player.js', import.meta.url), 'utf8');
const HOOKS_ONLY = (() => {
	const parts = ENGINE.split('window.MutationObserver');
	if (parts.length < 2) {
		throw new Error('player.mjs: the engine no longer reads window.MutationObserver; update HOOKS_ONLY.');
	}
	return parts.join('window.epmTestNoMutationObserver');
})();

// Counts addEventListener calls per element and event type (all frames),
// and fetches fresh server markup (what a re-render inserts).
const HELPERS = `(() => {
	const add = EventTarget.prototype.addEventListener;
	EventTarget.prototype.addEventListener = function (type, fn, options) {
		if (this && this.nodeType === 1) {
			this.__epmListeners = this.__epmListeners || {};
			this.__epmListeners[type] = (this.__epmListeners[type] || 0) + 1;
		}
		return add.call(this, type, fn, options);
	};
	window.epmTestClicks = (el) => (el && el.__epmListeners && el.__epmListeners.click) || 0;
	// Fresh server markup: the nth element matching selector on url.
	window.epmTestFresh = async (url, selector, nth = 0) => {
		const html = await (await fetch(url, { credentials: 'same-origin' })).text();
		const found = new DOMParser().parseFromString(html, 'text/html').querySelectorAll(selector)[nth];
		return found ? document.importNode(found, true) : null;
	};
})();`;

// Records Media Session action handlers and position states.
const MEDIA_SESSION = `(() => {
	if (!('mediaSession' in navigator)) return;
	window.epmTestSession = { handlers: {}, positions: [] };
	const set = navigator.mediaSession.setActionHandler.bind(navigator.mediaSession);
	navigator.mediaSession.setActionHandler = (action, handler) => { window.epmTestSession.handlers[action] = handler; return set(action, handler); };
	const position = navigator.mediaSession.setPositionState.bind(navigator.mediaSession);
	navigator.mediaSession.setPositionState = (state) => { window.epmTestSession.positions.push(state); return position(state); };
})();`;

// ---------------------------------------------------------------------------
// Fixtures: remote-audio episodes and the pages this suite needs.
const made = php(`
	$f = get_option( 'epm_test_fixtures' );
	$remote = str_replace( '//localhost', '//127.0.0.1', wp_get_attachment_url( $f['audio_3'] ) );
	$episode = function ( $slug, $title, $meta ) {
		$id = wp_insert_post( [ 'post_type' => 'podcast_episode', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_date' => '2026-05-01 10:00:00' ] );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, '_epm_' . $key, $value );
		}
		EPM\\Episodes::get_guid( $id );
		EPM\\Episodes::sync_duration_seconds( $id );
		return $id;
	};
	$page = function ( $slug, $content ) {
		return wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'post_content' => $content ] );
	};
	$ids = [];
	$ids['remote_known'] = $episode( 'epm-player-remote-known', 'Remote, known duration', [ 'audio_url' => $remote, 'audio_type' => 'audio/mpeg', 'duration' => '1:02', 'chapters' => [ [ 'time' => '0:00', 'title' => 'Start', 'url' => '' ], [ 'time' => '0:40', 'title' => 'Later', 'url' => '' ] ] ] );
	$ids['remote_unknown'] = $episode( 'epm-player-remote-unknown', 'Remote, unknown duration', [ 'audio_url' => $remote, 'audio_type' => 'audio/mpeg' ] );
	$ids['two'] = $page( 'epm-player-two', '[podcast_player id="' . $f['ep1'] . '" layout="full" sticky="yes"]' . "\\n\\n" . '[podcast_player id="' . $f['ep1'] . '" layout="full"]' . "\\n\\n" . '[podcast_chapters id="' . $f['ep1'] . '"]' . "\\n\\n" . '[podcast_episodes limit="10" layout="cards"]' );
	$ids['plain_list'] = $page( 'epm-player-plain-list', '[podcast_player id="' . $f['ep1'] . '" layout="full"]' . "\\n\\n" . '[podcast_episodes limit="10" layout="cards"]' );
	$ids['chapters_cards'] = $page( 'epm-player-chapters-cards', '[podcast_chapters id="' . $f['ep1'] . '"]' . "\\n\\n" . '[podcast_episodes limit="10" layout="cards"]' );
	$ids['no_shell'] = $page( 'epm-player-no-shell', '[podcast_player id="' . $f['ep2'] . '" layout="compact"]' );
	// Elementor page: two players of episode one, sticky off.
	$el = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'epm-player-widgets', 'post_name' => 'epm-player-widgets' ] );
	update_post_meta( $el, '_elementor_edit_mode', 'builder' );
	update_post_meta( $el, '_elementor_template_type', 'wp-page' );
	update_post_meta( $el, '_elementor_version', ELEMENTOR_VERSION );
	$widget = function ( $id ) use ( $f ) {
		return [ 'id' => $id, 'elType' => 'widget', 'widgetType' => 'epm-podcast-player', 'settings' => [ 'source' => 'specific', 'episode_id' => (string) $f['ep1'], 'layout' => 'full', 'sticky' => '' ], 'elements' => [] ];
	};
	update_post_meta( $el, '_elementor_data', wp_slash( wp_json_encode( [ [ 'id' => 'e0e0e01', 'elType' => 'container', 'settings' => [], 'isInner' => false, 'elements' => [ $widget( 'e0e0e02' ), $widget( 'e0e0e03' ) ] ] ] ) ) );
	\\Elementor\\Plugin::$instance->files_manager->clear_cache();
	$ids['widgets'] = $el;
	$urls = [];
	foreach ( $ids as $key => $id ) {
		$urls[ $key ] = get_permalink( $id );
	}
	$urls['ep1'] = get_permalink( $f['ep1'] );
	$urls['ep2'] = get_permalink( $f['ep2'] );
	$urls['ep3'] = get_permalink( $f['ep3'] );
	$urls['remote'] = $remote;
	echo wp_json_encode( [ 'ids' => $ids, 'urls' => $urls ] );
`);
const URLS = made.urls;
const EP1 = String(fx.ep1);

// The episode's audio and title as they are now (restored after a test
// that changes them).
const original = php(`echo wp_json_encode( [ 'audio' => get_post_meta( ${fx.ep1}, '_epm_audio_id', true ), 'title' => get_post( ${fx.ep1} )->post_title ] )`);

/** Give episode one another audio file and title, or restore them. */
function changeEpisodeOne(changed) {
	const audio = changed ? fx.audio_2 : Number(original.audio);
	const title = changed ? 'Episode One, new audio' : original.title;
	php(`
		global $wpdb;
		update_post_meta( ${fx.ep1}, '_epm_audio_id', ${audio} );
		$wpdb->update( $wpdb->posts, [ 'post_title' => ${JSON.stringify(title).replace(/\$/g, '\\$')} ], [ 'ID' => ${fx.ep1} ] );
		clean_post_cache( ${fx.ep1} );
		EPM\\Episodes::sync_duration_seconds( ${fx.ep1} );
		echo wp_json_encode( 1 );
	`);
}

const browser = await launch(['--autoplay-policy=no-user-gesture-required']);

/**
 * A page in its own context. Only this site and the "other host" are
 * reachable; requests to the other host are recorded in page.remote.
 */
async function open(url, { engine = null, viewport = { width: 1280, height: 900 }, options = {}, init = [], routes = [] } = {}) {
	const context = await browser.newContext({ viewport, ...options });
	await context.route((u) => u.origin !== ORIGIN && u.origin !== OTHER, (route) => route.abort('blockedbyclient'));
	if (engine) {
		await context.route(/\/assets\/js\/epm-player\.js/, (route) => route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: engine }));
	}
	for (const [pattern, handler] of routes) {
		await context.route(pattern, handler);
	}
	await context.addInitScript(HELPERS);
	for (const script of init) {
		await context.addInitScript(script);
	}
	const page = await context.newPage();
	page.problems = [];
	page.remote = [];
	page.on('pageerror', (e) => page.problems.push(`pageerror: ${e.message}`));
	page.on('console', (m) => {
		const where = `${m.text()} ${(m.location() && m.location().url) || ''}`;
		if (m.type() === 'error' && /epm-player|elementor-podcast-manager/.test(where) && !/ERR_BLOCKED_BY_CLIENT|net::ERR_FAILED|404/.test(where)) {
			page.problems.push(`console: ${where}`);
		}
	});
	page.on('request', (r) => {
		if (r.url().startsWith(OTHER)) {
			page.remote.push(r.url());
		}
	});
	if (url) {
		await page.goto(url);
		await page.waitForTimeout(700);
	}
	return page;
}

const noProblems = async (page, label = '') => {
	assert(page.problems.length === 0, `no browser errors${label ? ` (${label})` : ''} ${page.problems.join('; ')}`);
	await page.context().close();
};

/** State of one episode across every view on the page. */
const episodeState = (page, id) =>
	page.evaluate((id) => {
		const c = window.epmPlayerEngine.getController(id);
		const sticky = document.querySelector('[data-epm-sticky]');
		return {
			controller: !!c,
			src: c ? c.audio.currentSrc || c.audio.src : null,
			playing: c ? c.isPlaying() : false,
			error: c ? c.hasError() : false,
			t: c ? +c.audio.currentTime.toFixed(1) : null,
			duration: c ? c.getDuration() : null,
			players: [...document.querySelectorAll(`[data-epm-player][data-epm-episode-id="${id}"]`)].map((p) => ({ playing: p.classList.contains('is-playing'), error: p.classList.contains('has-error'), total: p.querySelector('[data-epm-total]').textContent })),
			cards: [...document.querySelectorAll(`[data-epm-card-play="${id}"]`)].map((b) => b.className),
			sticky: sticky ? { visible: !sticky.hidden, title: sticky.querySelector('[data-epm-sticky-title]').textContent, error: sticky.classList.contains('has-error'), artwork: !!sticky.querySelector('[data-epm-sticky-artwork] img') } : null,
			session: navigator.mediaSession && navigator.mediaSession.metadata ? { title: navigator.mediaSession.metadata.title, artwork: navigator.mediaSession.metadata.artwork.length } : null,
		};
	}, String(id));

// ---------------------------------------------------------------------------
// Elementor editor helpers.

async function openEditor(postId, engine = null) {
	const page = await open(null, { engine, viewport: { width: 1500, height: 1000 } });
	await login(page);
	await page.goto(`${BASE}/wp-admin/post.php?post=${postId}&action=elementor`);
	await page.waitForSelector('#elementor-preview-iframe', { timeout: 120000 });
	await page.frameLocator('#elementor-preview-iframe').locator('[data-widget_type^="epm-podcast-player"] [data-epm-player]').first().waitFor({ timeout: 120000 });
	await page.waitForTimeout(2000);
	await page.keyboard.press('Escape');
	page.preview = page.frames().find((f) => f.url().includes('elementor-preview'));
	return page;
}

const editorRun = (page, command, id, extra = {}) =>
	page.evaluate(({ command, id, extra }) => {
		const result = window.$e.run(command, { ...(id ? { container: window.elementor.getContainer(id) } : {}), ...extra });
		const container = Array.isArray(result) ? result[0] : result;
		return container && container.id ? container.id : null;
	}, { command, id, extra });

const setSettings = (page, id, settings) => editorRun(page, 'document/elements/settings', id, { settings, options: { external: true } });

/** One widget in the preview: present, episode, layout, play-button click listeners. */
const widget = (page, id) =>
	page.preview.evaluate((id) => {
		const w = document.querySelector(`.elementor-element-${id}`);
		const p = w && w.querySelector('[data-epm-player]');
		if (!w) {
			return { present: false };
		}
		return {
			present: true,
			episode: p ? p.dataset.epmEpisodeId : null,
			layout: p ? ([...p.classList].find((c) => /^epm-player--(minimal|compact|editorial|artwork|full)$/.test(c)) || '').slice(12) : null,
			clicks: p ? window.epmTestClicks(p.querySelector('[data-epm-play]')) : 0,
			playing: p ? p.classList.contains('is-playing') : false,
		};
	}, id);

/** Wait until the widget matches (rendered by the editor), then let init run. */
async function rendered(page, id, check) {
	const deadline = Date.now() + 30000;
	let state = await widget(page, id);
	while (!check(state) && Date.now() < deadline) {
		await page.waitForTimeout(250);
		state = await widget(page, id);
	}
	await page.waitForTimeout(400);
	return widget(page, id);
}

/** Press a widget's play button once; count the audio's play and pause events. */
const playOnce = (page, id) =>
	page.preview.evaluate(async (id) => {
		const p = document.querySelector(`.elementor-element-${id} [data-epm-player]`);
		const c = p && window.epmPlayerEngine.getController(p.dataset.epmEpisodeId);
		if (!c) {
			return { controller: false };
		}
		const audio = c.audio;
		let plays = 0;
		let pauses = 0;
		audio.addEventListener('play', () => plays++);
		audio.addEventListener('pause', () => pauses++);
		p.querySelector('[data-epm-play]').click();
		await new Promise((r) => setTimeout(r, 1200));
		const out = { controller: true, plays, pauses, playing: c.isPlaying(), shown: p.classList.contains('is-playing') };
		c.pause();
		return out;
	}, id);

/** Click listeners on every play button, card button and chapter list. */
const allControls = (frame) =>
	frame.evaluate(() => ({
		players: [...document.querySelectorAll('[data-epm-player] [data-epm-play]')].map(window.epmTestClicks),
		cards: [...document.querySelectorAll('[data-epm-card-play]')].map(window.epmTestClicks),
		chapters: [...document.querySelectorAll('[data-epm-chapters]')].map(window.epmTestClicks),
	}));
const onePerControl = (s) => s.players.length > 0 && [...s.players, ...s.cards, ...s.chapters].every((n) => n === 1);

/**
 * The editor flow of PLAY-01: every widget the editor renders is
 * initialized exactly once.
 */
async function editorFlow(label, engine) {
	const page = await openEditor(fx.elementor_page, engine);
	let s = await allControls(page.preview);
	assert(onePerControl(s) && s.cards.length > 0 && s.chapters.length > 0, `${label}: first render, one click listener per play button, card button and chapter list (${JSON.stringify(s)})`);

	const added = await editorRun(page, 'document/elements/create', 'c0ffee1', {
		model: { elType: 'widget', widgetType: 'epm-podcast-player', settings: { source: 'specific', episode_id: String(fx.ep2), layout: 'full' } },
	});
	let w = await rendered(page, added, (x) => x.present && x.episode === String(fx.ep2));
	assert(w.present && w.clicks === 1, `${label}: an inserted widget is initialized (${JSON.stringify(w)})`);

	await setSettings(page, added, { episode_id: String(fx.ep3) });
	w = await rendered(page, added, (x) => x.episode === String(fx.ep3));
	assert(w.episode === String(fx.ep3) && w.clicks === 1, `${label}: switching the episode re-initializes it (${JSON.stringify(w)})`);

	const copy = await editorRun(page, 'document/elements/duplicate', added);
	w = await rendered(page, copy, (x) => x.present && !!x.episode);
	assert(!!copy && w.present && w.clicks === 1, `${label}: a duplicate is initialized (${JSON.stringify({ copy, ...w })})`);

	await editorRun(page, 'document/elements/delete', added);
	w = await rendered(page, added, (x) => !x.present);
	assert(!w.present, `${label}: delete removes the widget`);
	await editorRun(page, 'document/history/undo');
	w = await rendered(page, added, (x) => x.present && !!x.episode);
	assert(w.present && w.clicks === 1, `${label}: undo restores it initialized (${JSON.stringify(w)})`);
	await editorRun(page, 'document/history/redo');
	w = await rendered(page, added, (x) => !x.present);
	assert(!w.present, `${label}: redo removes it again`);
	await editorRun(page, 'document/history/undo');
	w = await rendered(page, added, (x) => x.present && !!x.episode);
	assert(w.present && w.clicks === 1, `${label}: undo again (${JSON.stringify(w)})`);

	const seeded = await page.preview.evaluate(() => document.querySelector('.elementor-widget-epm-podcast-player').dataset.id);
	for (const layout of ['compact', 'editorial', 'full', 'artwork', 'minimal', 'full', 'compact', 'full', 'editorial', 'full']) {
		await setSettings(page, seeded, { layout });
		await rendered(page, seeded, (x) => x.layout === layout);
	}
	w = await widget(page, seeded);
	assert(w.layout === 'full' && w.clicks === 1, `${label}: after 10 re-renders the player has one click listener (${JSON.stringify(w)})`);
	const once = await playOnce(page, seeded);
	assert(once.controller && once.plays === 1 && once.pauses === 0 && once.playing && once.shown, `${label}: one click = one playback (${JSON.stringify(once)})`);
	s = await allControls(page.preview);
	assert(onePerControl(s), `${label}: at the end every control has one click listener (${JSON.stringify(s)})`);
	await noProblems(page, label);
}

try {
	// -----------------------------------------------------------------------
	console.log('Elementor hooks on their own (PLAY-01)');
	{
		// A widget Elementor reports ready after page load (popup, loop,
		// AJAX): fresh server markup, then Elementor's own ready trigger.
		const page = await open(`${BASE}/epm-elementor/`, { engine: HOOKS_ONLY });
		const late = await page.evaluate(async () => {
			const el = await window.epmTestFresh(location.href, '.elementor-widget-epm-podcast-player');
			el.id = 'epm-late';
			document.body.appendChild(el);
			window.elementorFrontend.elementsHandler.runReadyTrigger(el);
			await new Promise((r) => setTimeout(r, 300));
			return { clicks: window.epmTestClicks(el.querySelector('[data-epm-play]')) };
		});
		assert(late.clicks === 1, `the hook alone initializes a widget Elementor reports ready later (${JSON.stringify(late)})`);
		await page.click('#epm-late [data-epm-play]');
		await page.waitForTimeout(900);
		assert(await page.evaluate(() => document.querySelector('#epm-late [data-epm-player]').classList.contains('is-playing')), 'and it plays');
		await noProblems(page);
	}
	await editorFlow('editor, hooks only', HOOKS_ONLY);
	await editorFlow('editor, hooks and observer', null);

	// -----------------------------------------------------------------------
	console.log('One binding per control (PLAY-01, PLAY-N1)');
	{
		const page = await open(`${BASE}/epm-elementor/`);
		const counts = await page.evaluate(async () => {
			const widgets = [...document.querySelectorAll('[data-widget_type^="epm-"]')];
			for (let i = 0; i < 3; i++) {
				window.epmPlayerEngine.init(document);
			}
			widgets.forEach((w) => window.elementorFrontend.elementsHandler.runReadyTrigger(w));
			// Moving the widgets lets the MutationObserver see them again.
			widgets.forEach((w) => w.parentNode.appendChild(w));
			await new Promise((r) => setTimeout(r, 300));
			const n = (selector) => [...document.querySelectorAll(selector)].map(window.epmTestClicks);
			return {
				play: n('[data-epm-player] [data-epm-play]'),
				skip: n('[data-epm-seek-rel]'),
				speed: n('[data-epm-player] [data-epm-speed]'),
				cards: n('[data-epm-card-play]'),
				chapters: n('[data-epm-chapters]'),
				share: n('[data-epm-share-toggle]'),
			};
		});
		assert(Object.values(counts).every((list) => list.length > 0 && list.every((c) => c === 1)), `init() three times, the hooks and the observer: one click listener per control (${JSON.stringify(counts)})`);
		const once = await page.evaluate(async () => {
			const p = document.querySelector('.elementor-widget-epm-podcast-player [data-epm-player]');
			const c = window.epmPlayerEngine.getController(p.dataset.epmEpisodeId);
			let plays = 0;
			c.audio.addEventListener('play', () => plays++);
			p.querySelector('[data-epm-play]').click();
			await new Promise((r) => setTimeout(r, 900));
			return { plays, playing: c.isPlaying() };
		});
		assert(once.plays === 1 && once.playing, `one click = one playback (${JSON.stringify(once)})`);
		await noProblems(page);
	}

	// -----------------------------------------------------------------------
	console.log('Cloned DOM: Swiper loop duplicates (PLAY-N1)');
	{
		const page = await open(URLS.two);
		await page.addScriptTag({ url: `${BASE}/wp-content/plugins/elementor/assets/lib/swiper/v8/swiper.js` });
		const dup = await page.evaluate(async () => {
			// A looping carousel of a player, a chapter list and two cards.
			const slides = [document.querySelectorAll('[data-epm-player]')[1], document.querySelector('[data-epm-chapters]'), ...[...document.querySelectorAll('.epm-episode-card')].slice(0, 2)];
			const swiper = document.createElement('div');
			swiper.className = 'swiper';
			swiper.id = 'epm-carousel';
			swiper.style.width = '1200px';
			const wrapper = document.createElement('div');
			wrapper.className = 'swiper-wrapper';
			slides.forEach((el) => {
				const slide = document.createElement('div');
				slide.className = 'swiper-slide';
				slide.appendChild(el);
				wrapper.appendChild(slide);
			});
			swiper.appendChild(wrapper);
			document.body.prepend(swiper);
			new window.Swiper(swiper, { loop: true, slidesPerView: 4 });
			await new Promise((r) => setTimeout(r, 300));
			const clones = (selector) => [...swiper.querySelectorAll(`.swiper-slide-duplicate ${selector}`)].map(window.epmTestClicks);
			return { players: clones('[data-epm-play]'), chapters: clones('[data-epm-chapters]'), cards: clones('[data-epm-card-play]'), share: clones('[data-epm-share-toggle]') };
		});
		assert(dup.players.length > 0 && dup.chapters.length > 0 && dup.cards.length > 0, `the carousel cloned players, chapters and cards (${JSON.stringify(dup)})`);
		assert(Object.values(dup).every((list) => list.every((c) => c === 1)), `every clone is bound once (${JSON.stringify(dup)})`);
		const cardId = await page.evaluate(() => {
			const b = document.querySelector('#epm-carousel .swiper-slide-duplicate [data-epm-card-play]');
			b.click();
			return b.dataset.epmCardPlay;
		});
		await page.waitForTimeout(900);
		assert((await episodeState(page, cardId)).playing, 'a cloned card button plays its episode');
		await page.evaluate(() => document.querySelector('#epm-carousel .swiper-slide-duplicate [data-epm-player] [data-epm-play]').click());
		await page.waitForTimeout(900);
		assert((await episodeState(page, EP1)).playing && !(await episodeState(page, cardId)).playing, 'a cloned player plays its episode');
		await page.evaluate(() => document.querySelector('#epm-carousel .swiper-slide-duplicate [data-epm-chapters] [data-epm-seek="70"]').click());
		await page.waitForTimeout(600);
		const t = (await episodeState(page, EP1)).t;
		assert(t >= 70 && t < 75, `a cloned chapter list seeks (${t})`);
		await noProblems(page);
	}

	// -----------------------------------------------------------------------
	console.log('Source changes and re-renders (PLAY-02, PLAY-N2, PLAY-N11)');
	for (const broken of [false, true]) {
		const label = broken ? 'A failed (404), then fixed with B' : 'A, then B';
		const page = await open(`${BASE}/epm-elementor/`, {
			init: [MEDIA_SESSION],
			routes: broken ? [[/epm-episode-1\.mp3/, (route) => route.fulfill({ status: 404, body: 'gone' })]] : [],
		});
		await page.click('.elementor-widget-epm-podcast-player [data-epm-play]');
		await page.waitForTimeout(1000);
		if (broken) {
			const failed = await episodeState(page, EP1);
			assert(failed.error && failed.players.some((p) => p.error) && failed.sticky.error, `${label}: the broken source shows its error (${JSON.stringify(failed)})`);
		} else {
			await page.click('.elementor-widget-epm-podcast-player [data-epm-play]');
			await page.waitForTimeout(300);
		}
		// The episode gets another audio file and title; Elementor (or any
		// AJAX loader) re-renders the widget from the server.
		changeEpisodeOne(true);
		try {
			await page.evaluate(async () => {
				const el = await window.epmTestFresh(location.href, '.elementor-widget-epm-podcast-player');
				document.querySelector('.elementor-widget-epm-podcast-player').innerHTML = el.innerHTML;
			});
		} finally {
			changeEpisodeOne(false);
		}
		await page.waitForTimeout(500);
		await page.click('.elementor-widget-epm-podcast-player [data-epm-play]');
		await page.waitForTimeout(1500);
		const s = await episodeState(page, EP1);
		assert(/epm-episode-2\.mp3$/.test(s.src) && s.playing, `${label}: play uses B (${s.src})`);
		assert(Math.round(s.duration) === 150 && s.players[0].total === '2:30', `${label}: duration from B (${s.duration}, ${s.players[0].total})`);
		assert(!s.error && s.players.every((p) => !p.error) && s.cards.every((c) => !/has-error/.test(c)) && !s.sticky.error, `${label}: no view shows an error (${JSON.stringify(s)})`);
		assert(s.sticky.visible && s.sticky.title === 'Episode One, new audio', `${label}: the sticky bar shows B's title (${s.sticky.title})`);
		assert(s.session && s.session.title === 'Episode One, new audio', `${label}: Media Session shows B's title (${JSON.stringify(s.session)})`);
		await noProblems(page, label);
	}
	{
		// Re-rendering the player whose audio plays, then the other one.
		const page = await open(URLS.two);
		await page.click('[data-epm-player] [data-epm-play]');
		await page.waitForTimeout(900);
		for (const nth of [0, 1]) {
			const r = await page.evaluate(async (nth) => {
				const players = [...document.querySelectorAll('[data-epm-player]')];
				const c = window.epmPlayerEngine.getController(players[0].dataset.epmEpisodeId);
				let pauses = 0;
				c.audio.addEventListener('pause', () => pauses++);
				const el = await window.epmTestFresh(location.href, '[data-epm-player]', nth);
				players[nth].replaceWith(el);
				await new Promise((res) => setTimeout(res, 800));
				return { playing: c.isPlaying(), pauses, shown: el.classList.contains('is-playing') };
			}, nth);
			assert(r.playing && r.pauses === 0 && r.shown, `re-rendering player ${nth + 1} while it plays keeps playing, and the new view shows it (${JSON.stringify(r)})`);
		}
		// 40 re-renders while paused: removed views are released right away.
		await page.evaluate(() => window.epmPlayerEngine.getController(document.querySelector('[data-epm-player]').dataset.epmEpisodeId).pause());
		await page.waitForTimeout(300);
		const views = await page.evaluate(async () => {
			const source = await window.epmTestFresh(location.href, '[data-epm-player]');
			for (let i = 0; i < 40; i++) {
				document.querySelector('[data-epm-player]').replaceWith(source.cloneNode(true));
				await new Promise((r) => setTimeout(r, 20));
			}
			await new Promise((r) => setTimeout(r, 200));
			const c = window.epmPlayerEngine.getController(source.dataset.epmEpisodeId);
			return { views: c.views.length, connected: c.views.filter((v) => v.el.isConnected).length };
		});
		assert(views.views === views.connected && views.views <= 4, `after 40 re-renders only connected views are kept (${JSON.stringify(views)})`);
		await noProblems(page);
	}
	{
		// A player switched to another episode: the old controller goes.
		const page = await open(URLS.ep2);
		const r = await page.evaluate(async ({ url, before }) => {
			const el = await window.epmTestFresh(url, '[data-epm-player]');
			const had = !!window.epmPlayerEngine.getController(before);
			document.querySelector('[data-epm-player]').replaceWith(el);
			await new Promise((res) => setTimeout(res, 300));
			return { had, old: !!window.epmPlayerEngine.getController(before), now: !!window.epmPlayerEngine.getController(el.dataset.epmEpisodeId) };
		}, { url: URLS.ep3, before: String(fx.ep2) });
		assert(r.had && !r.old && r.now, `a controller left without views on the page is released (${JSON.stringify(r)})`);
		await noProblems(page);
	}
	{
		// Playback started from a chapter list has the episode's artwork.
		const page = await open(URLS.chapters_cards, { init: [MEDIA_SESSION] });
		await page.click('[data-epm-chapters] [data-epm-seek="30"]');
		await page.waitForTimeout(1000);
		const s = await episodeState(page, EP1);
		assert(s.playing && s.sticky.visible && s.sticky.artwork, `chapter-first playback: the sticky bar shows the artwork (${JSON.stringify(s.sticky)})`);
		assert(s.session && s.session.artwork > 0, `chapter-first playback: Media Session has artwork (${JSON.stringify(s.session)})`);
		await noProblems(page);
	}
	{
		// Remote audio: still nothing before the first press, re-render included.
		const page = await open(URLS.remote_known);
		await page.evaluate(async () => {
			const el = await window.epmTestFresh(location.href, '[data-epm-player]');
			document.querySelector('[data-epm-player]').replaceWith(el);
			await new Promise((r) => setTimeout(r, 300));
		});
		await page.focus('[data-epm-player] [data-epm-timeline]');
		await page.keyboard.press('ArrowRight');
		await page.keyboard.press('ArrowRight');
		await page.waitForTimeout(400);
		assert(page.remote.length === 0, `remote audio: no request after load, a re-render and seeking (${page.remote.join(', ')})`);
		await page.click('[data-epm-player] [data-epm-play]');
		await page.waitForTimeout(1500);
		const t = await page.evaluate(() => window.epmPlayerEngine.getController(document.querySelector('[data-epm-player]').dataset.epmEpisodeId).audio.currentTime);
		assert(page.remote.length > 0 && t >= 9.5 && t < 13, `the first press loads it and starts at the position chosen before (${t.toFixed(1)}s)`);
		await noProblems(page);
	}

	// -----------------------------------------------------------------------
	console.log('Re-renders in the Elementor editor (PLAY-02, PLAY-N2, PLAY-N10)');
	{
		const page = await openEditor(made.ids.widgets);
		const pv = page.preview;
		const state = () => pv.evaluate((id) => {
			const c = window.epmPlayerEngine.getController(id);
			const sticky = document.querySelector('[data-epm-sticky]');
			return {
				playing: c ? c.isPlaying() : null,
				src: c ? c.audio.currentSrc || c.audio.src : null,
				duration: c ? Math.round(c.getDuration()) : null,
				shown: [...document.querySelectorAll('[data-epm-player]')].map((p) => p.classList.contains('is-playing')),
				sticky: !!sticky && !sticky.hidden,
			};
		}, EP1);
		await pv.evaluate(() => document.querySelector('.elementor-element-e0e0e02 [data-epm-play]').click());
		await page.waitForTimeout(1000);
		await setSettings(page, 'e0e0e02', { layout: 'compact' });
		await rendered(page, 'e0e0e02', (x) => x.layout === 'compact');
		let s = await state();
		assert(s.playing && s.shown.length === 2 && s.shown.every(Boolean), `changing a control of the playing widget keeps it playing (${JSON.stringify(s)})`);

		// The settings change reaches the history after a debounce; deleting
		// before that would interleave the history steps.
		await page.waitForTimeout(2500);
		await editorRun(page, 'document/elements/delete', 'e0e0e02');
		await rendered(page, 'e0e0e02', (x) => !x.present);
		await editorRun(page, 'document/elements/delete', 'e0e0e03');
		await rendered(page, 'e0e0e03', (x) => !x.present);
		await page.waitForTimeout(1000);
		s = await state();
		assert(s.playing === false || s.playing === null, `deleting every view of a playing episode (no sticky bar) stops it (${JSON.stringify(s)})`);
		// Elementor needs a moment between history steps.
		await page.waitForTimeout(1500);
		await editorRun(page, 'document/history/undo');
		await rendered(page, 'e0e0e03', (x) => x.present && !!x.episode);
		await page.waitForTimeout(1500);
		await editorRun(page, 'document/history/undo');
		await rendered(page, 'e0e0e02', (x) => x.present && !!x.episode);
		await page.waitForTimeout(1000);

		// The episode's audio is replaced in WordPress, then the widget re-renders.
		changeEpisodeOne(true);
		try {
			await setSettings(page, 'e0e0e02', { layout: 'full' });
			await rendered(page, 'e0e0e02', (x) => x.layout === 'full');
		} finally {
			changeEpisodeOne(false);
		}
		await pv.evaluate(() => document.querySelector('.elementor-element-e0e0e02 [data-epm-play]').click());
		await page.waitForTimeout(1500);
		s = await state();
		assert(/epm-episode-2\.mp3$/.test(s.src) && s.playing && s.duration === 150, `after the audio was replaced, the re-rendered widget plays the new file (${JSON.stringify(s)})`);
		await pv.evaluate((id) => window.epmPlayerEngine.getController(id).pause(), EP1);

		// Sticky turned on in the editor: the preview has the bar.
		await setSettings(page, 'e0e0e03', { sticky: 'yes' });
		await page.waitForTimeout(3000);
		await pv.evaluate(() => document.querySelector('.elementor-element-e0e0e03 [data-epm-play]').click());
		await page.waitForTimeout(1000);
		s = await state();
		assert(s.sticky && s.playing, `turning on the sticky player in the editor shows the bar in the preview (${JSON.stringify(s)})`);
		await noProblems(page);
	}

	// -----------------------------------------------------------------------
	console.log('Volume (PLAY-03, PLAY-N9)');
	{
		const page = await open(URLS.two);
		const volume = () =>
			page.evaluate(() => {
				const players = [...document.querySelectorAll('[data-epm-player]')];
				const c = window.epmPlayerEngine.getController(players[0].dataset.epmEpisodeId);
				return {
					sliders: players.map((p) => p.querySelector('[data-epm-volume]').value),
					text: players.map((p) => p.querySelector('[data-epm-volume]').getAttribute('aria-valuetext')),
					volume: c.audio.volume,
					muted: c.audio.muted,
					events: window.epmTestVolumeEvents || 0,
				};
			});
		await page.evaluate(() => {
			const c = window.epmPlayerEngine.getController(document.querySelector('[data-epm-player]').dataset.epmEpisodeId);
			c.audio.addEventListener('volumechange', () => { window.epmTestVolumeEvents = (window.epmTestVolumeEvents || 0) + 1; });
		});
		const sliderA = page.locator('[data-epm-player] [data-epm-volume]').nth(0);
		await sliderA.focus();
		await page.keyboard.press('ArrowLeft');
		let v = await volume();
		assert(v.text[0] === '95%' && v.text[1] === '95%', `the volume is spoken as a percentage (${v.text.join(', ')})`);
		for (let i = 0; i < 5; i++) {
			await page.keyboard.press('ArrowLeft');
		}
		await page.waitForTimeout(200);
		v = await volume();
		assert(v.sliders[0] === '0.7' && v.sliders[1] === '0.7' && Math.abs(v.volume - 0.7) < 0.001, `the keyboard on slider A moves slider B and the audio (${JSON.stringify(v)})`);
		assert(v.events === 6, `one volume change per step, no loop between views (${v.events} events for 6 steps)`);
		const box = await sliderA.boundingBox();
		await page.mouse.click(box.x + 1, box.y + box.height / 2);
		await page.waitForTimeout(200);
		v = await volume();
		assert(v.sliders[0] === '0' && v.sliders[1] === '0', `the pointer on slider A moves slider B (${v.sliders.join(', ')})`);
		await page.evaluate(() => {
			const c = window.epmPlayerEngine.getController(document.querySelector('[data-epm-player]').dataset.epmEpisodeId);
			c.audio.volume = 0.3;
			c.audio.muted = true;
		});
		await page.waitForTimeout(200);
		v = await volume();
		assert(v.sliders.every((x) => x === '0') && v.text.every((x) => x === '0%'), `a native mute shows on every slider (${JSON.stringify(v)})`);
		await page.evaluate(() => {
			window.epmPlayerEngine.getController(document.querySelector('[data-epm-player]').dataset.epmEpisodeId).audio.muted = false;
		});
		await page.waitForTimeout(200);
		v = await volume();
		assert(v.sliders.every((x) => x === '0.3') && v.text.every((x) => x === '30%'), `a native volume change shows on every slider (${JSON.stringify(v)})`);
		await page.evaluate(() => {
			window.epmPlayerEngine.getController(document.querySelector('[data-epm-player]').dataset.epmEpisodeId).audio.muted = true;
		});
		await page.locator('[data-epm-player] [data-epm-volume]').nth(1).focus();
		await page.keyboard.press('ArrowRight');
		await page.waitForTimeout(200);
		v = await volume();
		assert(!v.muted && v.volume > 0 && v.sliders[0] === v.sliders[1] && v.sliders[0] !== '0', `raising the volume unmutes (${JSON.stringify(v)})`);
		await noProblems(page);
	}
	{
		// iOS: the volume belongs to the device; setting it has no effect.
		const READ_ONLY = `Object.defineProperty(HTMLMediaElement.prototype, 'volume', { configurable: true, get() { return 1; }, set(value) {} });`;
		const page = await open(URLS.two, { init: [READ_ONLY] });
		const shown = await page.evaluate(() => [...document.querySelectorAll('[data-epm-player] [data-epm-volume]')].map((input) => input.getClientRects().length > 0));
		assert(shown.length === 2 && shown.every((x) => !x), `a read-only volume hides the volume sliders (${shown.join(', ')})`);
		await noProblems(page);
	}
	{
		// Touch: seek and volume sliders keep a 24px hit area; the track stays 4px.
		const page = await open(URLS.two, { viewport: { width: 390, height: 844 }, options: { hasTouch: true, isMobile: true } });
		const hits = await page.evaluate(() => {
			const probe = (selector) => {
				const el = document.querySelector(selector);
				el.scrollIntoView({ block: 'center' });
				const r = el.getBoundingClientRect();
				const x = r.left + r.width / 2;
				const y = r.top + r.height / 2;
				const at = (dy) => {
					const hit = document.elementFromPoint(x, y + dy);
					return !!hit && (hit === el || el.contains(hit));
				};
				return { up: at(-11.5), down: at(11.5) };
			};
			return {
				timeline: probe('[data-epm-player] [data-epm-timeline]'),
				volume: probe('[data-epm-player] [data-epm-volume]'),
				track: Math.round(document.querySelector('[data-epm-player] .epm-player__track').getBoundingClientRect().height),
			};
		});
		assert(hits.timeline.up && hits.timeline.down && hits.volume.up && hits.volume.down, `touch: the seek and volume sliders take touches 12px above and below their centre (${JSON.stringify(hits)})`);
		assert(hits.track === 4, `touch: the visible track keeps its 4px (${hits.track})`);
		await noProblems(page);
	}

	// -----------------------------------------------------------------------
	console.log('Speed, resume and timestamp links (PLAY-N3, PLAY-N4, PLAY-N5)');
	{
		const page = await open(`${BASE}/epm-elementor/`);
		await page.click('.elementor-widget-epm-podcast-player [data-epm-speed]');
		const other = '.elementor-widget-epm-latest-episode [data-epm-player]';
		const label = await page.textContent(`${other} [data-epm-speed-value]`);
		await page.click(`${other} [data-epm-play]`);
		await page.waitForTimeout(800);
		const rate = await page.evaluate((sel) => window.epmPlayerEngine.getController(document.querySelector(sel).dataset.epmEpisodeId).audio.playbackRate, other);
		assert(label === '1.25×' && rate === 1.25, `the chosen speed applies to another episode's player on the page (${label}, ${rate})`);
		await noProblems(page);
	}
	{
		const page = await open(URLS.ep1);
		const stored = () => page.evaluate((id) => localStorage.getItem(`epm:pos:${id}`), EP1);
		await page.evaluate((id) => localStorage.setItem(`epm:pos:${id}`, '40'), EP1);
		await page.goto(`${URLS.ep1}?t=20`);
		await page.waitForTimeout(1000);
		assert((await page.textContent('[data-epm-player] [data-epm-current]')) === '0:20' && (await stored()) === '40', `opening a ?t= link without playing keeps the remembered position (${await stored()})`);
		await page.focus('[data-epm-player] [data-epm-timeline]');
		await page.keyboard.press('ArrowRight');
		await page.waitForTimeout(300);
		assert((await stored()) === '40', `seeking before playing keeps it too (${await stored()})`);
		await page.click('[data-epm-player] [data-epm-play]');
		await page.waitForTimeout(1200);
		await page.click('[data-epm-player] [data-epm-play]');
		await page.waitForTimeout(300);
		const now = Number(await stored());
		assert(now >= 25 && now < 30, `after playing, the position is remembered (${now})`);
		await noProblems(page);
	}
	for (const [where, t] of [['ep1', '99999999'], ['ep1', '95'], ['ep1', '10000h'], ['remote_unknown', '99999']]) {
		const page = await open(`${URLS[where]}?t=${t}`);
		const before = await page.evaluate(() => ({
			current: document.querySelector('[data-epm-player] [data-epm-current]').textContent,
			label: document.querySelector('[data-epm-player] [data-epm-play]').getAttribute('aria-label'),
		}));
		await page.click('[data-epm-player] [data-epm-play]');
		await page.waitForTimeout(1500);
		const after = await page.evaluate(() => {
			const c = window.epmPlayerEngine.getController(document.querySelector('[data-epm-player]').dataset.epmEpisodeId);
			return { t: +c.audio.currentTime.toFixed(1), ended: c.audio.ended, playing: c.isPlaying() };
		});
		assert(before.current === '0:00' && before.label === 'Play episode' && after.t < 5 && !after.ended && after.playing, `?t=${t} on ${where} is out of range: no hint, playback starts at 0 (${JSON.stringify({ before, after })})`);
		await noProblems(page);
	}
	{
		// Unknown duration: a position past the end is dropped once the file says so.
		const page = await open(`${URLS.remote_unknown}?t=70`);
		await page.click('[data-epm-player] [data-epm-play]');
		await page.waitForTimeout(1800);
		const after = await page.evaluate(() => {
			const p = document.querySelector('[data-epm-player]');
			const c = window.epmPlayerEngine.getController(p.dataset.epmEpisodeId);
			return { t: +c.audio.currentTime.toFixed(1), ended: c.audio.ended, playing: c.isPlaying() };
		});
		assert(after.t < 5 && !after.ended && after.playing, `?t= past the end of a file without a known duration starts at 0 (${JSON.stringify(after)})`);
		await noProblems(page);
		const valid = await open(`${URLS.remote_unknown}?t=30`);
		const label = await valid.getAttribute('[data-epm-player] [data-epm-play]', 'aria-label');
		await valid.click('[data-epm-player] [data-epm-play]');
		await valid.waitForTimeout(1800);
		const t = await valid.evaluate(() => window.epmPlayerEngine.getController(document.querySelector('[data-epm-player]').dataset.epmEpisodeId).audio.currentTime);
		assert(label === 'Play episode, Starts at 0:30' && t >= 30 && t < 34, `a valid ?t= still cues it (${label}, ${t.toFixed(1)}s)`);
		await noProblems(valid);
	}

	// -----------------------------------------------------------------------
	console.log('Media Session and keys (PLAY-N6, PLAY-N7)');
	{
		const page = await open(URLS.two, { init: [MEDIA_SESSION] });
		await page.click('[data-epm-player] [data-epm-play]');
		await page.waitForTimeout(1000);
		const r = await page.evaluate(async () => {
			const wait = (ms) => new Promise((res) => setTimeout(res, ms));
			const session = window.epmTestSession;
			const c = window.epmPlayerEngine.getController(document.querySelector('[data-epm-player]').dataset.epmEpisodeId);
			const n0 = session.positions.length;
			session.handlers.seekto({ action: 'seekto', seekTime: 70 });
			await wait(400);
			const single = { count: session.positions.length - n0, last: session.positions[session.positions.length - 1], t: c.audio.currentTime };
			const n1 = session.positions.length;
			for (let i = 0; i < 10; i++) {
				session.handlers.seekto({ action: 'seekto', seekTime: 10 + i * 5 });
				await wait(10);
			}
			await wait(600);
			const burst = { count: session.positions.length - n1, last: session.positions[session.positions.length - 1], t: c.audio.currentTime };
			c.pause();
			return { single, burst };
		});
		assert(r.single.count >= 1 && Math.abs(r.single.last.position - r.single.t) < 0.6, `a seek updates the lock-screen position (${JSON.stringify(r.single)})`);
		assert(r.burst.count >= 1 && r.burst.count <= 4 && Math.abs(r.burst.last.position - r.burst.t) < 0.6, `ten quick seeks: few updates, the last one right (${JSON.stringify(r.burst)})`);

		for (const selector of ['[data-epm-player] [data-epm-timeline]', '[data-epm-sticky] [data-epm-timeline]']) {
			// Mid-page, so an arrow key the slider does not handle scrolls.
			const keys = await page.evaluate((selector) => {
				document.querySelector(selector).focus({ preventScroll: true });
				window.scrollTo(0, 300);
				return window.scrollY;
			}, selector);
			const t0 = Number(await page.getAttribute(selector, 'aria-valuenow'));
			await page.keyboard.press('ArrowUp');
			const up = Number(await page.getAttribute(selector, 'aria-valuenow'));
			await page.keyboard.press('ArrowDown');
			await page.keyboard.press('ArrowDown');
			const down = Number(await page.getAttribute(selector, 'aria-valuenow'));
			const y = await page.evaluate(() => window.scrollY);
			assert(up === t0 + 5 && down === t0 - 5 && y === keys, `${selector.split(' ')[0]} timeline: ArrowUp/ArrowDown seek ±5 s and do not scroll (${t0} → ${up} → ${down}, scroll ${keys} → ${y})`);
		}
		await noProblems(page);
	}

	// -----------------------------------------------------------------------
	console.log('Sticky bar (PLAY-N8, PLAY-N10, WID-N5)');
	{
		// Landscape phone with a notch: the bar keeps its content inside the safe area.
		for (const insets of [true, false]) {
			const page = await open(null, { viewport: { width: 844, height: 390 }, options: { hasTouch: true, isMobile: true } });
			if (insets) {
				const cdp = await page.context().newCDPSession(page);
				await cdp.send('Emulation.setSafeAreaInsetsOverride', { insets: { top: 0, left: 47, right: 47, bottom: 21 } });
			}
			await page.route(URLS.ep1, async (route) => {
				const res = await route.fetch();
				const body = (await res.text()).replace(/(<meta name="viewport" content="[^"]*)"/, '$1, viewport-fit=cover"');
				await route.fulfill({ response: res, body });
			});
			await page.goto(URLS.ep1);
			await page.tap('[data-epm-player] [data-epm-play]');
			await page.waitForTimeout(700);
			const m = await page.evaluate(() => {
				const bar = document.querySelector('[data-epm-sticky]');
				const art = bar.querySelector('[data-epm-sticky-artwork]').getBoundingClientRect();
				const close = bar.querySelector('[data-epm-sticky-close]').getBoundingClientRect();
				return { left: Math.round(art.left), right: Math.round(innerWidth - close.right), bottom: getComputedStyle(bar).paddingBottom };
			});
			if (insets) {
				assert(m.left >= 47 && m.right >= 47 && parseFloat(m.bottom) >= 31, `with left/right/bottom insets the bar's content stays clear of them (${JSON.stringify(m)})`);
			} else {
				assert(m.left === 20 && m.right === 20 && m.bottom === '10px', `without insets the bar keeps its 20px padding (${JSON.stringify(m)})`);
			}
			await noProblems(page);
		}
	}
	{
		// A sticky player and the bar's shell inserted after load (AJAX).
		const page = await open(URLS.no_shell);
		assert((await page.locator('[data-epm-sticky]').count()) === 0, 'a page without a sticky player has no shell');
		await page.evaluate(async (two) => {
			const player = await window.epmTestFresh(two, '[data-epm-player]');
			player.id = 'epm-late-sticky';
			document.querySelector('[data-epm-player]').after(player);
			document.body.appendChild(await window.epmTestFresh(two, '[data-epm-sticky]'));
		}, URLS.two);
		await page.waitForTimeout(300);
		await page.click('#epm-late-sticky [data-epm-play]');
		await page.waitForTimeout(900);
		const s = await episodeState(page, EP1);
		assert(s.playing && s.sticky && s.sticky.visible && s.sticky.title.startsWith('Episode One'), `a shell that appears later is used (${JSON.stringify(s.sticky)})`);
		await noProblems(page);
	}
	{
		// Player with the sticky option off, next to a list (which brings the shell).
		const page = await open(URLS.plain_list);
		const bar = page.locator('[data-epm-sticky]');
		await page.click('[data-epm-player] [data-epm-play]');
		await page.waitForTimeout(900);
		assert((await episodeState(page, EP1)).playing && (await bar.isHidden()), 'a player with the sticky option off does not open the bar');
		await page.click(`[data-epm-card-play="${fx.ep2}"]`);
		await page.waitForTimeout(900);
		assert((await bar.isVisible()) && (await page.textContent('[data-epm-sticky-title]')) === 'Episode Two', 'a list play button opens it');
		await page.click('[data-epm-player] [data-epm-play]');
		await page.waitForTimeout(900);
		await bar.waitFor({ state: 'hidden', timeout: 2000 }).catch(() => {});
		assert((await episodeState(page, EP1)).playing && (await bar.isHidden()), 'starting that player again closes the bar instead of showing its episode');
		await noProblems(page);
	}
} finally {
	await browser.close();
	php(`foreach ( ${JSON.stringify(Object.values(made.ids))} as $id ) { wp_delete_post( (int) $id, true ); } echo wp_json_encode( 1 );`);
}

finish('player');
