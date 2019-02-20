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

// BUILDSERVICE.PHP - main library of functions
// to run buildservice, use buildservice_pull.php or buildservice_push.php


if (!file_exists("config.php")) {
	echo "First you need to copy config.php.default to config.php and edit it.\n";
	exit(1);
}
require_once("config.php");
require_once("lib.php");
require_once("status_codes.php");

require_once("plugins.php"); // Register all plugins


// Create directory structure that will be used for everything related to this program
function create_instance($zip_file)
{
	global $conf_max_instances, $conf_unzip_command, $conf_verbosity;

	// Find unused instance ID
	do {
		$instance = rand(0, $conf_max_instances);
		$path = instance_path($instance);
	} while (file_exists($path));

	directoryCleanup($path);

	if ($conf_verbosity>0) print "Unzipping file...\n";
	$output = `$conf_unzip_command "$zip_file" -d $path`;
	if ($conf_verbosity>2) print $output;
	return $instance;
}

function purge_instance($instance)
{
	rmMinusR(instance_path($instance));
	rmdir(instance_path($instance));
}

function instance_path($instance) 
{ 
	global $conf_basepath; 
	return $conf_basepath ."/bs_$instance"; 
}


// Returns a list of source files given language
function find_sources_recursive(&$filelist, $extensions, $path) {
	$files = glob($path."/*"); // There should be no hidden files...
	foreach ($files as $file) {
		if (is_dir($file)) {
			find_sources_recursive($filelist, $extensions, $file);
		} else {
			foreach($extensions as $ext) {
				if ( ends_with($file, $ext) )
					array_push($filelist, $file);
			}
		}
	}
}
function find_sources($task, $instance)
{
	global $conf_extensions;

	$path = instance_path($instance);
	$ext = $conf_extensions[$task['language']];
	$filelist = array();
	find_sources_recursive($filelist, $ext, $path);
	
	return $filelist;
}


// Replace various placeholders in compiler command line with their correct values
function parse_compiler_line($cmd, $compiler, $exe_file, $options, $filelist)
{
	// Create escaped path to source file list
	$escpath = "";
	$escape_pairs = array(" " => "\ ", "(" => "\(", ")" => "\)");
	foreach ($filelist as $file)
		$escpath .= strtr($file, $escape_pairs) . " ";

	$cmd = str_replace("COMPILER_PATH", $compiler['compiler_path'], $cmd);
	$cmd = str_replace("EXECUTOR_PATH", $compiler['executor_path'], $cmd);
	$cmd = str_replace("OPTIONS", $options, $cmd);
	$cmd = str_replace("SOURCE_FILES", $escpath, $cmd);
	$cmd = str_replace("OUTPUT_FILE", $exe_file, $cmd);
	return $cmd;
}

// Perform compilation
function do_compile($filelist, $exe_file, $compiler, $options, $instance)
{
	global $compiler_plugin, $conf_verbosity, $conf_nice;

	if ($conf_verbosity>0) print "Compiling ".basename($exe_file)."...\n";
	
	$compile_result = array();
	$compile_result['status'] = COMPILE_SUCCESS;
	$output = array();
	
	// Do it!
	$cmd = parse_compiler_line($compiler['cmd_line'], $compiler, $exe_file, $options, $filelist);
	$cmd .= " 2>&1";
	if (isset($conf_nice))
		$cmd = "nice -n $conf_nice $cmd";

	$k = exec($cmd, $output, $return);

	$compile_result['output'] = clear_unicode( join("\n", $output) );
	
	// Absurdly long compile output (can happen with e.g. infinitely recursive templates)
	if (strlen($compile_result['output']) > 100000)
		$compile_result['output'] = substr($compile_result['output'], 0, 100000);

	// Parse output, if plugin available
	if (array_key_exists($compiler['name'], $compiler_plugin))
		eval($compiler_plugin[$compiler['name']] . "(\$output, \$filelist, \$compile_result);");
	
	// Detect compiler failure by return value
	if ($return !== 0) {
		if ($conf_verbosity>0) print "Compiler failed.\n";
		$compile_result['status'] = COMPILE_FAIL;
	} else
		chmod($exe_file, 0755);

	// Remove references to local paths
	$compile_result['output'] = str_replace(instance_path($instance) . "/", "", $compile_result['output']);
	if (array_key_exists("parsed_output", $compile_result))
		foreach ($compile_result['parsed_output'] as &$msg)
			$msg['file'] = str_replace(instance_path($instance) . "/", "", $msg['file']);

	if ($conf_verbosity>2) print $compile_result['output'] . "\n";

	return $compile_result;
}



// Execute program with predefined input using profiler and debugger as neccessary
function do_run($filelist, $exe_file, $params, $compiler, $compiler_options, $instance)
{
	global $conf_max_program_output, $conf_verbosity, $conf_nice;

	$stdin_file    = instance_path($instance) . "/buildservice_stdin.txt";
	$stderr_file   = instance_path($instance) . "/buildservice_stderr.txt";
	$stdout_file   = instance_path($instance) . "/buildservice_stdout.txt";


	if ($conf_verbosity>0) print "Executing ".basename($exe_file)."...\n";

	$descriptorspec = array(
		0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
		1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
		2 => array("file", $stderr_file, "a") // stderr is a file to write to
	);
	
	// Do it!
	$cwd = instance_path($instance);
	$env = array();
	
	// Use compiler['exe_line'] as command line for execution
	$cmd = parse_compiler_line($compiler['exe_line'], $compiler, $exe_file, $compiler_options, $filelist);

	if (isset($conf_nice))
		$cmd = "nice -n $conf_nice $cmd";

	// Parse various execution params
	if (array_key_exists("timeout", $params) && $params['timeout'] > 0)
		$cmd = "ulimit -t ".$params['timeout']."; $cmd";
	if (array_key_exists("vmem", $params) && $params['vmem'] > 0)
		$cmd = "ulimit -v ".$params['vmem']."; $cmd";
	
	// Always enable coredumps
	$cmd = "ulimit -c 1000000; $cmd";
	$run_result = array();
	
	if ($params['use_pipes']) {
		file_put_contents($stdin_file, $params['stdin'] . "\n");
		$start_time = time();
		$run_result['output'] = `$cmd < $stdin_file`;
		if (strlen($run_result['output']) > $conf_max_program_output)
			$run_result['output'] = substr($run_result['output'], 0, $conf_max_program_output);
		$duration = time() - $start_time;
		
	} else {
		$process = proc_open($cmd, $descriptorspec, $pipes, $cwd, $env);
		
		if (is_resource($process)) {
			$statusar = proc_get_status($process);
			$pid = $statusar['pid']+1; // first one is ulimit...
			print "PID: $pid\n";
			
			fwrite($pipes[0], $params['stdin']);
			fclose($pipes[0]);
			
			// stream_get_contents will get stuck until program ends
			$start_time = time();
			$stdout = stream_get_contents($pipes[1], $conf_max_program_output+10);
			$duration = time() - $start_time;
			
			//file_put_contents($stdout_file, $stdout);
			$run_result['output'] = $stdout;
			fclose($pipes[1]);
		} else {
			if ($conf_verbosity>0) print "Not a resource\n";
			$run_result['status'] = EXECUTION_FAIL;
			return $run_result;
		}
	}

	$run_result['output'] = clear_unicode($run_result['output']);
	$run_result['duration'] = $duration;
	$run_result['status'] = EXECUTION_SUCCESS;

	if ($conf_verbosity>2) print $run_result['output'] . "\n";
	
	// Did it fail to finish before $timeout ?
	if ($duration >= $params['timeout']) { 
		if ($conf_verbosity>0) print "- Duration was $duration\n";
		$run_result['status'] = EXECUTION_TIMEOUT;
	}
	
	if ($filename = glob("$cwd/core*")) {
		if ($conf_verbosity>0) print "- Crashed (".$filename[0].")\n";
		$run_result['status'] = EXECUTION_CRASH;
		$run_result['core'] = $filename[0];
	}

	return $run_result;
}



// Execute debugger on given core dump file
function do_debug($exe_file, $debugger, $coredump, $filelist, $instance)
{
	global $debugger_plugin, $conf_verbosity, $conf_nice;

	if ($conf_verbosity>0) print "Debugging ".basename($exe_file)."...\n";
	
	// Do it!
	$cwd = instance_path($instance);
	$opts_core = str_replace( "COREFILE", $coredump, $debugger['opts_core'] );
	$cmd = $debugger['path']." ".$debugger['local_opts']." ".$opts_core." $exe_file";
	if (isset($conf_nice))
		$cmd = "nice -n $conf_nice $cmd";
	$cmd = "cd $cwd; $cmd";
	exec($cmd, $output);
	
	$debug_result = array();
	// Remove illegal and harmful unicode characters from output
	$debug_result['output'] = clear_unicode(join("\n", $output));
	
	if (array_key_exists($debugger['name'], $debugger_plugin))
		eval($debugger_plugin[$debugger['name']] . "(\$output, \$filelist, \$debug_result);");

	// Remove references to local paths
	$debug_result['output'] = str_replace( instance_path($instance) . "/", "", $debug_result['output'] );
	if (array_key_exists("parsed_output", $debug_result))
		foreach ($debug_result['parsed_output'] as &$msg)
			$msg['file'] = str_replace(instance_path($instance) . "/", "", $msg['file']);

	if ($conf_verbosity>2) print $debug_result['output'] . "\n";
		
	return $debug_result;
}


// Execute profiler on given executable
function do_profile($exe_file, $profiler, $filelist, $params, $instance)
{
	global $conf_max_program_output, $profiler_plugin, $conf_verbosity, $conf_nice;

	$profiler_log_file = instance_path($instance) . "/".basename($exe_file)."_profiler_log.txt";
	
	if ($conf_verbosity>0) print "Profiling ".basename($exe_file)."...\n";

	$stdin_file    = instance_path($instance) . "/buildservice_stdin.txt";
	$stderr_file   = instance_path($instance) . "/buildservice_stderr.txt";
	$stdout_file   = instance_path($instance) . "/buildservice_stdout.txt";
	
	// Do it!
	$descriptorspec = array(
		0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
		1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
		2 => array("file", $stderr_file, "a") // stderr is a file to write to
	);

	$cwd = instance_path($instance);
	$env = array();
	$optslog = str_replace("LOGFILE", $profiler_log_file, $profiler["opts_log"]);
	$cmd = $profiler["path"]." ".$profiler["local_opts"]." ".$optslog." $exe_file";
	
	if (isset($conf_nice))
		$cmd = "nice -n $conf_nice $cmd";
	
	// Redirect output cause it's combined program and profiler output
	// We will get just the profiler output from $profiler_log_file
	
	// Add execution params
	if (array_key_exists("timeout", $params) && $params['timeout'] > 0) {
		$timeout = $params['timeout'] * $profiler['timeout_ratio'];
		$cmd = "ulimit -t $timeout; $cmd";
	}
	if (array_key_exists("vmem_hard_limit", $profiler))
		$cmd = "ulimit -v ".$profiler['vmem_hard_limit']."; $cmd";
	else if (array_key_exists("vmem", $params) && $params['vmem'] > 0)
		$cmd = "ulimit -v ".$params['vmem']."; $cmd";
	
	if (array_key_exists("stdin", $params) && strlen($params['stdin']) > 0) {
		$stdin_name = instance_path($instance) . "/" . basename($exe_file) . "_stdin.txt";
		file_put_contents( $stdin_name, $params['stdin'] . "\n" );
		//$cmd .= "< $stdin_name";
	}
	
	// NOT TESTED
	$process = proc_open($cmd, $descriptorspec, $pipes, $cwd, $env);

	$profile_result = array();

	if (is_resource($process)) {
		$statusar = proc_get_status($process);
		$pid = $statusar['pid']+1; // first one is ulimit...
		print "PID: $pid\n";
		
		fwrite($pipes[0], $params['stdin']);
		fclose($pipes[0]);
		
		// stream_get_contents will get stuck until program ends
		$start_time = time();
		$stdout = stream_get_contents($pipes[1], $conf_max_program_output+10);
		$duration = time() - $start_time;
		
		file_put_contents($stdout_file, $stdout);
		fclose($pipes[1]);
	} else {
		if ($conf_verbosity>0) print "Not a resource\n";
		$profile_result['status'] = PROFILER_FAIL;
		return $profile_result;
	}

	$joutput = file_get_contents($profiler_log_file, false, NULL, -1, $conf_max_program_output);
	unlink ($profiler_log_file);
	$output = explode("\n", $joutput);

	$profile_result['output'] = clear_unicode($joutput);
	$profile_result['status'] = PROFILER_OK; // If we can't parse output, we should assume that profiling was ok
	
	if (array_key_exists($profiler['name'], $profiler_plugin))
		eval($profiler_plugin[$profiler['name']] . "(\$output, \$filelist, \$profile_result);");

	// Remove references to local paths

	$profile_result['output'] = str_replace(instance_path($instance) . "/", "", $profile_result['output']);
	if (array_key_exists("parsed_output", $profile_result))
		foreach ($profile_result['parsed_output'] as &$msg)
			$msg['file'] = str_replace(instance_path($instance) . "/", "", $msg['file']);

	if ($conf_verbosity>2) print $profile_result['output'];

	return $profile_result;
}



// Find symbols in global scope to know which files need to be included
function extract_global_symbols($filelist, $language) 
{
	global $source_parse_plugin, $conf_verbosity;

	$global_symbols = array();
	if (!array_key_exists($language, $source_parse_plugin)) {
		if ($conf_verbosity>0) print "No plugin for parsing '$language' found... can't extract global symbols.\n";
		return $global_symbols; // No plugin for this language, sorry :(
	}
	
	foreach ($filelist as $filename) {
		$contents = file_get_contents($filename);
		eval ("\$file_symbols = ".$source_parse_plugin[$language]." (\$contents, \$language, basename(\$filename));");
		foreach ($file_symbols as $symbol) {
			$f = "";
			if (array_key_exists($symbol, $global_symbols))
				$f = $global_symbols[$symbol];
			// If both header and implementation exist, use header
			if ( !ends_with($f, ".h") && !ends_with($f, ".hpp") )
				$global_symbols[$symbol] = $filename;
		}
	}
	return $global_symbols;
}

// Perform all tests on program given specification
function do_test($filelist, $global_symbols, $test, $compiler, $debugger, $profiler, $task, $instance)
{
	global $conf_max_program_output, $conf_verbosity;
	
	$test_result = array();
	$test_result['status'] = TEST_SUCCESS;
	$test_result['run_result'] = array();
	$test_result['debug_result'] = array();
	$test_result['profile_result'] = array();
	
	if ($conf_verbosity>0) print "Testing...\n";

	// Replacement strings
	$start_string  = "====START_TEST_".$test['id']."====";
	$end_string    = "====END_TEST_".$test['id']."====";
	$except_string = "====EXCEPTION_TEST_".$test['id']."====";
	
	// Find symbols that are required by this test
	$includes = array();
	if (!empty($global_symbols)) {
		foreach ($test['require_symbols'] as $symbol) {
			$found = false;
			foreach ($global_symbols as $global => $file) {
				if ($symbol === $global) {
					array_push($includes, $file);
					$found = true;
					break;
				}
			}
			if (!$found) {
				$test_result['status'] = TEST_SYMBOL_NOT_FOUND;
				$test_result['status_object'] = $symbol;
				if ($conf_verbosity>0) print "- test ".$test['id']." failed - symbol not found ($symbol)\n";
				return $test_result;
			}
		}
	}
	
	// Construct a new source file with embedded test
	$main_source_code = $main_filename = "";
	if ($task['language'] == "C" || $task['language'] == "C++") {
		if (!array_key_exists("main", $global_symbols)) {
			// This is a bug in get_global_symbols, as a program without main wouldn't compile!
			$test_result['status'] = TEST_SYMBOL_NOT_FOUND;
			$test_result['status_object'] = "main";
			if ($conf_verbosity>0) print "- test ".$test['id']." failed - main not found\n";
			return $test_result;
		}
		
		$main_filename = $global_symbols['main'];
		$main_source_code = file_get_contents($main_filename);

		// Rename main
		$newname = "_main";
		while (array_key_exists($newname, $global_symbols)) $newname = "_$newname";
		$newname = " $newname\${1}";
		$main_source_code = preg_replace("/\smain(\W)/", $newname, $main_source_code);
	}

	// Symbol renaming
	foreach ($test['replace_symbols'] as $sym) {
		foreach($sym as $symbol => $replacement) {
			$main_source_code = preg_replace("/(\W)$symbol(\W)/", "\${1}$replacement\${2}", $main_source_code);
		}
	}
	
	// Include files containing symbols we need
	$includes_code = "";
	foreach ($includes as $file) {
		if ($task['language'] == "C" || $task['language'] == "C++")
			$includes_code .= "#include \"$file\"\n";
		if ($task['language'] == "Python") {
			$import_file = basename(preg_replace("/\.py$/", "", $file));
			// Imported filenames must start with a letter in python
			if (!ctype_alpha($import_file[0])) {
				$import_file = "a".$import_file;
				$new_file = dirname($file) . "/a" . basename($file);
				rename ($file, $new_file);
			}
			$includes_code .= "from $import_file import *";
		}
	}
	
	// Also include stdin/stdout libraries cause they will surely be used in test
	if ($task['language'] == "C")
		$includes_code .= "#include <stdio.h>\n";
	if ($task['language'] == "C++")
		$includes_code .= "#include <iostream>\nusing std::cin;\nusing std::cout;\nusing std::cerr;\nusing std::endl;\n";

	// Construct test code
	$test_code = "";
	if ($task['language'] == "C")
		$test_code .= "int main() {\nprintf(\"$start_string\");\n ".$test['code']."\n printf(\"$end_string\");\nreturn 0;\n}\n";
	else if ($task['language'] == "C++")
		$test_code .= "int main() {\ntry {\n std::cout<<\"$start_string\";\n ".$test['code']."\n std::cout<<\"$end_string\";\n } catch (...) {\n std::cout<<\"$except_string\";\n }\nreturn 0;\n}\n";
	else if ($task['language'] == "Python")
		$test_code .= "print(\"$start_string\")\n".$test['code']."\nprint(\"$end_string\")\n";
	else
		$test_code = $test['code'];

	// Prevent cheating
	$main_source_code = str_replace($start_string,  "====cheat_protection====", $main_source_code);
	$main_source_code = str_replace($end_string,    "====cheat_protection====", $main_source_code);
	$main_source_code = str_replace($except_string, "====cheat_protection====", $main_source_code);
	
	// Construct whole file
	$main_length = substr_count($main_source_code, "\n");
	$main_source_code = $includes_code . $test['global_top'] . "\n" . $main_source_code . "\n" . $test['global_above_main'] . "\n" . $test_code . "\n";

	// Choose filename for test
	$test_filename = "bs_test_".$test['id'];
	if ($task['language'] == "C") $test_filename .= ".c";
	if ($task['language'] == "C++") $test_filename .= ".cpp";
	if ($task['language'] == "Python") $test_filename .= ".py";

	$test_path = instance_path($instance);
	if ($task['language'] == "C" || $task['language'] == "C++")
		// Locate test file in the same path that mainfile used to be
		$test_path = dirname($main_filename);

	while (in_array($test_path . "/" . $test_filename, $filelist)) $test_filename = "_".$test_filename;
	$test_filename = $test_path . "/" . $test_filename;
	
	file_put_contents($test_filename, $main_source_code);

	// Add test file to filelist
	if ($task['language'] == "C" || $task['language'] == "C++") {
		for ($i=0; $i<count($filelist); $i++)
			if ($filelist[$i] === $global_symbols['main'])
				$filelist[$i] = $test_filename;
	} else if ($task['language'] == "Python") {
		// In python we execute just the test file and it includes everything else
		$filelist = array($test_filename);
	} else {
		array_push($filelist, $test_filename);
	}

	// Calculate positions of original code and test code inside sources
	// that will be used to adjust output of compile/debug/profile to something user expects
	$adjustment_data = array();
	$adjustment_data['orig_filename']       = $main_filename;
	$adjustment_data['new_filename']        = $test_filename;
	$adjustment_data['global_top_pos']      = substr_count($includes_code, "\n");
	$adjustment_data['original_source_pos'] = $adjustment_data['global_top_pos'] + substr_count($test['global_top'], "\n") + 1 /* Added one \n */;
	$adjustment_data['global_above_pos']    = $adjustment_data['original_source_pos'] + $main_length + 1  /* Added one \n */;
	$adjustment_data['test_code_pos']       = $adjustment_data['global_above_pos'] + substr_count($test['global_above_main'], "\n") + 1  /* Added one \n */;
	// number of lines added to main per language
	if ($task['language'] == "C") $adjustment_data['test_code_pos'] += 2;
	if ($task['language'] == "C++") $adjustment_data['test_code_pos'] += 6;
	if ($task['language'] == "Python") $adjustment_data['test_code_pos'] += 1;



	// === TESTING COMMENCE ===

	// Compile test
	$test_exe_file = instance_path($instance) . "/bs_test_".$test['id'];
	$compile_result = do_compile($filelist, $test_exe_file, $compiler, $task['compiler_options_debug'], $instance);
	$test_result['compile_result'] = $compile_result;

	if ($compile_result['status'] !== COMPILE_SUCCESS) {
		$test_result['status'] = TEST_COMPILE_FAILED;
		if ($conf_verbosity>0) print "- test ".$test['id']." failed - compile error\n";
	
		// Adjust filenames and line numbers that were changed for the test
		if (array_key_exists('parsed_output', $test_result['compile_result']))
		foreach ($test_result['compile_result']['parsed_output'] as &$msg) {
			if (instance_path($instance) . "/" . $msg['file'] === $adjustment_data['new_filename'])
				test_adjust_lines($msg['file'], $msg['line'], $adjustment_data, $instance);
		}
		return $test_result;
	}

	// Execute test
	$run_result = do_run($filelist, $test_exe_file, $test['running_params'], $compiler, $task['compiler_options_debug'], $instance);
	$test_result['run_result'] = $run_result;
	$program_output = $run_result['output']; // Shortcut

	// Output was too long and it was cut off... let's pretend it finished ok
	if (strlen($program_output) >= $conf_max_program_output)
		$program_output .= "\n$end_string\n";

	// Find marker strings in program output
	$start_pos  = strpos($program_output, $start_string);
	if ($start_pos !== false) $start_pos += strlen($start_string);
	$end_pos    = strpos($program_output, $end_string);
	$except_pos = strpos($program_output, $except_string);

	// Remove marker strings from output
	if ($end_pos !== false)
		$program_output = substr( $program_output, $start_pos, $end_pos-$start_pos);
	else if ($except_pos !== false)
		$program_output = substr( $program_output, $start_pos, $except_pos-$start_pos);
	else if ($start_pos !== false)
		$program_output = substr( $program_output, $start_pos );

	$test_result['run_result']['output'] = $program_output;


	if ($run_result['status'] === EXECUTION_TIMEOUT) {
		$test_result['status'] = TEST_EXECUTION_TIMEOUT;
		if ($conf_verbosity>0) print "- test ".$test['id']." failed - execution timeout\n";
		// Profiler will simply execute even longer, so we don't go there
		return $test_result;
	}

	if ($run_result['status'] === EXECUTION_CRASH) {
		$test_result['status'] = TEST_EXECUTION_CRASH;

		// Use seldom: crashes are unreliable!
		if ($test['expected_crash'] === "true") {
			$test_result['status'] = TEST_SUCCESS;
			if ($conf_verbosity>0) print "- test ".$test['id']." ok (crash)\n";
		} else
			if ($conf_verbosity>0) print "- test ".$test['id']." failed - crash\n";

		// Debug in case of crash
		if ($debugger && $task['debug'] === "true") {
			$debug_result = do_debug($test_exe_file, $debugger, $run_result['core'], $filelist, $instance);
		
			// Adjust filenames and line numbers that were changed for the test
			foreach ($debug_result['parsed_output'] as &$msg) {
				if (instance_path($instance) . "/" . $msg['file'] === $adjustment_data['new_filename'])
					test_adjust_lines($msg['file'], $msg['line'], $adjustment_data, $instance);
			}
			$test_result['debug_result'] = $debug_result;
		}
		unlink ($run_result['core']);

		// If crash is unexpected, we will go on to profiler cause it can give some more information
		if ($test['expected_crash'] === "true") return $test_result;
	}

	else {


	// === FINDING EXPECTED OUTPUT IN PROGRAM OUTPUT ===


	// Remove invisible spaces in expected program output
	// Allow to specify newlines in expected output using \n
	foreach ($test['expected'] as &$ex) {
		$ex = str_replace("\r\n", "\n", $ex);
		$ex = str_replace("\\n", "\n", $ex);
		$ex = trim( preg_replace("/\s+\n/", "\n", $ex) );
	}

	// Program finished normally
	if ($start_pos !== false && $end_pos !== false) {
		if ($test['expected_exception'] === "true") {
			$test_result['status'] = TEST_WRONG_OUTPUT;
			if ($conf_verbosity>0) print "- test ".$test['id']." failed - expected exception\n";
			return $test_result;
		}

		$test_result['run_result']['output'] = $program_output;

		// Don't fail test because of invisible spaces and empty lines
		$program_output = preg_replace("/\s+\n/", "\n", $program_output);
		$program_output = trim( preg_replace("/\n+/", "\n", $program_output) );

		// Ignore whitespace
		if ($test['ignore_whitespace'] === "true") {
			$program_output = str_replace("\n", "", $program_output);
			$program_output = preg_replace("/\s+/", "", $program_output);
			foreach ($test['expected'] as &$ex)
				$ex = preg_replace("/\s+/", "", $ex);
		}

		// Look for expected outputs in program output
		$test_ok = false;
		$exnr = 1;
		foreach ($test['expected'] as &$ex) { // Why do we need a reference here???
			if ($program_output == $ex) {
				if ($conf_verbosity>0) print "- test ".$test['id']." ok (exact match $exnr)\n";
				$test_ok = true;
				break;
			}
			else if ($test['substring'] === "true" && strstr($program_output, $ex)) {
				if ($conf_verbosity>0) print "- test ".$test['id']." ok (substring $exnr)\n";
				$test_ok = true;
				break;
			}
			else if ($test['regex'] === "true" && preg_match("/$ex/", $program_output)) {
				if ($conf_verbosity>0) print "- test ".$test['id']." ok (regex $exnr)\n";
				$test_ok = true;
				break;
			}
			$exnr++;
		}
		
		if (!$test_ok) {
			$test_result['status'] = TEST_WRONG_OUTPUT;
			if ($conf_verbosity>0) print "- test ".$test['id']." failed\n";
			if ($conf_verbosity>2) print "Output:\n$program_output\nExpected:\n".$test['expected'][0];
			// We will continue to profiler as it may give us some explanation of result
		}
	}

	else if ($start_pos !== false && $except_pos !== false) {
		// TODO check type of exception
		if ($test['expected_exception'] === "false") {
			$test_result['status'] = TEST_UNEXPECTED_EXCEPTION;
			if ($conf_verbosity>0) print "- test ".$test['id']." failed - exception not expected\n";

			// We won't continue to profiler because unexpected exception usually causes memleaks
			// which won't exist after the reason for this exception is removed.
			// Otherwise programmer might be confused that memleak caused the exception
			return $test_result;
		}
		if ($conf_verbosity>0) print "- test ".$test['id']." ok (exception)\n";
	}
	
	else {
		$test_result['status'] = TEST_OUTPUT_NOT_FOUND;
		if ($conf_verbosity>0) print "- test ".$test['id']." failed - output not found ($start_pos, $end_pos, $except_pos)\n";
	}

	} // if ($run_result['status'] === EXECUTION_CRASH) { ... } else {

	// Profile
	if ($profiler && $task['profile'] === "true") {
		global $conf_profilers;
		$profile_result = do_profile($test_exe_file, $profiler, $filelist, $test['running_params'], $instance);
		
		// Adjust filenames and line numbers that were changed for the test
		foreach ($profile_result['parsed_output'] as &$msg) {
			// Valgrind always returns just the base file name
			if ( $msg['file'] === basename($adjustment_data['new_filename']) )
				test_adjust_lines($msg['file'], $msg['line'], $adjustment_data, $instance);
			if ( array_key_exists('file_alloced', $msg)
			     && instance_path($instance) . "/" . $msg['file_alloced'] === $adjustment_data['new_filename'] )
				test_adjust_lines($msg['file_alloced'], $msg['line_alloced'], $adjustment_data, $instance);
		}

		// If there are no errors, we will disregard profiler output
		if ($profile_result['status'] !== PROFILER_OK) {
			$test_result['profile_result'] = $profile_result;
			if ($test_result['status'] === TEST_SUCCESS) {
				$test_result['status'] = TEST_PROFILER_ERROR;
				if ($conf_verbosity>0) print "- test ".$test['id']." failed - profiler error\n";
			}
		} else if (count($conf_profilers) > 1) {
			$profile_result = do_profile($test_exe_file, $conf_profilers[1], $filelist, $test['running_params'], $instance);
			
			foreach ($profile_result['parsed_output'] as &$msg) {
				// Valgrind always returns just the base file name
				if ( $msg['file'] === basename($adjustment_data['new_filename']) )
					test_adjust_lines($msg['file'], $msg['line'], $adjustment_data, $instance);
				if ( array_key_exists('file_alloced', $msg)
				&& instance_path($instance) . "/" . $msg['file_alloced'] === $adjustment_data['new_filename'] )
					test_adjust_lines($msg['file_alloced'], $msg['line_alloced'], $adjustment_data, $instance);
			}

			// If there are no errors, we will disregard profiler output
			if ($profile_result['status'] !== PROFILER_OK) {
				$test_result['profile_result'] = $profile_result;
				if ($test_result['status'] === TEST_SUCCESS) {
					$test_result['status'] = TEST_PROFILER_ERROR;
					if ($conf_verbosity>0) print "- test ".$test['id']." failed - profiler 2 error\n";
				}
			}
		}
	}

	return $test_result;
}



// Helper function for adjusting filenames and linenumbers for code modified by test
function test_adjust_lines(&$file, &$line, $adj, $instance)
{
	if ($line < $adj['global_top_pos']) {
		// Error in includes? unpossible
	}
	else if ($line < $adj['original_source_pos']) {
		$file = "TEST_CODE_GLOBAL_TOP";
		$line -= $adj['global_top_pos'];
	}
	else if ($line < $adj['global_above_pos']) {
		// For languages that don't copy sources from main_filename this shouldn't happen
		$file = substr($adj['orig_filename'], strlen(instance_path($instance)) + 1);
		$line -= $adj['original_source_pos'];
	}
	else if ($line < $adj['test_code_pos']) {
		$file = "TEST_CODE_GLOBAL_ABOVE";
		$line -= $adj['global_above_pos'];
	}
	else {
		$file = "TEST_CODE";
		$line -= $adj['test_code_pos'];
	}
}


// This function tries to find a compiler given language and compiler_features
// if required_compiler is not found, returns false
// if no compiler for language or no compiler with given features is found, returns false
// if multiple compilers are found, returns preferred_compiler (if available)
function find_best_compiler($language, $required_compiler, $preferred_compiler, $compiler_features)
{
	global $conf_compilers;

	$found_compiler = false;
	foreach ($conf_compilers as $compiler) {
		// Check language
		if (strtolower($compiler["language"]) != strtolower($language))
			continue;

		// Check features
		if (!empty($compiler_features)) {
			$has_features = true;
			foreach($compiler_features as $feature)
				if (!in_array($feature, $compiler['features']))
					$has_features = false;
			if (!$has_features) continue;
		}

		// Check required/preffered
		if (strtolower($compiler["name"]) === strtolower($required_compiler)) {
			$found_compiler = $compiler;
			break;
		}
		if (!empty($required_compiler))
			continue;
		if (strtolower($compiler["name"]) === strtolower($preferred_compiler)) {
			$found_compiler = $compiler;
			break;
		}

		// We found a compiler that is not preferred
		$found_compiler = $compiler;
	}
	
	// Detect compiler version
	if ($found_compiler !== false) {
		$version_cmd = str_replace("COMPILER_PATH", $found_compiler['compiler_path'], $found_compiler['version_line']);
		$found_compiler['version'] = trim(`$version_cmd 2>&1`);
	}

	return $found_compiler;
}


// TODO: these functions are stubs... implement as required
function find_best_debugger($language)
{
	global $conf_debuggers;
	if (empty($conf_debuggers)) return false;
	$found_debugger = $conf_debuggers[0]; // Just return first one
	
	// Detect debugger version
	$version_cmd = str_replace("PATH", $found_debugger['path'], $found_debugger['version_line']);
	$found_debugger['version'] = trim(`$version_cmd 2>&1`);
	
	return $found_debugger;
}
function find_best_profiler($language)
{
	global $conf_profilers;
	if (empty($conf_profilers)) return false;
	$found_profiler = $conf_profilers[0]; // Just return first one
	
	// Detect debugger version
	$version_cmd = str_replace("PATH", $found_profiler['path'], $found_profiler['version_line']);
	$found_profiler['version'] = trim(`$version_cmd 2>&1`);
	
	return $found_profiler;
}

function get_os_version()
{
	$os = `uname -srm`; // Works on Cygwin!
	if (trim(`uname -s`) === "Linux")
		// Redirect stderr to /dev/null if lsb_release doesn't exist
		$os .= `lsb_release -sd 2>/dev/null`;
	return $os;
}



?>
