<?php
// Invoked by the Studio bridge test only on a marked disposable WordPress site.
if ( ! getenv('EPM_STUDIO_TEST_TOKEN') || ! file_exists(dirname(ABSPATH) . '/.epm-test-site') ) { WP_CLI::error('Disposable bridge test only.'); }
global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}epm_studio_daily"); $wpdb->query("DELETE FROM {$wpdb->prefix}epm_studio_sessions");
$a=new EPM\StudioAnalytics();$a->install();
update_option(EPM\StudioAnalytics::OPTION,['enabled'=>true,'token'=>getenv('EPM_STUDIO_TEST_TOKEN')],false);
// Model the production feed origin while routing only the transport to local Studio.
add_filter('home_url',static function($url,$path){return '/podcast/feed/'===$path?'https://businesstuning.at/podcast/feed/':$url;},10,2);
add_filter('pre_http_request',static function($pre,$args,$url){
 if($url!==EPM\StudioAnalytics::ENDPOINT)return $pre;
 return wp_remote_post('http://localhost:3000/api/integrations/podcast',$args);
},10,3);
$a->record('urn:bridge-test',gmdate('Y-m-d'),str_repeat('f',32),42);
$a->sync();$a->sync();
$status=get_option('epm_studio_status');
if(empty($status['ok']))WP_CLI::error('Bridge delivery failed: '.wp_json_encode($status));
WP_CLI::success('WordPress sent cumulative real HTTP snapshots twice.');
