import { BASE, launch, newPage, login, fixtures, php, assert, finish } from './lib.mjs';
import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs';
if (!php("echo wp_json_encode(is_file(dirname(rtrim(ABSPATH, '/')).'/.epm-test-site') && wp_get_environment_type() !== 'production');")) { throw new Error('Marked disposable site required.'); }
const browser=await launch();const page=await newPage(browser);await login(page);
const fx=fixtures();
fs.mkdirSync('/tmp/epm-product-review',{recursive:true});
await page.goto(BASE+'/wp-admin/admin.php?page=epm-dashboard');
assert(await page.locator('.epm-workbench').count()===1,'editorial workspace renders');
assert(await page.locator('.epm-workbench a[href*="post='+fx.draft+'"]').count()===2,'draft has direct edit links');
assert(await page.locator('.epm-workbench a[href*="post='+fx.future+'"]').count()===2,'upcoming episode has direct edit links');
await page.locator('#epm-episode-search').fill('First');await page.locator('.epm-workbench button[type=submit]').click();
await page.waitForURL(/edit.php/);assert(new URL(page.url()).searchParams.get('s')==='First','search opens WordPress catalog with query intact');
await page.goto(BASE+'/wp-admin/admin.php?page=epm-dashboard');
await page.locator('.epm-facts a[href*="post_status=draft"]').click();await page.waitForURL(/post_status=draft/);
assert(true,'draft count opens the corresponding catalog filter');
await page.goto(BASE+'/wp-admin/admin.php?page=epm-dashboard');
for(const [width,height] of [[1440,1000],[834,900],[390,844],[844,390]]) {
 await page.setViewportSize({width,height});await page.waitForTimeout(150);
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`dashboard fits ${width}px`);
 await page.screenshot({path:`/tmp/epm-product-review/dashboard-${width}.png`,fullPage:true});
}
const axe=await new AxeBuilder({page}).include('.epm-dashboard').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
fs.writeFileSync('/tmp/epm-product-review/axe.json',JSON.stringify(axe.violations,null,2));assert(axe.violations.length===0,'dashboard passes scoped axe checks');
await page.setViewportSize({width:1280,height:900});await page.goto(BASE+`/wp-admin/post.php?post=${fx.ep1}&action=edit`);
const wrap=page.locator('[data-epm-repeat="chapters"]');const rows=wrap.locator('[data-epm-repeat-rows] > [data-epm-repeat-row]');
const count=await rows.count();const title=await rows.first().locator('input[name$="[title]"]').inputValue();
await rows.first().locator('[data-epm-repeat-remove]').click();assert(await rows.count()===count-1,'chapter removal removes only selected row');
await rows.first().locator('input[name$="[title]"]').fill('Keep this later edit');
await wrap.locator('[data-epm-undo-remove]').focus();await page.keyboard.press('Enter');
assert(await rows.count()===count,'keyboard Undo restores the removed row');
assert(await rows.first().locator('input[name$="[title]"]').inputValue()===title,'restored chapter retains title');
assert(await rows.nth(1).locator('input[name$="[title]"]').inputValue()==='Keep this later edit','Undo preserves subsequent edits to other rows');
assert(await rows.first().locator('input').first().evaluate(e=>document.activeElement===e),'Undo returns focus to restored row');
await rows.first().locator('[data-epm-repeat-remove]').click();
for(const [width,height] of [[1280,900],[390,844]]){await page.setViewportSize({width,height});await wrap.scrollIntoViewIfNeeded();await page.screenshot({path:`/tmp/epm-product-review/undo-${width}.png`});}
await wrap.locator('[data-epm-undo-remove]').click();
// Unsaved checks must not mutate the published episode.
assert(php(`echo wp_json_encode(get_post_meta(${fx.ep1}, '_epm_chapters', true));`).every(c=>c.title!=='Keep this later edit'),'unsaved edits never reach published episode metadata');
assert(page.problems.length===0,'no plugin browser errors');
await browser.close();finish('editorial workspace');
