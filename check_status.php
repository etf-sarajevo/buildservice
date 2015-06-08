<?php


// CHECK_STATUS.PHP - web service alpha

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

$instance = intval($_GET['instance']);

$status_path = instance_path($instance) . "/buildservice_status.json"; // We use .json extension so it wouldn't be confused with JS sources

if (!file_exists($status_path))
	error("ERR001", "Instance not found $status_path");
	
$result = array();
$result['success'] = "true";
$result['status'] = json_decode(file_get_contents($status_path), true);

print json_encode($result);

?>