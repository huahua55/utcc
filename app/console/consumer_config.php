<?php
// 注意, 队列名都要加上 msg_ 前缀!
//	代码中的默认值为：
//		per_worker_threshold = 500
//		min_workers = 1
//		max_workers = 10

return array(
    array (
        'queue' => 'transcode_queue',
        'per_worker_threshold' => 1,
        'min_workers' => 2,
        'max_workers' => 5,
        'script' => dirname(__FILE__) . '/consumers/TranscodeConsumer.php'
    ),
//    array (
//        'queue' => 'q',
//        'per_worker_threshold' => 10,
//        'min_workers' => 1,
//        'max_workers' => 20,
//        'script' => dirname(__FILE__) . '/consumers/TestConsumer.php'
//    ),
);

