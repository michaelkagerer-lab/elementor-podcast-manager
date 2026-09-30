/**
 * Browser tests for the admin screens (Playwright/Chromium) against a site
 * seeded with tests/fixtures/seed.php.
 *
 *   cd tests/e2e && WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp node admin.mjs
 *
 * Suites: Design screen (live preview, preset tiles, contrast, save),
 * episode editor (next number, paste chapters, half-filled rows), episode
 * list (Quick Edit), keyboard focus and narrow screens. The design option
 * and every episode touched are restored at the end.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const BASE = process.env.WP_URL || 'http://localhost:8889';
const WP = process.env.WP_CLI || 'wp';
const wp = (args) => execFileSync(WP, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
const line = (output, prefix) => output.split('\n').find((l) => l.startsWith(prefix)) || '';
const fixtures = JSON.parse(line(wp(['option', 'get', 'epm_test_fixtures', '--format=json']), '{'));
// The design option may not exist yet (defaults): then it is deleted again.
const readDesign = () => {
	try {
		return line(wp(['option', 'get', 'epm_design_settings', '--format=json']), '{');
	} catch (e) {
		return '';
	}
};
const savedDesign = readDesign();
// WP-CLI output may carry notices from other plugins: keep the ID line.
const firstNumber = (output) => Number(output.split('\n').map((l) => l.trim()).find((l) => /^\d+$/.test(l)));
// Other plugins may print notices: read the value from a marked line.
const meta = (id, key) => line(wp(['eval', `echo "EPMV:" . get_post_meta( ${Number(id)}, '${key}', true ), "\\n";`]), 'EPMV:').slice(5).trim();

fs.mkdirSync('screenshots', { recursive: true });

let failures = 0;
const assert = (condition, message) => {
	console.log(`  ${condition ? '✓' : '✗'} ${message}`);
	if (!condition) failures++;
};

const browser = await chromium.launch({
	headless: true,
	...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}),
});

async function newPage(viewport = { width: 1280, height: 900 }) {
	const context = await browser.newContext({ viewport });
	await context.route((url) => url.origin !== new URL(BASE).origin, (route) => route.abort('blockedbyclient'));
	const page = await context.newPage();
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
	await page.goto(`${BASE}/wp-login.php`);
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'admin');
	await Promise.all([page.waitForURL(/\/wp-admin\//), page.click('#wp-submit')]);
	return page;
}

const noOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);

try {
	// -----------------------------------------------------------------------
	console.log('Design screen');
	{
		const page = await newPage();
		await page.goto(`${BASE}/wp-admin/admin.php?page=epm-design`);
		const canvasVar = (name) => page.evaluate((n) => document.querySelector('[data-epm-preview-canvas]').style.getPropertyValue(n).trim(), name);

		await page.fill('#epm-d-accent', '#ff0055');
		assert(await canvasVar('--epm-accent') === '#ff0055', 'preview updates live when a color changes');
		await page.fill('#epm-d-title_font_size', '31');
		const titleSize = await page.evaluate(() => getComputedStyle(document.querySelector('[data-epm-preview-canvas] .epm-player__title')).fontSize);
		assert(titleSize === '31px', `preview title follows the size field (${titleSize})`);
		await page.check('input[name="epm_design_settings[button_shape]"][value="square"]');
		assert(await canvasVar('--epm-button-radius') === '2px', 'button shape maps to --epm-button-radius');
		await page.selectOption('#epm-d-default_episode_layout', 'cards');
		const lists = await page.evaluate(() => ({
			cards: document.querySelector('[data-epm-preview-list="cards"]').hidden,
			rows: document.querySelector('[data-epm-preview-list="rows"]').hidden,
		}));
		assert(!lists.cards && lists.rows, 'episode list layout switches the preview to cards');
		assert((await page.textContent('[data-epm-dirty]')).trim() !== '', 'unsaved changes are flagged');

		await page.fill('#epm-d-text', '#eeeeee');
		const failing = await page.evaluate(() => {
			const item = document.querySelector('[data-epm-contrast-pair][data-fg="text"][data-bg="background"]');
			return { fail: item.classList.contains('is-fail'), ratio: item.querySelector('[data-epm-contrast-ratio]').textContent };
		});
		assert(failing.fail && /^1[.,]\d:1$/.test(failing.ratio), `contrast badge flags low text contrast (${failing.ratio})`);
		await page.fill('#epm-d-text', '#111827');
		assert(await page.evaluate(() => document.querySelector('[data-epm-contrast-pair][data-fg="text"][data-bg="background"]').classList.contains('is-pass')), 'contrast badge passes again');

		await page.check('[data-epm-track-auto]');
		const track = await page.evaluate(() => ({ value: document.querySelector('#epm-d-track_color').value, readonly: document.querySelector('#epm-d-track_color').readOnly }));
		assert(track.value === '' && track.readonly, 'Automatic track clears the color');
		assert(await canvasVar('--epm-track') === '', 'automatic track leaves --epm-track unset');

		await page.fill('#epm-d-muted', 'purple');
		await page.locator('#epm-d-muted').blur();
		assert(!(await page.locator('#epm-d-muted-error').isHidden()) && (await page.getAttribute('#epm-d-muted', 'aria-invalid')) === 'true', 'invalid hex shows an error next to the field');
		await page.fill('#epm-d-muted', '#6b7280');
		assert(await page.locator('#epm-d-muted-error').isHidden(), 'error clears once the value is valid');

		// Preset tiles: arrow keys move the selection and preview it.
		const presets = await page.evaluate(() => window.epmDesign.presets);
		await page.focus('input[name="epm_preset"]:checked');
		await page.keyboard.press('ArrowRight');
		const picked = await page.evaluate(() => document.querySelector('input[name="epm_preset"]:checked').value);
		assert(picked !== 'neutral' && (await page.evaluate(() => document.activeElement.name)) === 'epm_preset', `arrow key selects the next preset tile (${picked})`);
		assert(await canvasVar('--epm-background') === presets[picked].values.background, 'preview shows the selected preset');
		assert(await page.isVisible('[data-epm-preview-mine]'), 'preview says the preset is not applied yet');
		await page.click('[data-epm-preview-mine]');
		assert(await canvasVar('--epm-accent') === '#ff0055', '"Show my design" returns to the edited values');

		// Discard restores the saved values.
		await page.click('[data-epm-design-reset]');
		await page.waitForTimeout(100);
		assert((await page.inputValue('#epm-d-accent')) !== '#ff0055' && (await page.textContent('[data-epm-dirty]')).trim() === '', 'discard changes restores the saved design');

		// Save.
		await page.fill('#epm-d-title_font_size', '27');
		await page.check('input[name="epm_design_settings[shadow]"][value="soft"]');
		await Promise.all([page.waitForURL(/settings-updated=true/), page.click('#epm-design-form [type="submit"][name="submit"]')]);
		const stored = JSON.parse(readDesign());
		assert(stored.title_font_size === '27' && stored.shadow === 'soft', 'saving stores the new values');
		assert(await canvasVar('--epm-shadow') !== 'none', 'saved values render in the preview');

		// Apply asks for confirmation; Escape cancels.
		await page.check('input[name="epm_preset"][value="warm-paper"]');
		await page.click('[data-epm-preset-apply]');
		assert(await page.evaluate(() => document.querySelector('[data-epm-preset-dialog]').open), 'applying a preset asks for confirmation');
		await page.keyboard.press('Escape');
		assert(!(await page.evaluate(() => document.querySelector('[data-epm-preset-dialog]').open)) && (await page.evaluate(() => document.activeElement.matches('[data-epm-preset-apply]'))), 'Escape cancels and returns focus to the apply button');
		await page.click('[data-epm-preset-apply]');
		await Promise.all([page.waitForURL(/epm_design=preset-applied/), page.click('[data-epm-dialog-confirm]')]);
		const applied = JSON.parse(readDesign());
		assert(applied.preset === 'warm-paper' && applied.font_family === 'serif', 'confirmed preset is applied');
		assert(await page.isVisible('label[data-epm-preset-tile="warm-paper"] .epm-preset__badge'), 'the applied preset is marked "In use"');

		// Keyboard: every focusable control shows a focus indicator.
		await page.goto(`${BASE}/wp-admin/admin.php?page=epm-design`);
		await page.focus('#wpbody-content');
		const unringed = [];
		for (let i = 0; i < 70; i++) {
			await page.keyboard.press('Tab');
			const info = await page.evaluate(() => {
				const el = document.activeElement;
				if (!el || !el.closest('.epm-design')) {
					return null;
				}
				const own = getComputedStyle(el);
				const tile = el.closest('.epm-preset, .epm-chip');
				const ring = tile ? getComputedStyle(tile) : own;
				const visible = (own.outlineStyle !== 'none' && own.outlineWidth !== '0px') || own.boxShadow !== 'none' || (ring.outlineStyle !== 'none' && ring.outlineWidth !== '0px');
				return visible ? null : (el.id || el.name || el.className || el.tagName);
			});
			if (info) {
				unringed.push(info);
			}
		}
		assert(unringed.length === 0, `keyboard focus is visible on the Design screen ${unringed.join(', ')}`);
		assert(await noOverflow(page), 'no horizontal overflow at 1280px');
		await page.screenshot({ path: 'screenshots/admin-design.png' });
		assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
		await page.context().close();
	}

	{
		const page = await newPage({ width: 390, height: 844 });
		await page.goto(`${BASE}/wp-admin/admin.php?page=epm-design`);
		assert(await noOverflow(page), 'Design screen has no horizontal overflow at 390px');
		const order = await page.evaluate(() => {
			const top = (s) => document.querySelector(s).getBoundingClientRect().top;
			return top('.epm-design__presets') < top('.epm-design__preview') && top('.epm-design__preview') < top('.epm-design__form');
		});
		assert(order, 'on phones the preview follows the presets');
		await page.screenshot({ path: 'screenshots/admin-design-mobile.png', fullPage: true });
		assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
		await page.context().close();
	}

	// -----------------------------------------------------------------------
	console.log('Episode editor');
	{
		const page = await newPage({ width: 1280, height: 900 });
		await page.goto(`${BASE}/wp-admin/post-new.php?post_type=podcast_episode`);
		await page.waitForSelector('#title');
		const next = await page.evaluate(() => JSON.parse(document.querySelector('[data-epm-next-hint]').dataset.epmNextNumbers));
		assert(await page.isVisible('[data-epm-next-number]') && (await page.inputValue('#epm-episode-number')) === '', 'a new episode suggests the next number without filling it in');
		await page.fill('#epm-season-number', '2');
		assert((await page.textContent('[data-epm-next-hint]')).includes(String(next.seasons['2'] || 1)), 'the suggestion follows the season');
		await page.click('[data-epm-next-number]');
		assert((await page.inputValue('#epm-episode-number')) === String(next.seasons['2'] || 1), '"Use next number" fills the field');
		assert(await page.isHidden('[data-epm-next-number]'), 'the button disappears once a number is set');
		await page.fill('#epm-episode-number', '77');
		await page.fill('#epm-season-number', '');
		assert((await page.inputValue('#epm-episode-number')) === '77', 'a typed number is never replaced');

		await page.click('[data-epm-paste-chapters] summary');
		await page.fill('[data-epm-paste-text]', 'Timestamps\n00:00 Intro\n1:02 - Topic\n(12:30) Q&A\n12:45 – Listener mail\n[1:02:03] Wrap-up https://example.com/notes\nnot a chapter');
		await page.click('[data-epm-paste-apply]');
		const rows = await page.$$eval('[data-epm-repeat="chapters"] [data-epm-repeat-rows] [data-epm-repeat-row]', (list) => list.map((r) => [r.querySelector('[name$="[time]"]').value, r.querySelector('[name$="[title]"]').value, r.querySelector('[name$="[url]"]').value]));
		assert(JSON.stringify(rows) === JSON.stringify([['0:00', 'Intro', ''], ['1:02', 'Topic', ''], ['12:30', 'Q&A', ''], ['12:45', 'Listener mail', ''], ['1:02:03', 'Wrap-up', 'https://example.com/notes']]), `paste chapters fills the rows (${JSON.stringify(rows)})`);
		const result = await page.textContent('[data-epm-paste-result]');
		assert(/5 chapters/.test(result) && /2 lines were skipped/.test(result) && result.includes('not a chapter'), 'says how many chapters were read and which lines were skipped');
		const order = await page.evaluate(() => {
			const rowsEl = [...document.querySelectorAll('[data-epm-repeat="chapters"] [data-epm-repeat-rows] [data-epm-repeat-row]')];
			return [rowsEl[0].querySelector('[data-epm-repeat-up]').getAttribute('aria-disabled'), rowsEl[rowsEl.length - 1].querySelector('[data-epm-repeat-down]').getAttribute('aria-disabled')];
		});
		assert(order[0] === 'true' && order[1] === 'true', 'first "up" and last "down" are marked unavailable');
		await page.screenshot({ path: 'screenshots/admin-paste-chapters.png' });

		// A half-filled row stops the save with an error next to it.
		await page.fill('#title', 'E2E admin episode');
		await page.click('[data-epm-repeat="chapters"] [data-epm-repeat-add]');
		const focused = await page.evaluate(() => document.activeElement.name || '');
		assert(/\[time\]$/.test(focused), 'a new chapter row takes focus');
		await page.keyboard.type('Title only');
		await page.fill('[data-epm-repeat="chapters"] [data-epm-repeat-row]:last-child [name$="[time]"]', '');
		await page.fill('[data-epm-repeat="chapters"] [data-epm-repeat-row]:last-child [name$="[title]"]', 'Title only');
		// Leaving the title starts a WordPress autosave, which disables the
		// save buttons until it answers: wait for it before saving.
		await page.waitForFunction(() => !document.querySelector('#save-post').classList.contains('disabled'), null, { timeout: 15000 }).catch(() => {});
		await page.click('#save-post');
		await page.waitForTimeout(300);
		const blocked = await page.evaluate(() => ({
			url: location.href,
			error: document.querySelector('[data-epm-repeat="chapters"] [data-epm-repeat-row]:last-child [data-epm-repeat-error]').textContent,
			focus: document.activeElement.name || '',
		}));
		// The blocked save must not leave the buttons disabled (an autosave
		// may still disable them for a moment).
		blocked.disabled = await page.waitForFunction(() => !document.querySelector('#save-post').classList.contains('disabled'), null, { timeout: 5000 }).then(() => false, () => true);
		assert(/post-new\.php/.test(blocked.url) && blocked.error.length > 0 && /\[time\]$/.test(blocked.focus), 'a half-filled chapter stops the save and focuses the missing field');
		assert(!blocked.disabled, 'the save buttons stay usable');
		await page.click('[data-epm-repeat="chapters"] [data-epm-repeat-row]:last-child [data-epm-repeat-remove]');
		await Promise.all([page.waitForURL(/post\.php\?post=\d+&action=edit/), page.click('#save-post')]);
		const postId = await page.inputValue('#post_ID');
		const saved = JSON.parse(line(wp(['eval', `echo wp_json_encode( epm()->episodes->get_data( ${Number(postId)} ) ), "\\n";`]), '{'));
		assert(saved.chapters.length === 5 && saved.chapters[4].url === 'https://example.com/notes' && String(saved.episode_number) === '77', 'pasted chapters and the number are saved');
		assert(await noOverflow(page), 'no horizontal overflow in the editor');
		wp(['post', 'delete', String(postId), '--force']);
		assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
		await page.context().close();
	}

	{
		// Transcript file: pick a .vtt in the Media Library; saving with an
		// empty transcript fills the text from the file.
		const vttPath = `${process.cwd()}/screenshots/e2e-transcript.vtt`;
		fs.writeFileSync(vttPath, 'WEBVTT\n\n00:00:00.000 --> 00:00:03.000\n<v Host>Hello from the caption file.\n');
		const fileId = firstNumber(wp(['media', 'import', vttPath, '--porcelain']));
		const draftId = firstNumber(wp(['post', 'create', '--post_type=podcast_episode', '--post_status=draft', '--post_title=E2E transcript', '--porcelain']));
		const page = await newPage({ width: 1280, height: 900 });
		await page.goto(`${BASE}/wp-admin/post.php?post=${draftId}&action=edit`);
		await page.locator('[data-epm-transcript-choose]').scrollIntoViewIfNeeded();
		await page.click('[data-epm-transcript-choose]');
		const tile = page.locator(`.media-modal li.attachment[data-id="${fileId}"]`);
		await tile.waitFor({ timeout: 15000 });
		const others = await page.locator('.media-modal li.attachment').count();
		await tile.click();
		await page.click('.media-modal .media-button-select');
		assert((await page.textContent('[data-epm-transcript-name]')).includes('e2e-transcript') && (await page.textContent('[data-epm-transcript-type]')) === 'WebVTT', `the chosen transcript file is shown by name (${others} file(s) offered)`);
		assert(await page.isVisible('[data-epm-transcript-remove]'), 'a named remove button appears');
		await Promise.all([page.waitForURL(/message=/), page.click('#save-post')]);
		const text = line(wp(['eval', `echo "EPMV:" . wp_json_encode( get_post_meta( ${draftId}, '_epm_transcript', true ) ), "\\n";`]), 'EPMV:');
		assert(meta(draftId, '_epm_transcript_file_id') === String(fileId) && text.includes('Hello from the caption file.'), 'saving fills the transcript text from the file');
		assert(await page.isVisible('[data-epm-transcript-current]'), 'the file stays listed after saving');
		await page.screenshot({ path: 'screenshots/admin-transcript-file.png' });
		wp(['post', 'delete', String(draftId), '--force']);
		wp(['post', 'delete', String(fileId), '--force']);
		assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
		await page.context().close();
	}

	{
		const page = await newPage({ width: 390, height: 844 });
		await page.goto(`${BASE}/wp-admin/post.php?post=${fixtures.ep1}&action=edit`);
		await page.waitForSelector('#title');
		assert(await noOverflow(page), 'episode editor has no horizontal overflow at 390px');
		await page.screenshot({ path: 'screenshots/admin-editor-mobile.png', fullPage: true });
		assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
		await page.context().close();
	}

	// -----------------------------------------------------------------------
	console.log('Episode list');
	{
		const id = fixtures.ep3;
		const before = { number: meta(id, '_epm_episode_number'), type: meta(id, '_epm_episode_type'), explicit: meta(id, '_epm_explicit') };
		const page = await newPage({ width: 1280, height: 900 });
		await page.goto(`${BASE}/wp-admin/edit.php?post_type=podcast_episode`);
		const row = page.locator(`#post-${id}`);
		await row.hover();
		await row.locator('button.editinline').click();
		await page.waitForSelector(`#edit-${id}`);
		const filled = await page.evaluate((postId) => ['number', 'type'].map((k) => document.querySelector(`#edit-${postId} [data-epm-quick="${k}"]`).value), id);
		assert(filled[0] === String(before.number) && filled[1] === (before.type || 'full'), `Quick Edit shows the episode values (${filled.join(', ')})`);
		await page.screenshot({ path: 'screenshots/admin-quick-edit.png' });
		await page.fill(`#edit-${id} [data-epm-quick="number"]`, '33');
		await page.selectOption(`#edit-${id} [data-epm-quick="explicit"]`, 'clean');
		await page.click(`#edit-${id} button.save`);
		await page.waitForSelector(`#post-${id} .column-epm_episode_no`);
		await page.waitForFunction((postId) => /33/.test(document.querySelector(`#post-${postId} .column-epm_episode_no`).textContent), id, { timeout: 15000 });
		assert(meta(id, '_epm_episode_number') === '33' && meta(id, '_epm_explicit') === 'clean', 'Quick Edit saves the episode number and explicit flag');
		wp(['post', 'meta', 'update', String(id), '_epm_episode_number', String(before.number)]);
		wp(['post', 'meta', 'update', String(id), '_epm_explicit', before.explicit || 'inherit']);
		assert(await page.locator('th#taxonomy-podcast_topic, th.column-taxonomy-podcast_topic').count() > 0, 'the list has a Topics column');
		assert(page.problems.length === 0, `no browser errors ${page.problems.join('; ')}`);
		await page.context().close();
	}
} finally {
	if (savedDesign) {
		wp(['option', 'update', 'epm_design_settings', savedDesign, '--format=json']);
	} else {
		try {
			wp(['option', 'delete', 'epm_design_settings']);
		} catch (e) {
			// Already absent.
		}
	}
	await browser.close();
}

console.log(failures ? `\n${failures} admin browser check(s) failed.` : '\nAll admin browser checks passed.');
process.exit(failures ? 1 : 0);
