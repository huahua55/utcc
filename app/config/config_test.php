<?php
define('ENV', 'dev');

return array(
    'env' => ENV,
    'logger' => array(
        'level' => 'debug', // none/off|(LEVEL)
        'dump' => 'file', // none|html|file, 可用'|'组合
        'files' => array( // ALL|(LEVEL)
            'ALL'	=> '/data/applogs/utcc/' . date('Y-m-d') . '.log',
        ),
    ),
    'db' => array(
        'host' => '127.0.0.1',
        'dbname' => 'video',
        'username' => 'uservide',
        'password' => 'CHjduTY793CKLp',
        'charset' => 'utf8',
    ),
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
    ],
    'upload_path' => '/data/vod',
    'transcode_queue' => 'transcode_queue',
    'cdn' => 'http://s1.mzuis.com'
);
