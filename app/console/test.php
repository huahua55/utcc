<?php




error_reporting(E_ALL & ~E_NOTICE);

define('APP_PATH', dirname(__FILE__) . '/..');
define('IPHP_PATH', '/data/lib/iphp');
define('MANAGE_CONFIG_PATH', APP_PATH . '/console/consumer_config.php');
define('RUNNER_SCRIPT', APP_PATH . '/console/ConsumerRunner.php');
require_once(IPHP_PATH . '/loader.php');
App::init();


$program = preg_split("/\s+/", 1);
array_unshift($program, ' -v ');
print_r($program);die;
$a = pcntl_exec('/usr/local/php/bin/php', ' -v');


$conf['host']= '127.0.0.1';
$conf['port']= '30986';
$conf['pwd']= 'SDjfk9he6ui';
$redis = Util::redis();
$redis->connect($conf['host'], $conf['port'], 5);
if(isset($conf['pwd']) && !empty($conf['pwd'])){
    $redis->auth($conf['pwd']);
}

print_r($redis);die;

$args = [
    '/usr/bin/ffmpeg',
    '-i',
    '/data/vod/f482fc6a6ca111d198b53eed25a9c3c2.mp4',
    '-vcodec',
    'copy',
    '-acodec',
    'copy',
    '-vbsf',
    'h264_mp4toannexb',
    '/data/vod/f482fc6a6ca111d198b53eed25a9c3c2.ts',
    '-y',
];
system(implode(' ', $args), $status1);
var_dump($status1);
if(!file_exists('/data/vod/hls/f482fc6a6ca111d198b53eed25a9c3c2')) {
    //mkdir('/data/vod/hls/67366a79a02203a71407b2e72e773d4d/720');
}
$args = [
    '/usr/bin/ffmpeg',
    '-i',
    '/data/vod/f482fc6a6ca111d198b53eed25a9c3c2.ts',
    '-c',
    'copy',
    '-map',
    '0',
    '-f',
    'segment',
    '-segment_list',
    '/data/vod/hls/f482fc6a6ca111d198b53eed25a9c3c2/1280/index.m3u8',
    '-segment_time',
    '2',
    '/data/vod/hls/f482fc6a6ca111d198b53eed25a9c3c2/1280/%04d.ts'
];
ob_start();
passthru(implode(' ', $args), $status2);
$video_info = ob_get_contents();
ob_end_clean();
var_dump($video_info);
var_dump($status2);