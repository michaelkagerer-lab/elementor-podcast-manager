/** Real widget rendering with long imported content in narrow Elementor columns. */
import {BASE, php, assert, finish, launch, newPage, noOverflow} from './lib.mjs';
if (!php("echo wp_json_encode(is_file(dirname(rtrim(ABSPATH, '/\\\\')).'/.epm-test-site'));")) throw new Error('Requires a marked disposable test site.');
const browser=await launch();let ids=[];
try {
 const word='Donaudampfschifffahrtsgesellschaftskapitän'.repeat(8);
 const content=`<p><a href="https://example.invalid/">https://example.invalid/${word}</a></p><p>${word}</p>`;
 const data=php(`$ep=wp_insert_post(['post_type'=>'podcast_episode','post_status'=>'publish','post_title'=>'Narrow widget test']); foreach(json_decode(${JSON.stringify(JSON.stringify({show_notes:content,transcript:content,guest_name:'Guest',guest_role:word,guest_company:word,guest_bio:content}))},true) as $key=>$value){update_post_meta($ep,'_epm_'.$key,$value);} $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Widget content test']); $types=['epm-show-notes','epm-transcript','epm-guest'];$widgets=[];foreach($types as $i=>$type){$widgets[]=['id'=>'content'.$i,'elType'=>'widget','widgetType'=>$type,'settings'=>['source'=>'specific','episode_id'=>$ep,'show_bio'=>'yes'],'elements'=>[]];} $elements=[['id'=>'contentcolumn','elType'=>'container','settings'=>['content_width'=>'boxed','boxed_width'=>['unit'=>'px','size'=>320],'width'=>['unit'=>'px','size'=>320]],'elements'=>$widgets]];update_post_meta($id,'_elementor_data',wp_slash(wp_json_encode($elements)));update_post_meta($id,'_elementor_edit_mode','builder');update_post_meta($id,'_elementor_version',ELEMENTOR_VERSION);echo wp_json_encode([$ep,$id,get_permalink($id)]);`);
 ids=data.slice(0,2);
 const page=await newPage(browser);
 for(const width of [1280,390,320]){
  await page.setViewportSize({width,height:900});await page.goto(data[2]);
  for(const selector of ['.epm-show-notes','.epm-transcript','.epm-guest']){
   assert(await page.locator(selector).count()===1,`${width}: ${selector} renders real data`);
   const fit=await page.locator(selector).evaluate(el=>el.scrollWidth<=el.clientWidth+1);
   assert(fit,`${width}: ${selector} wraps long words and URLs inside its column`);
  }
  assert(await noOverflow(page),`${width}: long content does not widen the page`);
  await page.screenshot({path:`screenshots/elementor-content-${width}.png`,fullPage:true});
 }
 assert(page.problems.length===0,`no plugin browser errors: ${page.problems.join(' | ')}`);
 await page.context().close();
}finally{for(const id of ids)php(`wp_delete_post(${id},true); echo 1;`);await browser.close();}
finish('Elementor content');
