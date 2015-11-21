<?php

// BACKGROUND.PHP - web service alpha
// This script will always be run by web service so no need to check parameters

require_once("config.php");
require_once("lib.php");
require_once("buildservice.php");


function error($code, $msg) {
	global $background_log, $task_id, $instance_id, $status_path;
	$log_line = date("Y-m-d h:i:s") . " - $task_id - $instance_id - $code - $msg\n";
	file_put_contents($background_log, $log_line, FILE_APPEND);
	if ($status_path !== "") update_status($code, $msg);
	exit(0);
}

function update_status($code, $msg) {
	global $buildhost_description, $status_path, $compile_result, $run_result, $debug_result, $profile_result, $test_results;
	$status_object = array(
		"buildhost_description" => $buildhost_description, 
		"code" => $code, 
		"message" => $msg,
		"compile_result" => $compile_result,
		"run_result" => $run_result,
		"debug_result" => $debug_result,
		"profile_result" => $profile_result,
		"test_results" => $test_results
	);
	file_put_contents($status_path, json_encode($status_object));
}


$task_id = $argv[1];
$instance = $argv[2];
$program_id = $instance; // No better value to give here...
$status_path = "";

$background_log = $conf_basepath . "/background.log";
$task_path = $conf_basepath . "/task_$task_id.js";

$task = array();
if (file_exists($task_path))
	$task = json_decode(file_get_contents($task_path), true);

if (!$task || empty($task))
	error("ERR001", "Task spec file not found.");

$status_path = instance_path($instance) . "/buildservice_status.json"; // We use .json extension so it wouldn't be confused with JS sources
if (!file_exists($status_path))
	error("ERR002", "Instance status not found");



// Starting up... let's detect environment

$buildhost_description = array(
	"id" => $buildhost_id, 
	"os" => get_os_version()
);

$compile_result = $run_result = $debug_result = $profile_result = $test_results = array();


$compiler = find_best_compiler($task['language'], $task['required_compiler'], $task['preferred_compiler'], $task['compiler_features']);
if ($compiler === false)
	error("ERR003", "No suitable compiler found for language ".$task['language']);

$debugger = find_best_debugger($task['language']);
$profiler = find_best_profiler($task['language']);

// Add tool versions to buildhost description
$buildhost_description['compiler_version'] = $compiler['version'];
if ($debugger) $buildhost_description['debugger_version'] = $debugger['version'];
if ($profiler) $buildhost_description['profiler_version'] = $profiler['version'];


update_status("STA001", "Starting up...");


// Begin process

$filelist = find_sources($task, $instance);
if ($filelist == array())
	error("ERR004", "No sources found");

$exe_file = instance_path($instance) . "/bs_exec_$program_id";
$debug_exe_file = $exe_file . "_debug";

// Compile
if ($task['compile'] === "true") {
	$compile_result = do_compile($filelist, $exe_file, $compiler, $task['compiler_options'], $instance);

	if ($compile_result['status'] == COMPILE_SUCCESS)
		update_status("STA002", "Compile succeeded");
	else {
		update_status("STA003", "Compile failed");
		exit(0);
	}
} else {
	$exe_file = $task['exe_file'];
	$debug_exe_file = $task['debug_exe_file'];
}

// Run
if ($task['run'] === "true") {
	$run_result = do_run($filelist, $exe_file, $task['running_params'], $compiler, $task['compiler_options'], $instance);
	
	
	// Debug
	if ($run_result['status'] == EXECUTION_CRASH && $task['debug'] === "true" && $debugger) {
		update_status("STA004", "Program crashed");

		// Recompile with debug compiler_options
		$compile_result_debug = do_compile($filelist, $debug_exe_file, $compiler, $task['compiler_options_debug'], $instance);
		
		// If compiler failed with compiler_options_debug but succeeded with compiler_options, 
		// most likely options are bad... so we'll skip debugging
		if ($compile_result_debug['status'] === COMPILE_SUCCESS) {
			$debug_result = do_debug($debug_exe_file, $debugger, $run_result['core'], $filelist, $instance);
			update_status("STA006", "Program crashed - debugging finished");
			unlink($run_result['core']);
		}
	} else
		update_status("STA005", "Program executed successfully");	
	
	// Profile
	if ($run_result['status'] != EXECUTION_CRASH && $task['profile'] === "true" && $profiler) {
		// Recompile with debug compiler_options
		$compile_result_debug = do_compile($filelist, $debug_exe_file, $compiler, $task['compiler_options_debug'], $instance);

		if ($compile_result_debug['status'] === COMPILE_SUCCESS) {
			$profile_result = do_profile($debug_exe_file, $profiler, $filelist, $task['running_params'], $instance);
			update_status("STA007", "Program profiled");	
		}
	}
}

// Don't interfere with testing
unlink($exe_file);
if (file_exists($debug_exe_file)) unlink($debug_exe_file);

// Unit test
if ($task['test'] === "true") {
	$global_symbols = extract_global_symbols($filelist, $task['language']);
	$count = 1;
	foreach ($task['test_specifications'] as $test) {
		$test_result = do_test($filelist, $global_symbols, $test, $compiler, $debugger, $profiler, $task, $instance);
		$test_results[$test['id']] = $test_result;
		update_status("STA008", "Test ". ($count++) . " finished");	
	}
}

update_status("STA009", "Task completed");	

?>