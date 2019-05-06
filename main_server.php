<?php
use Workerman\Worker;
use Workerman\Lib\Timer;
use \Workerman\Connection\AsyncTcpConnection;
require_once __DIR__ . '/Autoloader.php';

// 心跳间隔55秒
define('HEARTBEAT_TIME', 55);

// 创建一个Worker监听2347端口，不使用任何应用层协议
$tcp_worker = new Worker("tcp://0.0.0.0:2347");

// 启动1个进程对外提供服务
$tcp_worker->count = 1;

// 新增加一个属性，用来保存uid到connection的映射(uid是用户id或者客户端唯一标识)
$tcp_worker->uidConnections = array();

$tcp_worker->onConnect = function($connection)
{
    echo "new connection from ip " . $connection->getRemoteIp() . "\n";
};

// tcp onWorkerStart
$tcp_worker->onWorkerStart = function($worker)
{     
	Timer::add(1, function()use($worker){
        $time_now = time();
        foreach($worker->connections as $connection) {
            // 有可能该connection还没收到过消息，则lastMessageTime设置为当前时间
            if (empty($connection->lastMessageTime)) {
                $connection->lastMessageTime = $time_now;
                continue;
            }
            // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
            if ($time_now - $connection->lastMessageTime > HEARTBEAT_TIME) {
                $connection->close();
		        echo "The ip:".$connection->getRemoteIp()."  uid:".$connection->uid." is closed, the client may have been offline or The server handles database exceptions.\n";
            }
        }
    });	
};

// 当客户端发来数据时
$tcp_worker->onMessage = function($connection, $data)
{
    global $tcp_worker;
    // 判断当前客户端是否已经验证,即是否设置了uid
    if(!isset($connection->uid))
    {
       // 没验证的话把第一个包当做uid（这里为了方便演示，没做真正的验证）
       $connection->uid = $data;
       /* 保存uid到connection的映射，这样可以方便的通过uid查找connection，
        * 实现针对特定uid推送数据
        */
       $tcp_worker->uidConnections[$connection->uid] = $connection;
       echo "Connection has been established. ip:".$connection->getRemoteIp()."  uid:".$connection->uid."\n";
       return $connection->send('login success, your uid is ' . $connection->uid);
    }
    // 其它逻辑，针对某个uid发送 或者 全局广播
    // 假设消息格式为 uid@message 时是对 uid 发送 message
    // uid 为 all 时是全局广播
    @list($recv_uid, $message) = explode('@', $data);
     echo "recv_uid:".$recv_uid."\n";
     echo "len:".strlen($recv_uid)."\n";
     if(checkstr($data))
     {
        // 全局广播
        if($recv_uid == 'all')
        {
            broadcast($message);
        }
        // 给特定uid发送 \r\n 
        else
        {
            sendMessageByUid($recv_uid."\r\n", $message);
        }
     }
     else
     {
        //心跳


        echo "from:".$connection->getRemoteIp()." uid:".$connection->uid." ".$data."\n";
        // 给connection临时设置一个lastMessageTime属性，用来记录上次收到消息的时间
        $connection->lastMessageTime = time();
        //----------------------------------------------------------------------------------------------
        // 与远程task服务建立异步连接，ip为远程task服务的ip，如果是本机就是127.0.0.1，如果是集群就是lvs的ip
        $task_connection = new AsyncTcpConnection('Text://127.0.0.1:12345');
        // 任务及参数数据
        $task_data = array(
            'function' => 'send_mail',
            'args'       => array('from'=>'xxx', 'to'=>'xxx', 'contents'=>$data),
        );
        // 发送数据
        $task_connection->send(json_encode($task_data));

        // 异步获得结果
        $task_connection->onMessage = function($task_connection, $task_result)use($connection)
        {
             // 结果
             var_dump($task_result);
             // 获得结果后记得关闭异步连接
             $task_connection->close();
             // send to client
            $connection->send('start: ' . $task_result.":over");
        };
        // 执行异步连接
        $task_connection->connect();
        //---------------------------------------------------------------------------------------------
     }   
};

$tcp_worker->onClose = function($connection)
{
    global $tcp_worker;
    if(isset($connection->uid))
    {
        // 连接断开时删除映射
        unset($tcp_worker->uidConnections[$connection->uid]);
    }
};

// 向所有验证的用户推送数据
function broadcast($message)
{
   global $tcp_worker;
   foreach($tcp_worker->uidConnections as $connection)
   {
        $connection->send($message);
   }
}

// 针对uid推送数据
function sendMessageByUid($uid, $message)
{
    global $tcp_worker;
    if(isset($tcp_worker->uidConnections[$uid]))
    {
        $connection = $tcp_worker->uidConnections[$uid];
        $connection->send($message);
    }
}

function checkstr($str)
{
     $needle ='@';//判断是否包含a这个字符
     $tmparray = explode($needle,$str);
     if(count($tmparray)>1)
     {
        return true;
     }
     else
     {
        return false;
     }
}

// 运行worker
Worker::runAll();
?>
