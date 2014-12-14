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


// LIB.PHP - helper functions for buildservice (mostly JSON stuff)


// Test if string ends with some other string without invoking the overhead of regex
function ends_with($string, $substring) 
{
	if (strlen($string) >= strlen($substring))
		if (substr($string, strlen($string)-strlen($substring)) === $substring)
			return true;
	return false;
}


function rmMinusR($path) 
{
	$files = glob($path."/*"); // There should be no hidden files...
	foreach ($files as $file) {
		if (is_dir($file)) {
			rmMinusR($file);
			rmdir($file);
		} else {
			unlink($file);
		}
	}
}


// Ensure that $path exists and is an empty directory
function directoryCleanup($path) 
{
	if (!file_exists($path)) {
		mkdir($path, 0777, true);
	} else if (!is_dir($path)) {
		unlink($path);
		mkdir($path);
	} else {
		rmMinusR($path);
	}
}


// Remove illegal and harmful unicode characters from output
function clear_unicode($text) 
{
	// We can't use this due to bug in php: https://bugs.php.net/bug.php?id=48147
	//if (function_exists('iconv'))
	//	return iconv("UTF-8", "UTF-8//IGNORE", $text);
	ini_set('mbstring.substitute_character', "none"); 
	return mb_convert_encoding($text, 'UTF-8', 'UTF-8'); 
}



// JSON functions
// http://wezfurlong.org/blog/2006/nov/http-post-from-php-without-curl/

function json_request($url, $parameters, $method = "GET") 
{
	global $conf_verbosity;

	$allowed_http_codes = array ("200"); // Only 200 is allowed

	$query = http_build_query($parameters);
	
	if ($method == "GET") 
		$http_result = @file_get_contents("$url?$query");
	else {
		$params = array('http' => array(
			'method' => 'POST',
			'content' => $query,
			'header' => "Content-Type: application/x-www-form-urlencoded\r\n" .
				"Content-Length: " . strlen ( $query ) . "\r\n"
			));
		$ctx = stream_context_create($params);
		$fp = fopen($url, 'rb', false, $ctx);
		if (!$fp) {
			echo "HTTP request failed for $url (POST)\n";
			return FALSE;
		}
		$http_result = stream_get_contents($fp);
		fclose($fp);
	}
	if ($http_result===FALSE) {
		print "HTTP request failed for $url?$query ($method)\n";
		return FALSE;
	}
	$http_code = explode(" ", $http_response_header[0]);
	$http_code = $http_code[1];
	if ( !in_array($http_code, $allowed_http_codes) ) {
		print "HTTP request returned code $http_code for $url?$query ($method)\n";
		return FALSE;
	}
		
	$json_result = json_decode($http_result, true); // Retrieve json as associative array
	if ($json_result===NULL) {
		print "Failed to decode result as JSON\n$http_result\n";
		// Why does this happen!?
		if ($conf_verbosity>0) { print_r($http_result); print_r($parameters); }
		return FALSE;
	} 

	else if (array_key_exists("server_message", $json_result)) {
		print "Message from server: " . $json_result["server_message"]."\n";
	}

	return $json_result;
}

// Repeat json_request $conf_json_max_retries times
function json_request_retry($url, $parameters, $method = "GET") 
{
	global $conf_json_max_retries;

	$result = json_request($url, $parameters, $method);
	if ($result !== FALSE) return $result;
	
	$try = 1;
	do {
		print "... try $try ...\n";
		$result = json_request($url, $parameters, $method);
		$try++;
	} while ($result === FALSE && $try < $conf_json_max_retries);
	if ($result === FALSE) {
		print "Giving up after $try attempts... try again later!\n";
		exit(1);
	}
	return $result;
}

// Simulate SQL query with json
function json_query($action, $parameters = array(), $method = "GET") 
{
	global $conf_json_base_url, $session_id, $conf_verbosity;

	$url = $conf_json_base_url."buildservice.php"; // FIXME make RESTful
	$parameters['action'] = $action;

	if ($session_id !== "")
		$parameters[session_name()] = $session_id;
	$result = json_request_retry($url, $parameters, $method);

	if (!array_key_exists("success", $result)) {
		print "JSON query $action failed: unknown reason\n";
		if ($conf_verbosity>0) print_r($result);
	} 
	else if ($result["success"] !== "true") {
		print "JSON query $action failed: ". $result['code']. " : ". $result['message'] . "\n"; 
		return FALSE;
	}
	return $result["data"];
}


function json_login() 
{
	global $conf_json_base_url, $conf_json_user, $conf_json_pass;

	$url = $conf_json_base_url."auth.php";  // FIXME make RESTful

	$data = array("login" => $conf_json_user, "pass" => $conf_json_pass);
	$result = json_request_retry ($url, $data, "POST");
	if ($result['success'] !== "true") {
		print "Invalid username/password for webservice: ".$result['code']."\n";
		exit(5);
	}
	
	return $result['sid'];
}

function json_get_binary_file($filename, $action, $parameters = array(), $method = "GET") 
{
	global $conf_json_base_url, $session_id;

	$url = $conf_json_base_url."buildservice.php"; // FIXME make RESTful
	$parameters['action'] = $action;
	if ($session_id !== "")
		$parameters[session_name()] = $session_id;

	$query = http_build_query($parameters);

	if ($method === "GET") {
		$url = "$url?$query";
		$params = array( 'http' => array( 'method' => "GET" ) );
	} else {
		$params = array('http' => array(
			'method' => $method,
			'content' => $query,
			'header' => "Content-Type: application/x-www-form-urlencoded\r\n" .
				"Content-Length: " . strlen ( $query ) . "\r\n"
			));
	}
	$ctx = stream_context_create($params);
	$fp = fopen($url, 'rb', false, $ctx);
	if (!$fp) {
		echo "HTTP request failed for $url (file download $method)\n";
		return FALSE;
	}
	file_put_contents($filename, $fp); // This works since PHP 5.1.0
	fclose($fp);

	// Test for error
	$fp = fopen($filename, 'r');
	$two = fread($fp, 2);
	fclose($fp);
	if ($two === "{\"") { // This is json
		$json_result = json_decode(file_get_contents($filename), true);
		if ($json_result !== false) {
			if ($json_result["success"] !== "true") {
				print "JSON query failed: ". $json_result['code']. " : ". $json_result['message'] . "\n"; 
			} else {
				print "Server returned JSON instead of binary file\n"; 
			}
			return FALSE;
		}
	}
	return true;
}

?>
