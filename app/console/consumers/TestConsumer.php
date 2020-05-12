<?php

class TestConsumer extends BaseConsumer
{
	function init(){
		echo "init...\n";
	}
	
	function process_job($job){
		sleep(20);
		Logger::debug('TestConsumer process_job done');
		return true;
	}
}