/** UX-N14: a focused chapter must stay above the sticky player. */
import { BASE, assert, finish, launch, newPage, section } from './lib.mjs';

const browser = await launch(['--autoplay-policy=no-user-gesture-required']);
try {
	await section('Focused chapter on a short phone viewport', async () => {
		const page = await newPage(browser, { width: 390, height: 320 });
		await page.goto(`${BASE}/epm-elementor/`);
		await page.locator('[data-epm-player] [data-epm-play]').first().click();
		await page.locator('[data-epm-sticky]').waitFor({ state: 'visible' });
		await page.waitForFunction(() => document.querySelector('[data-epm-sticky]').getAnimations().every((animation) => animation.playState !== 'running'));
		await page.evaluate(() => {
			const target = document.querySelector('.epm-chapters__seek');
			const bar = document.querySelector('[data-epm-sticky]');
			const rect = target.getBoundingClientRect();
			window.scrollBy({ top: rect.top - (bar.getBoundingClientRect().top - rect.height + 16), behavior: 'instant' });
			// Already in the viewport: native focus need not scroll around an overlay.
			target.focus({ preventScroll: true });
		});
		await page.waitForFunction(() => document.activeElement.getBoundingClientRect().bottom <= document.querySelector('[data-epm-sticky]').getBoundingClientRect().top - 7, null, { timeout: 2000 }).catch(() => {});
		const geometry = await page.evaluate(() => ({ chapter: document.activeElement.getBoundingClientRect().bottom, bar: document.querySelector('[data-epm-sticky]').getBoundingClientRect().top, focused: document.activeElement.matches('.epm-chapters__seek') }));
		assert(geometry.focused && geometry.chapter <= geometry.bar - 7, `the focused chapter is fully visible above the bar (${JSON.stringify(geometry)})`);
		assert(page.problems.length === 0, `no browser errors ${page.problems.join(' | ')}`);
		await page.context().close();
	});
} finally {
	await browser.close();
}
finish('sticky focus');
