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

// buildservice configuration



// Give some name to this buildhost
$buildhost_id = "localhost";

// Size of program output (stdout+stderr) is limited for performance and security reasons
$conf_max_program_output = 10000;

// Verbosity level for messages on stdout
// 0 = no output
// 1 = information about what is being done currently
// 2 = some debugging output
// 3 = forward output from all child processes to stdout
$conf_verbosity = 1;


// Maximum number of simultainously executed tasks... 10000 should be enough to choke the server :)
// However sometimes instances are incorrectly purged so this number can be increased as a stopgap measure
$conf_max_instances = 10000;


// JSON options

$conf_json_base_url = "http://url-to-build-service.com";
$conf_json_login_required = true;
$conf_json_user = "someuser";
$conf_json_pass = "somepass";

$conf_json_max_retries = 10;


// Commands and paths

$conf_tmp_path = "/tmp";
// Base directory for all files related to buildservice
$conf_basepath = "/tmp/buildservice";
$conf_unzip_command = "unzip";


// ----------------------------------------------
// COMPILERS
// ----------------------------------------------

$conf_compilers = array(
	// GCC
	array(
		"name" => "gcc",
		"language" => "C",
		"compiler_path" => "/usr/bin/gcc",
		"executor_path" => "", // Compiler generates executables
		"cmd_line" => "COMPILER_PATH -o OUTPUT_FILE SOURCE_FILES OPTIONS",
		"exe_line" => "OUTPUT_FILE",
		"features" => array(), // add features supported by this compiler 
	),

	// G++
	array(
		"name" => "g++",
		"language" => "C++",
		//"path" => "/opt/gcc-4.8.2/bin/g++",
		"compiler_path" => "/usr/bin/g++",
		"executor_path" => "", // Compiler generates executables
		//"cmd_line" => "COMPILER_PATH -o OUTPUT_FILE SOURCE_FILES -Wl,-rpath /opt/gcc-4.8.2/lib64 OPTIONS",
		"cmd_line" => "COMPILER_PATH -o OUTPUT_FILE SOURCE_FILES OPTIONS",
		"exe_line" => "OUTPUT_FILE",
		"features" => array( "c++11" ),
	),

	// JDK
	array(
		"name" => "jdk",
		"language" => "Java",
		"compiler_path" => "javac",
		"executor_path" => "java",
		"cmd_line" => "COMPILER_PATH OPTIONS SOURCE_FILES",
		"exe_line" => "EXECUTOR_PATH OUTPUT_FILE", // FIXME with java output files are always named Foo.class, so this needs to be hardcoded
		"features" => array(), 
	),

	// PYTHON
	array(
		"name" => "python3",
		"language" => "Python",
		"compiler_path" => "/usr/bin/python3",
		"executor_path" => "/usr/bin/python3",
		"cmd_line" => "COMPILER_PATH -m py_compile OPTIONS SOURCE_FILES",
		"exe_line" => "EXECUTOR_PATH OPTIONS SOURCE_FILES",
		"features" => array( "python3" ), // Python version 3 is used
	),
);


// ----------------------------------------------
// DEBUGGERS
// ----------------------------------------------

$conf_debuggers = array(
	array(
		"name" => "gdb",
		"path" => "gdb",
		"local_opts" => "", // add options that need to be passed every time
		"features" => array(),
		// options needed to process core dump (COREFILE will be replaced with filename)
		"opts_core" => "--batch -ex \"bt 100\" --core=COREFILE", 
	),
);



// ----------------------------------------------
// PROFILERS
// ----------------------------------------------

$conf_profilers = array(
	array(
		"name" => "valgrind",
		"path" => "valgrind",
		"local_opts" => "", // add options that need to be passed every time
		"features" => array(),
		// options to pass to create a logfile that will be analyzed later
		"opts_log" => "--leak-check=full --log-file=LOGFILE", 
		//"opts_log" => "--leak-check=full --log-file-exact=LOGFILE",  // old valgrind
		"timeout_ratio" => 2, // We expect valgrind to run twice as long as program alone
		// valgrind needs a lot of ram to work, sometimes as much as 100 MB for simple "hello world" style programs
		// We don't want to enforce usual memory limits but we also don't want misbehaving programs to crash our machine
		// Put roughly half your RAM below (in kB)
		"vmem_hard_limit" => 1000000,
	),
);


// Lists of source filename extensions per language
$conf_extensions = array(
	"C"    => array( ".c", ".h" ),
	"C++"  => array( ".cpp", ".h", ".cxx", ".hxx" ),
	"Java" => array( ".java" ),
	"Python" => array( ".py" ),
);


?>
