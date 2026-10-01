/**
 * Browser tests for widget findings of the 1.3.0 audit, against a site
 * seeded with tests/fixtures/seed.php:
 *
 * - WID-N1  row lists in a narrow Elementor column (and the Design
 *           preview) keep readable titles, at 1280, 390 and 320 px;
 * - WID-N2  two paginated lists on one page page independently; a single
 *           list keeps /page/N/;
 * - share menus in copied markup (carousel loops) get their own ids;
 * - the volume slider is announced once, as "Volume";
 * - the Latest Episode widget's sticky player option.
 *
 *   cd tests/e2e && WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp node widgets.mjs
 *
 * Pages created here are deleted at the end.
 */
import { BASE, php, assert, finish, launch, newPage, login, noOverflow, section } from './lib.mjs';

const fx = php(`echo wp_json_encode( get_option( 'epm_test_fixtures' ) );`);

const elementorPage = (slug, elements) =>
	php(`
		$old = get_page_by_path( ${JSON.stringify(slug)} );
		if ( $old ) { wp_delete_post( $old->ID, true ); }
		$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => ${JSON.stringify(slug)}, 'post_name' => ${JSON.stringify(slug)} ] );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( json_decode( ${JSON.stringify(JSON.stringify(elements))}, true ) ) ) );
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
const widget = (id, widgetType, settings) => ({ id, elType: 'widget', widgetType, settings, elements: [] });
const container = (id, settings, elements) => ({ id, elType: 'container', settings, isInner: false, elements });

/** Row titles narrower than half their row, and elements sticking out of the list. */
const rowProblems = (page, scope) =>
	page.evaluate((scope) => {
		const out = { rows: 0, narrow: [], outside: [] };
		document.querySelectorAll(`${scope} .epm-episode-list`).forEach((list) => {
			const box = list.getBoundingClientRect();
			list.querySelectorAll('.epm-episode-row').forEach((row) => {
				out.rows++;
				const title = row.querySelector('.epm-episode-row__title');
				const r = row.getBoundingClientRect();
				if (title && title.getBoundingClientRect().width < r.width * 0.5) {
					out.narrow.push(`${title.textContent.trim()}: ${Math.round(title.getBoundingClientRect().width)}/${Math.round(r.width)}`);
				}
				row.querySelectorAll('*').forEach((el) => {
					const e = el.getBoundingClientRect();
					if (e.width && (e.right > box.right + 1 || e.left < box.left - 1)) {
						out.outside.push(`${el.className || el.tagName}: ${Math.round(e.left)}–${Math.round(e.right)} / ${Math.round(box.left)}–${Math.round(box.right)}`);
					}
				});
			});
		});
		return out;
	}, scope);

const created = [];
const browser = await launch();

try {
	// -----------------------------------------------------------------------
	await section('Row lists in narrow columns (WID-N1)', async () => {
		php(`epm()->design->apply_preset( 'business-tuning' ); echo 1;`);
		const narrow = { content_width: 'boxed', boxed_width: { unit: 'px', size: 420, sizes: [] }, width: { unit: 'px', size: 420, sizes: [] } };
		const [id, url] = elementorPage('epm-widgets-narrow', [
			container('n000001', narrow, [
				widget('n000002', 'epm-episode-list', { layout: 'list', number: 5 }),
				widget('n000003', 'epm-episode-list', { layout: 'editorial-rows', number: 5 }),
			]),
		]);
		created.push(id);
		for (const width of [1280, 390, 320]) {
			const page = await newPage(browser, { width, height: 900 });
			await page.goto(url);
			const result = await rowProblems(page, '.elementor-element-n000001');
			assert(result.rows >= 8 && result.narrow.length === 0, `${width} px: every title keeps at least half its row (${result.narrow.slice(0, 3).join('; ')})`);
			assert(result.outside.length === 0, `${width} px: nothing sticks out of the list (${result.outside.slice(0, 3).join('; ')})`);
			assert(await noOverflow(page), `${width} px: no horizontal scrolling`);
			await page.locator('.elementor-element-n000001').screenshot({ path: `screenshots/widgets-narrow-${width}.png` });
			await page.context().close();
		}
		// A row container sizes widgets by their content: lists and players
		// (size containers) must not collapse to nothing there.
		const [rowId, rowUrl] = elementorPage('epm-widgets-row', [
			container('w000001', { flex_direction: 'row' }, [
				widget('w000002', 'epm-episode-list', { number: 3 }),
				widget('w000003', 'text-editor', { editor: 'Hello world' }),
				widget('w000004', 'epm-podcast-player', { source: 'specific', episode_id: String(fx.ep1), layout: 'full' }),
			]),
		]);
		created.push(rowId);
		const rowPage = await newPage(browser, { width: 1280, height: 900 });
		await rowPage.goto(rowUrl);
		const widths = await rowPage.evaluate(() => ['w000002', 'w000004'].map((id) => Math.round(document.querySelector(`.elementor-element-${id}`).getBoundingClientRect().width)));
		assert(widths[0] >= 300 && widths[1] >= 300, `in a row container the list and the player keep a usable width (${widths.join(' / ')} px)`);
		const rowRows = await rowProblems(rowPage, '.elementor-element-w000002');
		assert(rowRows.narrow.length === 0 && rowRows.outside.length === 0, `and the list's titles keep their row (${JSON.stringify(rowRows).slice(0, 160)})`);
		await rowPage.screenshot({ path: 'screenshots/widgets-row-container.png' });
		await rowPage.context().close();

		const admin = await newPage(browser, { width: 1280, height: 900 });
		await login(admin);
		await admin.goto(`${BASE}/wp-admin/admin.php?page=epm-design`);
		const preview = await rowProblems(admin, '[data-epm-preview-canvas]');
		assert(preview.rows >= 1 && preview.narrow.length === 0 && preview.outside.length === 0, `the Design preview's rows keep their titles (${JSON.stringify(preview).slice(0, 200)})`);
		await admin.locator('[data-epm-preview]').screenshot({ path: 'screenshots/widgets-design-preview-rows.png' });
		await admin.context().close();
		php(`delete_option( 'epm_design_settings' ); echo 1;`);
	});

	// -----------------------------------------------------------------------
	await section('Two paginated lists on one page (WID-N2)', async () => {
		const [id, url] = elementorPage('epm-widgets-two-lists', [
			container('t000001', {}, [
				widget('t000002', 'epm-episode-list', { layout: 'list', number: 2, pagination: 'numbered', orderby: 'date' }),
				widget('t000003', 'epm-episode-list', { layout: 'list', number: 3, pagination: 'numbered', orderby: 'title', order: 'ASC' }),
			]),
		]);
		const [singleId, singleUrl] = elementorPage('epm-widgets-one-list', [container('o000001', {}, [widget('o000002', 'epm-episode-list', { layout: 'list', number: 2, pagination: 'numbered' })])]);
		created.push(id, singleId);
		const titles = (page) =>
			page.evaluate(() => ['t000002', 't000003'].map((id) => [...document.querySelectorAll(`.elementor-element-${id} .epm-episode-row__title`)].map((t) => t.textContent.trim())));
		const page = await newPage(browser);
		await page.goto(url);
		const first = await titles(page);
		assert(first[0].length === 2 && first[1].length === 3, `both lists on page 1 (${JSON.stringify(first)})`);
		await Promise.all([page.waitForNavigation(), page.locator('.elementor-element-t000002 .epm-pagination a', { hasText: '2' }).first().click()]);
		const second = await titles(page);
		assert(JSON.stringify(second[1]) === JSON.stringify(first[1]), `paging list A leaves list B on page 1 (${JSON.stringify(second)})`);
		assert(JSON.stringify(second[0]) !== JSON.stringify(first[0]) && second[0].length > 0, 'list A shows its page 2');
		assert(await page.locator('.elementor-element-t000002 .epm-pagination .current').textContent() === '2', 'list A marks page 2 as current');
		assert(await page.locator('.elementor-element-t000003 .epm-pagination .current').textContent() === '1', 'list B still marks page 1');
		await Promise.all([page.waitForNavigation(), page.locator('.elementor-element-t000003 .epm-pagination a', { hasText: '2' }).first().click()]);
		const both = await titles(page);
		assert(JSON.stringify(both[0]) === JSON.stringify(second[0]) && JSON.stringify(both[1]) !== JSON.stringify(first[1]), `then paging list B keeps list A on its page 2 (${JSON.stringify(both)})`);

		await page.goto(singleUrl.replace(/\/$/, '') + '/page/2/');
		const single = await page.evaluate(() => [...document.querySelectorAll('.epm-episode-row__title')].map((t) => t.textContent.trim()));
		assert(single.length === 2 && (await page.locator('.epm-pagination .current').textContent()) === '2', `a single list keeps /page/2/ (${JSON.stringify(single)})`);
		const link = await page.locator('.epm-pagination a').first().getAttribute('href');
		assert(/\/page\/\d+\/|epm-widgets-one-list\/?$/.test(link || ''), `and links in that form (${link})`);
		await page.context().close();
	});

	// -----------------------------------------------------------------------
	await section('Copied markup gets its own ids; the volume is named once', async () => {
		const [id, url] = shortcodePage('epm-widgets-clones', `[podcast_player id="${fx.ep1}" layout="full"]`);
		created.push(id);
		const page = await newPage(browser);
		await page.goto(url);
		await page.waitForFunction(() => window.epmPlayerEngine);
		const named = await page.getByRole('slider', { name: 'Volume', exact: true }).count();
		assert(named === 1, `the volume slider is announced as "Volume" (${named})`);
		const snapshot = await page.locator('.epm-player__volume').ariaSnapshot();
		assert((snapshot.match(/Volume/g) || []).length === 1, `and only once (${snapshot.replace(/\s+/g, ' ')})`);

		// A carousel copies slides (Swiper loop, Elementor's loop carousels).
		await page.evaluate(() => {
			const player = document.querySelector('[data-epm-player]');
			player.parentNode.appendChild(player.cloneNode(true));
			player.parentNode.appendChild(player.cloneNode(true));
		});
		await page.waitForTimeout(500);
		const ids = await page.evaluate(() => {
			const all = [...document.querySelectorAll('[id]')].map((el) => el.id);
			const dupes = all.filter((id, i) => all.indexOf(id) !== i);
			const refs = [...document.querySelectorAll('[aria-controls], label[for]')].map((el) => el.getAttribute('aria-controls') || el.getAttribute('for'));
			const toggles = [...document.querySelectorAll('[data-epm-share-toggle]')].map((t) => {
				const menu = document.getElementById(t.getAttribute('aria-controls'));
				return !!menu && t.closest('[data-epm-share]').contains(menu);
			});
			return { dupes, missing: refs.filter((r) => !document.getElementById(r)), toggles };
		});
		assert(ids.dupes.length === 0, `no duplicate ids after copying (${ids.dupes.join(', ')})`);
		assert(ids.missing.length === 0, `every aria-controls/for points to an element (${ids.missing.join(', ')})`);
		assert(ids.toggles.length === 3 && ids.toggles.every(Boolean), 'every share button controls its own menu');
		await page.locator('[data-epm-share-toggle]').nth(2).click();
		const open = await page.evaluate(() => [...document.querySelectorAll('[data-epm-share-menu]')].map((m) => !m.hidden));
		assert(JSON.stringify(open) === JSON.stringify([false, false, true]), `the copy opens its own menu (${JSON.stringify(open)})`);
		await page.keyboard.press('Escape');
		const focusedToggle = await page.evaluate(() => [...document.querySelectorAll('[data-epm-share-toggle]')].indexOf(document.activeElement));
		assert(focusedToggle === 2, 'Escape returns focus to the copy\'s button');
		assert(page.problems.length === 0, `no browser errors ${page.problems.join(' | ')}`);
		await page.context().close();
	});

	// -----------------------------------------------------------------------
	await section('Latest Episode: sticky player option', async () => {
		const [id, url] = elementorPage('epm-widgets-latest-sticky', [container('s000001', {}, [widget('s000002', 'epm-latest-episode', { sticky: 'yes', layout: 'full' })])]);
		const [offId, offUrl] = elementorPage('epm-widgets-latest-plain', [container('s000003', {}, [widget('s000004', 'epm-latest-episode', { layout: 'full' })])]);
		created.push(id, offId);
		const play = async (page) => {
			await page.locator('[data-epm-player] [data-epm-play]').first().click();
			await page.waitForTimeout(800);
			return page.evaluate(() => {
				const bar = document.querySelector('[data-epm-sticky]');
				return bar ? !bar.hidden : false;
			});
		};
		const page = await newPage(browser);
		await page.goto(url);
		assert(await play(page), 'sticky on: playing brings the sticky player');
		await page.goto(offUrl);
		assert(!(await play(page)), 'default: no sticky player');
		assert(page.problems.length === 0, `no browser errors ${page.problems.join(' | ')}`);
		await page.context().close();
	});
} catch (error) {
	console.log(`  ✗ ${error && error.stack ? error.stack.split('\n').slice(0, 4).join(' | ') : error}`);
	assert(false, 'the suite ran to the end');
} finally {
	await browser.close();
	php(`foreach ( ${JSON.stringify(created)} as $id ) { wp_delete_post( (int) $id, true ); } delete_option( 'epm_design_settings' ); echo 1;`);
}

finish('widgets');
