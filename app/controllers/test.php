<?php

class TestController extends Controller
{
    function index($ctx) {
        $getID3 = new getID3();
        $file = '/data/vod/f482fc6a6ca111d198b53eed25a9c3c2.mp4';
        $fileinfo = $getID3->analyze($file);
        //print_r(md5_file($file));
        print_r($fileinfo);
        exit;
        //setlocale(LC_ALL, 'zh_CN.UTF-8');
        //print_r(pathinfo('/data/vod/电脑八十多.vod.mp4'));
    }

}