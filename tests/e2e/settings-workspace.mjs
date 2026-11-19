/** Local-only regression: settings round trip, editor navigation and responsive surfaces. */
import {launch,login,BASE,php,assert,finish} from './lib.mjs';
import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs';
if (!php("echo wp_json_encode(is_file(dirname(rtrim(ABSPATH, '/')).'/.epm-test-site') && wp_get_environment_type() !== 'production');")) throw new Error('Marked disposable site required.');
const out=process.env.EPM_REVIEW_DIR || 'screenshots/settings-workspace';fs.mkdirSync(out,{recursive:true});
const saved=php("echo wp_json_encode(base64_encode(serialize([ 'epm_podcast_settings'=>get_option('epm_podcast_settings'), 'epm_hosting'=>get_option('epm_hosting'), 'epm_setup'=>get_option('epm_setup'), 'epm_distribution'=>get_option('epm_distribution') ]))); ");
const identity=()=>php("$out=[]; foreach(get_posts(['post_type'=>'podcast_episode','post_status'=>'publish','numberposts'=>-1]) as $p){ $out[$p->ID]=[$p->guid,get_post_meta($p->ID,'_epm_audio_url',true),get_post_meta($p->ID,'_epm_guid',true)]; } echo wp_json_encode($out);");
const before=identity();const browser=await launch(['--disable-gpu']);
try {
 const context=await browser.newContext();const page=await context.newPage();await login(page);await page.context().storageState({path:'/tmp/epm-product-review/auth.json'});
 await page.goto(BASE+'/wp-admin/admin.php?page=epm-settings');
 assert(await page.locator('.epm-settings-nav a').count()===5,'settings has direct section navigation');
 const old=await page.locator('#epm-s-short').inputValue();await page.locator('#epm-s-short').fill('Local settings round-trip');
 assert((await page.locator('[data-epm-settings-dirty]').innerText()).includes('Unsaved'),'editing shows unsaved state');
 await page.locator('#epm-s-short').fill(old);assert(await page.locator('[data-epm-settings-dirty]').innerText()==='','reverting values clears unsaved state');
 await page.locator('#epm-s-short').fill('Local settings round-trip');
 await page.locator('.epm-settings-nav a[href="#epm-settings-feed-status"]').focus();await page.keyboard.press('Enter');
 assert(await page.evaluate(()=>document.activeElement.id==='epm-settings-feed-status'),'section navigation transfers keyboard focus');
 await page.locator('#submit').click();await page.waitForURL(/settings-updated=true/);
 assert(php("echo wp_json_encode(epm()->settings->get('short_description'));")==='Local settings round-trip','settings persist through real form submission');
 assert(await page.locator('[data-epm-settings-dirty]').innerText()==='','saved form starts clean');
 await page.locator('#epm-s-short').fill(old);await page.locator('#submit').click();await page.waitForURL(/settings-updated=true/);
 await page.locator('#epm-s-title').fill('');await page.locator('#submit').click();assert(await page.locator('#epm-s-title').evaluate(e=>!e.validity.valid),'required title prevents invalid save');await page.reload();
 // Dirty-page dialog is expected only when leaving edited settings.
 let warned=false;page.once('dialog',async d=>{warned=d.type()==='beforeunload';await d.accept();});await page.locator('#epm-s-title').fill('Unsaved local title');await page.goto(BASE+'/wp-admin/admin.php?page=epm-settings');assert(warned,'leaving unsaved settings asks before losing edits');
 const ids=Object.keys(before);const editor='post.php?post='+ids[0]+'&action=edit';
 await page.goto(BASE+'/wp-admin/'+editor);await page.locator('#epm-chapters .handlediv').click();await page.locator('.epm-episode-basics a[href="#epm-chapters"]').click();assert(await page.locator('#epm-chapters .inside').isVisible(),'section navigation opens collapsed chapters');
 assert(await page.evaluate(()=>document.activeElement.id==='epm-chapters'),'editor section receives keyboard focus');
 const routes={settings:'admin.php?page=epm-settings',design:'admin.php?page=epm-design',hosting:'admin.php?page=epm-hosting',distribution:'admin.php?page=epm-distribution',setup:'admin.php?page=epm-setup',analytics:'admin.php?page=epm-studio-analytics',editor};
 const findings=[];
 for(const [name,path] of Object.entries(routes)) {
  await page.goto(BASE+'/wp-admin/'+path,{waitUntil:'domcontentloaded'});await page.waitForTimeout(350);
  for(const [width,height] of [[320,568],[390,844],[834,900],[1440,1000],[844,390]]) {
   await page.setViewportSize({width,height});await page.waitForTimeout(300);await page.evaluate(()=>scrollTo(0,0));
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+' fits '+width+'x'+height);
   await page.screenshot({path:`${out}/${name}-${width}x${height}.png`,fullPage:true});
   if(width===390 || width===1440) {
    const scope=name==='editor'?'#poststuff':'.epm-workspace';
    const violations=(await new AxeBuilder({page}).include(scope).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations;
    findings.push({name,width,violations});assert(violations.length===0,name+' accessibility '+width);
   }
  }
 }
 fs.writeFileSync(out+'/axe.json',JSON.stringify(findings,null,2));
 assert(JSON.stringify(identity())===JSON.stringify(before),'published identifiers and audio references remain unchanged');
} finally {
 await browser.close();php(`foreach(unserialize(base64_decode('${saved}')) as $k=>$v){ if(false===$v){delete_option($k);}else{update_option($k,$v);} } EPM\\Feed::flush_cache(); echo wp_json_encode(true);`);
}
finish('settings workspace');
