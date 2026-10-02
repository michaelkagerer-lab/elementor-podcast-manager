/** Actual de_DE PHP and WordPress script catalogs; no injected translations. */
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {BASE,php,assert,finish,launch,newPage,login} from './lib.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const lib=`${root}/tests/integration/lib.php`;
const saved=php(`require_once '${lib}'; $snap=[]; foreach (['WPLANG',EPM\\AdminPages::SETUP_OPTION,EPM\\ImportJob::OPTION,'cron'] as $key) {$snap[$key]=epm_test_option_snapshot($key);} $user=get_users(['role'=>'administrator','number'=>1])[0]; echo wp_json_encode(['options'=>base64_encode(serialize($snap)),'user'=>$user->ID,'meta'=>get_user_meta($user->ID,'locale')]);`);
const browser=await launch();
try {
 php(`update_option('WPLANG','de_DE'); update_user_meta(${saved.user},'locale','de_DE'); delete_option(EPM\\ImportJob::OPTION); update_option(EPM\\AdminPages::SETUP_OPTION,['path'=>'external','resume'=>'connect']); echo wp_json_encode(true);`);
 const page=await newPage(browser);
 await login(page);
 let count=1;
 await page.route('**/wp-admin/admin-ajax.php',route=>{
  const data=route.request().postData()||'';
  if(data.includes('epm_setup_save'))return route.fulfill({json:{success:true,data:{}}});
  if(!data.includes('epm_import_preview'))return route.continue();
  return route.fulfill({json:{success:true,data:{feed_url:'https://feeds.example.test/de.xml',channel:{title:'Nachhaltigkeit und Digitalisierung'},episodes:count,existing:0,duplicates:[],blocked:0,complete:true,token:'de-probe'}}});
 });
 await page.goto(`${BASE}/wp-admin/admin.php?page=epm-setup&step=connect`);
 assert((await page.locator('html').getAttribute('lang')).startsWith('de'),'WordPress applies the German admin locale');
 assert((await page.locator('[data-panel="connect"]').textContent()).includes('RSS-Feed-Adresse'),'PHP templates load the German catalog');
 await page.fill('#epm-setup-feed','https://feeds.example.test/de.xml');
 await page.click('[data-action="check-feed"]');
 await page.waitForSelector('[data-preview]:not([hidden])');
 const fact=page.locator('[data-preview-episodes]').locator('..');
 assert((await fact.textContent()).replace(/\s+/g,' ').trim()==='1 Folge','actual catalog uses the German singular');
 count=1234;
 await page.click('[data-action="check-feed"]');
 await page.waitForFunction(()=>document.querySelector('[data-preview-episodes]').textContent==='1.234');
 assert((await fact.textContent()).replace(/\s+/g,' ').trim()==='1.234 Folgen','actual catalog uses German plural and number formatting');
 assert((await page.locator('[data-import-button]').textContent()).includes('1.234 Folgen importieren'),'setup script catalog loads through its WordPress path hash');
 await page.setViewportSize({width:390,height:844});
 for(const screen of ['dashboard','hosting','distribution','settings','design']) {
  await page.goto(`${BASE}/wp-admin/admin.php?page=epm-${screen}`);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth+2),`${screen} German labels fit at 390px`);
 }
 await page.screenshot({path:'screenshots/german-design-390.png',fullPage:true});
 const fx=php('echo wp_json_encode(get_option("epm_test_fixtures"));');
 await page.goto(`${BASE}/wp-admin/post.php?post=${fx.ep1}&action=edit`);
 assert((await page.locator('body').textContent()).includes('Kapitel einfügen'),'episode editor PHP catalog loads');
 assert(await page.evaluate(()=>wp.i18n.__('Next free number: %d','elementor-podcast-manager'))==='Nächste freie Nummer: %d','episode editor script catalog loads under the non-minified basename');
 assert(await page.evaluate(()=>wp.i18n._n('Added %d chapter.','Added %d chapters.',2,'elementor-podcast-manager'))==='%d Kapitel hinzugefügt.','chapter script plural translation loads');
 await page.goto(`${BASE}/?p=${fx.ep1}`);
 assert(await page.locator('[data-epm-share-menu]').first().getAttribute('aria-label')==='Diese Folge teilen','front-end player uses the German PHP catalog');
 assert(page.problems.filter(x=>x.startsWith('pageerror:')).length===0,'no browser exceptions in the German journeys');
 await page.context().close();
} finally {
 await browser.close();
 php(`require_once '${lib}'; foreach(unserialize(base64_decode(${JSON.stringify(saved.options)})) as $key=>$value){epm_test_option_restore($key,$value);} delete_user_meta(${saved.user},'locale'); foreach(${JSON.stringify(saved.meta)} as $value){add_user_meta(${saved.user},'locale',$value);} echo wp_json_encode(true);`);
}
finish('German catalog');
