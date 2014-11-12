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

$source_parse_plugin['C'] = "parse_c_cpp";
$source_parse_plugin['C++'] = "parse_c_cpp";

// Various helper functions for parsing sourcecode

// This is currently only used to find which file contains main()
// but in the future other tests may be implemented that don't require a compiler

// Finds matching closed brace for open brace at $pos
// In case there is no matching brace, function will return strlen($string)
function find_matching($string, $pos) 
{
	global $conf_verbosity;

	$open_chr = $string[$pos];
	if ($open_chr === "{") $closed_chr = "}";
	if ($open_chr === "(") $closed_chr = ")";
	if ($open_chr === "[") $closed_chr = "]";
	if ($open_chr === "<") $closed_chr = ">";
	$level=0;
	
	for ($i=$pos; $i<strlen($string); $i++) {
		if ($string[$i] === $open_chr) $level++;
		if ($string[$i] === $closed_chr) $level--;
		if ($level==0) break;
		// Skip various non-code blocks
		if (substr($string, $i, 2) == "//") $i = skip_to_newline($string, $i);
		if (substr($string, $i, 2) == "/*") {
			$eoc = strpos($string, "*/", $i);
			if ($eoc === false) {
				if ($conf_verbosity>1) print "extract_global_symbols(): syntax error: C-style comment doesn't end\n";
				break;
			}
			$i = $eoc+2;
		}
		if ($string[$i] == "'") {
			$end = strpos($string, "'", $i+1);
			if ($end === false) {
				if ($conf_verbosity>1) print "extract_global_symbols(): unclosed char constant\n";
				break;
			}
			$i = $end;
		}
		if ($string[$i] == '"') {
			$end = strpos($string, '"', $i+1);
			if ($end === false) {
				if ($conf_verbosity>1) print "extract_global_symbols(): unclosed string constant\n";
				break;
			}
			$i = $end;
		}
	}
	return $i;
}

function skip_whitespace($string, $i) 
{
	while ( $i<strlen($string) && ctype_space($string[$i]) ) $i++;
	return $i;
}

// Valid identifier characters in C and C++
function ident_char($c) { return (ctype_alnum($c) || $c == "_"); }

// Skip ident chars
function skip_ident_chars($string, $i) 
{
	while ( $i<strlen($string) && ident_char($string[$i]) ) $i++;
	return $i;
}
function skip_to_newline($string, $i) 
{
	$i = strpos($string, "\n", $i);
	if ($i===false) return strlen($string);
	return $i;
}

function skip_template($string, $i)
{
	global $conf_verbosity;

	if ($i>=strlen($string) || $string[$i] !== "<") return false;
	$i = find_matching($string, $i);
	if ($i === false) {
		if ($conf_verbosity>1) print "extract_global_symbols(): syntax error in $file: template never ends\n";
		return false;
	}
	return $i;
}


// Find symbols in global scope to know which files need to be included
function parse_c_cpp($sourcecode, $language, $file /* Only used for error messages... */) 
{
	global $conf_verbosity;

	$symbols = array();

	$lineno=1;
	for ($i=0; $i<strlen($sourcecode); $i++) {
		// Skip whitespace
		$i = skip_whitespace($sourcecode, $i);
		if ($i==strlen($sourcecode)) break;
		
		// Find #define'd constants
		if (substr($sourcecode, $i, 7) == "#define") {
			$i = skip_whitespace($sourcecode, $i+7);
			
			// If valid identifier doesn't follow, syntax error
			if (!ident_char($sourcecode[$i])) {
				if ($conf_verbosity>1) print "extract_global_symbols(): syntax error in $file:$i: invalid symbol after #define: ".$sourcecode[$i]."\n";
				break;
			}
			
			$define_begin = $i;
			$i = skip_ident_chars($sourcecode, $i);
			$define_name = substr($sourcecode, $define_begin, $i-$define_begin);
			array_push($symbols, $define_name);
print "Define $define_name filename $file begin $define_begin end $i\n";
			
			// Skip to newline
			$i = skip_to_newline($sourcecode, $i);
print "Skip to newline to $i\n";
			continue;
		}
		
		// Find classes and structs
		if (substr($sourcecode, $i, 5) == "class" || substr($sourcecode, $i, 5) == "struct") {
			$i = skip_whitespace($sourcecode, $i+5); 
			
			// If a valid identifier doesn't follow the keyword, syntax error
			if (!ident_char($sourcecode[$i])) {
				if ($conf_verbosity>1) print "extract_global_symbols(): syntax error in $file:$i: invalid symbol after class/struct: ".$sourcecode[$i]."\n";
				break;
			}
			
			$class_begin = $i;
			$i = skip_ident_chars($sourcecode, $i);
			$class_name = substr($sourcecode, $class_begin, $i-$class_begin);
			
			// If semicolon is closer than curly brace, this is just forward definition so we skip it
			$sc_pos    = strpos($sourcecode, ";", $i);
			$curly_pos = strpos($sourcecode, "{", $i);
			
			// there is neither curly nor semicolon, syntax error
			if ($curly_pos === false && $sc_pos === false) {
				if ($conf_verbosity>1) print "extract_global_symbols(): syntax error in $file:$i: neither ; nor { after class/struct\n";
				break;
			}

			if ($curly_pos === false || ($sc_pos !== false && $sc_pos < $curly_pos)) {
				$i = $sc_pos;
				continue;
			}
			
print "Class $class_name filename $file begin $class_begin end $i\n";
			array_push($symbols, $class_name);
			
			// Skip to end of block
			$i = find_matching($sourcecode, $curly_pos);
print "Skip to curly to $i\n";
			if ($i==strlen($sourcecode)) {
				if ($conf_verbosity>1) print "extract_global_symbols(): syntax error in $file:$curly_pos: missing closed curly\n";
				break;
			}
		}
		
		// Skip other preprocessor directives and C++-style comments
		if ($sourcecode[$i] == "#" || substr($sourcecode, $i, 2) == "//") {
			// Skip to newline
			$i = skip_to_newline($sourcecode, $i);
print "Skip #include or comment to $i\n";
			continue;
		}
		
		// Skip C-style comments
		if (substr($sourcecode, $i, 2) == "/*") {
			// Skip to end of comment
			$eoc = strpos($sourcecode, "*/", $i);
			if ($eoc === false) {
				if ($conf_verbosity>1) print "extract_global_symbols(): syntax error in $file:$i: C-style comment doesn't end\n";
				break;
			}
			$i = $eoc+2;
		}
		
		// Skip using
		if (substr($sourcecode, $i, 5) == "using") {
			// Skip to semicolon
			$sc_pos = strpos($sourcecode, ";", $i);
			if ($sc_pos === false) {
				if ($conf_verbosity>1) print "extract_global_symbols(): syntax error in $file:$i: missing semicolon after using\n";
				break;
			}
			$i = $sc_pos+1;
		}
		
		// Skip template definitions
		if (substr($sourcecode, $i, 8) == "template") {
			$i = skip_whitespace($sourcecode, $i+8);
			if ($i<strlen($sourcecode) && $sourcecode[$i] == "<") {
				$i = skip_template($sourcecode, $i);
				if ($i === false) break;
print "Skip template to $i\n";
			} else {
				// No template after "template" keyword? syntax error
				if ($conf_verbosity>1) print "extract_global_symbols(): syntax error in $file:$i: no template after 'template' keyword: ".$sourcecode[$i]."\n";
				break;
			}
		}
		
		// The rest is likely an identifier of some kind in global scope - we want that
		if (ident_char($sourcecode[$i])) {
			// Skip keyword const
			if (substr($sourcecode, $i, 5) == "const")
				$i = skip_whitespace($sourcecode, $i+5); 

			// skip type
			$start_ns = $end_ns = -1;
			$start_type = $i;
			$i = skip_ident_chars($sourcecode, $i);
			$end_type = $i;
			$i = skip_whitespace($sourcecode, $i); 
			
			// skip template as part of type
			if ($sourcecode[$i] == "<") {
				$i = skip_template($sourcecode, $i);
				if ($i === false || $i === strlen($sourcecode)-1) break;
				$i = skip_whitespace($sourcecode, $i+1); 
			}
			
			// skip namespace as part of type
			if (substr($sourcecode, $i, 2) == "::") {
				// We already skipped namespace so now we are skipping actual type
				$i = skip_whitespace($sourcecode, $i+2); 
				$start_ns = $start_type;
				$end_ns = $end_type;
				$start_type = $i;
				$i = skip_ident_chars($sourcecode, $i);
				$end_type = $i;
				$i = skip_whitespace($sourcecode, $i); 

				// skip template as part of type
				if ($sourcecode[$i] == "<") {
					$i = skip_template($sourcecode, $i);
					if ($i === false || $i === strlen($sourcecode)-1) break;
					$i = skip_whitespace($sourcecode, $i+1); 
				}
			}

			// there could be characters: * & [] ^
			$typechars = array("*", "&", "[", "]", "^");
			while (in_array($sourcecode[$i], $typechars)) $i++;
			$i = skip_whitespace($sourcecode, $i); 
			
			// here comes identifier
			$ident_begin = $i;
			$i = skip_ident_chars($sourcecode, $i);
			if ($ident_begin != $i) {
				$ident_name = substr($sourcecode, $ident_begin, $i-$ident_begin);
				$i = skip_whitespace($sourcecode, $i); 
			
				if ($sourcecode[$i] == "<" || $sourcecode[$i] == ":") {
					// This is a class method
print "Skipping method of class $ident_name\n";
				} else {
print "Ident $ident_name filename $file begin $ident_begin end $i\n";
					array_push($symbols, $ident_name);
				}
			} else {
				// this is not actually identifier!?
				// FIXME also external constructor and destructor, but not relevant right now
				$ident_name = substr($sourcecode, $start_type, $end_type-$start_type);
				if ($start_ns == -1) {
print "Typeless ident $ident_name filename $file begin $start_type end $end_type\n";
					array_push($symbols, $ident_name);
				} else {
print "Skipping typeless method $ident_name of class ".substr($sourcecode, $start_ns, $end_ns-$start_ns)."\n";
				}
//print "Skipping non-ident\n";
			}
			
			// skip to semicolon or end of block, whichever comes first
			$sc_pos    = strpos($sourcecode, ";", $i);
			$curly_pos = strpos($sourcecode, "{", $i);
			
			// there is neither curly nor semicolon, syntax error
			if ($curly_pos === false && $sc_pos === false) {
				if ($conf_verbosity>1) print "extract_global_symbols(): syntax error in $file:$i: neither ; nor { after identifier\n";
				break;
			}

			if ($curly_pos === false || ($sc_pos !== false && $sc_pos < $curly_pos))
				$i = $sc_pos;
			else
				$i = find_matching($sourcecode, $curly_pos);
			if ($i==strlen($sourcecode)) {
				if ($conf_verbosity>1) print "extract_global_symbols(): syntax error in $file:$curly_pos: missing closed curly\n";
				break;
			}
print "Skip to after ident to $i (sc_pos $sc_pos curly pos $curly_pos)\n";
		}
	}

	return $symbols;
}


// Earlier attempt to write the function using regexes

/*

// Find symbols in global scope to know which files need to be included
function parse_c_cpp($sourcecode, $language) 
{

	// Find and remove all classes
	while (preg_match("/(?:^|\n)\s*class\s+(\w*?)([^;*?)\{(.*?)\}/s", $sourcecode, $matches) {
		$class_name = $matches[1];
		array_push($symbols, $class_name);
		$sourcecode = preg_replace("/\sclass (\w*?)([^;*?)\{(.*?)\}/", "", $sourcecode);
	}
	
	// Find structs
	while (preg_match("/(?:^|\n)\s*struct\s+(\w*?)([^;*?)\{(.*?)\}/s", $sourcecode, $matches) {
		$struct_name = $matches[1];
		array_push($symbols, $struct_name);
		$sourcecode = preg_replace("/\sstruct (\w*?)([^;*?)\{(.*?)\}/", "", $sourcecode);
	}
	
	// Find defines
	while (preg_match("/(?:^|\n)\s*\#define\s+(\w*?)\s/", $sourcecode, $matches) {
		$define_name = $matches[1];
		array_push($symbols, $define_name);
		$sourcecode = preg_replace("/\s\#define\s+(\w*?)\s.*", "", $sourcecode);
	}
	
	// Find function definitions in global scope
	// Explanation:
	//   (?:^|\;|\}) - closed brace }, semicolon ;, or start of string ^ - ensure there is no 
	// [^\(\)\{\}\;\.]*[\s\n]*(const)?\s*\s/", $sourcecode, $matches) {
	//   (?:^|\n) - start of string ^ or newline \n
	//   \s* - some number of spaces
	//   \w* - type (may be ommitted)
	//   .?  - some character for e.g. pointer or reference
	//   (\w+) - function name
	//   (const)? - possibly denoted as const function
	//   \(  - opening brace
	//   .*? - whatever (non-greedy match) - parameters which can contain pretty much any character
	//   \)  - closing brace
	//   \{  - open curly brace
	while (preg_match("/(?:^|\n)\s*\w*\s*.?\s*(\w+)\s*(const)?\s*\(.*?\)\s*\{/", $sourcecode, $matches) {
		$function_name = $matches[1];
		array_push($symbols, $function_name);
		$sourcecode = preg_replace("/\s\#define\s+(\w*?)\s.*", "", $sourcecode);
	}
}*/



?>