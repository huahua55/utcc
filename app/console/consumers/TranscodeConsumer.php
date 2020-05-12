<?php

class TranscodeConsumer extends BaseConsumer
{
	function init(){
	    parent::init();
	}
	
	function process_job($job){
	    $vid = $job;
        $source_video = SourceVideoInfo::get($vid);
        try {
            Db::begin();
            $sql = 'update %s set status=%d where id=%d and status in (%s)';
            $sql = sprintf($sql, SourceVideoInfo::$table_name,
                SourceVideoInfo::TRANSCODING, $vid, Db::build_in_string([SourceVideoInfo::UPLOAD_SUCCESS, SourceVideoInfo::TRANSCODE_FAIL]));
            if (Db::update($sql)) {
                Task::save([
                    'vid' => $vid,
                    'video_width' => $source_video->width,
                    'transcode_start_time' => date('Y-m-d H:i:s'),
                    'transcode_status' => Task::TRANSCODE_STATUS_START,
                ]);
                if ($source_video->width > 640) {
                    Task::save([
                        'vid' => $vid,
                        'video_width' => 640,
                        'transcode_start_time' => date('Y-m-d H:i:s'),
                        'transcode_status' => Task::TRANSCODE_STATUS_START,
                    ]);
                }
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
        }
        $tasks = Task::find(0,10, sprintf('vid=%d and transcode_status=%d', $vid, Task::TRANSCODE_STATUS_START));
        if ($tasks) {
            foreach ($tasks as $task) {
                if ($task->video_width == $source_video->width) {
                    $args = [
                        '/usr/bin/ffmpeg',
                        '-y',
                        '-i',
                        $source_video->src_path,
                        '-vcodec',
                        'copy',
                        '-acodec',
                        'copy',
                        '-hls_time',
                        '2',
                        '-hls_list_size',
                        '0',
                        '-f',
                        'hls',
                    ];
                } else {
                    $args = [
                        '/usr/bin/ffmpeg',
                        '-y',
                        '-i',
                        $source_video->src_path,
                        '-c:v',
                        'libx264',
                        '-c:a',
                        'aac',
                        '-vf',
                        sprintf('scale=%d:-2', $task->video_width),
                        '-hls_time',
                        '2',
                        '-hls_list_size',
                        '0',
                        '-f',
                        'hls',
                    ];
                }
                $target_path = "/data/vod/hls/{$source_video->md5}/{$task->video_width}";
                if (!file_exists($target_path)) {
                    Logger::debug("创建目录{$target_path}");
                    $bool = mkdir($target_path, 0755, true);
                    if (!$bool) {
                        Logger::error("创建目录{$target_path}失败");
                        return false;
                    }
                }
                $m3u8_file = "$target_path/i.m3u8";
                // 转码
                $args[] = $m3u8_file;
                $cmd = implode(' ', $args);
                $t1 = microtime(true);
                Logger::debug("执行转码命令：$cmd start");
                system($cmd, $return_var);
                $t2 = microtime(true) - $t1;
                Logger::debug("执行转码命令：$cmd end, 耗时:$t2");
                if ($return_var === false) {
                    Logger::error("转码命令执行失败: $cmd");
                    $source_video->update([
                        'status' => SourceVideoInfo::TRANSCODE_FAIL
                    ]);
                    return false;
                }
                $task->update([
                    'video_path' => $m3u8_file,
                    'transcode_finish_time' => date('Y-m-d H:i:s'),
                    'transcode_status' => Task::TRANSCODE_STATUS_SUCCESS
                ]);
            }
            $source_video->update([
                'status' => SourceVideoInfo::TRANSCODE_SUCCESS
            ]);
            $this->notify($vid);
        }
		return true;
	}

	//
	function notify($vid) {
        $source_video = SourceVideoInfo::get($vid);
        $vod = Vod::find_one("vod_id={$source_video->vod_id}");
        $tasks = Task::find(0,2, sprintf('vid=%d and transcode_status=%d', $vid, Task::TRANSCODE_STATUS_SUCCESS), 'video_width asc');
        $plotNo = $source_video->plotNo;
        if ($tasks) {
            $vod_play_url = $vod->vod_play_url;
            if ($vod_play_url) {
                $arr = explode('$$$', $vod_play_url);
                foreach ($arr as $k => &$sub) {
                    if (isset($tasks[$k])) {
                        $play_url = App::$config['cdn'] . str_replace('/data/vod', '', $tasks[$k]->video_path);
                        $sub = trim($sub, '#');
                        $sub .= sprintf('#%s$%s#', $plotNo, $play_url);
                    }
                    unset($sub);
                }
                $vod_play_url = join('$$$', $arr);
            } else {
                $subs = [];
                foreach ($tasks as $k => $task) {
                    $play_url = App::$config['cdn'] . str_replace('/data/vod', '', $tasks[$k]->video_path);
                    $subs[$k] = sprintf('%s$%s', $plotNo, $play_url);
                }
                $vod_play_url = join('$$$', $subs);
            }
            $sql = "update %s set vod_play_url='%s', vod_play_from='%s' where vod_id=%d";
            $sql = sprintf($sql, Vod::$table_name,
                $vod_play_url, 'dplayer$$$dplayer', $vod->vod_id);
            Db::update($sql);
        }
    }

}