import { BASE,launch,newPage,php,assert,finish,fixtures } from './lib.mjs';
import fs from 'node:fs';
if (!php("echo wp_json_encode(is_file(dirname(rtrim(ABSPATH, '/')).'/.epm-test-site') && wp_get_environment_type() !== 'production');")) { throw new Error('Marked disposable site required.'); }
const user=php(`$id=wp_insert_user(['user_login'=>'epm_review_'+wp_generate_password(8,false),'user_pass'=>'epm-local-review','role'=>'contributor']); update_user_meta($id,'locale','de_DE'); echo wp_json_encode(['id'=>$id,'login'=>get_userdata($id)->user_login]);`.replace("'epm_review_'+","'epm_review_'."));
const browser=await launch();const page=await newPage(browser,{width:390,height:844});
let draft=0;
try {
 await page.goto(BASE+'/wp-login.php');await page.fill('#user_login',user.login);await page.fill('#user_pass','epm-local-review');await page.click('#wp-submit');await page.waitForURL(/wp-admin/);
 await page.goto(BASE+'/wp-admin/admin.php?page=epm-dashboard');
 assert(await page.locator('.epm-workbench__list').count()===0,'empty contributor queue does not disclose another author drafts');
 assert((await page.locator('.epm-workbench__empty').innerText()).includes('Keine Entwürfe'),'German empty state is translated');
 await page.screenshot({path:'/tmp/epm-product-review/empty-de-390.png',fullPage:true});
 draft=php(`echo wp_json_encode(wp_insert_post(['post_type'=>'podcast_episode','post_status'=>'draft','post_author'=>${user.id},'post_title'=>'Unternehmensentwicklung und Zukunftsentscheidungen: Wie wir gemeinsam neue Perspektiven schaffen — eine außergewöhnlich lange Episodenüberschrift']));`);
 await page.reload();
 assert(await page.locator('.epm-workbench__list > li').count()===1,'contributor sees new own draft');
 assert((await page.locator('[data-epm-next-action]').innerText()).includes('Weiterbearbeiten'),'continue-editing action is translated');
 for(const [width,height] of [[320,568],[390,844],[1440,1000]]) {
  await page.setViewportSize({width,height});await page.waitForTimeout(100);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`long German title fits ${width}px`);
  await page.screenshot({path:`/tmp/epm-product-review/german-${width}.png`,fullPage:true});
 }
 await page.setViewportSize({width:390,height:844});await page.goto(BASE+`/wp-admin/post.php?post=${draft}&action=edit`);
 const wrap=page.locator('[data-epm-repeat="chapters"]');await wrap.locator('[data-epm-repeat-add]').click();
 const rows=wrap.locator('[data-epm-repeat-rows]>[data-epm-repeat-row]');await rows.last().locator('input[name$="[title]"]').fill('Einleitung');
 await rows.last().locator('[data-epm-repeat-remove]').click();
 assert((await wrap.locator('[data-epm-undo-remove]').innerText()).includes('rückgängig'),'German Undo label is translated');
 await wrap.locator('[data-epm-undo-remove]').scrollIntoViewIfNeeded();await page.screenshot({path:'/tmp/epm-product-review/undo-de-390.png'});
 await wrap.locator('[data-epm-undo-remove]').click();
 assert(await rows.last().locator('input[name$="[title]"]').inputValue()==='Einleitung','German Undo restores the actual chapter');
} finally {
 await browser.close();php(`require_once ABSPATH.'wp-admin/includes/user.php'; ${draft?`wp_delete_post(${draft},true);`:''} wp_delete_user(${user.id}); echo wp_json_encode(true);`);
}
finish('German and empty states');
