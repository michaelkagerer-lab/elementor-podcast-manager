<?php
if(!defined('ABSPATH') || !is_file(dirname(rtrim(ABSPATH,'/')).'/.epm-test-site') || wp_get_environment_type()==='production'){WP_CLI::error('Disposable site required.');}
require_once __DIR__.'/lib.php';$t=new EPM_Test_Runner();$a=new EPM\StudioAnalytics();$a->install();global $wpdb;
$guid='urn:argon-analytics:'.wp_generate_uuid4();$day=gmdate('Y-m-d');$session=str_repeat('c',32);$id=hash('sha256',$guid.'|'.$day);$sid=hash('sha256',$id.'|'.$session);
try{
 $t->test('A new session cannot claim a day of listening',static function($t)use($a,$guid,$day,$session){$t->assert(is_wp_error($a->record($guid,$day,$session,86400)));});
 $t->test('Legitimate cumulative playback and retries are retained',static function($t)use($a,$guid,$day,$session,$wpdb,$id){
  $t->assert(!is_wp_error($a->record($guid,$day,$session,15)));$a->record($guid,$day,$session,45);$a->record($guid,$day,$session,15);
  $t->same(45,(int)$wpdb->get_var($wpdb->prepare("SELECT seconds FROM {$wpdb->prefix}epm_studio_daily WHERE id=%s",$id)));
 });
 $t->test('Rapid cumulative inflation is rejected',static function($t)use($a,$guid,$day,$session){$t->assert(is_wp_error($a->record($guid,$day,$session,600)));});
 $t->test('Delayed cumulative packets remain valid',static function($t)use($a,$guid,$day,$session,$wpdb,$sid,$id){
  $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}epm_studio_sessions SET started_at=started_at-120 WHERE id=%s",$sid));
  $t->assert(!is_wp_error($a->record($guid,$day,$session,120)));
  $t->same(120,(int)$wpdb->get_var($wpdb->prepare("SELECT seconds FROM {$wpdb->prefix}epm_studio_daily WHERE id=%s",$id)));
 });
}finally{$wpdb->delete($wpdb->prefix.'epm_studio_sessions',['id'=>$sid]);$wpdb->delete($wpdb->prefix.'epm_studio_daily',['id'=>$id]);}
$t->finish();
