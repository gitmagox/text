<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2017/6/21
 * Time: 14:43
 */

$table = new swoole_table(2048);
$table->column('fd', swoole_table::TYPE_INT);
$table->column('from_id', swoole_table::TYPE_INT);
$table->create();

//启动一个websocket_server  运行模式为多进程
$server = new swoole_websocket_server("0.0.0.0", 9501,3);

//共享内存表
$server->table = $table;

$server->set(array(
    // swoole server config
    'daemonize'   => 0, //是否开启守护进程
    'reactor_num' =>2,//通过此参数来调节poll线程的数量，以充分利用多核
    'work_mode'   => 3,
    'worker_num'  => 8,//设置启动的worker进程数量。swoole采用固定worker进程的模式。
    'max_request' => 10000,//此参数表示worker进程在处理完n次请求后结束运行。manager会重新创建一个worker进程。此选项用来防止worker进程内存溢出。
    'backlog'     => 128,//此参数将决定最多同时有多少个待accept的连接，swoole本身accept效率是很高的，基本上不会出现大量排队情况。
    'open_cpu_affinity' =>1,//启用CPU亲和设置
    'tcp_defer_accept' => 5,//此参数设定一个秒数，当客户端连接连接到服务器时，在约定秒数内并不会触发accept，直到有数据发送，或者超时时才会触发。
    'log_file' => '/data/log/swoole.log',//错误日志文件,指定swoole错误日志文件。在swoole运行期发生的异常信息会记录到这个文件中。默认会打印到屏幕。
    'dispatch_mode' => 2,//1平均分配，2按FD取摸固定分配，3抢占式分配，默认为取模(dispatch=2)
    'task_worker_num' => 8,//task进程数量
));

//当WebSocket客户端与服务器建立连接并完成握手后会回调此函数。
//$request是一个Http请求对象
$server->on('open', function (swoole_websocket_server $_server, swoole_http_request $request) {
    $client = $_server->table->get($request->fd);
    if (!$client) {
        // 获取客户端信息
        $info = $_server->getClientInfo($request->fd);
        // 存入客户端列表中
        $_server->table->set($request->fd, [ 'fd' => $request->fd, 'from_id' => $info['from_id'], 'data' => null ]);
        if ($_server->table->count()) {
            //广播客户端新用户消息
            foreach ($_server->table as $client) {
                $_server->push($client['fd'], json_encode([
                    'message' => "欢迎客户端{$request->fd}",
                    'count'   => $_server->table->count()
                ]));
            }
        }
        echo "当前活跃用户为：{$_server->table->count()}人\n";
    }
});

$server->on('message', function (swoole_websocket_server $ser, $fm) {
    // 广播客户端用户消息
    if ($ser->table->count()) {
        foreach ($ser->table as $client) {
            $ser->push($client['fd'],
                json_encode([ 'message' => "客户端{$fm->fd}说:".$fm->data, 'count' => $ser->table->count() ]));
        }
    }
});

$server->on('close', function ($ser, $fd) {
    //删除离开的客户端
    $ser->table->del($fd);
    //广播在线用户离开的是那个客户端
    if ($ser->table->count()) {
        foreach ($ser->table as $client) {
            $ser->push($client['fd'], json_encode([ 'message' => "客户端{$fd}离开了", 'count' => $ser->table->count() ]));
        }
    }
    echo "客户端 {$fd} 关闭\n";
});

//服务启动
$server->start();