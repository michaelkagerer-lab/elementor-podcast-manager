/** Local editor recovery, private-feed actions, localization and zoom. */
import {BASE,php,fixtures,assert,finish,launch,login} from './lib.mjs';
import fs from 'node:fs';
if(!php("echo wp_json_encode(is_file(dirname(rtrim(ABSPATH,'/')).'/.epm-test-site') && wp_get_environment_type()!=='production');"))throw Error('Disposable site required');
const out=process.env.EPM_REVIEW_DIR||'screenshots/workspace-states';fs.mkdirSync(out,{recursive:true});
const saved=php("echo wp_json_encode(base64_encode(serialize([get_option('epm_hosting'),get_user_meta(1,'locale',true),get_option('epm_podcast_settings')]))); ");
const browser=await launch(['--disable-gpu']);
try{
 const page=await browser.newPage({viewport:{width:1440,height:1000}});await login(page);
 const fx=fixtures();await page.goto(`${BASE}/wp-admin/post.php?post=${fx.ep1}&action=edit`);
 const audio=page.locator('.epm-upload__preview');const button=page.locator('#epm-audio [data-epm-preview-play]');
 await button.focus();await page.keyboard.press('Enter');await page.waitForFunction(()=>{const a=document.querySelector('.epm-upload__preview');return a&&!a.paused&&a.currentTime>0;});
 assert(await audio.isVisible(),'editor previews actual local audio');assert(await audio.evaluate(a=>document.activeElement===a),'keyboard play transfers focus to native audio controls');
 await page.screenshot({path:out+'/editor-playing-1440.png',fullPage:true});
 await audio.evaluate(a=>{a.pause();a.src='/missing-workspace-audio.mp3';a.load();});await page.locator('#epm-audio [data-epm-preview-error]').waitFor({state:'visible'});
 assert((await button.innerText())==='Retry','missing audio offers retry');await page.screenshot({path:out+'/editor-error-1440.png',fullPage:true});
 const source=php(`echo wp_json_encode(wp_get_attachment_url((int)get_post_meta(${fx.ep1},'_epm_audio_id',true)));`);
 await audio.evaluate((a,url)=>{a.src=url;},source);await button.click();await page.waitForFunction(()=>{const a=document.querySelector('.epm-upload__preview');return a&&!a.paused&&a.currentTime>0;});assert(await audio.isVisible(),'retry recovers when media becomes available');await audio.evaluate(a=>a.pause());
 php("update_option('epm_hosting',['mode'=>'external','feed_url'=>'https://example.org/private.xml?token=local-test-secret','redirect'=>false]);echo wp_json_encode(true);");
 php("$s=get_option('epm_podcast_settings');$s['feed_alias']=true;update_option('epm_podcast_settings',$s);echo wp_json_encode(true);");
 const alias=await page.request.get(BASE+'/?feed=podcast',{maxRedirects:0});assert(alias.status()===301 && alias.headers().location===BASE+'/podcast/feed/','private-feed alias redirects to local feed without credentials');
 for(const name of ['dashboard','settings','distribution']){await page.goto(`${BASE}/wp-admin/admin.php?page=epm-${name}`);assert(await page.locator('a[href=""]').count()===0,name+' has no empty links for private feed');assert(!(await page.locator('.epm-app, .epm-settings').innerText()).includes('local-test-secret') && await page.locator('a[href*="local-test-secret"], [data-epm-copy*="local-test-secret"]').count()===0,name+' never offers feed credentials for sharing');await page.setViewportSize({width:390,height:844});await page.screenshot({path:out+'/'+name+'-private-390.png',fullPage:true});}
 php(`$saved=unserialize(base64_decode('${saved}')); update_option('epm_hosting',$saved[0]);update_user_meta(1,'locale','de_DE');echo wp_json_encode(true);`);
 for(const name of ['settings','design','hosting','distribution','setup','studio-analytics']){await page.goto(`${BASE}/wp-admin/admin.php?page=epm-${name}`);assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+' German fits narrow screen');await page.screenshot({path:out+'/'+name+'-de-390.png',fullPage:true});}
 await page.goto(`${BASE}/wp-admin/post.php?post=${fx.ep1}&action=edit`);assert((await button.innerText())==='Folge abspielen','editor preview uses actual German JS catalog');await page.screenshot({path:out+'/editor-de-390.png',fullPage:true});
 await page.setViewportSize({width:1440,height:1000});await page.goto(`${BASE}/wp-admin/admin.php?page=epm-settings`);await page.evaluate(()=>document.documentElement.style.zoom='2');assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'settings fit at 200% zoom');await page.screenshot({path:out+'/settings-de-zoom200.png',fullPage:true});
}finally{await browser.close();php(`$saved=unserialize(base64_decode('${saved}'));update_option('epm_hosting',$saved[0]);update_option('epm_podcast_settings',$saved[2]);if(''===$saved[1]){delete_user_meta(1,'locale');}else{update_user_meta(1,'locale',$saved[1]);}EPM\\Feed::flush_cache();echo wp_json_encode(true);`);}
finish('workspace states');
