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


// Plugins for parsing compiler output

$compiler_plugin = array();

require("plugins/compiler_gcc.php");


// Plugins for parsing debugger output

$debugger_plugin = array();

require("plugins/debugger_gdb.php");


// Plugins for parsing profiler output

$profiler_plugin = array();

require("plugins/profiler_valgrind.php");


// Plugins for parsing sourcecode

$source_parse_plugin = array();

require("plugins/parse_c_cpp.php");

?>
