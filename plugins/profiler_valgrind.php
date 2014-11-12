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

// PROFILER_VALGRIND - buildservice plugin for parsing valgrind output

$profiler_plugin['valgrind'] = "profiler_valgrind";

function profiler_valgrind($profiler_output, $filelist, &$profile_result) 
{
	$current_message = $parsed_output = array();
	
	$alLocation = false;
	
	$message_strings = array( 
		array ( "msg" => "Invalid read of size", "status" => PROFILER_OOB), 
		array ( "msg" => "Use of uninitialised value", "status" => PROFILER_UNINIT), 
		array ( "msg" => "Conditional jump or move depends on uninitialised value", "status" => PROFILER_UNINIT), 
		array ( "msg" => "are definitely lost", "status" => PROFILER_MEMLEAK), 
		array ( "msg" => "Invalid free", "status" => PROFILER_INVALID_FREE), 
		array ( "msg" => "Mismatched free", "status" => PROFILER_MISMATCHED_FREE), 
		array ( "msg" => "Invalid read of size", "status" => PROFILER_OOB), 
	);
	
	foreach ($profiler_output as $profiler_line) {
		// Remove PID
		$profiler_line = preg_replace("/^==\d+==/", "", $profiler_line);
		
		// Looking for start of next message
		if ($current_message === array()) {
			foreach ($message_strings as $msg)
				if (strstr($profiler_line, $msg['msg'])) {
					$current_message['type'] = $msg['status'];
					if ($profile_result['status'] === PROFILER_OK)
						$profile_result['status'] = $msg['status'];
					$current_message['output'] = $profiler_line . "\n";
				}
			/*
			if (strstr($profiler_line, "cannot throw exceptions and so is aborting")) 
				... what would you want to do with this?
			*/
			continue;
		}

		// We are parsing a valgrind message
		
		if (!preg_match("/\w/", $profiler_line)) { // Empty line means end of message
			// Remove duplicate messages
			$duplicate = false;
			foreach ($parsed_output as $msg)
				if ($msg['file'] === $current_message['file'] && $msg['line'] === $current_message['line'] && $msg['type'] === $current_message['type'])
					$duplicate = true;

			if (!$duplicate)
				array_push($parsed_output, $current_message);
			$current_message = array();
			$alLocation = false;
			continue;
		}
		
		if (preg_match("/^\s+Address .*? is .*? inside a block of size/", $profiler_line)) {
			// Line where memory was alloc'ed follows
			$alLocation = true;
		}
		
		// Ordinary message line
		else if (preg_match("/^\s+(at|by) 0x[\dA-F]+: .*? \((.*?\:.*?)\)$/", $profiler_line, $matches)) {
			list($profiler_file, $profiler_lineno) = explode(":", $matches[2]);
			// We're looking for first mention of file that is part of our project
			// All filenames produced by valgrind will be relative paths
			foreach ($filelist as $file) {
				if (basename($file) == basename($profiler_file)) {
					if ($alLocation === false && !array_key_exists("file", $current_message)) {
						$current_message['file'] = $profiler_file;
						$current_message['line'] = $profiler_lineno;
					}
					if ($alLocation === true && !array_key_exists("file_alloced", $current_message)) {
						$current_message['file_alloced'] = $profiler_file;
						$current_message['line_alloced'] = $profiler_lineno;
					}
				}
			}
		}
		$current_message['output'] .= clear_unicode($profiler_line) . "\n";
	}
	
	$profile_result['parsed_output'] = $parsed_output;
}

?>