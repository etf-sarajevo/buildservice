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

$compiler_plugin['gcc'] = "compiler_gcc";
$compiler_plugin['g++'] = "compiler_gcc";

function gcc_cleanup($msg) {
	$msg = str_replace("‘", "'", $msg);
	$msg = str_replace("’", "'", $msg);
	$msg = str_replace("`", "'", $msg);
	$msg = str_replace(",", ",", $msg);
	$msg = preg_replace("/\[-W.*?\]/", "", $msg);
	$msg = str_replace("(first use in this function)", "", $msg);
	return trim($msg);
}

function compiler_gcc($compiler_output, $filelist, &$compiler_result) {
	$k = join("\n", $compiler_output);
	$current_message = array();
	$state = "";
	
	foreach($compiler_output as $line) {
		$matches = array();
		if (preg_match("/^([^\:\s]*?): In function ‘(.*?)’:$/", $line, $matches)) {
			if (!empty($current_message)) $compiler_result['parsed_output'][] = $current_message;
			$current_message = array();
			
			$state = "infunction";
			$file = $matches[1];
			$function = $matches[2];
			// Do nothing
		}
		else if (preg_match("/^([^\:\s]*?):(\d+):(\d+): error: (.*?)$/", $line, $matches)) {
			if (!empty($current_message)) $compiler_result['parsed_output'][] = $current_message;
			$current_message = array();
			
			$state = "error";
			$current_message = array();
			$current_message['type'] = "error";
			$current_message['file'] = $matches[1];
			$current_message['line'] = $matches[2];
			$current_message['col'] = $matches[3];
			$current_message['message'] = gcc_cleanup($matches[4]);
			$current_message['output'] = $line;
		}
		else if (preg_match("/^([^\:\s]*?):(\d+):(\d+): warning: (.*?)$/", $line, $matches)) {
			if (!empty($current_message)) $compiler_result['parsed_output'][] = $current_message;
			$current_message = array();
			
			$state = "warning";
			$current_message = array();
			$current_message['type'] = "warning";
			$current_message['file'] = $matches[1];
			$current_message['line'] = $matches[2];
			$current_message['col'] = $matches[3];
			$current_message['message'] = gcc_cleanup($matches[4]);
			$current_message['output'] = $line;
		} 
		else if (preg_match("/^([^\:\s]*?):(\d+):(\d+): note: (.*?)$/", $line, $matches)) {
			if (!empty($current_message)) $compiler_result['parsed_output'][] = $current_message;
			$current_message = array();
			
			$state = "note";
			$file = $matches[1];
			$line = $matches[2];
			$col = $matches[3];
			$msg = $matches[4];
			// Do nothing
		}
		else if (preg_match("/^([^\:\s]*?): (undefined reference to `.*?')$/", $line, $matches)) {
			if (!empty($current_message)) $compiler_result['parsed_output'][] = $current_message;
			$current_message = array();
			
			$state = "error";
			$current_message = array();
			$current_message['type'] = "error";
			$current_message['message'] = gcc_cleanup($matches[2]);
			$current_message['output'] = $line;
			
			$compiler_result['parsed_output'][] = $current_message;
			$current_message = array();
		}
		else {
			if (!empty($current_message)) {
				$current_message['output'] .= $line;
				if (empty($current_message['code'])) $current_message['code'] = trim($line);
			}
		}
	}
	if (!empty($current_message)) $compiler_result['parsed_output'][] = $current_message;

	return $k;
}

?>
