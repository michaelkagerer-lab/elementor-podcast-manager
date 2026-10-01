/** UX-N5: a media selection keeps keyboard focus when its lookup finishes. */
import { BASE, fixtures, assert, finish, launch, newPage, login, section } from './lib.mjs';

const fx = fixtures();
const browser = await launch();
try {
	await section('Audio picker focus after selecting and replacing a file', async () => {
		const page = await newPage(browser);
		await login(page);
		await page.route('**/admin-ajax.php', async (route) => {
			if ((route.request().postData() || '').includes('action=epm_audio_describe')) {
				// The modal closes before the real metadata response re-enables its opener.
				const response = await route.fetch();
				await new Promise((resolve) => setTimeout(resolve, 250));
				await route.fulfill({ response });
			} else {
				await route.continue();
			}
		});
		await page.goto(`${BASE}/wp-admin/post.php?post=${fx.no_audio}&action=edit`);
		const choose = page.locator('[data-epm-choose-audio]');
		for (const id of [fx.audio_1, fx.audio_2]) {
			await choose.focus();
			await page.keyboard.press('Enter');
			const modal = page.locator('.media-modal:visible');
			await modal.locator(`.attachment[data-id="${id}"]`).click({ timeout: 20000 });
			await modal.locator('.media-button-select').click();
			await page.waitForFunction((id) => Number(document.querySelector('[data-epm-audio-id]').value) === id && !document.querySelector('[data-epm-choose-audio]').disabled, id, { timeout: 10000 });
			assert(await choose.evaluate((button) => button === document.activeElement), `selecting file ${id} returns focus to Replace audio`);
		}
		assert(page.problems.length === 0, `no browser errors ${page.problems.join(' | ')}`);
		await page.context().close();
	});
} finally {
	await browser.close();
}
finish('media focus');
