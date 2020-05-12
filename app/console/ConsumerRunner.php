<?php
/**
 * ConsumerRunner.php 用来启动一个 consumer, 使用方法:
 * php ConsumerRunner.php consumer_file queue_name
 */
error_reporting(E_ALL & ~E_NOTICE);

define('APP_PATH', dirname(__FILE__) . '/..');
define('IPHP_PATH', '/data/lib/iphp');
require_once(IPHP_PATH . '/loader.php');
App::init();

class ConsumerRunner
{
	const TIME_INTERVAL = 3;
	// 处理次数
	const BATCH_NUM = 500;
	const MAX_RUN_TIME = 3600;

	private $stop;
    private $redis;
    private $queue_name;

	function run() {
		$stime = time();
		$job_count = 0;
		$this->init();
		while(!$this->stop){
			if(time() - $stime > self::MAX_RUN_TIME){
				break;
			}
            // 执行到一定次数后, 主动退出, 避免 php 内存泄露
            if($job_count++ >= self::BATCH_NUM){
                break;
            }
			$msg = $this->get_msg();
			if($msg == null) {
				$this->idle();
				continue;
			}
			$this->process_msg($msg);
		}
	}
	
	function idle(){
		$sleep_time = 100 * 1000; // 100ms
		$MAX_SLEEP_COUNT = intval(self::TIME_INTERVAL * 1000 * 1000 / $sleep_time);
		$sleep_count = 0;
		while(!$this->stop) {
			// 每次只 sleep 很短时间, 以便快速响应 stop 指令
			declare (ticks = 1); // 告诉PHP编译器, 这里可以插入中断(signal)检查语句
			usleep($sleep_time);
			if(++$sleep_count < $MAX_SLEEP_COUNT){
				continue;
			}else{
				break;
			}
		}
	}
	
	function stop() { //stop
		//logger::info("catch SIGTERM");
		$this->stop = true;
	}
	
	private function init() {
		declare (ticks = 1);
		pcntl_signal(SIGTERM, array($this, 'stop'));
		$this->stop = false;
        $this->redis = Util::redis();

		global $argv;
		$consumer_file = trim($argv[1]);
		if(!file_exists($consumer_file)){
			_throw("consumer script file not exists: $consumer_file");
		}

		$ps = explode('.', basename($consumer_file));
		$consumer_class = $ps[0];
		require_once($consumer_file);
		if(!class_exists($consumer_class)){
			_throw("consumer script class not exists: $consumer_class");
		}

        $this->queue_name = trim($argv[2]);
        if(strlen($this->queue_name) == 0 || strlen($this->queue_name) > 128){
            _throw("invalid queue_name: {$this->queue_name}");
        }

		$this->consumer = new $consumer_class();
		$this->consumer->init();
	}
	
	private function get_msg() {
        $msg = $this->redis->rPop($this->queue_name);
        if(is_null($msg)) {
            return null;
        }
        return $msg;
	}
	
	private function process_msg($msg){
        $job = intval($msg);
        if ($job <= 0) {
            Logger::info("{$this->queue_name} 数据格式错误: {$msg}");
            return null;
        }

        $stime = microtime(1);
        try{
            $ret = $this->consumer->process_job($job);
        } catch(Exception $e) {
            Logger::error("处理任务失败, msg: {$msg}, error: " . $e->getMessage());
            return;
        }

        $use_time = sprintf('%.2f', microtime(1) - $stime);

        if ($ret !== false) {
            Logger::debug("{$this->queue_name} 任务处理成功, use_time: $use_time, msg: {$msg}");
        } else {
            Logger::error("{$this->queue_name} 任务处理失败, 删除任务: {$msg}");
        }
	}
}

$queue_name = trim($argv[2]);
$runner = new ConsumerRunner();
try{
    Logger::info("start $queue_name");
    $runner->run();
}catch(Exception $e){
    Logger::error("$queue_name " . $e->getMessage());
    sleep(2); // sleep 2s, 避免管理进程频繁重启
}
Logger::info("stop $queue_name");