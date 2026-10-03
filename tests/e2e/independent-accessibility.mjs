/** Fresh semantic/contrast scans of plugin-owned UI, desktop and narrow screens. */
import fs from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { BASE, php, assert, finish, launch, newPage, login, noOverflow, focusRingVisible } from './lib.mjs';
const fx = php("echo wp_json_encode(get_option('epm_test_fixtures'));");
const browser = await launch();
const report = [];
const pages = [];
try {
 for (const layout of ['minimal','compact','editorial','artwork','full']) {
  const entry = php(`$id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Player ${layout}','post_content'=>'[podcast_player id="${fx.ep1}" layout="${layout}" sticky="yes"]']); echo wp_json_encode([$id,get_permalink($id)]);`);
  pages.push(entry);
 }
 for (const width of [1280,390]) {
  const admin = await newPage(browser, {width,height:900});
  await login(admin);
  for (const screen of ['dashboard','settings','design','hosting','setup','distribution']) {
   await admin.goto(`${BASE}/wp-admin/admin.php?page=epm-${screen}`);
   const selector = screen==='settings'?'.epm-settings':'.epm-app';
   if (!(await admin.locator(selector).count())) throw new Error(`Missing plugin screen ${screen}`);
   const result=await new AxeBuilder({page:admin}).include(selector).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
   report.push({surface:screen,width,violations:result.violations});
   assert(result.violations.length===0, `${screen} at ${width}px: WCAG scan ${result.violations.map(x=>`${x.id} (${x.nodes.length})`).join(', ') || 'passes'}`);
   assert(await noOverflow(admin), `${screen} at ${width}px fits the viewport`);
   if (screen === 'design') {
    const viewport = admin.locator('[data-epm-preview-viewport]');
    await viewport.focus();
    assert(await viewport.evaluate(el => el === document.activeElement), `design preview at ${width}px accepts keyboard focus`);
    assert(await focusRingVisible(admin), `design preview at ${width}px has a visible focus ring`);
   }
   if (['dashboard','design','setup'].includes(screen)) await admin.locator(selector).screenshot({path:`screenshots/independent-${screen}-${width}.png`});
  }
  await admin.context().close();
  const site=await newPage(browser,{width,height:900});
  for (let index=0;index<pages.length;index++) {
   const [id,url]=pages[index];
   await site.goto(url);
   // Wait for the initial share-label transition; audit settled text contrast.
   await site.waitForFunction(() => [...document.querySelectorAll('.epm-share__label-done')].every(el => Number(getComputedStyle(el).opacity) === 0));
   const result=await new AxeBuilder({page:site}).include('[data-epm-player]').withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
   report.push({surface:`player-${index}`,width,violations:result.violations});
   assert(result.violations.length===0,`player ${index} at ${width}px: WCAG scan ${result.violations.map(x=>`${x.id} (${x.nodes.length})`).join(', ') || 'passes'}`);
   assert(await noOverflow(site),`player ${index} at ${width}px fits the viewport`);
   await site.locator('[data-epm-player]').screenshot({path:`screenshots/independent-player-${index}-${width}.png`});
  }
  await site.context().close();
 }
} finally {
 fs.writeFileSync('screenshots/independent-accessibility.json',JSON.stringify(report,null,2));
 await browser.close();
 php(`foreach (${JSON.stringify(pages.map(x=>x[0]))} as $id) wp_delete_post((int)$id,true); echo 1;`);
}
finish('independent accessibility');
