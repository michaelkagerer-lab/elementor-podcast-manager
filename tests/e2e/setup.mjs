/**
 * Browser tests for getting a show onto the site: the activation redirect,
 * the setup assistant (keep a host, host here, move a locked show), the
 * Hosting & import screen and the Distribution screen.
 *
 *   cd tests/e2e && WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp node setup.mjs
 *
 * Feeds come from the test HTTP fixtures (tests/fixtures/mu-plugins/
 * epm-test-http.php, installed by tests/bin/setup-wp.sh), so the site
 * never leaves localhost. Each scenario starts from a site without a
 * podcast; at the end the fixtures are seeded again and every option
 * touched here is put back. Screenshots land in tests/e2e/screenshots/.
 */
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE, wp, php, assert, finish, launch, newPage, login, noOverflow, focused, focusRingVisible, tabTo } from './lib.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const LOCKED_FEED = 'https://feeds.example.test/synthetic/locked-show.xml';
const PAGED_FEED = 'https://feeds.example.test/synthetic/paged-1.xml';
const LISTING = 'https://pca.st/itunes/epm-e2e-show';
const OPTIONS = ['epm_hosting', 'epm_sync_state', 'epm_import_job', 'epm_import_lock', 'epm_setup', 'epm_distribution', 'epm_podcast_guid', 'epm_activation_redirect', 'epm_design_settings', 'epm_feed_build'];
const phpList = (values) => `[ ${values.map((v) => `'${v}'`).join(', ')} ]`;

// What the run changes, to put back at the end.
const saved = php(`echo wp_json_encode( array_map( static function ( $name ) { return get_option( $name, '__epm_absent__' ); }, array_combine( ${phpList(OPTIONS)}, ${phpList(OPTIONS)} ) ) )`);

/**
 * A site without a podcast: no episodes, no settings, no setup progress.
 * Pages the assistant created and media copied by imports are removed.
 */
function fresh() {
	php(`
		EPM\\ImportJob::cancel();
		EPM\\ImportJob::release_lock();
		$state = EPM\\AdminPages::setup_state();
		if ( $state['page_id'] > 0 ) { wp_delete_post( $state['page_id'], true ); }
		foreach ( get_posts( [ 'post_type' => 'podcast_episode', 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ], 'posts_per_page' => -1, 'fields' => 'ids' ] ) as $id ) { wp_delete_post( $id, true ); }
		foreach ( get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_epm_source_url' ] ) as $id ) { wp_delete_attachment( $id, true ); }
		foreach ( [ EPM\\PodcastSettings::OPTION, EPM\\Hosting::OPTION, EPM\\Hosting::STATE_OPTION, EPM\\ImportJob::OPTION, 'epm_import_lock', EPM\\AdminPages::SETUP_OPTION, EPM\\Directories::OPTION, EPM\\Feed::GUID_OPTION, EPM\\DesignSettings::OPTION, EPM\\AdminPages::REDIRECT_OPTION ] as $name ) { delete_option( $name ); }
		wp_clear_scheduled_hook( EPM\\Hosting::CRON_HOOK );
		wp_clear_scheduled_hook( EPM\\ImportJob::CRON_HOOK );
		EPM\\Feed::flush_cache();
		echo wp_json_encode( true )
	`);
}

/** The panel that has focus (the assistant moves focus to each new step). */
const focusedPanel = (page) => page.evaluate(() => (document.activeElement && document.activeElement.getAttribute('data-panel')) || '');

/** Visible step names in the progress list. */
const visibleSteps = (page) => page.$$eval('[data-epm-steps] [data-step]', (items) => items.filter((i) => !i.hidden).map((i) => i.getAttribute('data-step')));

/** Text of the visible parts of an element (data-path-only spans hidden). */
const visibleText = (page, selector) =>
	page.$eval(selector, (el) => {
		const walk = (node) => {
			if (node.nodeType === Node.TEXT_NODE) {
				return node.textContent;
			}
			if (node.nodeType !== Node.ELEMENT_NODE || node.hidden) {
				return '';
			}
			return Array.from(node.childNodes).map(walk).join('');
		};
		return walk(el).replace(/\s+/g, ' ').trim();
	});

/** Status and Location of a request, without following redirects. */
async function head(url) {
	const response = await fetch(url, { redirect: 'manual' });
	return `${response.status} ${response.headers.get('location') || ''}`.trim();
}

const browser = await launch();

// ---------------------------------------------------------------------------
console.log('Activation');
{
	fresh();
	wp(['plugin', 'deactivate', 'elementor-podcast-manager', '--quiet']);
	wp(['plugin', 'activate', 'elementor-podcast-manager', '--quiet']);
	const page = await newPage(browser);
	await login(page);
	assert(/page=epm-setup/.test(page.url()), `the first admin page after activation opens the setup assistant (${page.url()})`);
	await page.goto(`${BASE}/wp-admin/`);
	assert(!/page=epm-setup/.test(page.url()), 'only once');

	php(`update_option( EPM\\PodcastSettings::OPTION, [ 'title' => 'Configured show' ] ); add_option( EPM\\AdminPages::REDIRECT_OPTION, 1, '', false ); echo wp_json_encode( true )`);
	await page.goto(`${BASE}/wp-admin/`);
	assert(!/page=epm-setup/.test(page.url()), 'a site with a podcast is not redirected');
	assert(php(`echo wp_json_encode( get_option( EPM\\AdminPages::REDIRECT_OPTION ) )`) === false, 'the redirect flag is used up either way');
	assert((await head(`${BASE}/podcast/feed/`)).startsWith('200'), 'the feed still answers after reactivation');
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
console.log('Setup assistant: keep the current host (keyboard)');
{
	fresh();
	const page = await newPage(browser);
	await login(page);
	await page.goto(`${BASE}/wp-admin/admin.php?page=epm-setup`);
	assert(JSON.stringify(await visibleSteps(page)) === JSON.stringify(['path', 'connect', 'import', 'show', 'look', 'done']), 'before a choice, the progress shows the full journey');
	await page.screenshot({ path: 'screenshots/setup-path.png', fullPage: true });

	// Step 1 with the keyboard only: WordPress's skip link, then Tab.
	await tabTo(page, () => document.activeElement && document.activeElement.getAttribute('href') === '#wpbody-content', { max: 5 });
	await page.keyboard.press('Enter');
	const reached = await tabTo(page, () => document.activeElement && document.activeElement.name === 'path', { max: 20 });
	assert(reached, 'Tab reaches the hosting choices');
	assert(await focusRingVisible(page), `the focused choice shows a focus ring (${await focused(page)})`);
	await tabTo(page, () => document.activeElement && document.activeElement.type === 'submit' && !!document.activeElement.closest('[data-step-form="path"]'));
	await page.keyboard.press('Enter');
	await page.waitForTimeout(200);
	const pathError = page.locator('[data-panel="path"] [data-error]');
	assert(await pathError.isVisible(), `continuing without a choice says what to do ("${(await pathError.textContent()) || ''}")`);
	assert((await focusedPanel(page)) === '', 'and stays on step 1');
	const told = await page.evaluate(() => ({
		live: document.querySelector('[data-epm-announce]').textContent,
		error: document.querySelector('#epm-setup-path-error').textContent,
		described: document.querySelector('[data-step-form="path"] fieldset').getAttribute('aria-describedby'),
		focus: document.activeElement && document.activeElement.name,
	}));
	assert(told.live === told.error && told.live.length > 0, `the error is announced ("${told.live}")`);
	assert(told.described === 'epm-setup-path-error', 'and tied to the choices');
	assert(told.focus === 'path', `focus goes to the choices (${told.focus})`);
	// Arrow keys move through the choices (and select); Space selects the focused one.
	for (let i = 0; i < 4; i++) {
		const current = await page.evaluate(() => document.activeElement.value);
		if (current === 'external') {
			if (!(await page.isChecked('input[name="path"][value="external"]'))) {
				await page.keyboard.press('Space');
			}
			break;
		}
		await page.keyboard.press('ArrowDown');
	}
	assert(await page.isChecked('input[name="path"][value="external"]'), 'arrow keys choose "Keep my current host"');
	await tabTo(page, () => document.activeElement && document.activeElement.type === 'submit' && !!document.activeElement.closest('[data-step-form="path"]'));
	assert(await focusRingVisible(page), 'Continue shows a focus ring');
	await page.keyboard.press('Enter');
	await page.waitForSelector('[data-panel="connect"]:not([hidden])');
	assert((await focusedPanel(page)) === 'connect', `focus moves to step 2 (${await focused(page)})`);
	assert(!(await pathError.isVisible()), 'the error is gone');
	assert((await visibleText(page, '#epm-setup-connect-title')) === 'Which host publishes your podcast?', 'step 2 asks for the host');
	await page.waitForTimeout(200);
	const stepSaid = await page.textContent('[data-epm-announce]');
	assert(stepSaid === 'Step 2 of 6: Which host publishes your podcast?', `the step is announced with its visible title only ("${stepSaid}")`);
	assert(/step=connect/.test(page.url()), 'the step is kept in the address');

	// Step 2: paste the feed, check it with Enter.
	await tabTo(page, () => document.activeElement && document.activeElement.id === 'epm-setup-feed');
	await page.keyboard.type(LOCKED_FEED);
	await page.keyboard.press('Enter');
	await page.waitForSelector('[data-panel="connect"] [data-preview]:not([hidden])', { timeout: 30000 });
	assert((await page.textContent('[data-preview-title]')) === 'Synthetic Show', 'the preview names the show');
	assert((await page.textContent('[data-preview-episodes]')) === '5', 'and counts its episodes');
	await page.waitForTimeout(200);
	const found = await page.textContent('[data-epm-announce]');
	assert(found === 'Synthetic Show: 5 episodes found.', `the preview is announced as a sentence ("${found}")`);
	assert(await page.locator('[data-preview-locked]').isHidden(), 'a lock does not matter when the host stays');
	assert(/\b1\b/.test((await page.textContent('[data-preview-notes]')) || ''), 'notes mention the hidden and duplicate episodes');
	const importLabel = ((await page.textContent('[data-import-button]')) || '').trim();
	assert(importLabel === 'Import 5 episodes', `the button says what it does ("${importLabel}")`);
	await page.screenshot({ path: 'screenshots/setup-connect-preview.png', fullPage: true });

	await tabTo(page, () => document.activeElement && document.activeElement.hasAttribute('data-import-button'));
	await page.keyboard.press('Enter');
	await page.waitForSelector('[data-panel="import"]:not([hidden])', { timeout: 30000 });
	assert((await focusedPanel(page)) === 'import', `focus moves to the import (${await focused(page)})`);
	await page.waitForSelector('[data-import-continue]:not([disabled])', { timeout: 60000 });
	assert(/5 new/.test((await page.textContent('[data-import-summary]')) || ''), `the import reports 5 new episodes (${await page.textContent('[data-import-summary]')})`);
	assert((await page.getAttribute('[data-panel="import"] [role="progressbar"]', 'aria-valuenow')) === '100', 'the progress bar is full');
	assert(await page.locator('[data-action="cancel-import"]').isHidden(), 'nothing left to stop');
	await page.screenshot({ path: 'screenshots/setup-import-done.png', fullPage: true });

	// Continue reloads the assistant with the details taken from the feed.
	await page.locator('[data-import-continue]').focus();
	await Promise.all([page.waitForURL(/step=show/), page.keyboard.press('Enter')]);
	await page.waitForSelector('[data-panel="show"]:not([hidden])');
	const tops = await page.evaluate(() => {
		const top = (id) => Math.round(document.querySelector(`label[for="${id}"]`).getBoundingClientRect().top);
		return { author: top('epm-setup-author'), category: top('epm-setup-category'), owner: top('epm-setup-owner-name'), email: top('epm-setup-owner-email') };
	});
	assert(tops.author === tops.category && tops.owner === tops.email, `fields side by side line up (${JSON.stringify(tops)})`);
	const show = await page.evaluate(() => ({
		title: document.querySelector('#epm-setup-title').value,
		author: document.querySelector('#epm-setup-author').value,
		email: document.querySelector('#epm-setup-owner-email').value,
		category: document.querySelector('#epm-setup-category').selectedOptions[0]?.textContent.trim(),
		language: document.querySelector('#epm-setup-language').value,
		description: document.querySelector('#epm-setup-description').value,
	}));
	assert(show.title === 'Synthetic Show' && show.author === 'Sam Synth' && show.email === 'owner@show.example.test', `show details come from the feed (${JSON.stringify(show)})`);
	assert(/Business.*Marketing/.test(show.category || '') && show.language === 'en_GB', 'category and language too');
	assert(show.description === 'A made-up show used by the test suites.', 'the description is shown as plain text');
	await page.screenshot({ path: 'screenshots/setup-show-prefilled.png', fullPage: true });

	await page.locator('[data-step-form="show"] [type="submit"]').focus();
	await page.keyboard.press('Enter');
	await page.waitForSelector('[data-panel="look"]:not([hidden])');
	assert((await focusedPanel(page)) === 'look', 'focus moves to the look step');
	assert(await page.isChecked('[data-step-form="look"] [name="create_page"]'), 'a podcast page is offered');
	await page.locator('[data-step-form="look"] input[name="preset"]').nth(1).check();
	await page.screenshot({ path: 'screenshots/setup-look.png', fullPage: true });
	await page.locator('[data-step-form="look"] [type="submit"]').focus();
	await page.keyboard.press('Enter');
	await page.waitForSelector('[data-panel="done"]:not([hidden])');
	assert((await focusedPanel(page)) === 'done', 'focus moves to the last step');
	assert((await visibleText(page, '#epm-setup-done-title')) === 'Your website is connected to your host', 'the last step names the result');
	assert((await page.textContent('[data-public-feed]')).trim() === LOCKED_FEED, 'listeners get the host\'s feed');
	await page.waitForSelector('[data-page-link]:not([hidden])');
	const pageUrl = await page.getAttribute('[data-page-link]', 'href');
	await page.waitForTimeout(500);
	await page.screenshot({ path: 'screenshots/setup-done-external.png', fullPage: true });

	const server = php(`
		$h = EPM\\Hosting::all();
		$ids = get_posts( [ 'post_type' => 'podcast_episode', 'post_status' => [ 'publish', 'draft' ], 'posts_per_page' => -1, 'fields' => 'ids' ] );
		echo wp_json_encode( [
			'mode' => $h['mode'], 'feed' => $h['feed_url'], 'sync' => $h['sync'], 'redirect' => $h['redirect'],
			'published' => count( array_filter( $ids, static function ( $id ) { return 'publish' === get_post_status( $id ); } ) ),
			'all' => count( $ids ),
			'done' => EPM\\AdminPages::setup_state()['done'],
			'page' => get_post_status( EPM\\AdminPages::setup_state()['page_id'] ),
			'preset' => epm()->design->get( 'preset' ),
		] )
	`);
	assert(server.mode === 'external' && server.feed === LOCKED_FEED && server.sync === true && server.redirect === true, `hosting saved (${JSON.stringify(server)})`);
	assert(server.published === 3 && server.all === 5, 'episodes mirrored (hidden and undated ones as drafts)');
	assert(server.done === true && server.page === 'publish', 'setup finished and the podcast page is published');
	assert((await head(`${BASE}/podcast/feed/`)) === `301 ${LOCKED_FEED}`, 'the site\'s feed address redirects to the host');
	await page.goto(pageUrl);
	assert(await page.getByText('Episode 3: Plain notes').first().isVisible(), 'the podcast page lists the imported episodes');
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
console.log('Setup assistant: host on this website (390 px)');
{
	fresh();
	const page = await newPage(browser, { width: 390, height: 844 });
	await login(page);
	await page.goto(`${BASE}/wp-admin/admin.php?page=epm-setup`);
	assert(await noOverflow(page), 'step 1 fits 390 px');
	await page.check('input[name="path"][value="new"]');
	await page.click('[data-step-form="path"] [type="submit"]');
	await page.waitForSelector('[data-panel="show"]:not([hidden])');
	assert((await focusedPanel(page)) === 'show', 'focus moves to the show details');
	assert(JSON.stringify(await visibleSteps(page)) === JSON.stringify(['path', 'show', 'look', 'done']), 'hosting here skips the host and import steps');
	assert((await page.getAttribute('[data-step="show"]', 'aria-current')) === 'step', 'the current step is marked for assistive technology');
	assert(await noOverflow(page), 'show details fit 390 px');

	await page.fill('#epm-setup-title', '');
	await page.fill('#epm-setup-owner-email', 'not-an-email');
	await page.click('[data-step-form="show"] [type="submit"]');
	const errors = await page.evaluate(() => ({
		title: !document.querySelector('#epm-setup-title-error').hidden && document.querySelector('#epm-setup-title').getAttribute('aria-invalid') === 'true',
		email: !document.querySelector('#epm-setup-owner-email-error').hidden && document.querySelector('#epm-setup-owner-email').getAttribute('aria-invalid') === 'true',
		described: document.querySelector('#epm-setup-owner-email').getAttribute('aria-describedby').includes('epm-setup-owner-email-error'),
		focus: document.activeElement.id,
	}));
	assert(errors.title && errors.email && errors.described, `errors appear next to the fields (${JSON.stringify(errors)})`);
	assert(errors.focus === 'epm-setup-title', 'focus goes to the first field to fix');
	assert((await page.locator('[data-panel="show"]').isVisible()) && !(await page.locator('[data-panel="look"]').isVisible()), 'nothing is saved while fields are invalid');
	await page.screenshot({ path: 'screenshots/setup-new-errors-390.png', fullPage: true });

	await page.fill('#epm-setup-title', 'Coffee & Code');
	await page.fill('#epm-setup-owner-email', 'host@example.com');
	await page.fill('#epm-setup-description', 'A show about <b>coffee</b> and code.');
	await page.selectOption('#epm-setup-category', { label: 'Technology' });
	await page.click('[data-step-form="show"] [type="submit"]');
	await page.waitForSelector('[data-panel="look"]:not([hidden])');
	assert(await page.locator('#epm-setup-title-error').isHidden(), 'fixed fields lose their errors');
	assert(await noOverflow(page), 'the style step fits 390 px');
	await page.click('[data-step-form="look"] [type="submit"]');
	await page.waitForSelector('[data-panel="done"]:not([hidden])');
	assert((await visibleText(page, '#epm-setup-done-title')) === 'Your podcast is set up', 'done');
	const feedShown = (await page.locator('[data-panel="done"] [data-path-only="new"] .epm-copy__value').textContent()).trim();
	assert(feedShown === `${BASE}/podcast/feed/`, `the feed to submit is this site's (${feedShown})`);
	await page.waitForSelector('[data-readiness]:not([hidden])', { timeout: 10000 }).catch(() => {});
	assert(await page.locator('[data-readiness]').isVisible(), 'what is still missing is listed (no artwork, no episode)');
	assert(await noOverflow(page), 'the last step fits 390 px');
	await page.screenshot({ path: 'screenshots/setup-new-done-390.png', fullPage: true });

	const server = php(`
		$page = EPM\\AdminPages::setup_state()['page_id'];
		echo wp_json_encode( [
			'mode' => EPM\\Hosting::get( 'mode' ),
			'title' => epm()->settings->get( 'title' ),
			'email' => epm()->settings->get( 'owner_email' ),
			'category' => epm()->settings->get( 'category' ),
			'description' => epm()->settings->get( 'description' ),
			'page' => get_post_status( $page ),
			'content' => get_post_field( 'post_content', $page ),
		] )
	`);
	assert(server.mode === 'self' && server.title === 'Coffee & Code' && server.email === 'host@example.com' && server.category === 'Technology', `settings saved (${JSON.stringify(server)})`);
	assert(!/<b>/.test(server.description) || /<b>coffee<\/b>/.test(server.description), 'the description is stored safely');
	assert(server.page === 'publish' && /\[podcast_episodes/.test(server.content) && /\[podcast_subscribe/.test(server.content), 'the podcast page holds the episode list and subscribe buttons');
	assert((await head(`${BASE}/podcast/feed/`)).startsWith('200'), 'the site publishes the feed');
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
console.log('Setup assistant: move a locked show here');
{
	fresh();
	const page = await newPage(browser);
	await login(page);
	await page.goto(`${BASE}/wp-admin/admin.php?page=epm-setup`);
	await page.check('input[name="path"][value="move"]');
	await page.click('[data-step-form="path"] [type="submit"]');
	await page.waitForSelector('[data-panel="connect"]:not([hidden])');
	assert((await visibleText(page, '#epm-setup-connect-title')) === 'Which host are you moving from?', 'step 2 asks for the old host');
	await page.click('[data-step-form="connect"] [data-action="check-feed"]');
	assert(await page.locator('[data-panel="connect"] [data-error]').isVisible(), 'checking without an address says what to paste');
	assert((await page.getAttribute('#epm-setup-feed', 'aria-invalid')) === 'true', 'and marks the field');
	await page.fill('#epm-setup-feed', LOCKED_FEED);
	await page.click('[data-step-form="connect"] [data-action="check-feed"]');
	await page.waitForSelector('[data-panel="connect"] [data-preview]:not([hidden])', { timeout: 30000 });
	assert(await page.locator('[data-preview-locked]').isVisible(), 'a locked feed is explained');
	assert(await page.locator('[data-confirm-owner]').isVisible(), 'and the owner\'s consent is asked for');
	assert(await page.isChecked('[name="download_media"]'), 'copying the media is the default when moving');

	await page.click('[data-import-button]');
	await page.waitForTimeout(300);
	const consent = page.locator('#epm-setup-confirm-error');
	assert(await consent.isVisible(), `importing without consent is refused (${((await consent.textContent()) || '').trim()})`);
	assert(await page.locator('#epm-setup-feed-error').isHidden(), 'the message is not shown under the feed address');
	const box = await page.evaluate(() => {
		const input = document.querySelector('[name="confirm_owner"]');
		return { described: input.getAttribute('aria-describedby'), invalid: input.getAttribute('aria-invalid') };
	});
	assert(box.described === 'epm-setup-confirm-error' && box.invalid === 'true', `it belongs to the consent box (${JSON.stringify(box)})`);
	assert((await focused(page)) === 'input[name=confirm_owner]', 'focus goes to the consent box');
	assert(await page.locator('[data-panel="connect"]').isVisible(), 'the import does not start');
	await page.screenshot({ path: 'screenshots/setup-move-locked.png', fullPage: true });

	await page.check('[name="confirm_owner"]');
	await page.click('[data-import-button]');
	await page.waitForSelector('[data-panel="import"]:not([hidden])', { timeout: 30000 });
	assert(await consent.isHidden(), 'the consent message is gone once confirmed');
	await page.waitForSelector('[data-import-continue]:not([disabled])', { timeout: 120000 });
	assert(/5 new/.test((await page.textContent('[data-import-summary]')) || ''), `every episode moved (${await page.textContent('[data-import-summary]')})`);
	await Promise.all([page.waitForURL(/step=show/), page.click('[data-import-continue]')]);
	await page.click('[data-step-form="show"] [type="submit"]');
	await page.waitForSelector('[data-panel="look"]:not([hidden])');
	await page.click('[data-step-form="look"] [type="submit"]');
	await page.waitForSelector('[data-panel="done"]:not([hidden])');
	assert((await visibleText(page, '#epm-setup-done-title')) === 'Your episodes are here — one step left', 'the last step asks for the redirect at the old host');
	assert(((await page.textContent('[data-redirect-help]')) || '').trim().length > 20, 'with instructions for the host');
	await page.screenshot({ path: 'screenshots/setup-move-done.png', fullPage: true });

	const server = php(`
		$s = epm()->settings->all();
		$ids = get_posts( [ 'post_type' => 'podcast_episode', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] );
		echo wp_json_encode( [
			'moved_in' => $s['moved_in'], 'locked' => $s['locked'], 'limit' => (int) $s['feed_limit'],
			'guid' => get_option( EPM\\Feed::GUID_OPTION ),
			'local' => count( array_filter( $ids, static function ( $id ) { return (int) get_post_meta( $id, '_epm_audio_id', true ) > 0; } ) ),
			'published' => count( $ids ),
			'mode' => EPM\\Hosting::get( 'mode' ),
		] )
	`);
	assert(server.moved_in === true && server.locked === true, `the feed is prepared for the move (${JSON.stringify(server)})`);
	assert(server.limit === 0 || server.limit >= server.published, 'every episode fits the feed limit');
	assert(server.guid === 'c0ffee00-1234-5abc-8def-0123456789ab', 'the show keeps its podcast:guid');
	assert(server.published === 3 && server.local === 3, 'published episodes play from this site');
	assert(server.mode === 'self', 'this site hosts the show now');
	const feed = await (await fetch(`${BASE}/podcast/feed/`)).text();
	assert(feed.includes(`<itunes:new-feed-url>${BASE}/podcast/feed/</itunes:new-feed-url>`), 'the feed announces its new address');
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
console.log('Hosting & import');
{
	fresh();
	const page = await newPage(browser);
	await login(page);
	await page.goto(`${BASE}/wp-admin/admin.php?page=epm-hosting`);
	assert(await page.locator('[data-external-only]').isHidden() && (await page.locator('[data-self-only]').isVisible()), 'self-hosted: statistics, no host settings');
	await page.check('input[name="epm_hosting[mode]"][value="external"]');
	assert(await page.locator('[data-external-only]').isVisible() && (await page.locator('[data-self-only]').isHidden()), 'switching to another host shows its settings at once');
	assert(await page.$eval('#epm-hosting-feed', (input) => input.required), 'another host needs its feed address');
	await page.click('[data-hosting-form] [type="submit"]');
	assert(/page=epm-hosting/.test(page.url()) && !/settings-updated/.test(page.url()), 'the form is not sent without it');
	await page.selectOption('#epm-hosting-provider', 'buzzsprout');
	assert(((await page.textContent('[data-provider-help]')) || '').length > 10, 'the host\'s help text follows the choice');
	await page.fill('#epm-hosting-feed', PAGED_FEED);
	await Promise.all([page.waitForURL(/settings-updated=true/), page.click('[data-hosting-form] [type="submit"]')]);
	assert(await page.locator('[data-sync-card]').isVisible(), 'the sync card appears for a host');
	await page.click('[data-action="sync-now"]');
	await page.waitForFunction(() => /Working|Problem/.test(document.querySelector('[data-sync-status] .epm-badge')?.textContent || ''), null, { timeout: 30000 });
	const badge = ((await page.textContent('[data-sync-status] .epm-badge')) || '').trim();
	const message = ((await page.textContent('[data-sync-message]')) || '').trim();
	assert(badge === 'Working', `"Sync now" syncs (${badge}: ${message})`);
	assert(message.length > 0, 'and says what happened');
	const synced = php(`echo wp_json_encode( count( get_posts( [ 'post_type' => 'podcast_episode', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_epm_source_feed', 'meta_value' => '${PAGED_FEED}' ] ) ) )`);
	assert(synced >= 2, `episodes mirrored from the host (${synced})`);
	await page.screenshot({ path: 'screenshots/hosting-external.png', fullPage: true });

	// A one-off import of a locked feed.
	await page.fill('#epm-import-url', LOCKED_FEED);
	await page.click('[data-import-form] [data-action="check"]');
	await page.waitForSelector('[data-import-form] [data-preview]:not([hidden])', { timeout: 30000 });
	assert(await page.locator('[data-import-form] [data-confirm-owner]').isVisible(), 'a locked feed asks for consent before copying');
	await page.click('[data-import-form] [data-action="start"]');
	await page.waitForSelector('[data-job]:not([hidden])', { timeout: 30000 });
	assert(await page.evaluate(() => document.activeElement === document.querySelector('[data-job]')), 'focus moves to the progress, not to the page');
	await page.waitForSelector('[data-job-episodes]:not([hidden])', { timeout: 60000 });
	assert(/5 new/.test((await page.textContent('[data-job-summary]')) || ''), `the import runs with progress (${await page.textContent('[data-job-summary]')})`);
	assert((await page.getAttribute('[data-job] [role="progressbar"]', 'aria-valuenow')) === '100', 'progress reaches 100 %');
	await page.screenshot({ path: 'screenshots/hosting-import.png', fullPage: true });

	// Copying a show while mirroring its host: asked first, and audio that
	// could not be copied is listed with links to the episodes.
	await page.fill('#epm-import-url', 'https://feeds.example.test/synthetic/missing-audio.xml');
	await page.click('[data-import-form] [data-action="check"]');
	await page.waitForSelector('[data-import-form] [data-preview]:not([hidden])', { timeout: 30000 });
	await page.check('[data-import-form] [name="download_media"]');
	let asked = '';
	page.once('dialog', (dialog) => {
		asked = dialog.message();
		dialog.accept();
	});
	await page.click('[data-import-form] [data-action="start"]');
	await page.waitForSelector('[data-media-failed]:not([hidden])', { timeout: 60000 });
	assert(/This website/.test(asked), `copying the audio of a mirrored show asks first ("${asked}")`);
	const media = await page.evaluate(() => ({
		title: document.querySelector('[data-media-failed-title]').textContent,
		links: Array.from(document.querySelectorAll('[data-media-failed-list] a')).map((a) => [a.textContent, /post\.php\?post=\d+&action=edit/.test(a.href)]),
		summary: document.querySelector('[data-job-summary]').textContent,
	}));
	assert(/1 episode/.test(media.title) && JSON.stringify(media.links) === '[["Missing audio episode",true]]', `audio that was not copied is listed (${JSON.stringify(media)})`);
	assert(/1 audio not copied/.test(media.summary), `and counted (${media.summary})`);
	assert(php(`echo wp_json_encode( EPM\\Hosting::get( 'mode' ) )`) === 'self', 'the moved show is hosted here now');
	await page.screenshot({ path: 'screenshots/hosting-media-failed.png', fullPage: true });

	await page.setViewportSize({ width: 390, height: 844 });
	assert(await noOverflow(page), 'Hosting & import fits 390 px');

	await page.check('input[name="epm_hosting[mode]"][value="self"]');
	await Promise.all([page.waitForURL(/settings-updated=true/), page.click('[data-hosting-form] [type="submit"]')]);
	assert(await page.locator('[data-sync-card]').count() === 0, 'back on this website: no sync card');
	assert((await head(`${BASE}/podcast/feed/`)).startsWith('200'), 'and the site serves its feed again');
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

// ---------------------------------------------------------------------------
console.log('A feed that cannot be read completely');
{
	fresh();
	const BROKEN = 'https://feeds.example.test/synthetic/paged-broken-1.xml';
	const page = await newPage(browser);
	await login(page);
	await page.goto(`${BASE}/wp-admin/admin.php?page=epm-hosting`);

	await page.fill('#epm-import-url', BROKEN);
	await page.click('[data-import-form] [data-action="check"]');
	await page.waitForSelector('[data-import-form] [data-preview]:not([hidden])', { timeout: 30000 });
	const callout = page.locator('[data-import-form] [data-preview-incomplete]');
	const said = ((await callout.textContent()) || '').replace(/\s+/g, ' ').trim();
	assert(await callout.isVisible(), 'an incomplete feed is announced next to the result');
	assert(/Page 2/.test(said) && /paged-broken-2\.xml/.test(said) && /404/.test(said), `naming the page, its address and the error (${said})`);
	assert(/2 episodes/.test((await page.textContent('[data-import-form] [data-preview-meta]')) || ''), 'the episodes found so far are counted');
	assert(await page.locator('[data-import-form] [data-preview-incomplete-mirror]').isVisible(), 'mirroring what was found is offered');
	assert(await page.locator('[data-import-form] [data-accept-partial]').isHidden(), 'no move confirmation while the audio is not copied');

	// Try again: the page still fails, the result says so.
	await page.click('[data-import-form] [data-action="retry-feed"]');
	await page.waitForFunction(() => !document.querySelector('[data-action="retry-feed"]').hasAttribute('aria-busy'), null, { timeout: 30000 });
	assert(await callout.isVisible(), 'after trying again the feed is still incomplete');
	assert(await page.evaluate(() => document.activeElement === document.querySelector('[data-import-form] [data-preview-incomplete]')), 'focus moves to the result of the retry');

	// Copying the audio moves the show: only with the informed confirmation.
	await page.check('[data-import-form] [name="download_media"]');
	const accept = page.locator('[data-import-form] [data-accept-partial]');
	assert(await accept.isVisible(), 'a move asks to confirm what is missing');
	assert(/Move only the 2 episodes/.test((await accept.textContent()) || ''), `naming how many episodes move (${((await accept.textContent()) || '').trim()})`);
	await page.click('[data-import-form] [data-action="start"]');
	await page.waitForTimeout(300);
	const refusal = page.locator('#epm-import-partial-error');
	assert(await refusal.isVisible(), 'moving without the confirmation is refused next to the checkbox');
	const box = await page.evaluate(() => {
		const input = document.querySelector('[data-import-form] [name="accept_partial"]');
		return { focused: document.activeElement === input, described: input.getAttribute('aria-describedby'), invalid: input.getAttribute('aria-invalid') };
	});
	assert(box.focused && box.described === 'epm-import-partial-error' && box.invalid === 'true', `focus goes to the confirmation, which carries the message (${JSON.stringify(box)})`);
	assert(await page.locator('[data-job]').isHidden(), 'the import does not start');
	await page.screenshot({ path: 'screenshots/hosting-incomplete-feed.png', fullPage: true });

	await page.check('[data-import-form] [name="accept_partial"]');
	assert(await refusal.isHidden(), 'the message goes once confirmed');
	await page.click('[data-import-form] [data-action="start"]');
	await page.waitForSelector('[data-job-episodes]:not([hidden])', { timeout: 60000 });
	const partial = ((await page.textContent('[data-job-incomplete]')) || '').trim();
	assert(await page.locator('[data-job-incomplete]').isVisible() && /only part of the feed/.test(partial), `the result says the import covers part of the feed (${partial})`);
	assert(/2 new/.test((await page.textContent('[data-job-summary]')) || ''), 'the two episodes found are imported');
	const moved = php(`echo wp_json_encode( [ epm()->settings->get( 'moved_in' ), EPM\\ImportJob::get()['options']['accept_partial'] ?? null ] )`);
	assert(JSON.stringify(moved) === '[true,true]', `the confirmed move is finished (${JSON.stringify(moved)})`);
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();

	// The setup assistant: the same confirmation when moving.
	fresh();
	const setup = await newPage(browser);
	await login(setup);
	await setup.goto(`${BASE}/wp-admin/admin.php?page=epm-setup`);
	await setup.check('input[name="path"][value="move"]');
	await setup.click('[data-step-form="path"] [type="submit"]');
	await setup.waitForSelector('[data-panel="connect"]:not([hidden])');
	await setup.fill('#epm-setup-feed', BROKEN);
	await setup.click('[data-step-form="connect"] [data-action="check-feed"]');
	await setup.waitForSelector('[data-panel="connect"] [data-preview]:not([hidden])', { timeout: 30000 });
	assert(await setup.locator('[data-panel="connect"] [data-preview-incomplete]').isVisible(), 'the assistant shows the incomplete feed');
	assert(await setup.locator('[data-panel="connect"] [data-accept-partial]').isVisible(), 'and asks to confirm what is missing before a move');
	await setup.click('[data-import-button]');
	await setup.waitForTimeout(300);
	assert(await setup.locator('#epm-setup-partial-error').isVisible(), 'without it the move is refused');
	assert((await focused(setup)) === 'input[name=accept_partial]', `focus goes to the confirmation (${await focused(setup)})`);
	assert(await setup.locator('[data-panel="connect"]').isVisible(), 'the import does not start');
	await setup.setViewportSize({ width: 390, height: 844 });
	assert(await noOverflow(setup), 'the step fits 390 px');
	assert(setup.problems.length === 0, `no browser errors ${setup.problems.join('; ')}`);
	await setup.context().close();
}

// ---------------------------------------------------------------------------
console.log('Distribution');
{
	fresh();
	php(`update_option( EPM\\PodcastSettings::OPTION, [ 'title' => 'Distribution Show' ] ); echo wp_json_encode( true )`);
	const page = await newPage(browser);
	await login(page);
	await page.goto(`${BASE}/wp-admin/admin.php?page=epm-distribution`);
	const progress = () =>
		page.evaluate(() => ({
			score: document.querySelector('[data-dist-score]').textContent.trim(),
			primary: Array.from(document.querySelectorAll('[data-epm-distribution] [data-submit-link].button-primary')).map((a) => a.closest('[data-directory]').getAttribute('data-directory')),
		}));
	const before = await progress();
	assert(before.score === '0 of 4' && JSON.stringify(before.primary) === '["apple"]', `one "Submit" button is primary: the next platform (${JSON.stringify(before)})`);
	const apple = page.locator('[data-directory="apple"]');
	await apple.locator('summary').click();
	await apple.locator('[name="submitted"]').check();
	await page.waitForFunction(() => document.querySelector('[data-dist-score]').textContent.trim() === '1 of 4', null, { timeout: 10000 }).catch(() => {});
	const after = await progress();
	assert(after.score === '1 of 4' && JSON.stringify(after.primary) === '["spotify"]', `the count and the next platform follow at once (${JSON.stringify(after)})`);
	const gaps = await page.evaluate(() => {
		const copy = document.querySelector('[data-epm-distribution] .epm-copy').getBoundingClientRect();
		const help = document.querySelector('[data-epm-distribution] .epm-copy + .epm-field__help').getBoundingClientRect();
		const check = document.querySelector('.epm-dist-check').getBoundingClientRect();
		return { copyToHelp: Math.round(help.top - copy.bottom), helpToCheck: Math.round(check.top - help.bottom) };
	});
	assert(gaps.copyToHelp >= 8 && gaps.helpToCheck >= 16, `the feed card is spaced (${JSON.stringify(gaps)})`);

	const row = page.locator('[data-directory="pocketcasts"]');
	await row.locator('summary').click();
	await row.locator('[name="submitted"]').check();
	await page.waitForFunction(() => document.querySelector('[data-directory="pocketcasts"] [data-status-badge]').textContent.trim() === 'Submitted', null, { timeout: 10000 }).catch(() => {});
	assert(((await row.locator('[data-status-badge]').textContent()) || '').trim() === 'Submitted', 'ticking "I submitted the feed" marks the platform as submitted');

	await row.locator('[name="url"]').fill('pca.st/itunes/epm-e2e-show');
	await row.locator('[type="submit"]').click();
	const invalid = await page.evaluate(() => {
		const form = document.querySelector('[data-directory-form="pocketcasts"]');
		const input = form.querySelector('[name="url"]');
		return { error: !form.querySelector('[data-error]').hidden, invalid: input.getAttribute('aria-invalid'), focus: document.activeElement === input, described: input.getAttribute('aria-describedby') };
	});
	assert(invalid.error && invalid.invalid === 'true' && invalid.focus, `an incomplete link is explained next to the field (${JSON.stringify(invalid)})`);
	assert(/\bepm-dir-error-pocketcasts\b/.test(invalid.described || ''), `and tied to it (${invalid.described})`);

	await row.locator('[name="url"]').fill(LISTING);
	await row.locator('[type="submit"]').click();
	await page.waitForFunction(() => document.querySelector('[data-directory="pocketcasts"] [data-status-badge]').textContent.trim() === 'Listed', null, { timeout: 10000 }).catch(() => {});
	assert(((await row.locator('[data-status-badge]').textContent()) || '').trim() === 'Listed', 'saving the listing link marks it as listed');
	assert(!/epm-dir-error/.test((await row.locator('[name="url"]').getAttribute('aria-describedby')) || ''), 'the fixed field loses the error reference');
	await page.screenshot({ path: 'screenshots/distribution-listed.png', fullPage: true });

	await page.reload();
	assert(((await row.locator('[data-status-badge]').textContent()) || '').trim() === 'Listed' && (await row.locator('[name="url"]').inputValue()) === LISTING, 'progress is kept');
	await page.setViewportSize({ width: 390, height: 844 });
	assert(await noOverflow(page), 'Distribution fits 390 px');
	await page.screenshot({ path: 'screenshots/distribution-390.png', fullPage: true });

	const subscribe = await page.context().newPage();
	await subscribe.goto(`${BASE}/epm-shortcodes/`);
	assert(await subscribe.locator(`a[href="${LISTING}"]`).count() > 0, 'the podcast page gets a subscribe link to the listing');
	assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
	await page.context().close();
}

await browser.close();

// ---------------------------------------------------------------------------
// Put the site back: the fixtures, then every option this suite touched.
fresh();
wp(['eval-file', path.join(ROOT, 'tests/fixtures/seed.php')], { EPM_ALLOW_TEST_SEED: '1' });
php(`
	$saved = json_decode( base64_decode( '${Buffer.from(JSON.stringify(saved)).toString('base64')}' ), true );
	foreach ( $saved as $name => $value ) {
		if ( '__epm_absent__' === $value ) { delete_option( $name ); } else { update_option( $name, $value ); }
	}
	EPM\\Hosting::reschedule();
	EPM\\Feed::flush_cache();
	echo wp_json_encode( true )
`);

finish('setup');
