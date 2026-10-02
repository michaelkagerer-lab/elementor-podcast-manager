/** UX-N9: singular/plural preview labels and site-locale number formatting. */
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE, php, assert, finish, launch, newPage, login } from './lib.mjs';
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const LIB = `${ROOT}/tests/integration/lib.php`;
const saved = php(`require_once '${LIB}'; echo wp_json_encode( base64_encode( serialize( epm_test_option_snapshot( EPM\\AdminPages::SETUP_OPTION ) ) ) )`);
const browser = await launch();
try {
 php("update_option( EPM\\AdminPages::SETUP_OPTION, [ 'path' => 'external', 'resume' => 'connect' ] ); echo wp_json_encode( true )");
 const page = await newPage(browser);
 await login(page);
 let count = 1;
 await page.route('**/wp-admin/admin-ajax.php', async route => {
  const body = route.request().postData() || '';
  if (body.includes('epm_setup_save')) return route.fulfill({ json: { success: true, data: {} } });
  if (body.includes('epm_import_start')) return route.fulfill({ json: { success: true, data: { status: 'done', total: 1234, done: 1234, counts: { created: 1, updated: 1233 }, log: [{ action: 'created', title: 'Count probe' }] } } });
  if (!route.request().postData()?.includes('epm_import_preview')) return route.continue();
  await route.fulfill({ json: { success: true, data: { feed_url: 'https://feeds.example.test/counts.xml', channel: { title: 'Count probe' }, episodes: count, existing: 0, duplicates: [], blocked: 0, complete: true, token: 'count-probe' } } });
 });
 await page.goto(`${BASE}/wp-admin/admin.php?page=epm-setup&step=connect`);
 await page.fill('#epm-setup-feed', 'https://feeds.example.test/counts.xml');
 await page.click('[data-action="check-feed"]');
 await page.waitForSelector('[data-preview]:not([hidden])');
 const fact = page.locator('[data-preview-episodes]').locator('..');
 assert((await fact.textContent()).replace(/\s+/g,' ').trim() === '1 episode', 'a one-item feed uses the singular episode label');
 await page.evaluate(() => { document.documentElement.lang = 'de-DE'; });
 count = 1234;
 await page.click('[data-action="check-feed"]');
 await page.waitForFunction(() => document.querySelector('[data-preview-episodes]').textContent !== '1');
 assert(await page.locator('[data-preview-episodes]').textContent() === '1.234', 'preview numbers follow the site locale');
 assert((await fact.textContent()).includes('episodes'), 'a multi-item feed uses the plural label');
 await page.evaluate(() => wp.i18n.setLocaleData({
  '': { 'plural-forms': 'nplurals=2; plural=(n != 1);', lang: 'de' },
  '%1$s new episode': ['%1$s neue Folge', '%1$s neue Folgen'],
  '%1$s updated episode': ['%1$s aktualisierte Folge', '%1$s aktualisierte Folgen'],
 }, 'elementor-podcast-manager'));
 await page.click('[data-import-button]');
 await page.waitForSelector('[data-panel="import"]:not([hidden])');
 const summary = await page.locator('[data-import-summary]').textContent();
 assert(summary.includes('1 neue Folge'), 'import results use a complete translated singular phrase');
 assert(summary.includes('1.233 aktualisierte Folgen'), 'import results use a complete translated plural phrase and localized count');
 assert((await page.locator('[data-import-count]').textContent()).includes('1.234'), 'import progress uses localized numbers');
 assert(page.problems.length === 0, `no browser errors: ${page.problems.join('; ')}`);
 await page.context().close();
} finally {
 php(`require_once '${LIB}'; epm_test_option_restore( EPM\\AdminPages::SETUP_OPTION, unserialize( base64_decode( '${saved}' ) ) ); echo wp_json_encode( true )`);
 await browser.close();
}
finish('i18n-counts');
