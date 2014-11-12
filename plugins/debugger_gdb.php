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


$debugger_plugin['gdb'] = "debugger_gdb";

// Very primitive GDB backtrace parsing!
function debugger_gdb($debugger_output, $filelist, &$debugger_result) {
	$line = -1;
	$msg = array();
	// Find first line in backtrace that belongs to our source
	foreach ($debugger_output as $output_line) {
		foreach ($filelist as $source_file) {
			$substring = " at $source_file:";
			if ($match = strstr($output_line, $substring)) {
				$msg['file'] = $source_file;
				$msg['line'] = intval(substr($match, strlen($substring)));
				break;
			}
		}
		if ($msg !== array())
			break;
	}
	$debugger_result['parsed_output'] = array();
	array_push($debugger_result['parsed_output'], $msg);
}

?>