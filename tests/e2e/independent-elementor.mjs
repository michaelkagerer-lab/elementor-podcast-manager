/** IA-01: discover podcast widgets in the real sidebar, not only saved documents. */
import { BASE, php, assert, finish, launch, newPage, login } from './lib.mjs';
const fx = php("echo wp_json_encode(get_option('epm_test_fixtures'));");
const browser = await launch();
try {
 const page = await newPage(browser, { width: 1500, height: 1000 });
 await login(page);
 await page.goto(`${BASE}/wp-admin/post.php?post=${fx.elementor_page}&action=elementor`);
 await page.waitForFunction(() => window.elementor?.loaded && window.$e, null, {timeout: 60000});
 await page.locator('#elementor-loading').waitFor({state:'hidden', timeout:60000});
 await page.keyboard.press('Escape');
 await page.getByRole('dialog').waitFor({state:'hidden', timeout:10000});
 await page.evaluate(() => window.$e.route('panel/elements/categories'));
 await page.locator('#elementor-panel-elements-search-input').waitFor({timeout:20000});
 const category = page.locator('#elementor-panel-category-epm-podcast');
 assert(await category.count() === 1, 'the real widget sidebar contains the Podcast category');
 if (await category.count()) {
  await category.locator('.elementor-panel-category-title').click();
  const widgets = category.locator('.elementor-element-wrapper');
  await widgets.first().waitFor();
  assert(await widgets.count() === 12, 'all twelve widget tiles are available to site builders');
  await page.screenshot({path:'screenshots/independent-elementor-library.png'});
  const search = page.locator('#elementor-panel-elements-search-input');
  await search.fill('Podcast Player');
  const tile = page.locator('#elementor-panel .elementor-element-wrapper').filter({has:page.getByText('Podcast Player', {exact:true})});
  await tile.waitFor();
  assert(await tile.isVisible(), 'the player is discoverable through the native search');
  const frame = page.frameLocator('#elementor-preview-iframe');
  const players = frame.locator('.elementor-widget-epm-podcast-player');
  const before = await players.count();
  await tile.dragTo(frame.locator('.elementor-element-c0ffee1').first());
  await page.waitForFunction((before) => document.querySelector('#elementor-preview-iframe').contentDocument.querySelectorAll('.elementor-widget-epm-podcast-player').length > before, before, {timeout:15000});
  assert(await players.count() > before, 'a searched widget can be inserted through drag and drop');
 } else {
  await page.screenshot({path:'screenshots/independent-elementor-library-missing.png'});
 }
 assert(page.problems.length===0, `no plugin browser errors: ${page.problems.join(' | ')}`);
 await page.context().close();
} finally {await browser.close();}
finish('independent Elementor');
