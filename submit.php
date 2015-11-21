<?php

// SUBMIT.PHP - web service alpha

header('Access-Control-Allow-Origin: http://www-spidercode.rhcloud.com');
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");

function error($code, $msg) {
	$result = array();
	$result['success'] = "false";
	$result['code'] = $code;
	$result['message'] = $msg;
	print json_encode($result);
	exit(0);
}


if (!file_exists("config.php")) {
	error("ERR004", "Buildservice not configured");
}
require_once("config.php");
require_once("lib.php");
require_once("buildservice.php");


$program = $task = false;

if (isset($_FILES['program_data'])) 
	$program = $_FILES['program_data']['tmp_name'];
if (isset($_FILES['task_data']) && file_exists($_FILES['task_data']['tmp_name'])) 
	$task = json_decode(file_get_contents($_FILES['task_data']['tmp_name']), true);
else if (isset($_POST['task_data'])) 
	$task = json_decode($_POST['task_data'], true);

if (!$program || (!file_exists($program)) || $_FILES['program_data']['error']!==UPLOAD_ERR_OK)
	error("ERR001", "File upload failed $program ".file_exists($program)." err ".$_FILES['program_data']['error']);

if (!$task)
	error("ERR002", "Task data not sent");

$compiler = find_best_compiler($task['language'], $task['required_compiler'], $task['preferred_compiler'], $task['compiler_features']);
if ($compiler === false)
	error("ERR003", "No suitable compiler found for language ".$task['language']);


$instance = create_instance($program);
$filelist = find_sources($task, $instance);
if ($filelist == array()) 
	error("ERR005", "No sources found");


$json_path = $conf_basepath . "/task_" . $task['id'] . ".js";
file_put_contents($json_path, json_encode($task));

$status_path = instance_path($instance) . "/buildservice_status.json"; // We use .json extension so it wouldn't be confused with JS sources
$status = array("status" => "Background process not started yet");
file_put_contents($status_path, json_encode($status));

$result = array();
$result['success'] = "true";
$result['message'] = "Instance created";
$result['instance'] = $instance;
print json_encode($result);

exec("php background.php ".$task['id']." $instance > /tmp/testbackground 2>&1 &");

?>