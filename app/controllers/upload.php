<?php

class UploadController extends AjaxController
{
    function init($ctx)
    {
        parent::init($ctx);
        header("content-type:application/json; charset=utf-8");
    }

    // @param id
    // @param name 文件名
    // @param type 文件类型
    // @param size 文件总大小
    // @param md5value 文件MD5值
    // @param isChunked 是否分片上传
    // @param chunk 当前分片
    // @param chunks 总分片
    // @param vod_id 电影或电视剧ID
    // @param plotNo 剧集序号
    function index($ctx) {
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");

        // Support CORS
        header("Access-Control-Allow-Origin: *");
        // other CORS headers if any...
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            exit; // finish preflight CORS requests here
        }

        // 10 minutes execution time
        @set_time_limit(10 * 60);

        $vod_id = intval($_REQUEST['vod_id']);
        $plotNo = intval($_REQUEST['plotNo']);
        $name = $_REQUEST['name'];
        $md5 = trim($_REQUEST['md5']);
        if (!$md5 || strlen($md5) != 32) {
            _throw('文件MD5不正确，请等待MD5计算完毕再上传');
        }
        $video = SourceVideoInfo::get_by('md5', $md5);
        if ($video) {
            _throw(sprintf('文件MD5重复'));
        }
        if ($vod_id <= 0) {
            _throw('视频ID参数错误');
        }
        if ($plotNo <= 0) {
            _throw('剧集序号plotNo参数错误');
        }
        $video = SourceVideoInfo::find_one("vod_id='$vod_id' and plotNo='$plotNo'");
        if ($video) {
            _throw('剧集序号plotNo重复');
        }

        // Settings
        $targetDir =  '/data/vod/tmp';
        $uploadDir = '/data/vod';

        $cleanupTargetDir = true; // Remove old files
        $maxFileAge = 3 * 24 * 3600; // Temp file age in seconds

        // Create target dir
        if (!file_exists($targetDir)) {
            if (!mkdir($targetDir)) {
                _throw(sprintf('%s目录创建失败', $targetDir));
            }
        }

        // Create target dir
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir)) {
                _throw(sprintf('%s目录创建失败', $uploadDir));
            }
        }

        // Get a file name
        $fileName = $md5 . '.' . pathinfo($name, PATHINFO_EXTENSION);

        $filePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
        $uploadPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

        // Chunking might be enabled
        $chunk = isset($_REQUEST["chunk"]) ? intval($_REQUEST["chunk"]) : 0;
        $chunks = isset($_REQUEST["chunks"]) ? intval($_REQUEST["chunks"]) : 1;

        // Remove old temp files
        if ($cleanupTargetDir) {
            if (!is_dir($targetDir) || !$dir = opendir($targetDir)) {
                _throw('Failed to open temp directory', 100);
            }

            while (($file = readdir($dir)) !== false) {
                $tmpfilePath = $targetDir . DIRECTORY_SEPARATOR . $file;

                // If temp file is current file proceed to the next
                if ($tmpfilePath == "{$filePath}_{$chunk}.part" || $tmpfilePath == "{$filePath}_{$chunk}.parttmp") {
                    continue;
                }

                // Remove temp file if it is older than the max age and is not the current file
                if (preg_match('/\.(part|parttmp)$/', $file) && (@filemtime($tmpfilePath) < time() - $maxFileAge)) {
                    @unlink($tmpfilePath);
                }
            }
            closedir($dir);
        }


        // Open temp file
        if (!$out = @fopen("{$filePath}_{$chunk}.parttmp", "wb")) {
            _throw('Failed to open output stream', 102);
        }

        if (!empty($_FILES)) {
            if ($_FILES["file"]["error"] || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
                _throw('Failed to move uploaded file.', 103);
            }

            // Read binary input stream and append it to temp file
            if (!$in = @fopen($_FILES["file"]["tmp_name"], "r")) {
                _throw('Failed to open input stream.', 101);
            }
        } else {
            if (!$in = @fopen("php://input", "r")) {
                _throw('Failed to open input stream.', 101);
            }
        }
        stream_copy_to_stream($in, $out);

        @fclose($out);
        @fclose($in);

        rename("{$filePath}_{$chunk}.parttmp", "{$filePath}_{$chunk}.part");

        $done = true;
        for( $index = 0; $index < $chunks; $index++ ) {
            if ( !file_exists("{$filePath}_{$index}.part") ) {
                $done = false;
                break;
            }
        }
        if ( $done ) {
            $t = microtime(true);
            if (!$out = @fopen($uploadPath, "w")) {
                throw new Exception('Failed to open output stream.', 101);
            }

            if ( flock($out, LOCK_EX) ) {
                for( $index = 0; $index < $chunks; $index++ ) {
                    if (!$in = @fopen("{$filePath}_{$index}.part", "r")) {
                        break;
                    }

                    stream_copy_to_stream($in, $out);

                    @fclose($in);
                    @unlink("{$filePath}_{$index}.part");
                }

                flock($out, LOCK_UN);
            }
            @fclose($out);
            Logger::debug(sprintf('combine files memory use:%s time use:%s', Util::memoryUse(), microtime(true)-$t));
            //
            $getID3 = new getID3();
            $file_info = $getID3->analyze($uploadPath);
            $format = $file_info['fileformat'];
            $size = $file_info['filesize'];
            $bitrate = intval($file_info['bitrate']);
            $height = intval($file_info['video']['resolution_y']);
            $width = intval($file_info['video']['resolution_x']);
            $fps = intval($file_info['video']['frame_rate']);
            $duration = intval($file_info['playtime_seconds']);
            try {
                $video = SourceVideoInfo::save([
                    'vod_id' => $vod_id,
                    'plotNo' => $plotNo,
                    'name' => $name,
                    'duration' => $duration,
                    'src_path' => $file_info['filenamepath'],
                    'format' => $format,
                    'bitrate' => $bitrate,
                    'width' => $width,
                    'height' => $height,
                    'fps' => $fps,
                    'md5' => $md5,
                    'size' => $size,
                    'status' => SourceVideoInfo::UPLOAD_SUCCESS,
                ]);
            } catch (Exception $e) {
                Logger::error(sprintf('视频入库失败:%s', $e->getMessage()));
                _throw('视频上传入库失败');
            }

            // 入队待转码队列
            $ret = Util::redis()->lPush(App::$config['transcode_queue'], $video->id);
            if ($ret === false) {
                Logger::error(sprintf('待转码视频ID:%d 入队失败', $video->id));
            }
        }

        return;
    }

}
