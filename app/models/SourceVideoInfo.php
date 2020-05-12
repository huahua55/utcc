<?php

class SourceVideoInfo extends Model
{
	static $table_name = 'utcc_source_video_info';

    const UPLOADING = 100;
	const UPLOAD_SUCCESS = 102;
    const UPLOAD_FAIL = 104;
    const UPLOAD_REPEAT = 105;
    const TRANSCODING = 200;
    const TRANSCODE_SUCCESS = 202;
    const TRANSCODE_PART_SUCCESS = 203;
    const TRANSCODE_FAIL = 204;

    static $status_table = [
        self::UPLOADING => '上传中',
        self::UPLOAD_SUCCESS => '上传完成',
        self::UPLOAD_FAIL => '上传失败',
        self::UPLOAD_REPEAT => '上传重复视频',
        self::TRANSCODING => '转码中',
        self::TRANSCODE_SUCCESS => '全部码流转码结束，均成功',
        self::TRANSCODE_PART_SUCCESS => '全部码流转码结束，部分成功',
        self::TRANSCODE_FAIL => '全部码流转码结束，均失败',
    ];

    function status_text()
    {
        return self::$status_table[$this->status];
    }

}