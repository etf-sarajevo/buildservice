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
	else if ($open_chr === "(") $closed_chr = ")";
	else if ($open_chr === "[") $closed_chr = "]";
	else if ($open_chr === "<") $closed_chr = ">";
	else $closed_chr = ")"; // This is surely an error, but at least avoid infinite loop
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
				if ($conf_verbosity>1) parser_error("C-style comment doesn't end", "", $string, $i);
				break;
			}
			$i = $eoc+2;
		}
		if ($string[$i] == "'") {
			$end = strpos($string, "'", $i+1);
			if ($end === false) {
				if ($conf_verbosity>1) parser_error("unclosed char constant", "", $string, $i);
				break;
			}
			$i = $end;
		}
		if ($string[$i] == '"') {
			$end = strpos($string, '"', $i+1);
			// Skip escaped quotes
			while ($end>1 && $string[$end-1] == "\\") $end = strpos($string, '"', $end+1);
			if ($end === false) {
				if ($conf_verbosity>1) parser_error("unclosed string constant", "", $string, $i);
				break;
			}
			$i = $end;
		}
	}
	return $i;
}

// Display error message with some context
function parser_error($msg, $file, $code, $pos)
{
	$context_before = $context_after = 20;

	print "C/C++ parser error: $msg\n";

	print "   ";
	if (!empty($file)) print "File: $file, ";

	// Get line number
	$line = 1;
	for ($i=0; $i<$pos; $i++) if ($code[$i] == "\n") $line++;
	print "Line: $line, ";

	$start = $pos - $context_before;
	$end   = $pos + $context_after;
	if ($start < 0) $start=0;
	if ($start > strlen($code)) $start=strlen($code);
	if ($end > strlen($code)) $end=strlen($code);
	print "Context: ".substr($code, $start, $end-$start)."\n";
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
		if ($conf_verbosity>1) parser_error("template never ends", "", $string, $i);
		return false;
	}
	return $i;
}

function skip_constructor($string, $pos)
{
	global $conf_verbosity;

	$open_brace_pos = strpos($string, "(", $pos);
	$close_brace_pos = find_matching($string, $open_brace_pos);
	if ($close_brace_pos == strlen($string)) {
		if ($conf_verbosity>1) parser_error("ctor invalid parameter list", $file, $string, $end_type);
		return false;
	}

	$colon_pos = strpos($string, ":", $close_brace_pos);
	$sc_pos    = strpos($string, ";", $close_brace_pos);
	$curly_pos = strpos($string, "{", $close_brace_pos);
	if ($colon_pos !== false && ($sc_pos === false || $colon_pos < $sc_pos) && ($curly_pos === false || $colon_pos < $curly_pos)) {
		for ($i=$colon_pos+1; $i<strlen($string); $i++) {
			$i = skip_whitespace($string, $i);
			if ($string[$i] == ';' || $string[$i] == '{') return $i;

			$i = skip_ident_chars($string, $i);
			$i = skip_whitespace($string, $i);
			if ($string[$i] == '(' || $string[$i] == '{') $i = find_matching($string, $i)+1;
			else {
				if ($conf_verbosity>1) parser_error("invalid init list format (no brace)", "", $string, $i);
				return false;
			}
			$i = skip_whitespace($string, $i);
			if ($string[$i] != ',' && $string[$i] != ';' && $string[$i] != '{') {
				if ($conf_verbosity>1) parser_error("invalid init list format (no comma)", "", $string, $i);
				return false;
			} else if ($string[$i] == ';' || $string[$i] == '{') $i--;
		}
	}
	return $pos;
}


// Find symbols in global scope to know which files need to be included
function parse_c_cpp($sourcecode, $language, $file /* Only used for error messages... */) 
{
	global $conf_verbosity;

	$symbols = array();

	// Remove comments from code
	$sourcecode = preg_replace("|/\*.*?\*/|", "", $sourcecode);
	$sourcecode = preg_replace("|//[^\*]*?\n|", "", $sourcecode);

	$lineno=1;
	for ($i=0; $i<strlen($sourcecode); $i++) {
		$i = skip_whitespace($sourcecode, $i);
		if ($i==strlen($sourcecode)) break;
		
		// Find #define'd constants
		if (substr($sourcecode, $i, 7) == "#define") {
			$i = skip_whitespace($sourcecode, $i+7);
			
			// If valid identifier doesn't follow, syntax error
			if (!ident_char($sourcecode[$i])) {
				if ($conf_verbosity>1) parser_error("invalid symbol after #define: ".$sourcecode[$i], $file, $sourcecode, $i);
				break;
			}
			
			$define_begin = $i;
			$i = skip_ident_chars($sourcecode, $i);
			$define_name = substr($sourcecode, $define_begin, $i-$define_begin);
			if ($conf_verbosity>2) print "Define $define_name\n";
			array_push($symbols, $define_name);
			
			$i = skip_to_newline($sourcecode, $i);
			continue;
		}
		
		// Find classes and structs
		if (substr($sourcecode, $i, 5) == "class" || substr($sourcecode, $i, 5) == "struct") {
			$i = skip_whitespace($sourcecode, $i+5); 
			
			// If a valid identifier doesn't follow the keyword, syntax error
			if (!ident_char($sourcecode[$i])) {
				if ($conf_verbosity>1) parser_error("invalid symbol after class/struct: ".$sourcecode[$i], $file, $sourcecode, $i);
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
				if ($conf_verbosity>1) parser_error("neither ; nor { after class/struct", $file, $sourcecode, $i);
				break;
			}

			if ($curly_pos === false || ($sc_pos !== false && $sc_pos < $curly_pos)) {
				$i = $sc_pos;
				continue;
			}
			
			if ($conf_verbosity>2) print "Class $class_name\n";
			array_push($symbols, $class_name);
			
			// Skip to end of block
			$i = find_matching($sourcecode, $curly_pos);
			if ($i==strlen($sourcecode)) {
				if ($conf_verbosity>1) parser_error("missing closed curly", $file, $sourcecode, $curly_pos);
				break;
			}
		}
		
		// Skip C-style comments
		if (substr($sourcecode, $i, 2) == "/*") {
			// Skip to end of comment
			$eoc = strpos($sourcecode, "*/", $i);
			if ($eoc === false) {
				if ($conf_verbosity>1) parser_error("C-style comment doesn't end", $file, $sourcecode, $i);
				break;
			}
			$i = $eoc+2;
		}
		
		// Skip other preprocessor directives and C++-style comments
		if ($sourcecode[$i] == "#" || substr($sourcecode, $i, 2) == "//") {
			// Skip to newline
			$i = skip_to_newline($sourcecode, $i);
			continue;
		}
		
		// Skip using
		if (substr($sourcecode, $i, 5) == "using") {
			// Skip to semicolon
			$sc_pos = strpos($sourcecode, ";", $i);
			if ($sc_pos === false) {
				if ($conf_verbosity>1) parser_error("missing semicolon after 'using'", $file, $sourcecode, $i);
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
			} else {
				// No template after "template" keyword? syntax error
				if ($conf_verbosity>1) parser_error("no template after 'template' keyword: ".$sourcecode[$i], $file, $sourcecode, $i);
				break;
			}
		}
		
		// The rest is likely an identifier of some kind in global scope - we want that!
		if (ident_char($sourcecode[$i])) {
			// Skip keyword const
			if (substr($sourcecode, $i, 5) == "const")
				$i = skip_whitespace($sourcecode, $i+5); 

			// skip type
			$multiword = array("long double", "unsigned int", "unsigned long", "short int", "unsigned short"); // TODO add others
			$found = false;
			foreach($multiword as $type) 
				if (strlen($sourcecode)>$i+strlen($type) && substr($sourcecode, $i, strlen($type)) == $type) {
					$found = true;
					$start_type = $i;
					$i += strlen($type);
					$end_type = $i;
					break;
				}
			if (!$found) {
				$start_ns = $end_ns = -1;
				$start_type = $i;
				$i = skip_ident_chars($sourcecode, $i);
				$end_type = $i;
			}
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
			
				if ($sourcecode[$i] == "<" && $ident_name !== "operator" || $sourcecode[$i] == ":") {
					// This is a class method
					$class_name = $ident_name;

					// Find method name (used just for debugging msgs)
					if ($sourcecode[$i] == "<") $i = find_matching($sourcecode, $i)+1;
					if ($i !== false && $i < strlen($sourcecode)-1) {
						if ($sourcecode[$i] == ":") $i += 2;
						$ident_begin = $i;
						if ($sourcecode[$i] == "~") $i++;
						$i = skip_ident_chars($sourcecode, $i);
						$ident_name = substr($sourcecode, $ident_begin, $i-$ident_begin);
					}

					if ($conf_verbosity>2) print "Skip class method $class_name::$ident_name\n";
				} else {
					if ($conf_verbosity>2) print "Ident $ident_name\n";
					array_push($symbols, $ident_name);
				}
			} else {
				// This catches two cases not handled with above code
				// where ident would be detected as "type" and type as "namespace"

				if ($sourcecode[$start_type] == "~") // Destructor
					$end_type = skip_ident_chars($sourcecode, $start_type+1);
				$ident_name = substr($sourcecode, $start_type, $end_type-$start_type);
				
				// Typeless idents (possible...)
				if ($start_ns == -1) {
					if ($conf_verbosity>2) print "Typeless ident $ident_name\n";
					array_push($symbols, $ident_name);

				// Ctor, dtor and such
				} else {
					$class_name = substr($sourcecode, $start_ns, $end_ns-$start_ns);
					if ($conf_verbosity>2) print "Skip ctor-like ident $class_name::$ident_name\n";

					// In case of constructor, we need to skip the initialization list
					// This wouldn't be neccessary if not for C++11 style initializers using curly braces e.g.
					// MyClass::MyClass() : attribute{value}, attribute{value} { /* Actual ctor code */ }
					$i = skip_constructor($sourcecode, $end_type);
					if ($i === false) break;
				}
			}
			
			// skip to semicolon or end of block, whichever comes first
			do {
				$repeat = false;
				$sc_pos    = strpos($sourcecode, ";", $i);
				$curly_pos = strpos($sourcecode, "{", $i);
				// BUT if curly is inside braces, skip that too
				$open_brace_pos = strpos($sourcecode, "(", $i);
				if ($open_brace_pos && $open_brace_pos < $sc_pos && $open_brace_pos < $curly_pos) {
					$i = find_matching($sourcecode, $open_brace_pos);
					$repeat = true;
				}
			} while ($repeat);
			
			// there is neither curly nor semicolon, syntax error
			if ($curly_pos === false && $sc_pos === false) {
				if ($conf_verbosity>1) parser_error("neither ; nor { after identifier", $file, $sourcecode, $i);
				break;
			}

			else if ($curly_pos === false || ($sc_pos !== false && $sc_pos < $curly_pos))
				$i = $sc_pos;
			else
				$i = find_matching($sourcecode, $curly_pos);
			if ($i==strlen($sourcecode)) {
				if ($conf_verbosity>1) parser_error("missing closed curly", $file, $sourcecode, $curly_pos);
				break;
			}
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