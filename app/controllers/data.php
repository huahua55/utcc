<?php

class DataController extends AjaxController
{
    function init($ctx)
    {
        parent::init($ctx);
        header("content-type:application/json; charset=utf-8");
    }

    // @param vod_id 电影或电视剧ID
    // @param plotNo 剧集序号
    function index($ctx)
    {
        $vod_id = intval($_REQUEST['vod_id']);
        if ($vod_id <= 0) {
            return [];
        }
        $videos = SourceVideoInfo::find(0, 200, "vod_id='$vod_id'", 'plotNo asc');
        if ($videos) {
            foreach ($videos as $k => $v) {
                $videos[$k]->status_text = $v->status_text();
            }
        }
        return $videos;
    }

}
