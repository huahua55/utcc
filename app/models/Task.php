<?php

class Task extends Model
{
	static $table_name = 'utcc_task';

    const TRANSCODE_STATUS_TODO = 0;
	const TRANSCODE_STATUS_START = 1;
    const TRANSCODE_STATUS_SUCCESS = 2;
    const TRANSCODE_STATUS_FAIL = 3;

}