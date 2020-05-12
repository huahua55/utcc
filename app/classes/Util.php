<?php
class Util
{
	static function redis()
    {
		static $redis = null;
		if($redis === null){
			$conf = App::$config['redis'];
			if(!$conf){
				return null;
			}
			try{
                $redis = new Redis();
                $redis->connect($conf['host'], $conf['port'], 5);
			} catch (Exception $e){
				Logger::error($e->getMessage());
				_throw("redis error");
			}
		}
		return $redis;
	}

    static function formatBytes($bytes, $precision = 2) {

        $units = array("b", "kb", "mb", "gb", "tb");

        $bytes = max($bytes, 0);

        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));

        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . " " . $units[$pow];

    }

    static function memoryUse() {
	    return self::formatBytes(memory_get_peak_usage());
    }

}
