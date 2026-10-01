/** UX-N3: blocked clipboard access offers a real fallback on every copy screen. */
import { BASE, assert, finish, launch, newPage, login, section } from './lib.mjs';

const browser = await launch();
try {
	for (const screen of ['setup', 'hosting', 'distribution']) {
		await section(`Feed copy on ${screen}`, async () => {
			const page = await newPage(browser);
			await login(page);
			await page.goto(`${BASE}/wp-admin/admin.php?page=epm-${screen}`);
			const button = page.locator('[data-copy], [data-epm-copy]').first();
			// Exercise the rendered copy control independently of the setup/import journey.
			await button.evaluate((button) => {
				for (let node = button; node; node = node.parentElement) node.hidden = false;
			});
			const value = await button.getAttribute('data-epm-copy') || await button.getAttribute('data-copy');
			for (const mode of ['rejected', 'unavailable', 'legacy-success']) {
				await page.evaluate((mode) => {
					window.__legacyCopies = 0;
					Object.defineProperty(navigator, 'clipboard', { configurable: true, value: mode === 'unavailable' ? undefined : { writeText: () => Promise.reject(new Error('Permission denied')) } });
					document.execCommand = () => { window.__legacyCopies++; return mode === 'legacy-success'; };
					window.getSelection().removeAllRanges();
				}, mode);
				await button.click();
				await page.waitForFunction(() => window.__legacyCopies > 0, null, { timeout: 3000 }).catch(() => {});
				const result = await page.evaluate(() => ({ calls: window.__legacyCopies, selected: window.getSelection().toString(), live: [...document.querySelectorAll('[aria-live="assertive"]')].map((node) => node.textContent).join(' ') }));
				assert(result.calls === 1, `${mode}: uses the legacy fallback once (${result.calls})`);
				if (mode === 'legacy-success') {
					assert(await button.evaluate((button) => button.classList.contains('is-copied') || button.textContent === 'Copied'), 'successful fallback confirms the copy');
				} else {
					assert(result.selected === value, `${mode}: selects the visible feed address for manual copying`);
					assert(/Couldn.t copy automatically/.test(result.live), `${mode}: announces failure assertively`);
					assert(!(await button.evaluate((button) => button.classList.contains('is-copied') || button.textContent === 'Copied')), 'failed copying never claims success');
				}
			}
			assert(page.problems.length === 0, `no browser errors ${page.problems.join(' | ')}`);
			await page.context().close();
		});
	}
} finally {
	await browser.close();
}
finish('copy');
