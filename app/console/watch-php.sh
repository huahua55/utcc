#!/bin/bash
cur_dir=`old=\`pwd\`; cd \`dirname $0\`; echo \`pwd\`; cd $old;`
php=php
php_script=$cur_dir/ConsumerManager.php

get_pid() {
	ps aux | grep "php .*ConsumerManager\.php" | grep -v "grep" | head -n 1 | awk '{print $2}'
}

start() {
	pid=$(get_pid)
	if [[ $pid == "" ]] ; then
		$php $php_script > /dev/null 2>&1
		if [[ $? != 0 ]] ; then
			echo "Start failed......"
		else
			echo "Start successfull......"
		fi
	else
		echo -e "Have the process already.\nyou can use \"$0 list\""
	fi
}

check() {
	start
}

stop() {
	pid=$(get_pid)	
	if [[ $pid == "" ]] ; then
		echo "Can't find the process ConsumerManager"
    else
		kill $pid
		while true ; do
			pid=$(get_pid)
			if [[ $pid == "" ]] ; then
				break
			fi
		done
		echo "Stop successfull......."
	fi		
}

restart() {
	stop
	start
}

list() {
	pid=$(get_pid)	
	if [[ $pid != "" ]];then
			ps -ef | awk -v PID=$pid '$3==PID{a[$NF]++;} END {for (i in a) print i " , " a[i];}' | sort -k 1
	fi
}

usage () {
cat << EOF
Usage : $0 start|stop|restart|list|check
EOF
exit
}

if [[ $1 == "" ]] ; then
	usage
fi

case "$1" in
	"start" )
		start
	;;
	"check" )
		start
	;;
	"stop" )
		stop
	;;
	"restart" )
		restart
	;;
	"list" )
		list
	;;
	*)
		usage
	;;
esac
