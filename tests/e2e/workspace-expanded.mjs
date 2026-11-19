import {launch,BASE,assert,finish} from './lib.mjs';import fs from 'node:fs';
const out=process.env.EPM_REVIEW_DIR||'screenshots/workspace-expanded';fs.mkdirSync(out,{recursive:true});const b=await launch(['--disable-gpu']);
try{
 const p=await b.newPage({storageState:'/tmp/epm-product-review/auth.json'});
 for(const width of [390,834,1440]){
  await p.setViewportSize({width,height:900});await p.goto(BASE+'/wp-admin/admin.php?page=epm-design');
  const hiddenGeometry=await p.locator('details:not([open]) :is(input,select,button,textarea)').evaluateAll(es=>es.filter(e=>e.getBoundingClientRect().width>0).map(e=>({id:e.id,visible:e.checkVisibility(),details:e.closest('details').open})));fs.writeFileSync(out+'/closed-details-'+width+'.json',JSON.stringify(hiddenGeometry,null,2));assert(hiddenGeometry.every(e=>!e.visible),'closed-details geometry is not visible at '+width);
  await p.locator('.epm-design__section').evaluateAll(es=>es.forEach(e=>e.open=true));await p.screenshot({path:out+'/design-expanded-'+width+'.png',fullPage:true});assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'expanded design fits '+width);
  await p.goto(BASE+'/wp-admin/admin.php?page=epm-hosting');
  for(const task of ['import','move']){await p.locator(`[data-epm-hosting-task="${task}"]`).click();await p.waitForFunction(t=>document.querySelector(`[data-epm-hosting-task="${t}"]`).getAttribute("aria-current")==="page",task);await p.waitForTimeout(250);await p.screenshot({path:out+'/hosting-'+task+'-'+width+'.png',fullPage:true});assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),task+' fits '+width);}
  await p.goto(BASE+'/wp-admin/admin.php?page=epm-distribution');await p.locator('.epm-platform__details').first().evaluate(e=>e.open=true);await p.screenshot({path:out+'/distribution-open-'+width+'.png',fullPage:true});
 }
 await p.goto(BASE+'/wp-admin/admin.php?page=epm-studio-analytics');await p.locator('#epm-studio-token').fill('invalid');await p.locator('input[type=submit]').click();assert(await p.locator('#epm-studio-token').evaluate(e=>!e.validity.valid),'invalid connection key stays on form with native validation');
}finally{await b.close();}finish('expanded workspace');
