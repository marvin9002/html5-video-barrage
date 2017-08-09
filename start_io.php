<?php

include __DIR__ . '/vendor/autoload.php';
include __DIR__ . '/vendor/workerman/mysql-master/src/Connection.php';

use Workerman\Worker;
use Workerman\WebServer;
use Workerman\Lib\Timer;
use PHPSocketIO\SocketIO;

//use Workerman\MySQL;
//var_dump( new Workerman\MySQL\Connection('127.0.0.1', '3306', 'root', '', 'danmu'));die;
// 全局数组保存uid在线数据
$uidConnectionMap = array();
// 记录最后一次广播的在线用户数
$last_online_count = 0;
// 记录最后一次广播的在线页面数
$last_online_page_count = 0;

// PHPSocketIO服务
$sender_io = new SocketIO(2120);
// 客户端发起连接事件时，设置连接socket的各种事件回调
$sender_io->on('connection', function($socket) {
    // 当客户端发来登录事件时触发
    $socket->on('login', function ($uid)use($socket) {
        global $uidConnectionMap, $last_online_count, $last_online_page_count;
        // 已经登录过了
        if (isset($socket->uid)) {
            return;
        }
        // 更新对应uid的在线数据
        $uid = (string) $uid;
        if (!isset($uidConnectionMap[$uid])) {
            $uidConnectionMap[$uid] = 0;
        }
        // 这个uid有++$uidConnectionMap[$uid]个socket连接
        ++$uidConnectionMap[$uid];
        // 将这个连接加入到uid分组，方便针对uid推送数据
        $socket->join($uid);
        $socket->uid = $uid;
        // 更新这个socket对应页面的在线数据
        $socket->emit('update_online_count', "当前<b>{$last_online_count}</b>人在线，共打开<b>{$last_online_page_count}</b>个页面");
    });

    // 当客户端断开连接是触发（一般是关闭网页或者跳转刷新导致）
    $socket->on('disconnect', function () use($socket) {
        if (!isset($socket->uid)) {
            return;
        }
        global $uidConnectionMap, $sender_io;
        // 将uid的在线socket数减一
        if (--$uidConnectionMap[$socket->uid] <= 0) {
            unset($uidConnectionMap[$socket->uid]);
        }
    });
});

// 当$sender_io启动后监听一个http端口，通过这个端口可以给任意uid或者所有uid推送数据
$sender_io->on('workerStart', function() {
    // 监听一个http端口
    $inner_http_worker = new Worker('http://0.0.0.0:2121');
    $inner_http_worker->onWorkerStart = function ($http_connection, $data) {
        // 一个定时器，定时向所有uid推送当前uid在线数及在线页面数
    };
    global $listNum;
    $listNum = 100;
    Timer::add(5, function() {
        global $sender_io, $db,$listNum;
        $db = new Workerman\MySQL\Connection('127.0.0.1', '3306', 'root', '', 'danmu');
        do {
            $cont = $db->select('id,content')->from('content')->orderByDESC(array('id'))->limit(5)->query();
            foreach ($cont as $v) {
                if ($v['content']) {
                    $sender_io->emit('old_msg', json_encode(['code' => '200', 'data' => $v['content']]));
                }
            }
            $listNum--;
        } while ($listNum > 1);
    });










    // 当http客户端发来数据时触发
    $inner_http_worker->onMessage = function($http_connection, $data) {
        $_POST = $_POST ? $_POST : $_GET;
        // 推送数据的url格式 type=publish&to=uid&content=xxxx
        // 将db实例存储在全局变量中(也可以存储在某类的静态成员中)
        global $db;
        $db = new Workerman\MySQL\Connection('127.0.0.1', '3306', 'root', '', 'danmu');
        global $sender_io;
        switch (@$_POST['type']) {
            case 'send_msg':
                $content = htmlspecialchars(@$_GET['content']);
                $insert_id = $db->insert('content')->cols(array(
                            'content' => $content,
                            'create_time' => time(),
                            'ip' => '127001',
                            'wechartid' => 'sd23dsf423fsdfew'))->query();
                $to = @$_GET['to'];
                if ($to) {
                    $sender_io->to($to)->emit('new_msg', json_encode(['code' => '200', 'data' => $content]));
                } else {
                    $cont = $db->select('*')->from('content')->orderByDESC(array('id'))->row();
                    $sender_io->emit('new_msg', json_encode(['code' => '200', 'data' => $cont['content']]));
                }
                return $http_connection->send(json_encode(['code' => '200', 'msg' => 'ssok']));
        }
        return $http_connection->send(json_encode(['code' => '500', 'msg' => 'fail']));
    };
    // 执行监听
    $inner_http_worker->listen();

    // 一个定时器，定时向所有uid推送当前uid在线数及在线页面数
    Timer::add(1, function() {
        global $uidConnectionMap, $sender_io, $last_online_count, $last_online_page_count;
        $online_count_now = count($uidConnectionMap);
        $online_page_count_now = array_sum($uidConnectionMap);

        // 只有在客户端在线数变化了才广播，减少不必要的客户端通讯
        if ($last_online_count != $online_count_now || $last_online_page_count != $online_page_count_now) {
            $sender_io->emit('update_online_count', "当前<b>{$online_count_now}</b>人在线，共打开<b>{$online_page_count_now}</b>个页面");
            $last_online_count = $online_count_now;
            $last_online_page_count = $online_page_count_now;
        }
    });
});

if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
