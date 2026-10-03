import { BASE, php, assert, finish, launch, newPage, login, noOverflow, focusRingVisible } from './lib.mjs';
const browser = await launch();
const starterState = php("echo wp_json_encode(get_option(\\EPM\\AdminPages::SETUP_OPTION));");
let starter = null;
const saved = php("echo wp_json_encode(get_option('epm_design_settings'));");
try {
 const page = await newPage(browser, {width:390,height:844});
 await login(page);
 await page.goto(`${BASE}/wp-admin/admin.php?page=epm-design`);
 assert(await page.locator('.epm-design').evaluate(el=>el.scrollHeight<3500), 'mobile design starts with a compact overview');
 assert(await page.locator('[data-epm-preview-viewport]').evaluate(el=>el.clientHeight<=440), 'mobile preview stays near the editing task');
 const folds = page.locator('[data-epm-design-form] details');
 assert(await folds.count()>=5, 'advanced controls use named disclosures');
 if (await folds.count()) {
  await folds.first().locator(':scope > summary').focus(); await page.keyboard.press('Enter');
  assert(await folds.first().evaluate(el=>el.open), 'keyboard opens controls');
  assert(await focusRingVisible(page), 'disclosure has visible keyboard focus');
  await page.locator('#epm-d-accent').fill('#245678');
  await page.locator('#epm-details > summary').click();
  const share = page.locator('#epm-details-player-show-share');
  await share.setChecked(!(await share.isChecked())); const expected=await share.isChecked();
  await page.locator('#epm-design-save-submit').click(); await page.waitForURL(/settings-updated/);
  const value=php("echo wp_json_encode(array_merge(epm()->design->all(), ['effective_details'=>\\EPM\\Details::effective('player')]));");
  assert(value.accent==='#245678' && value.effective_details.show_share===expected, 'one save persists appearance and details');
  await page.locator('#epm-details > summary').click();
  await page.locator('[data-epm-details-reset]').click();
  await page.locator('#epm-design-save-submit').click();
  await page.waitForURL(/settings-updated/);
  await page.waitForLoadState('domcontentloaded');
  const reset=php("echo wp_json_encode(epm()->design->all());");
  assert(Object.keys(reset.details).length===0 && reset.accent==='#245678', 'built-in reset clears details overrides and retains the design');
 }
 assert(await noOverflow(page), 'design fits mobile width');
 await page.locator('.epm-design').screenshot({path:'screenshots/ux-clarity-design-390.png'});
 await page.goto(`${BASE}/wp-admin/admin.php?page=epm-distribution`);
 assert(await page.locator('[data-epm-distribution]').evaluate(el=>el.scrollHeight<4000), 'distribution prioritizes essential platforms');
 await page.locator('[data-epm-distribution]').screenshot({path:'screenshots/ux-clarity-distribution-390.png'});
 await page.goto(`${BASE}/wp-admin/admin.php?page=epm-distribution#epm-dir-pocketcasts`);
 assert(await page.locator('#epm-dir-pocketcasts').isVisible(), 'direct links reveal a collapsed directory group');
 await page.goto(`${BASE}/wp-admin/admin.php?page=epm-dashboard`);
 assert(await page.locator('[data-epm-next-action] .button-primary').count()===1,'dashboard offers one primary next action');
 await page.locator('.epm-dashboard').screenshot({path:'screenshots/ux-clarity-dashboard-390.png'});
 await page.setViewportSize({width:1280,height:900});
 for(const screen of ['design','dashboard','distribution']) {
  await page.goto(`${BASE}/wp-admin/admin.php?page=epm-${screen}`); assert(await noOverflow(page),`${screen} fits desktop`);
  await page.locator('.epm-app').screenshot({path:`screenshots/ux-clarity-${screen}-1280.png`});
 }
 starter = php("\\EPM\\AdminPages::update_setup_state(['page_id'=>0]); echo wp_json_encode(\\EPM\\AdminPages::create_podcast_page());");
 await page.goto(starter.url);
 assert(await page.locator('.elementor-widget-epm-latest-episode').count()===1, 'starter renders a real latest-episode widget');
 assert(await page.locator('.elementor-widget-epm-episode-list').count()===1, 'starter renders an episode archive');
 assert(await page.locator('h2').filter({hasText:'All episodes'}).count()>=1, 'starter archive has a clear heading');
} finally {
 await browser.close();
 if (starter?.id) php(`wp_delete_post(${starter.id},true); echo 1;`);
 php(starterState===false ? "delete_option(\\EPM\\AdminPages::SETUP_OPTION); echo 1;" : `update_option(\\EPM\\AdminPages::SETUP_OPTION, json_decode(${JSON.stringify(JSON.stringify(starterState))},true)); echo 1;`);
 php(saved===false ? "delete_option('epm_design_settings'); echo 1;" : `update_option('epm_design_settings', json_decode(${JSON.stringify(JSON.stringify(saved))},true)); echo 1;`);
}
finish('UX clarity');
