/** UX-N9: translated recovery instructions, with diagnostics in a disclosure. */
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {BASE,php,assert,finish,launch,newPage,login} from './lib.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const saved=php(`require_once '${root}/tests/integration/lib.php'; echo wp_json_encode(base64_encode(serialize(epm_test_option_snapshot(EPM\\ImportJob::OPTION))));`);
const browser=await launch();
try {
 php(`delete_option(EPM\\ImportJob::OPTION); echo wp_json_encode(true);`);
 const page=await newPage(browser);
 await login(page);
 let network=false, partial=false;
 await page.route('**/wp-admin/admin-ajax.php',async route=>{
  if(!(route.request().postData()||'').includes('epm_import_preview')) return route.continue();
  if(network) return route.abort('failed');
  if(partial) return route.fulfill({json:{success:true,data:{feed_url:'https://feeds.example.test/error.xml',token:'partial-probe',episodes:1,existing:0,duplicates:[],blocked:0,channel:{title:'Partial probe'},catalog:{complete:false,retry:true,message:'The next page did not answer in time.',details:'cURL error 28: page two probe'}}}});
  return route.fulfill({json:{success:false,data:{message:'The podcast host did not answer in time. Try again in a few minutes.',details:'cURL error 28: <img src=x onerror=alert(1)> private probe'}}});
 });
 await page.goto(`${BASE}/wp-admin/admin.php?page=epm-hosting#epm-task-import`);
 await page.locator('[data-import-form] [name="url"]').fill('https://feeds.example.test/error.xml');
 await page.locator('[data-action="check"]').click();
 const error=page.locator('[data-import-form] [data-error]');
 await error.waitFor({state:'visible'});
 assert((await error.textContent()).startsWith('The podcast host did not answer in time.'),'feed error starts with recovery instructions');
 assert(await error.locator('details').count()===1,'technical detail has an optional disclosure');
 assert(await error.locator('details').getAttribute('open')===null,'diagnostics start collapsed');
 assert(await error.locator('img').count()===0,'diagnostics are escaped plain text');
 if(await error.locator('summary').count()) {
  await error.locator('summary').focus();
  await page.keyboard.press('Enter');
  assert(await error.locator('details').getAttribute('open')!==null,'technical details open with the keyboard');
 }
 partial=true;
 await page.locator('[data-action="check"]').click();
 await page.waitForSelector('[data-preview-incomplete]:not([hidden])');
 assert((await page.locator('[data-preview-incomplete-text]').textContent()).includes('did not answer in time'),'partial-feed errors keep actionable copy');
 const partialDetail=page.locator('[data-preview-incomplete-details] details');
 assert(await partialDetail.count()===1 && await partialDetail.getAttribute('open')===null,'partial-feed diagnostics start collapsed');
 assert((await partialDetail.textContent()).includes('page two probe'),'partial-feed diagnostics retain the failed page detail');
 network=true;
 await page.locator('[data-action="check"]').click();
 await page.waitForFunction(()=>document.querySelector('[data-import-form] [data-error]').textContent.includes('Something went wrong'));
 assert((await error.textContent()).startsWith('Something went wrong. Check your connection and try again.'),'browser network failure uses translated recovery copy');
 assert(await error.locator('details').count()===1,'network diagnostic remains available separately');
 assert(page.problems.filter(x=>x.startsWith('pageerror:')).length===0,'no browser exceptions');
 await page.context().close();
} finally {
 await browser.close();
 php(`require_once '${root}/tests/integration/lib.php'; epm_test_option_restore(EPM\\ImportJob::OPTION,unserialize(base64_decode(${JSON.stringify(saved)}))); echo wp_json_encode(true);`);
}
finish('feed errors');
