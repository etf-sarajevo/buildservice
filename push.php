<?php

// PUSH.PHP - web service alpha


if (!file_exists("config.php")) {
	error("ERR004", "Buildservice not configured");
}
require_once("config.php");
require_once("lib.php");
require_once("buildservice.php");
require_once("status_codes.php");


// CLI version
if (php_sapi_name() == "cli") {
	echo "push.php\nCopyright (c) 2014-2016 Vedran Ljubović\nElektrotehnički fakultet Sarajevo\nLicensed under GNU GPL v3\n\n";

	if (array_key_exists('cli_enabled', $conf_push) && $conf_push['cli_enabled'] === false) 
		print "CLI disabled\n\n";
		
	else if ($argc > 1) {
		$result = cli_parse_arguments($argc, $argv);
		
		if ($result['success'] == "false") print "ERROR: ";
		if (array_key_exists('data', $result) && !empty($result['data'])) {
			foreach($result['data'] as $data) {
				if (is_array($data))  
					print join("\t", $data);
				else print $data;
				print "\n";
			}
		} else
			print $result['message'];
		print "\n";
	}
	else
		usage();

// Web service
} else {
	if (array_key_exists('ws_enabled', $conf_push) && $conf_push['ws_enabled'] === false)
		$result = error('ERR100', 'Web service disabled');
	else
		$result = ws_parse_arguments();

	if (defined("JSON_PRETTY_PRINT"))
		print json_encode($result, JSON_PRETTY_PRINT);
	else
		print json_encode($result);
}


// Print CLI HELP
function usage() {
	?>
Usage:	php push.php PARAMS

Available PARAMS are:
 next-task		ID of next task in queue
 list-tasks		List all tasks
 list-progs TASKID	List all programs in task TASKID available to current user
 list-queue		Dump current queue
 list-current		What is currently being build and where
 task-info TASKID	Some information about task TASKID
 prog-info PROGID	Some information about program PROGID
 add-task FILE		Adds JSON file to list of tasks
 add-program TASKID NAME FILE
			Adds program file with given name in given task
 retry TASKID PROGID	Retry program PROGID in task TASKID
 help			This help page

<?php

}


// Parse command line arguments and invoke functions
function cli_parse_arguments($argc, $argv) {
	// Display help
	if ($argc == 1 || in_array("help", $argv) || in_array("--help", $argv) || in_array("-h", $argv)) {
		usage();
		return ok('');
	}

	// Commands that take no params
	if (in_array("list-tasks", $argv))
		return getTaskList();
	if (in_array("next-task", $argv)) {
		$msg = nextTask();
		if ($msg['data']['id'] == "false") {
			unset ($msg['data']);
			$msg['message'] = "No more tasks in queue";
		}
		return $msg;
	}
	if (in_array("list-queue", $argv))
		return listPrograms(0);
	if (in_array("list-current", $argv))
		return getCurrent();

	// Commands that take one param
	$pi = 0;
	if (($pi = array_search("list-progs", $argv)) || ($pi = array_search("prog-info", $argv)) || ($pi = array_search("task-info", $argv)) || ($pi = array_search("add-task", $argv))) {
		if ($pi == 1) $ii = 2; else $ii = 1;
		if ($argc < 3) {
			return error('ERR202', $argv[$pi]." takes exactly one parameter.");
		} else if ($argv[$pi] != "add-task" && !is_numeric($argv[$ii]))
			return error('ERR201', "ID should be an integer.");
		else {
			if ($argv[$pi] == "task-info") {
				$taskid = $argv[$ii];
				$taskmsg = getTaskData($taskid);
				$task = $taskmsg['data'];
				$result = ok("Task ID: $taskid\nName: ".$task['name']."\nLanguage: ".$task['language']);
				return $result;
			}
			if ($argv[$pi] == "list-progs")
				return listPrograms($argv[$ii]);
			if ($argv[$pi] == "prog-info") {
				return getProgramData($argv[$ii]);
			}
			if ($argv[$pi] == "add-task") {
				if (!file_exists($argv[$ii]))
					return error('ERR002', 'File not found');
				$task = json_decode( file_get_contents($argv[$ii]), true );
				if ($task === NULL)
					return error('ERR203', "File ".$argv[$ii]." doesn't seem to be a JSON file");
				$result = setTask($task);
				return $result;
			}
			// TODO
		}
		return;
	}

	// Cmds that have fixed param order
	if ($argv[1] == "add-program" && $argc==5) {
		if (!is_numeric($argv[2]))
			return error('ERR201', "ID should be an integer.");
		if (!file_exists($argv[4]))
			return error('ERR002', 'File not found');
		return addProgram($argv[2], $argv[3], $argv[4]);
	}
	if ($argv[1] == "retry" && $argc==4) {
		if (!is_numeric($argv[2]) || !is_numeric($argv[3]))
			return error('ERR201', "ID should be an integer.");
		return retryProgram($argv[2], $argv[3]);
	}
	
	usage();
	exit (0);
}


// Parse web arguments and invoke functions
function ws_parse_arguments() {
	global $conf_allow_push, $conf_allow_pull, $conf_push_delete_done;
	
	$pull_actions = array("getTaskList", "getTaskData", "getProgramData", "listPrograms", "getCurrent", "nextTask", "assignProgram", 
	"setProgramStatus", "setCompileResult", "setExecuteResult", "setDebugResult", "setProfileResult", "setTestResult", "getFile");
	$push_actions = array();
	
	$action = $_REQUEST['action'];
	
	// Authenticate
	if (in_array($action, $pull_actions) && !authenticate($conf_allow_pull))
		return error('ERR001', 'Not allowed');
	if (in_array($action, $push_actions) && !authenticate($conf_allow_push))
		return error('ERR001', 'Not allowed');
	

	// PULL ACTIONS

	if ($action == "getTaskList") return getTaskList();
	if ($action == "getTaskData") return getTaskData(intval($_REQUEST['task']));
	if ($action == "getProgramData") return getProgramData(intval($_REQUEST['program']));
	if ($action == "listPrograms") return listPrograms(intval($_REQUEST['task']));
	if ($action == "getCurrent") return getCurrent();

	if ($action == "nextTask") if (isset($_REQUEST['previousTask']))
		return nextTask(intval($_REQUEST['previousTask']));
	else
		return nextTask(0);
	if ($action == "assignProgram") return assignProgram(intval($_REQUEST['task']), json_decode($_REQUEST['buildhost'], true));
	if ($action == "setProgramStatus") {
		$program = intval($_REQUEST['program']);
		updateProgramStatus($program, "status", intval($_REQUEST['status']));
		clearCurrent($program);
		return ok('');
	}
	if ($action == "setCompileResult") {
		updateProgramStatus(intval($_REQUEST['program']), "compile_result", json_decode($_REQUEST['result'], true));
		return ok('');
	}
	if ($action == "setExecuteResult") {
		updateProgramStatus(intval($_REQUEST['program']), "run_result", json_decode($_REQUEST['result'], true));
		return ok('');
	}
	if ($action == "setDebugResult") {
		updateProgramStatus(intval($_REQUEST['program']), "debug_result", json_decode($_REQUEST['result'], true));
		return ok('');
	}
	if ($action == "setProfileResult") {
		updateProgramStatus(intval($_REQUEST['program']), "profile_result", json_decode($_REQUEST['result'], true));
		return ok('');
	}
	if ($action == "setTestResult") {
		$status = getProgramStatus(intval($_REQUEST['program']));
		$test_results = $status['test_results'];
		$test_results[intval($_REQUEST['test'])] = json_decode($_REQUEST['result'], true);
		updateProgramStatus(intval($_REQUEST['program']), "test_results", $test_results);
		return ok('');
	}
	
	// This one is specific as it doesn't return JSON
	if ($action == "getFile") getProgramFile(intval($_REQUEST['program']));
	
	
	// PUSH ACTIONS
	if ($action == "setTask") return setTask( json_decode($_REQUEST['task'], true) );
	// File must be uploaded to webservice
	if ($action == "addProgram") return addProgram(intval($_REQUEST['task']), $_REQUEST['name'], $_FILES['program']['tmp_name']);
	if ($action == "getProgramStatus") {
		$msg = ok('');
		$msg['status'] = getProgramStatus(intval($_REQUEST['program']));
		return $msg;
	}
	if ($action == "deleteProgram") return deleteProgram(intval($_REQUEST['program']));
}


// List all tasks
function getTaskList() {
	global $conf_basepath;
	
	$taskpath = $conf_basepath . "/tasks";
	if (!is_dir($taskpath)) return ok('');
	
	$tasks = array();
	foreach(scandir($taskpath) as $entry) {
		if ($entry == "." || $entry == "..") continue;
		$task = json_decode( file_get_contents("$taskpath/$entry"), true );
		$titem = array();
		$titem['id'] = $task['id'];
		$titem['name'] = $task['name'];
		array_push($tasks, $titem);
	}
	$msg = ok('');
	$msg['data'] = $tasks;
	return $msg;
}


// Get description for task TASKID
function getTaskData($taskid) {
	global $conf_basepath;
	$taskpath = $conf_basepath . "/tasks/$taskid";
	if (!file_exists($taskpath)) return error('ERR002', 'File not found');
	$msg = ok('');
	$msg['data'] = json_decode( file_get_contents($taskpath), true );
	return $msg;
}


// Get description for task TASKID
function setTask($task) {
	global $conf_basepath;
	
	$taskpath = $conf_basepath . "/tasks";
	// Assign first available task number
	if (!array_key_exists('id', $task) || intval($task['id']) == 0) {
		$maxtask = 0;
		foreach(scandir($taskpath) as $entry) {
			if ($entry == "." || $entry == "..") continue;
			if ($entry > $maxtask) $maxtask = $entry;
		}
		$maxtask++;
		$task['id'] = $maxtask;
	}
	
	if (!is_dir($conf_basepath))
		mkdir ($conf_basepath);
	if (!is_dir($taskpath))
		mkdir ($taskpath);
	$taskpath .= "/" . $task['id'];
	
	if (defined("JSON_PRETTY_PRINT"))
		$output = json_encode($task, JSON_PRETTY_PRINT);
	else
		$output = json_encode($task);
	file_put_contents( $taskpath, $output );
	
	$msg = ok('');
	$msg['data']['id'] = $task['id'];
	return $msg;
}


// Get data for program PROGID (just name, not $msg)
function getProgramData($progid) {
	global $conf_basepath;
	
	$progpath = $conf_basepath . "/queue/$progid/name";
	if (!file_exists($progpath))
		return error('ERR002', 'File not found');
	$msg = ok('');
	$msg['data']['id'] = $progid;
	$msg['data']['name'] = getProgramName($progid);
	return $msg;
}

// Get descriptive name for program PROGID (just name, not $msg)
function getProgramName($progid) {
	global $conf_basepath;
	$namepath = $conf_basepath . "/queue/$progid/name";
	return file_get_contents($namepath);
}


// Dump program file to stdout for download
function getProgramFile($progid) {
	global $conf_basepath;
	
	$filename = "$progid.zip";
	$filepath = $conf_basepath . "/queue/$progid/$filename";
	
	if (!file_exists($filepath)) return error('ERR002', 'File not found');

	$type = `file -bi '$filepath'`;
	header("Content-Type: $type");
	header('Content-Disposition: attachment; filename="' . $filename.'"', false);
	header("Content-Length: ".(string)(filesize($filepath)));

	// workaround for http://support.microsoft.com/kb/316431
	header("Pragma: dummy=bogus"); 
	header("Cache-Control: private");

	$k = readfile($filepath,false);
	exit(0);
}


// Get status of project
function getProgramStatus($progid) {
	global $conf_basepath;
	
	if (!is_dir($conf_basepath . "/queue/$progid"))
		return error('ERR002', 'File not found');
	
	$statusfile = $conf_basepath . "/queue/$progid/status";
	if (!file_exists($statusfile))
		$status = array(
			"buildhost_description" => array(), 
			"status" => PROGRAM_AWAITING_TESTS,
			"compile_result" => array(),
			"run_result" => array(),
			"debug_result" => array(),
			"profile_result" => array(),
			"test_results" => array()
		);
	else
		$status = json_decode( file_get_contents($statusfile), true );
		
	// Find number of items in queue
	$qlock = "$conf_basepath/queue.lock";
	// Avoid unneccessary waiting for locks
	if ($status['status'] == PROGRAM_AWAITING_TESTS && !file_exists($qlock)) {
		$queue = readQueue();
		$count = 0;
		foreach ($queue as $qitem) {
			if ($qitem['prog'] == $progid) break;
			$count++;
		}
		$status['queue_items'] = $count;
	}
	return $status;
}

// Update JSON file that contains current status of a build project
function updateProgramStatus($progid, $key, $value) {
	global $conf_basepath;
	
	$status = getProgramStatus($progid);
	
	$statusfile = $conf_basepath . "/queue/$progid/status";
	$status[$key] = $value;
	$status['time'] = time();
	if (defined("JSON_PRETTY_PRINT"))
		file_put_contents($statusfile, json_encode($status, JSON_PRETTY_PRINT) );
	else
		file_put_contents($statusfile, json_encode($status) );
}


// Add program in given task to queue
function addProgram($taskid, $progname, $progfile) {
	global $conf_basepath;
	$taskpath = $conf_basepath . "/tasks/$taskid";
	if (!file_exists($taskpath)) return error('ERR002', 'File not found'); // Unknown task
	if (!file_exists($progfile)) return error('ERR002', 'File not found');

	$msg = ok('');
	
	// Use MD5 hash do detect if this is a readding
	$md5 = md5_file($progfile);
	$taskmd5 = md5_file($taskpath);
	$md5file = $conf_basepath . "/md5sums";
	if (file_exists($md5file)) foreach(file($md5file) as $line) {
		list($progid, $some_md5, $some_taskmd5) = explode(" ", trim($line));
		if ($md5 == $some_md5 && $taskmd5 == $some_taskmd5) {
			$msg['data']['id'] = $progid;
			return $msg;
		}
	}
	
	// Find latest ID
	$progspath = $conf_basepath . "/queue";
	if (!is_dir($progspath)) mkdir($progspath);
	
	$progid = 0;
	foreach(scandir($progspath) as $usedid) {
		if ($usedid == "." || $usedid == "..") continue;
		if ($usedid > $progid) $progid=$usedid;
	}
	$progid++;
	
	mkdir("$progspath/$progid");
	file_put_contents("$progspath/$progid/name", $progname);
	copy($progfile, "$progspath/$progid/$progid.zip");
	
	$qitem = array();
	$qitem['task'] = $taskid;
	$qitem['prog'] = $progid;
	
	$queue = readQueue();
	array_push($queue, $qitem);
	lock();
	writeQueue($queue);
	file_put_contents($md5file, "$progid $md5 $taskmd5\n", FILE_APPEND | LOCK_EX);
	unlock();
	
	$msg['data']['id'] = $progid;
	return $msg;
}


// Add program in given task to queue
function retryProgram($taskid, $progid) {
	clearCurrent($progid);

	$queue = readQueue();
	foreach ($queue as $qitem)
		if ($qitem['task'] == $taskid && $qitem['prog'] == $progid)
			return error('ERR003', 'Program already in queue');
	
	$qitem = array();
	$qitem['task'] = $taskid;
	$qitem['prog'] = $progid;
	array_push($queue, $qitem);
	lock();
	writeQueue($queue);
	unlock();

	$msg = ok('');
	$msg['data']['id'] = $progid;
	return $msg;
}


// List programs for task TASKID in queue
function deleteProgram($progid) {
	global $conf_basepath;
	
	$queue = readQueue();
	$removed = false;
	for($i=0; $i<count($queue); $i++) {
		if ($queue[$i]['prog'] == $progid) {
			unset($queue[$i]);
			$removed = true;
			break;
		}
	}
	if ($removed) {
		lock();
		writeQueue($queue);
		unlock();
	}
	
	$filename = "$progid.zip";
	$filepath = $conf_basepath . "/queue/$progid";
	unlink("$filepath/$filename");
	unlink("$filepath/name");
	unlink("$filepath/status");
	rmdir($filepath);
}


// List programs for task TASKID in queue
function listPrograms($taskid) {
	global $conf_basepath;

	$msg = ok('');
	$queue = readQueue();
	foreach($queue as $qitem) {
		if ($taskid == 0 || $qitem['task'] == $taskid) {
			$item = array();
			$item['id'] = $qitem['prog'];
			$item['name'] = getProgramName($qitem['prog']);
			array_push($msg['data'], $item);
		}
	}
	return $msg;
}


// List programs that are currently being built
function getCurrent() {
	global $conf_basepath;
	
	$curfile = "$conf_basepath/current";
	$msg = ok('');
	if (!file_exists($curfile)) return $msg;
	foreach(file($curfile) as $line) {
		$item = array();
		$item['id'] = intval($line);
		$item['name'] = getProgramName($item['id']);
		$item['host'] = strstr(trim($line), " ");
		array_push($msg['data'], $item);
	}
	return $msg;
}


// Get task ID for next program in queue
function nextTask($previousTask) {
	$msg = ok('');
	$queue = readQueue();
	if (empty($queue))
		$msg['data']['id'] = "false";
	else if (intval($previousTask) == 0) {
		$qitem = array_shift($queue);
		$msg['data']['id'] = $qitem['task'];
	} else {
		do {
			$qitem = array_shift($queue);
		} while ($qitem['task'] != $previousTask && !empty($queue));
		if (empty($queue)) 
			$queue = readQueue();
		$qitem = array_shift($queue);
		$msg['data']['id'] = $qitem['task'];
	}
	return $msg;
}	


// Assign next program in given TASKID to buildhost
function assignProgram($taskid, $buildhost_description) {
	$msg = ok('');
	$queue = readQueue();
	$found = false;
	$nq = array();
	foreach($queue as $qitem) {
		if ($qitem['task'] == $taskid && $found === false)
			$found = $qitem;
		else
			array_push($nq, $qitem);
	}
	if ($found === false) {
		$msg['data']['id'] = "false";
		return $msg;
	}
	
	lock();
	writeQueue($nq);
	appendCurrent($found['prog'], $buildhost_description);
	unlock();
	
	updateProgramStatus($found['prog'], "buildhost_description", $buildhost_description);
	updateProgramStatus($found['prog'], "status", PROGRAM_CURRENTLY_TESTING);
	
	$msg['data']['id'] = $found['prog'];
	return $msg;
}


// Universal function for authenticating user to web service
function authenticate($conf) {
	if (array_key_exists("disabled", $conf) && $conf["disabled"] === true)
		return false;
		
	if (array_key_exists("http_auth", $conf) && $conf["http_auth"] !== false) {
		if (!isset($_SERVER['PHP_AUTH_USER']))
			return false;
		if ($conf["http_auth"] !== true && !in_array($_SERVER['PHP_AUTH_USER'], $conf["http_auth"]))
			return false;
	}
	
	if (array_key_exists("php_session", $conf) && $conf["php_session"] !== false) {
		session_start();
		if (is_array($conf["php_session"])) foreach ($conf["php_session"] as $key => $value) {
			if (strlen($key)>7 && substr($key,0,7) == "exists_") {
				$key = substr($key,7);
				if (!isset($_SESSION[$key]))
					return false;
				
			} else {
				if (!isset($_SESSION[$key]))
					return false;
				
				if (is_array($value))
					if (!in_array($_SESSION[$key], $value))
						return false;					
				else
					if ($_SESSION[$key] !== $value)
						return false;
			}
		}
	}
	
	if (array_key_exists("hosts", $conf) && is_array($conf["hosts"])) {
		if (!in_array($_SERVER['REMOTE_ADDR'], $conf['hosts']) && !in_array($_SERVER['REMOTE_HOST'], $conf['hosts']))
			return false;
	}
	
	return true;
}


// Read in the queue
function readQueue() {
	global $conf_basepath;
	
	$qpath = "$conf_basepath/queuefile";
	
	lockWait();
	$queue = array();
	if (!file_exists($qpath)) return $queue;
	foreach(file($qpath) as $line) {
		list($dir,$name) = explode("/", trim($line));
		$qitem = array();
		$qitem['task'] = $dir;
		$qitem['prog'] = $name;
		array_push($queue, $qitem);
	}
	return $queue;
}


// Read in the queue -- it's assumed that file is locked!
function writeQueue($queue) {
	global $conf_basepath;
	
	$qpath = "$conf_basepath/queuefile";
	$output = "";
	foreach($queue as $qitem)
		$output .= $qitem['task'] . "/" . $qitem['prog'] . "\n";
	file_put_contents($qpath, $output, LOCK_EX);
}


// Append data about currently processed task -- it's assumed that file is locked!
function appendCurrent($progid, $buildhost_description) {
	global $conf_basepath;
	
	$curfile = "$conf_basepath/current";
	// Buildhost can't contain newlines
	$buildhost = str_replace("\n", " ", $buildhost_description['id']);
	file_put_contents($curfile, "$progid $buildhost\n", FILE_APPEND | LOCK_EX);
}


// Remove data about currently processed task
function clearCurrent($progid) {
	global $conf_basepath;
	
	$curfile = "$conf_basepath/current";
	$output = "";
	foreach(file($curfile) as $line) {
		$pgd = substr($line, 0, strlen($progid)+1);
		if ($pgd !== "$progid ")
			$output .= $line;
	}
	file_put_contents($curfile, $output, LOCK_EX);
}


// Wait for queue lock to be freed
function lockWait() {
	global $conf_basepath, $conf_qlock_time_limit, $conf_qlock_what;
	
	$qlock = "$conf_basepath/queue.lock";
	$errfile = "$conf_basepath/lock_errors";
	
	$wait = 0;
	while (file_exists($qlock)) {
		$wait++;
		sleep(1);
		if ($wait <= $conf_qlock_time_limit) continue;
		
		file_put_contents($errfile, time(), FILE_APPEND);
		if ($conf_qlock_what == "quit") {
			print "ERROR: Waiting for queue lock for $wait seconds...\n";
			quit(1);
		}
		if ($conf_qlock_what == "delete")
			unlink ($qlock);
		break;
	}
}

// Set lock
function lock() {
	global $conf_basepath;
	$qlock = "$conf_basepath/queue.lock";
	lockWait();
	touch($qlock);
}

// Clear lock
function unlock() {
	global $conf_basepath;
	$qlock = "$conf_basepath/queue.lock";
	unlink($qlock);
}


// HELPER functions

// Construct ok/error messages
function error($code, $msg) {
	$result = array();
	$result['success'] = "false";
	$result['code'] = $code;
	$result['message'] = $msg;
	return $result;
}

function ok($msg) {
	$result = array();
	$result['success'] = "true";
	$result['message'] = $msg;
	$result['data'] = array();
	return $result;
}


?>