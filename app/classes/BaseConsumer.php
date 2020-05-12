<?php
abstract class BaseConsumer
{
	function init(){
	}
	
	// 如果任务处理失败, 应该返回 false, 否则应该返回 true
	abstract function process_job($job);
}
