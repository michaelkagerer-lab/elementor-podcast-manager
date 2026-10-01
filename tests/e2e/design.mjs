/**
 * Browser tests for "Details shown by default" and the design data around
 * it (DESIGN-01, DESIGN-N1, DESIGN-N2, DESIGN-N3) against a site seeded
 * with tests/fixtures/seed.php:
 *
 * - the details form on the Design screen (keyboard, phones), saving it
 *   changes new widgets on the site and the preview;
 * - preset maps stored by 1.1–1.3 are offered as suggestions with what
 *   would change, applied or dismissed with one action;
 * - the real Elementor editor stores an explicit layout equal to the
 *   default and keeps it when a preset changes the default; "Default"
 *   follows;
 * - widgets saved by 1.3.0 open with their values made explicit, save
 *   unchanged, and "Use Podcast → Design defaults" switches one widget to
 *   the site defaults;
 * - artwork radius and the round guest photo inside Elementor widgets and
 *   on a shortcode page.
 *
 *   cd tests/e2e && WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp node design.mjs
 *
 * Pages created here are deleted and the design option removed at the end.
 */
import fs from 'node:fs';
import { BASE, php, assert, finish, launch, newPage, login, noOverflow, focusRingVisible, tabTo, section } from './lib.mjs';

const fx = php(`echo wp_json_encode( get_option( 'epm_test_fixtures' ) );`);
const legacy = JSON.parse(fs.readFileSync(new URL('../fixtures/elementor-1.3.0.json', import.meta.url), 'utf8').split('%ep1%').join(String(fx.ep1)));

const resetDesign = () => php(`delete_option( 'epm_design_settings' ); \\Elementor\\Plugin::$instance->files_manager->clear_cache(); echo 1;`);
const applyPreset = (id) => php(`epm()->design->apply_preset( ${JSON.stringify(id)} ); \\Elementor\\Plugin::$instance->files_manager->clear_cache(); echo 1;`);

/** An Elementor page from widget data; returns [id, url]. */
const elementorPage = (slug, widgets, containerSettings = {}) =>
	php(`
		$old = get_page_by_path( ${JSON.stringify(slug)} );
		if ( $old ) { wp_delete_post( $old->ID, true ); }
		$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => ${JSON.stringify(slug)}, 'post_name' => ${JSON.stringify(slug)} ] );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
		$data = [ [ 'id' => 'd5e5f01', 'elType' => 'container', 'settings' => json_decode( ${JSON.stringify(JSON.stringify(containerSettings))}, true ) ?: [], 'isInner' => false, 'elements' => json_decode( ${JSON.stringify(JSON.stringify(widgets))}, true ) ] ];
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		\\Elementor\\Plugin::$instance->files_manager->clear_cache();
		echo wp_json_encode( [ $id, get_permalink( $id ) ] );
	`);
const shortcodePage = (slug, content) =>
	php(`
		$old = get_page_by_path( ${JSON.stringify(slug)} );
		if ( $old ) { wp_delete_post( $old->ID, true ); }
		$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => ${JSON.stringify(slug)}, 'post_name' => ${JSON.stringify(slug)}, 'post_content' => ${JSON.stringify(content)} ] );
		echo wp_json_encode( [ $id, get_permalink( $id ) ] );
	`);
const storedWidgets = (id) => php(`echo wp_json_encode( array_column( (array) json_decode( (string) get_post_meta( ${id}, '_elementor_data', true ), true )[0]['elements'], 'settings', 'id' ) );`);

/** Visible details of every player on a page, by Elementor element ID (or index). */
const playersOn = (page) =>
	page.evaluate(() =>
		[...document.querySelectorAll('[data-epm-player]')].map((p) => {
			const el = p.closest('[data-id]');
			const has = (sel) => !!p.querySelector(sel);
			return {
				id: el ? el.dataset.id : '',
				layout: ([...p.classList].find((c) => /^epm-player--(minimal|compact|editorial|artwork|full)$/.test(c)) || '').slice(12),
				artwork: has('.epm-player__artwork'),
				volume: has('[data-epm-volume]'),
				date: has('.epm-meta__item--date'),
				description: has('.epm-player__description'),
				download: has('.epm-player__download'),
				share: has('[data-epm-share]'),
			};
		})
	);

const created = [];
const browser = await launch();

try {
	resetDesign();
	const [newPageId, newPageUrl] = elementorPage('epm-design-new-widget', [
		{ id: 'n1a2b3c', elType: 'widget', widgetType: 'epm-podcast-player', settings: { epm_schema: '2', source: 'specific', episode_id: String(fx.ep1) }, elements: [] },
	]);
	created.push(newPageId);

	// -----------------------------------------------------------------------
	await section('Details shown by default (Design screen)', async () => {
		resetDesign();
		const page = await newPage(browser);
		await login(page);
		await page.goto(`${BASE}/wp-admin/admin.php?page=epm-design`);
		const form = page.locator('[data-epm-details-form]');
		assert(await form.isVisible(), 'the Design screen has a details form');
		const contexts = await form.locator('fieldset legend, [data-epm-details-context]').count();
		assert(contexts >= 4, `one group per context (${contexts})`);
		const volume = page.locator('input[name="epm_details[player][show_volume]"][type="checkbox"]');
		assert(await volume.isChecked(), 'Player → Volume slider is on by default');
		const name = await volume.evaluate((input) => (input.labels && input.labels.length ? [...input.labels].map((l) => l.textContent.trim()).join(' ') : '') + ' ' + (input.getAttribute('aria-label') || '') + ' ' + (input.getAttribute('aria-labelledby') ? input.getAttribute('aria-labelledby').split(' ').map((id) => (document.getElementById(id) || { textContent: '' }).textContent.trim()).join(' ') : ''));
		assert(/volume/i.test(name) && /player/i.test(name), `the checkbox names its detail and context ("${name.trim()}")`);

		const reached = await tabTo(page, () => document.activeElement && document.activeElement.name === 'epm_details[player][show_volume]', { max: 160 });
		assert(reached, 'the keyboard reaches the details checkboxes');
		assert(await focusRingVisible(page), 'with a visible focus ring');
		await page.keyboard.press('Space');
		assert(!(await volume.isChecked()), 'Space unchecks it');
		await Promise.all([page.waitForNavigation(), form.locator('[type="submit"]').first().click()]);
		assert(await page.locator('.notice-success').first().isVisible(), 'saved with a confirmation');
		assert(!(await page.locator('input[name="epm_details[player][show_volume]"][type="checkbox"]').isChecked()), 'the choice is kept');
		const preview = await page.evaluate(() => !!document.querySelector('[data-epm-preview-part="player"] [data-epm-volume]'));
		assert(!preview, 'the player preview follows the saved details');
		assert(await page.locator('[data-epm-preview-part="episode-page"] [data-epm-player]').count() === 1, 'the preview shows the episode page player too');
		await page.screenshot({ path: 'screenshots/design-details.png', fullPage: true });

		const site = await newPage(browser);
		await site.goto(newPageUrl);
		const [p] = await playersOn(site);
		assert(p && !p.volume, `a new Player widget on the site follows (${JSON.stringify(p)})`);
		await site.context().close();

		await page.setViewportSize({ width: 390, height: 844 });
		await page.reload();
		assert(await noOverflow(page), 'the details form fits 390 px');
		await page.locator('[data-epm-details-form]').screenshot({ path: 'screenshots/design-details-390.png' });
		assert(page.problems.length === 0, `no browser errors ${page.problems.join(' | ')}`);
		await page.context().close();
		resetDesign();
	});

	// -----------------------------------------------------------------------
	await section('Preset maps from 1.1–1.3: suggestions, never active', async () => {
		resetDesign();
		const stored130 = () =>
			php(`
				$o = array_merge( EPM\\DesignSettings::defaults(), (array) epm()->presets->get( 'business-tuning' )['tokens'] );
				unset( $o['details'], $o['details_suggested'], $o['details_version'] );
				$o['preset'] = 'business-tuning';
				$o['preset_visibility'] = [ 'show_artwork' => true, 'show_episode_label' => true, 'show_title' => true, 'show_episode_number' => true, 'show_season' => false, 'show_guest' => true, 'show_description' => true, 'show_date' => true, 'show_duration' => true ];
				$o['preset_player'] = [ 'show_playback_speed' => true, 'show_skip_backward' => true, 'show_skip_forward' => true, 'show_volume' => false, 'show_download' => false ];
				$o['preset_episode_list'] = [];
				update_option( 'epm_design_settings', $o );
				echo 1;
			`);
		stored130();
		const site = await newPage(browser);
		await site.goto(newPageUrl);
		const before = await playersOn(site);
		assert(before[0] && before[0].volume, 'after the update the site still shows the volume slider');

		const page = await newPage(browser);
		await login(page);
		await page.goto(`${BASE}/wp-admin/admin.php?page=epm-design`);
		const card = page.locator('[data-epm-details-suggested]');
		assert(await card.isVisible(), 'the Design screen offers the preset\'s suggestions');
		const text = await card.innerText();
		assert(/volume/i.test(text), `and lists what would change (${text.replace(/\s+/g, ' ').slice(0, 200)})`);
		await card.screenshot({ path: 'screenshots/design-suggestions.png' });

		await Promise.all([page.waitForNavigation(), card.locator('button[value="dismiss"], [data-epm-suggestion-dismiss]').first().click()]);
		assert(!(await page.locator('[data-epm-details-suggested]').count()), 'dismissed: the card is gone');
		await site.reload();
		assert((await playersOn(site))[0].volume, 'and the site did not change');

		stored130();
		await page.goto(`${BASE}/wp-admin/admin.php?page=epm-design`);
		await Promise.all([page.waitForNavigation(), page.locator('[data-epm-details-suggested] button[value="apply"], [data-epm-suggestion-apply]').first().click()]);
		assert(!(await page.locator('[data-epm-details-suggested]').count()), 'applied: the card is gone');
		assert(!(await page.locator('input[name="epm_details[player][show_volume]"][type="checkbox"]').isChecked()), 'the details form shows the applied values');
		await site.reload();
		const after = await playersOn(site);
		assert(!after[0].volume && after[0].description, `applied: new widgets follow (${JSON.stringify(after[0])})`);
		assert(page.problems.length === 0, `no browser errors ${page.problems.join(' | ')}`);
		await page.context().close();
		await site.context().close();
		resetDesign();
	});

	// -----------------------------------------------------------------------
	await section('An explicit layout survives preset changes (DESIGN-N1, real editor)', async () => {
		resetDesign();
		const [editId, editUrl] = elementorPage('epm-design-editor-layout', []);
		created.push(editId);
		const page = await newPage(browser, { width: 1500, height: 1000 });
		await login(page);
		await page.goto(`${BASE}/wp-admin/post.php?post=${editId}&action=elementor`);
		await page.waitForSelector('#elementor-preview-iframe', { timeout: 120000 });
		await page.waitForFunction(() => {
			try {
				return !!(window.$e && window.elementor && window.elementor.getContainer && window.elementor.getContainer('d5e5f01'));
			} catch (e) {
				return false;
			}
		}, null, { timeout: 120000 });
		await page.waitForTimeout(1500);
		const create = (settings) =>
			page.evaluate((settings) => window.$e.run('document/elements/create', { container: window.elementor.getContainer('d5e5f01'), model: { elType: 'widget', widgetType: 'epm-podcast-player', settings } }).id, settings);
		const followed = await create({ source: 'specific', episode_id: String(fx.ep1) });
		const chosen = await create({ source: 'specific', episode_id: String(fx.ep1) });
		// Creating a widget opens its panel asynchronously. Wait for the
		// last widget to render before selecting its controls.
		await page.frameLocator('#elementor-preview-iframe').locator(`.elementor-element-${chosen} [data-epm-player]`).waitFor({ timeout: 30000 });
		await page.evaluate((id) => window.$e.run('document/elements/select', { container: window.elementor.getContainer(id) }), chosen);
		await page.evaluate((id) => window.$e.run('panel/editor/open', { model: window.elementor.getContainer(id).model, view: window.elementor.getContainer(id).view }), chosen);
		await page.locator('.elementor-control-section_player >> visible=true').click();
		const select = page.locator('.elementor-control-layout select >> visible=true');
		await select.waitFor({ timeout: 20000 });
		const defaultLabel = await select.locator('option[value=""]').textContent();
		assert(/Podcast → Design/.test(defaultLabel || '') && /Minimal/.test(defaultLabel || ''), `the layout control offers "${(defaultLabel || '').trim()}"`);
		assert((await select.inputValue()) === '', 'a new widget starts on Default');
		await select.selectOption('minimal');
		await page.waitForTimeout(800);
		await page.evaluate(() => window.$e.run('document/save/publish'));
		await page.waitForFunction(() => !window.elementor.saver.isEditorChanged(), null, { timeout: 30000 }).catch(() => {});
		await page.waitForTimeout(2000);
		await page.screenshot({ path: 'screenshots/design-editor-layout.png' });
		assert(page.problems.length === 0, `no browser errors in the editor ${page.problems.join(' | ')}`);
		await page.context().close();

		const stored = storedWidgets(editId);
		assert(stored[chosen] && stored[chosen].layout === 'minimal', `the explicit layout is stored (${JSON.stringify(stored[chosen])})`);
		assert(stored[chosen] && stored[chosen].epm_schema === '2' && stored[followed] && stored[followed].epm_schema === '2', 'new widgets carry the schema marker');
		assert(stored[followed] && !('layout' in stored[followed]), 'the other widget stays on Default');

		applyPreset('business-tuning');
		const site = await newPage(browser);
		await site.goto(editUrl);
		const players = await playersOn(site);
		const byId = Object.fromEntries(players.map((p) => [p.id, p]));
		assert(byId[chosen] && byId[chosen].layout === 'minimal', `after applying Business Tuning the chosen Minimal stays (${JSON.stringify(byId[chosen])})`);
		assert(byId[followed] && byId[followed].layout === 'editorial', `the Default widget follows to Editorial (${JSON.stringify(byId[followed])})`);
		assert(byId[followed] && !byId[followed].volume, 'and its details follow the preset (no volume slider)');
		await site.context().close();
		resetDesign();
	});

	// -----------------------------------------------------------------------
	await section('Widgets saved by 1.3.0 in the editor', async () => {
		resetDesign();
		const [legacyId, legacyUrl] = elementorPage('epm-design-legacy', legacy.map((w) => ({ id: w.id, elType: 'widget', widgetType: w.widgetType, settings: w.settings, elements: [] })));
		created.push(legacyId);
		const site = await newPage(browser);
		await site.goto(legacyUrl);
		const before = await playersOn(site);
		applyPreset('business-tuning');
		await site.reload();
		const strip = (list) => list.map((p) => (p.id === 'p130002' ? { ...p, layout: '' } : p));
		assert(JSON.stringify(strip(await playersOn(site))) === JSON.stringify(strip(before)), 'a preset change leaves widgets saved by 1.3.0 as they were');

		const page = await newPage(browser, { width: 1500, height: 1000 });
		await login(page);
		await page.goto(`${BASE}/wp-admin/post.php?post=${legacyId}&action=elementor`);
		await page.waitForSelector('#elementor-preview-iframe', { timeout: 120000 });
		await page.frameLocator('#elementor-preview-iframe').locator('.elementor-element-p130001 [data-epm-player]').waitFor({ timeout: 120000 });
		await page.waitForTimeout(1500);
		const settings = await page.evaluate(() => window.elementor.getContainer('p130001').settings.toJSON());
		assert(settings.epm_schema === '2' && settings.show_volume === 'no' && settings.show_artwork === 'yes' && settings.show_description === 'no', `the editor shows its 1.3.0 values explicitly (${JSON.stringify({ volume: settings.show_volume, artwork: settings.show_artwork, description: settings.show_description })})`);
		await page.evaluate(() => window.$e.run('panel/editor/open', { model: window.elementor.getContainer('p130001').model, view: window.elementor.getContainer('p130001').view }));
		await page.locator('.elementor-control-section_player >> visible=true').click();
		const volumeSelect = page.locator('.elementor-control-show_volume select >> visible=true');
		assert((await volumeSelect.inputValue()) === 'no', 'the Volume control reads Hide');
		await page.locator('.elementor-control-section_player').screenshot({ path: 'screenshots/design-editor-legacy-panel.png' }).catch(() => {});

		// Saved unchanged: renders the same.
		await page.evaluate(() => window.$e.run('document/save/publish'));
		await page.waitForTimeout(3000);
		await site.reload();
		assert(JSON.stringify(strip(await playersOn(site))) === JSON.stringify(strip(before)), 'saving it in the editor changes nothing on the site');
		const stored = storedWidgets(legacyId);
		assert(stored.p130001 && stored.p130001.epm_schema === '2' && stored.p130001.show_volume === 'no', `stored with explicit values (${JSON.stringify(stored.p130001)})`);

		// One action switches the widget to the site defaults.
		const button = page.locator('.elementor-control-epm_details_defaults button >> visible=true');
		assert(await button.isVisible(), 'the widget offers "Use Podcast → Design defaults"');
		await button.click();
		await page.waitForTimeout(800);
		assert((await volumeSelect.inputValue()) === '', 'every detail is back on Default');
		await page.evaluate(() => window.$e.run('document/save/publish'));
		await page.waitForTimeout(3000);
		const reset = storedWidgets(legacyId).p130001;
		assert(reset && !Object.keys(reset).some((k) => /^show_/.test(k)), `no detail stored any more (${JSON.stringify(reset)})`);
		await site.reload();
		const after = Object.fromEntries((await playersOn(site)).map((p) => [p.id, p]));
		assert(after.p130001 && after.p130001.description && !after.p130001.volume && after.p130001.layout === 'full', `it now follows Business Tuning, keeping its layout (${JSON.stringify(after.p130001)})`);
		assert(page.problems.length === 0, `no browser errors in the editor ${page.problems.join(' | ')}`);
		await page.context().close();
		await site.context().close();
		resetDesign();
	});

	// -----------------------------------------------------------------------
	await section('Artwork radius and the round guest photo inside Elementor (DESIGN-N2)', async () => {
		resetDesign();
		php(`update_post_meta( ${fx.ep1}, '_epm_guest_image_id', ${fx.square_image} ); EPM\\Episodes::clear_data_cache( ${fx.ep1} ); echo 1;`);
		const ep = { source: 'specific', episode_id: String(fx.ep1) };
		const [elId, elUrl] = elementorPage('epm-design-radius', [
			{ id: 'r000001', elType: 'widget', widgetType: 'epm-podcast-player', settings: { ...ep, layout: 'artwork' }, elements: [] },
			{ id: 'r000002', elType: 'widget', widgetType: 'epm-podcast-player', settings: { ...ep, layout: 'artwork', style_source: 'custom', artwork_radius: { unit: 'px', size: 37 } }, elements: [] },
			{ id: 'r000003', elType: 'widget', widgetType: 'epm-episode-list', settings: { layout: 'cards', number: 2, style_source: 'custom', list_artwork_radius: { unit: 'px', size: 37 } }, elements: [] },
			{ id: 'r000004', elType: 'widget', widgetType: 'epm-episode-header', settings: { ...ep, show_artwork: 'yes' }, elements: [] },
			{ id: 'r000005', elType: 'widget', widgetType: 'epm-latest-episode', settings: { show_player: '' }, elements: [] },
			{ id: 'r000006', elType: 'widget', widgetType: 'epm-guest', settings: ep, elements: [] },
			{ id: 'r000007', elType: 'widget', widgetType: 'epm-podcast-hero', settings: {}, elements: [] },
		]);
		const [scId, scUrl] = shortcodePage('epm-design-radius-sc', `[podcast_player id="${fx.ep1}" layout="artwork"]\n\n[podcast_guest id="${fx.ep1}"]\n\n[podcast_episodes limit="2" layout="cards"]`);
		created.push(elId, scId);
		const radius = (page, sel) => page.evaluate((sel) => { const el = document.querySelector(sel); return el ? getComputedStyle(el).borderTopLeftRadius : 'missing'; }, sel);
		const page = await newPage(browser);
		await page.goto(elUrl);
		const values = {
			player: await radius(page, '.elementor-element-r000001 .epm-player__artwork img'),
			custom: await radius(page, '.elementor-element-r000002 .epm-player__artwork img'),
			card: await radius(page, '.elementor-element-r000003 .epm-episode-card__artwork img'),
			header: await radius(page, '.elementor-element-r000004 .epm-episode-header__artwork img'),
			latest: await radius(page, '.elementor-element-r000005 .epm-latest__artwork img'),
			guest: await radius(page, '.elementor-element-r000006 .epm-guest__image'),
			hero: await radius(page, '.elementor-element-r000007 .epm-podcast-hero__artwork img'),
		};
		assert(values.player === '8px', `Player widget artwork follows Podcast → Design (${values.player})`);
		assert(values.custom === '37px', `the widget's Artwork border radius applies (${values.custom})`);
		assert(values.card === '37px', `card artwork radius applies (${values.card})`);
		assert(values.header === '8px', `Episode Header artwork (${values.header})`);
		assert(values.latest === '8px', `Latest Episode card artwork (${values.latest})`);
		assert(values.guest === '50%', `the guest photo stays round (${values.guest})`);
		assert(values.hero === '8px', `hero artwork (${values.hero})`);
		await page.locator('.elementor-element-r000001').screenshot({ path: 'screenshots/design-radius-elementor.png' });

		await page.goto(scUrl);
		const sc = {
			player: await radius(page, '.epm-player__artwork img'),
			guest: await radius(page, '.epm-guest__image'),
			card: await radius(page, '.epm-episode-card__artwork img'),
		};
		assert(sc.player === '8px' && sc.guest === '50%', `the same on a shortcode page (${JSON.stringify(sc)})`);
		applyPreset('card');
		await page.reload();
		const cardPreset = await radius(page, '.epm-player__artwork img');
		await page.goto(elUrl);
		const cardPresetEl = await radius(page, '.elementor-element-r000001 .epm-player__artwork img');
		assert(cardPreset === '16px' && cardPresetEl === '16px', `a preset's artwork radius reaches both (${cardPreset} / ${cardPresetEl})`);
		php(`delete_post_meta( ${fx.ep1}, '_epm_guest_image_id' ); EPM\\Episodes::clear_data_cache( ${fx.ep1} ); echo 1;`);
		await page.context().close();
		resetDesign();
	});
} catch (error) {
	console.log(`  ✗ ${error && error.stack ? error.stack.split('\n').slice(0, 4).join(' | ') : error}`);
	assert(false, 'the suite ran to the end');
} finally {
	await browser.close();
	php(`foreach ( ${JSON.stringify(created)} as $id ) { wp_delete_post( (int) $id, true ); } delete_option( 'epm_design_settings' ); echo 1;`);
}

finish('design');
