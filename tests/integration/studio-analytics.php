<?php
require_once __DIR__ . '/lib.php';
$t = new EPM_Test_Runner();
$a = new EPM\StudioAnalytics();
$a->install();
global $wpdb;
$daily = $wpdb->prefix . 'epm_studio_daily';
$sessions = $wpdb->prefix . 'epm_studio_sessions';
$guid = 'urn:studio-analytics-test'; $day = gmdate('Y-m-d'); $session = str_repeat('a',32);
$wpdb->query("DELETE FROM $daily"); $wpdb->query("DELETE FROM $sessions");
$t->test('cumulative retries and older packets do not double-count', function($t) use($a,$guid,$day,$session,$wpdb,$daily) {
 $t->assert(!is_wp_error($a->record($guid,$day,$session,15)), 'first record');
 $a->record($guid,$day,$session,15);$a->record($guid,$day,$session,10);$a->record($guid,$day,$session,45);
 $r=$wpdb->get_row("SELECT * FROM $daily",ARRAY_A);
 $t->assert(1===(int)$r['plays'] && 45===(int)$r['seconds'] && 2===(int)$r['revision'], 'one play, cumulative 45 seconds, two revisions');
});
update_option(EPM\StudioAnalytics::OPTION,['enabled'=>true,'token'=>'epm_'.str_repeat('b',64)],false);
$t->test('network failure retains pending batch',function($t)use($a,$wpdb,$daily){
 $fail=static function(){return new WP_Error('offline','Offline');};add_filter('pre_http_request',$fail);$a->sync();remove_filter('pre_http_request',$fail);
 $t->assert(0===(int)$wpdb->get_var("SELECT synced_revision FROM $daily"),'not acknowledged');
 $t->assert(!get_option('epm_studio_status')['ok'],'truthful failure');
});
$t->test('acknowledgement does not discard a concurrent new measurement',function($t)use($a,$wpdb,$daily,$guid,$day,$session){
 $capture=static function($pre,$args,$url)use($a,$guid,$day,$session){
  if($url!==EPM\StudioAnalytics::ENDPOINT)return $pre;
  $b=json_decode($args['body'],true);if($b['rows'][0]['seconds']!==45)throw new Exception('wrong payload');
  $a->record($guid,$day,$session,75);
  return ['headers'=>[],'body'=>wp_json_encode(['ok'=>true,'accepted'=>1]),'response'=>['code'=>200,'message'=>'OK']];
 };add_filter('pre_http_request',$capture,10,3);$a->sync();remove_filter('pre_http_request',$capture);
 $r=$wpdb->get_row("SELECT * FROM $daily",ARRAY_A);$t->assert((int)$r['revision']>(int)$r['synced_revision'],'new version stays pending');
});
$t->test('public endpoint rejects origin, non-consent and unpublished episodes',function($t)use($a,$day,$session){
 $req=new WP_REST_Request('POST','/epm/v1/analytics/playback');$req->set_header('Content-Type','application/json');$req->set_body(wp_json_encode(['episodeId'=>999999,'day'=>$day,'session'=>$session,'seconds'=>15,'consent'=>true]));
 $t->assert(is_wp_error($a->receive($req)),'missing origin rejected');$req->set_header('Origin','http://localhost:8893');
 $t->assert(is_wp_error($a->receive($req)),'unknown episode rejected');
});
$t->test('frontend config never exposes connection secret',function($t){$t->assert(false===strpos(wp_json_encode(EPM\StudioAnalytics::client_config()),'epm_'.str_repeat('b',64)),'no token in config');});
$wpdb->query("DELETE FROM $daily");$wpdb->query("DELETE FROM $sessions");delete_option(EPM\StudioAnalytics::OPTION);wp_clear_scheduled_hook(EPM\StudioAnalytics::CRON);
$t->finish();
