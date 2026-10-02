/** UX-N2 / UX-N6: stopped moves and recovery from interrupted progress checks. */
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { BASE, php, assert, finish, launch, newPage, login } from './lib.mjs';
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const LIB = `${ROOT}/tests/integration/lib.php`;
const saved = php(`require_once '${LIB}'; $out=[]; foreach ([EPM\\AdminPages::SETUP_OPTION,EPM\\ImportJob::OPTION,'cron'] as $name) {$out[$name]=epm_test_option_snapshot($name);} echo wp_json_encode(base64_encode(serialize($out)));`);
const browser = await launch();
const setJob = status => php(`update_option(EPM\\AdminPages::SETUP_OPTION,['path'=>'move','resume'=>'import']); update_option(EPM\\ImportJob::OPTION,['status'=>'${status}','token'=>'recovery-probe','position'=>3,'total'=>10,'counts'=>['created'=>3],'options'=>['purpose'=>'move'],'channel'=>['title'=>'Recovery probe'],'touched'=>time()]); wp_clear_scheduled_hook(EPM\\ImportJob::CRON_HOOK); echo wp_json_encode(true);`);
try {
 const page = await newPage(browser);
 await login(page);
 if (process.env.EPM_RECOVERY_BASELINE_JS) {
  const baseline = fs.readFileSync(process.env.EPM_RECOVERY_BASELINE_JS,'utf8');
  await page.route('**/epm-setup.js*',r=>r.fulfill({contentType:'application/javascript',body:baseline}));
 }
 for (const status of ['cancelled','failed']) {
  setJob(status);
  await page.goto(`${BASE}/wp-admin/admin.php?page=epm-setup&step=done`);
  await page.waitForSelector('[data-panel]:not([hidden])');
  assert(await page.locator('[data-panel="done"]').isHidden(),`${status} move cannot show success or redirect instructions`);
  assert(await page.locator('[data-panel="import"]').isVisible(),`${status} move returns to its progress and recovery controls`);
  const text = await page.locator('[data-import-lede]').textContent();
  assert(text.includes('Import stopped: 3 of 10'),`${status} move states the incomplete count`);
  assert(!text.includes('continues in the background'),`${status} move does not promise background work`);
  await page.waitForTimeout(80);
  assert((await page.locator('[data-epm-announce]').textContent()).includes('Import stopped'),`${status} is announced to screen readers`);
 }
 setJob('running');
 let steps=0;
 await page.route('**/wp-admin/admin-ajax.php',async route=>{
  const body=route.request().postData()||'';
  if(body.includes('epm_setup_save'))return route.fulfill({json:{success:true,data:{}}});
  if(!body.includes('epm_import_step'))return route.continue();
  if(++steps===1)return route.abort('failed');
  await route.fulfill({json:{success:true,data:{status:'cancelled',total:10,done:3,counts:{created:3},log:[]}}});
 });
 await page.goto(`${BASE}/wp-admin/admin.php?page=epm-setup&step=import`);
 await page.waitForSelector('[data-import-error]:not([hidden])');
 const error=page.locator('[data-import-error]');
 const message=await error.textContent();
 assert(await error.getAttribute('role')==='alert','connection error has an accessible alert role');
 assert(message.includes('connection was interrupted')&&!message.includes('Failed to fetch'),'network errors explain recovery in plain language');
 assert(await page.locator('[data-action="retry-import"]').isVisible(),'interrupted progress check offers a retry');
 assert(await page.locator('[data-import-continue]').isDisabled(),'unknown import state cannot advance');
 if (await page.locator('[data-action="retry-import"]').isVisible()) {
 await page.locator('[data-action="retry-import"]').click();
 await page.waitForFunction(()=>document.querySelector('[data-import-lede]').textContent.includes('Import stopped'));
 assert(steps===2,'retry checks progress once without starting another import');
 assert(await error.isHidden(),'successful progress check clears the stale network error');
 assert(await page.locator('[data-action="retry-import"]').isHidden(),'stopped job no longer offers a network-check retry');
 assert(await page.evaluate(()=>!!document.activeElement.closest('[data-panel="import"]')),'assistant retry keeps keyboard focus in the import');
 await page.waitForTimeout(80);
 assert((await page.locator('[data-epm-announce]').textContent()).includes('Import stopped: 3 of 10'),'retry announces the server-reported stopped state');
 }

 if (!process.env.EPM_RECOVERY_BASELINE_JS) {
  setJob('running');
  steps=0;
  await page.setViewportSize({width:390,height:844});
  await page.goto(`${BASE}/wp-admin/admin.php?page=epm-hosting`);
  await page.waitForSelector('[data-job-error]:not([hidden])');
  const hostError=page.locator('[data-job-error]');
  assert(await hostError.getAttribute('role')==='alert','Hosting & import announces connection errors through an alert');
  assert((await hostError.textContent()).includes('connection was interrupted')&&!(await hostError.textContent()).includes('Failed to fetch'),'Hosting & import explains network recovery without raw errors');
  await page.waitForTimeout(80);
  assert((await page.locator('[data-epm-announce]').textContent()).includes('connection was interrupted'),'Hosting & import announces interrupted progress');
  const retry=page.locator('[data-action="retry-progress"]');
  assert(await retry.isVisible(),'Hosting & import offers a progress retry');
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth+2),'recovery controls fit a narrow phone viewport');
  await page.locator('[data-job]').screenshot({path:'/tmp/epm-hosting-recovery-error.png'});
  if(await retry.isVisible()) {
   await retry.click();
   await page.waitForSelector('[data-job-stopped]:not([hidden])');
   assert((await page.locator('[data-job-stopped]').textContent()).includes('Import stopped: 3 of 10'),'Hosting & import shows the stopped count after retry');
   assert(await hostError.isHidden()&&await retry.isHidden(),'Hosting & import clears stale network errors after a successful check');
   assert(await page.evaluate(()=>!!document.activeElement.closest('[data-job]')),'progress retry preserves keyboard focus in the import');
   await page.waitForTimeout(80);
   assert((await page.locator('[data-epm-announce]').textContent()).includes('Import stopped: 3 of 10'),'Hosting & import announces the stopped job');
   await page.locator('[data-job]').screenshot({path:'/tmp/epm-hosting-recovery-stopped.png'});
  }
 }
 assert(page.problems.filter(p=>p.startsWith('pageerror:')).length===0,'no JavaScript exceptions during recovery');
 await page.context().close();
} finally {
 php(`require_once '${LIB}'; foreach(unserialize(base64_decode('${saved}')) as $name=>$value){epm_test_option_restore($name,$value);} echo wp_json_encode(true);`);
 await browser.close();
}
finish('import-recovery');
