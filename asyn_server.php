<?php
	use Workerman\Worker;
	require_once __DIR__ . '/Autoloader.php';
	
	// task worker，使用Text协议
	$task_worker = new Worker('Text://0.0.0.0:12345');

	// task进程数可以根据需要多开一些
	$task_worker->count = 100;
	$task_worker->name = 'TaskWorker';
	//只有php7才支持task->reusePort，可以让每个task进程均衡的接收任务
	//$task->reusePort = true;
	$task_worker->onMessage = function($connection, $task_data)
	{
	     //假设发来的是json数据
	     $task_data = json_decode($task_data, true);
	     var_dump($task_data["args"]["contents"]);
	     $task_data["args"]["contents"] =  $task_data["args"]["contents"]+1;
	     // 根据task_data处理相应的任务(sql)逻辑.... 得到结果，这里省略....
	     $task_result = $task_data;
	     // 发送结果
	     $connection->send(json_encode($task_result));
	};

	$task_worker->onClose = function($connection)
	{
	       echo "Asynchronous process task completed, closed...\n";
	};

	Worker::runAll();
?>
