<?php
error_reporting(E_ALL & ~E_NOTICE);

define('APP_PATH', dirname(__FILE__) . '/..');
define('IPHP_PATH', '/data/lib/iphp');
define('MANAGE_CONFIG_PATH', APP_PATH . '/console/consumer_config.php');
define('RUNNER_SCRIPT', APP_PATH . '/console/ConsumerRunner.php');
require_once(IPHP_PATH . '/loader.php');
App::init();

class ConsumerManager {

	CONST PER_WORKER_THRESHOLD = 20;
	CONST MIN_WORKERS = 1;
	CONST MAX_WORKERS = 10;

	private $child_count_map; // 任务关联的进程数 结构['path/TranscodeConsumer.php transcode_queue' => 10]
	private $child_pid_map; // 任务关联的进程ID列表 结构['path/TranscodeConsumer.php transcode_queue' => [1,2,3]]
	
	private $stop;
	
	public function __construct(){
		$this->child_count_map = array();
		$this->child_pid_map = array();

		$this->stop = false;
	}
	
	public function run() {
		$this->init();

		Logger::info("ProcessManager start....");
		while(!$this->stop) {
			$this->check_all_child_process(); // 循环检测子进程退出，并启动新进程
			$adjust_child_count_map = $this->analyze_job(); // 计算出待调整的进程及数量
            Logger::debug('adjust_child_count_map:'.Text::json_encode($adjust_child_count_map));
			$this->adjust_child_process($adjust_child_count_map); // 处理待调整的进程及数量 数量为正则创建 为负数则回收进程

			clearstatcache(true);
			$config = include(APP_PATH . '/config/config.php');
			Logger::init($config['logger']);

			Logger::debug('Manager idle start');
			$this->idle();
            Logger::debug('Manager idle end');
		}
		$this->destroy_all_child_process(); // 停止并回收所有子进程
		Logger::info("ProcessManager end....");
		exit();
	}
	
	function idle(){
		$sleep_time = 100 * 1000; // 100ms
		$MAX_SLEEP_COUNT = intval(3 * 1000 * 1000 / $sleep_time);
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
	
	public function stop() {
		Logger::debug("catch SIGTERM");
		$this->stop = true;
	}
	
	private function destroy_all_child_process() {
		foreach ($this->child_pid_map as $key => $value) {
			foreach ($value as $index => $pid) {
				Logger::info("stop worker: $key, pid: $pid");
				if(posix_kill($pid, SIGTERM) === false) {
					Logger::error("failed to stop worker: $key, pid: $pid");
					return;
				}
			}
		}
		while(1) {
			$ret = pcntl_wait($status);
			if($ret == -1) {
				break;
			}
		}
	}
	
	private function init() {
		declare (ticks = 1);
		pcntl_signal(SIGTERM, array($this, 'stop')); // 注册信号
        Logger::debug('init监听SIGTERM信号');
		if(!file_exists(MANAGE_CONFIG_PATH)) {
			Logger::warn("the config file " . MANAGE_CONFIG_PATH . " is not exists");
			exit();
		}
	}
	
	private function check_all_child_process() {
		foreach ($this->child_pid_map as $key => $value) {
			foreach ($value as $index => $pid) {
			    // 返回的值可以是-1，0或者 >0的值，
                //如果是-1, 表示子进程出错，
                //如果>0表示子进程已经退出且值是退出的子进程pid，至于如何退出， 可以通过$status状态码反应。
                //那什么时候返回0呢， 只有在option 参数为 WNOHANG且子进程正在运行时0, 也就是说当设置了options=WNOHANG时， 如果子进程还没有退出， 此时pcntl_waitpid就会返回0
                //另外， 如果不设置这个参数为WNOHANG， pcntl_waitpid 就会阻塞运行， 直到子进程退出
				//unblocking...,test the child processes is killed or not.
				$ret = pcntl_waitpid($pid, $status, WNOHANG | WUNTRACED);
				if($ret == $pid) {
					unset($this->child_pid_map[$key][$index]);
					Logger::info("child process is killed by someone for some reason, wait it successfull, $key, $pid");
					$this->create_child_process($key);
				}else if($ret == -1) {
					Logger::error("the child is not a child process belonging to the manager, $key, $pid");
				}
			}
		}
	}
	
	private function adjust_child_process($adjust_child_count_map) {
		$destroyed_pid = array();
		foreach ($adjust_child_count_map as $key => $value) {
			if($value > 0) {
				for($i = 0; $i < $value; $i++){
					$this->create_child_process($key);
				}
			} else {
				$count = abs($value);
				for($i = 0; $i < $count; $i++) {
					$pid = $this->destroy_child_process($key);
					if($pid > 0) {
						$destroyed_pid[] = $pid;
					}
				}
			}
		}
		$this->recycle_destroyed_child_process($destroyed_pid) ;
	}
	
	private function recycle_destroyed_child_process($destroyed_pid) {
		foreach ($destroyed_pid as $pid) {
			// 		Blocking here and waiting the special pid. Why not pcntl_wait?
			// 		Because some other child processes may be killed for some reason in the meaning time.
			// 		If we use pcntl_wait, these zombies will be processed, but we don't know
			// 		who these children are;
			// 		We must launch a new process which is the same as the process,
			// 		but we don't know anything about it.
			$ret = pcntl_waitpid($pid, $status);
			if($ret == $pid) {
				Logger::info("manager recycle a child process, $pid");
			}
			if($ret == -1) {
				Logger::error("the child is not a child process belonging to the manager, $pid");
				return ;
			}	
		}
		
	}
	
	private function create_child_process($key) {
		$pid = pcntl_fork();
		if($pid < 0) {
		    // 启动子进程失败
			Logger::debug("fork child process failed");
			return ;
		} else if($pid == 0) {
		    // 子进程
			Logger::info("start worker: $key, pid: " . posix_getpid());
			$program = preg_split("/\s+/", $key);
			array_unshift($program, RUNNER_SCRIPT);
			Logger::debug('pcntl_exec program:' . Text::json_encode($program));
			pcntl_exec('/usr/bin/php', $program);
		} else {
		    // 父进程
			$this->child_pid_map[$key][] = $pid;
		}
	}
	
	private function destroy_child_process($key) {
		$pid = array_pop($this->child_pid_map[$key]);
		if($pid === NULL) {
			Logger::error("there is no pid to be killed, must have a big bug");
			return;
		}
		//if this pid was killed by someone before the follow statement, the posix_kill
		//returns true still, because the zombie process has not be process by the parent(pcntl_wait|pcntl_waitpid).
		//so don't worry about this, we can consider that the signal sent by someone has been ignore.
		if(posix_kill($pid, SIGTERM) === false) {
			Logger::debug("manager kill child process failed, $pid");
			return;
		}
		Logger::info("kill worker: $key, pid: $pid");
		return $pid;
	}

	// 对比实时计算需要开启的进程数与已存在的进程数
	private function analyze_job() {
		clearstatcache(true);

		$conf_file = realpath(MANAGE_CONFIG_PATH);
		$manage_conf = include($conf_file);
		if($manage_conf === false || !is_array($manage_conf)){
			Logger::warn("config file is not existed or don't have a array");
			exit();
		}
        // 实时计算需要开启的进程数
		$new_cosumer_count_map = array();
		foreach ($manage_conf as $each_conf) {
            $command_line = $each_conf['script'] . " " . $each_conf['queue'];
			$command_line = trim($command_line);
			$workers = $this->calc_worker_count($each_conf);
			$new_cosumer_count_map[$command_line] = $workers;
		}
        // 计算与已存在的进程数差异
		$diff_child_count_map = array();
		foreach ($new_cosumer_count_map as $key => $value) {
			$key = trim($key);
			if(array_key_exists($key, $this->child_count_map)) {
				$tmp = $value - $this->child_count_map[$key];
				if($tmp != 0) {
					$diff_child_count_map[$key] = $tmp;
					$ps = explode(' ', $key);
					Logger::debug("job: {$ps[1]}, change workers: {$this->child_count_map[$key]}=>$value");
				}
			} else {
				$diff_child_count_map[$key] = $value;
			}
		}
		
		foreach ($this->child_count_map as $key => $value) {
			if(!array_key_exists($key, $new_cosumer_count_map)) {
				$diff_child_count_map[$key] = -$value;
			}
		}
		
		$this->child_count_map = $new_cosumer_count_map;
		return $diff_child_count_map;
	}

	// 根据配置及待处理任务数计算启动进程数
	private function calc_worker_count($each_conf) {
		$min_workers = intval($each_conf['min_workers']) > 0 ? intval($each_conf['min_workers']) : self::MIN_WORKERS;
		$max_workers = intval($each_conf['max_workers']) > 0 ? intval($each_conf['max_workers']) : self::MAX_WORKERS;

        try{
            $redis = Util::redis();
            $qsize = $redis->lLen($each_conf['queue']);
            Logger::debug("qsize:$qsize");
        } catch (Exception $e) {
            Logger::error($e->getMessage());
            return $min_workers;
        }

		$per_worker_threshold = intval($each_conf['per_worker_threshold']) > 0 ?
			intval($each_conf['per_worker_threshold']) : self::PER_WORKER_THRESHOLD;
		$workers = ceil($qsize / $per_worker_threshold);

		if($workers <= $min_workers){
			return $min_workers;
		} else if($workers >= $max_workers){
			return $max_workers;
		} else {
			return $workers;
		}
	}
}

$pid = pcntl_fork();
if($pid > 0) {
	exit();
}

posix_setsid();

$pid = pcntl_fork();
if($pid > 0) {
	exit();
}

$manager = new ConsumerManager();
$manager->run();

