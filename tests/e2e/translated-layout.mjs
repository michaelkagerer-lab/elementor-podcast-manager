/** UX-N10: German-length labels fit real admin screens and the share menu. */
import { BASE, assert, finish, launch, newPage, login, noOverflow, fixtures } from './lib.mjs';
const browser = await launch();
try {
 const page = await newPage(browser, { width: 390, height: 844 });
 await login(page);
 for (const screen of ['epm-dashboard', 'epm-hosting', 'epm-distribution', 'epm-settings', 'epm-setup&step=done']) {
  await page.goto(`${BASE}/wp-admin/admin.php?page=${screen}`);
  assert(await page.locator('.epm-app, .epm-settings').count() > 0, `the real ${screen} screen renders`);
  await page.evaluate(() => {
   document.querySelectorAll('.epm-app .button, .epm-settings .button').forEach(button => {
    if (button.offsetWidth && !button.querySelector('input')) button.textContent = 'Podcast-Veröffentlichungseinstellungen überprüfen und fortfahren';
   });
  });
  assert(await noOverflow(page), `German-length action labels fit ${screen} at 390px`);
 }
 await page.setViewportSize({ width: 320, height: 640 });
 await page.goto(`${BASE}/?p=${fixtures().ep1}`);
 await page.locator('.epm-player [data-epm-share-toggle]').first().click();
 const item = page.locator('.epm-player .epm-share__item').first();
 await item.evaluate(el => { const label = el.querySelector('span:last-child') || el; label.textContent = 'Wiedergabeposition in die Zwischenablage kopieren'; });
 const fits = await item.evaluate(el => el.scrollWidth <= el.clientWidth + 1);
 assert(fits, 'the translated share item wraps without clipping at 320px');
 assert(await noOverflow(page), 'the translated share menu stays inside the viewport');
 await page.context().close();
} finally { await browser.close(); }
finish('translated-layout');
