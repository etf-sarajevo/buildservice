<?php


// BUILDSERVICE - automated compiling, execution, debugging, testing and profiling
// (c) Vedran Ljubovic and others 2014.
//
//     This program is free software: you can redistribute it and/or modify
//     it under the terms of the GNU General Public License as published by
//     the Free Software Foundation, either version 3 of the License, or
//     (at your option) any later version.
// 
//     This program is distributed in the hope that it will be useful,
//     but WITHOUT ANY WARRANTY; without even the implied warranty of
//     MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//     GNU General Public License for more details.
// 
//     You should have received a copy of the GNU General Public License
//     along with this program.  If not, see <http://www.gnu.org/licenses/>.

// PULL.PHP - evaluate programs returned by external webservice


require_once("config.php");
require_once("lib.php");
require_once("status_codes.php");
require_once("buildservice.php");


// Command line params 
echo "pull.php\nCopyright (c) 2014 Vedran Ljubović\nElektrotehnički fakultet Sarajevo\nLicensed under GNU GPL v3\n\n";

$taskid = $progid = 0;

if ($argc > 1) 
	parse_arguments($argc, $argv);

authenticate();

if ($taskid != 0) 
	process_task($taskid, $progid);

else {
	// Process tasks with pending programs until none are left
	do {
		// Next task
		$result = json_query("nextTask");
		if (is_array($result) && $result['id'] !== "false")
			process_task($result['id']);
	} while (is_array($result) && $result['id'] !== "false");
}

if ($conf_verbosity>0) print "Finished.\n";
exit(0);



// Process all pending programs in given task
// If $progid isn't zero, process just that program
function process_task($taskid, $progid = 0) {
	global $conf_verbosity, $buildhost_id;
	// Get task data
	$task = json_query("getTaskData", array("task" => $taskid));
	if ($conf_verbosity>0) print "Task ($taskid): ".$task['name']."\n";

	$compiler = find_best_compiler($task['language'], $task['required_compiler'], $task['preferred_compiler'], $task['compiler_features']);
	if ($compiler === false) {
		if ($conf_verbosity>0) print "No suitable compiler found for task ".$task['name'].".\n";
		if ($conf_verbosity>1) {
			print "Language: ".$task['language']." Compiler: ".$task['required_compiler']. "/". $task['preferred_compiler'];
			if (!empty($task['compiler_features'])) print " Features: ".join(", ",$task['compiler_features']);
			print "\n";
		}
		return;
	}

	// Find debugger & profiler
	$debugger = find_best_debugger($task['language']);
	$profiler = find_best_profiler($task['language']);

	if ($conf_verbosity>0) {
		print "Found compiler: ".$compiler['name']."\n";
		if ($debugger) print "Found debugger: ".$debugger['name']."\n";
		if ($profiler) print "Found profiler: ".$profiler['name']."\n";
		print "\n";
	}

	if ($progid != 0)
		process_program($task, $compiler, $debugger, $profiler, $progid);

	else while(true) {
		// Loop through available programs for this task
		$result = json_query("assignProgram", array("task" => $taskid, "buildhost" => $buildhost_id));

		// Upon calling assignProgram server will assign program to this buildhost
		// If buildhost doesn't set a status after certain time, it will be released for other hosts to build

		if (!is_array($result) || $result['id'] === "false") {
			if ($conf_verbosity>0) print "\nNo more programs for task ".$task['name'].".\n\n"; 
			break; // Exit programs loop
		}
		$progid = $result['id']; // some integer unique among all programs for all tasks
		
		process_program($task, $compiler, $debugger, $profiler, $progid);
	}

}


function process_program($task, $compiler, $debugger, $profiler, $program_id) {
	global $conf_tmp_path, $conf_verbosity;

	if ($conf_verbosity>0) print "Program id: ".$program_id;
	
	// Display program data
	$result = json_query("getProgramData", array("program" => $program_id) );
	if ($conf_verbosity>0) print " - ".$result['name']."\n";

	// Get files (format is ZIP)
	$zip_file = $conf_tmp_path."/bs_download_$program_id.zip";
	if (!json_get_binary_file($zip_file, "getFile", array("program" => $program_id))) {
		if ($conf_verbosity>0) print "Downloading file failed.\n";
		// We want program to remain assigned because this is a server error
		return;
	}

	// Create directory structure that will be used for everything related to this program
	$instance = create_instance($zip_file);
	if ($conf_verbosity>0) print "Instance $instance\n";

	// Find source files (in case they are inside subdir)...
	$filelist = find_sources($task, $instance);
	if ($filelist == array()) {
		// Skip to next program, nothing to do
		json_query("setProgramStatus", array("program" => $program_id, "status" => PROGRAM_NO_SOURCES_FOUND), "POST" );
		if ($conf_verbosity>0) print "No sources found.\n";
		purge_instance($instance);
		return; 
	}

	// Executable path
	$exe_file = instance_path($instance) . "/bs_exec_$program_id";
	$debug_exe_file = $exe_file . "_debug";

	// Compile
	if ($task['compile'] === "true") {
		$compile_result = do_compile($filelist, $exe_file, $compiler, $task['compiler_options'], $instance);
		json_query( "setCompileResult", array("program" => $program_id, "result" => json_encode($compile_result)), "POST" );

		if ($compile_result['status'] !== COMPILE_SUCCESS) {
			json_query( "setProgramStatus", array("program" => $program_id, "status" => PROGRAM_COMPILE_ERROR ), "POST" );
			purge_instance($instance);
			return; // skip run, test etc. if program can't be compiled
		}
	} else {
		$exe_file = $task['exe_file'];
		$debug_exe_file = $task['debug_exe_file'];
	}

	// Run
	if ($task['run'] === "true") {
		$run_result = do_run($filelist, $exe_file, $task['running_params'], $compiler, $task['compiler_options'], $instance);

		json_query( "setExecuteResult", array("program" => $program_id, "result" => json_encode($run_result)), "POST" );

		// Debug
		if ($run_result['status'] == EXECUTION_CRASH && $task['debug'] === "true" && $debugger) {
			// Recompile with debug compiler_options
			$compile_result = do_compile($filelist, $debug_exe_file, $compiler, $task['compiler_options_debug'], $instance);
			
			// If compiler failed with compiler_options_debug but succeeded with compiler_options, 
			// most likely options are bad... so we'll skip debugging
			if ($compile_result['status'] === COMPILE_SUCCESS) {
				$debug_result = do_debug($debug_exe_file, $debugger, $run_result['core'], $filelist, $instance);
				json_query( "setDebugResult", array("program" => $program_id, "result" => json_encode($debug_result)), "POST" );
				unlink($run_result['core']);
			}
		}
		
		// Profile
		if ($run_result['status'] != EXECUTION_CRASH && $task['profile'] === "true" && $profiler) {
			// Recompile with debug compiler_options
			$compile_result = do_compile($filelist, $debug_exe_file, $compiler, $task['compiler_options_debug'], $instance);

			if ($compile_result['status'] === COMPILE_SUCCESS) {
				$profile_result = do_profile($debug_exe_file, $profiler, $filelist, $task['running_params'], $instance);
				json_query( "setProfileResult", array("program" => $program_id, "result" => json_encode($profile_result)), "POST" );
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
			if ($conf_verbosity>0) print "Test ".($count++)."\n";
			$test_result = do_test($filelist, $global_symbols, $test, $compiler, $debugger, $profiler, $task, $instance);
			json_query("setTestResult", array( "program" => $program_id, "test" => $test['id'], "result" => json_encode($test_result)), "POST" );
		}
	}

	json_query( "setProgramStatus", array("program" => $program_id, "status" => PROGRAM_FINISHED_TESTING ), "POST" );


	purge_instance($instance);
	unlink($zip_file);
	if ($conf_verbosity>0) print "Program $program_id (instance $instance) finished.\n\n";

} // End process_program


function parse_arguments($argc, $argv) {
	global $taskid, $progid;

	if ($argc>3)
		print "Error: too many parameters.\n\n";

	else if ($argv[1] == "list-tasks") {
		authenticate();
		list_tasks();
		exit (0);
	}

	else if ($argv[1] == "list-progs" || $argv[1] == "prog-info" || $argv[1] == "task-info") {
		if ($argc == 2)
			print "Error: ".$argv[1]." takes exactly one parameter.\n\n";
		else if (!is_numeric($argv[2]))
			print "Error: ID is an integer.\n\n";
		else {
			authenticate();
			if ($argv[1] == "list-progs") list_progs($argv[2]);
			if ($argv[1] == "prog-info") prog_info($argv[2]);
			if ($argv[1] == "task-info") task_info($argv[2]);
			exit (0);
		}
	}

	else if ($argv[1] != "help" && $argv[1] != "--help" && $argv[1] != "-h") {
		if (!is_numeric($argv[1]) || ($argc==3 && !is_numeric($argv[2])))
			print "Error: TASKID is an integer.\n\n";
		else {
			$taskid = $argv[1];
			if ($argc == 3) $progid = $argv[2];
			return;
		}
	}

	echo "Usage:\n\tphp pull.php PARAMS\n\n";
	echo "Available PARAMS are:\n (none)\t\t\tProcess all unfinished programs in all available tasks\n TASKID\t\t\tProcess all unfinished programs in task TASKID\n TASKID PROGID\t\tProcess program PROGID in task TASKID\n list-tasks\t\tList all tasks available to current user\n list-progs TASKID\tList all programs in task TASKID available to current user\n task-info TASKID\tSome information about task TASKID\n prog-info PROGID\tSome information about program PROGID\n help\t\t\tThis page\n\n";
	exit (1);
}



function authenticate()
{
	global $session_id, $conf_json_login_required, $conf_verbosity;
	$session_id = "";
	if ($conf_json_login_required) {
		if ($conf_verbosity>0) print "Authenticating...\n";
		$session_id = json_login();
		if ($conf_verbosity>0) print "Login successful!\n\n";
	}
}

function list_tasks() {
	$tasks = json_query("getTaskList");
	print "\nAvailable tasks:\n";
	foreach ($tasks as $task)
		print "  ".$task['id']."\t".$task['name']."\n";
}

function progs_sort_by_name($p1, $p2) { return strcmp($p1['name'], $p2['name']); }
function list_progs($taskid) {
	$progs = json_query("getProgList", array("task" => $taskid));
	print "\nAvailable programs in task:\n";
	usort($progs, "progs_sort_by_name");
	foreach ($progs as $prog)
		print "  ".$prog['id']."\t".$prog['name']."\n";
}
function prog_info($progid) {
	$status_codes = array("", "Awaiting tests", "Plagiarized", "Compile error", "Finished testing", "Graded", "No sources found");

	$proginfo = json_query("getProgramData", array("program" => $progid));
	print "\nProgram ID: $progid\nName: ".$proginfo['name']."\nStatus: ".$status_codes[$proginfo['status']]." (".$proginfo['status'].")\n\nTask info:";
	task_info($proginfo['task']);
}
function task_info($taskid) {
	$task = json_query("getTaskData", array("task" => $taskid));
	print "\nTask ID: $taskid\nName: ".$task['name']."\nLanguage: ".$task['language']."\n";
}

?>

