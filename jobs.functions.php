<?php
/**
	@file jobs.functions.php This file provides generic functions that can be
	used by all Jobs-related scripts.
*/

/**
	Ensure \a $path is an existing directory by creating missing directories
	if needed.
	@return FALSE if \a $path could not be created, TRUE otherwise.
*/
function mkpath($path) {
	if(@mkdir($path) or file_exists($path)) return TRUE;
	return(mkpath(dirname($path)) and mkdir($path));
}

/**
	@return the PHP file defining how to run a \a $job_type job.
*/
function path_for_job_type($job_type) {
	return constant('JOBS_DEFINITION_DIR') . '/' . $job_type . '.job.php';
}

/**
	Trim leading and trailing spaces from \a $string, strips out characters
	other than letters, digits, dash and underscore.
	@return the resulting string, or FALSE if it turns out to be empty.
*/
function clean_string($string) {
	$cleaned_string = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($string));
	if (!strlen($cleaned_string)) return FALSE;
	return $cleaned_string;
}

/**
	Check the \a parameter GET argument was provided before handing it to
	clean_string().
	@return the \a parameter GET argument after clean up, or FALSE if the
	resulting string turns out to be empty.
	@see clean_string()
*/
function clean_get_parameter($parameter) {
	if (isset($_GET[$parameter])) {
		return clean_string($_GET[$parameter]);
	}
	return FALSE;
}


/**
	Check the current HTTP method against those in \a $http_methods
	@param $whitelist States whether \a $http_methods should be considered as a
	white list or black list.
*/
function restrict_http_methods($http_methods, $whitelist = TRUE) {
	if (!isset($_SERVER['REQUEST_METHOD'])) return;
	
	$match = in_array($_SERVER['REQUEST_METHOD'], $http_methods);
	if ($whitelist != $match) {
		header('HTTP/1.1 405 Method Not Allowed');
		exit();
	}
}

/**
	@return the contents of the \a $result array formatted as required in the
	"format" GET argument.
*/
function format_result($result) {
	$required_format = trim($_GET['format']);
	if ($required_format == 'json' && function_exists('json_encode')) {
		return json_encode($result);
	}
	else if ($required_format == 'yaml' && function_exists('yaml_emit')) {
		return yaml_emit($result);
	}
	else if ($required_forat == 'print_r') {
		return print_r($result, TRUE);
	}
	else if ($required_format == 'var_export') {
		return var_export($result, TRUE);
	}
	else {
		// default, text-based output
		return format_text_array($result);
	}
}

/**
	@return the contents of the \a $array array formatted in our own
	text-based format.
*/
function format_text_array($array) {
	$string = '';
	foreach ($array as $key => $value) {
		if (is_array($value)) {
			$string .= sprintf("%s:\n", $key);
			foreach (preg_split("/\n/", format_text_array($value), -1, PREG_SPLIT_NO_EMPTY) as $line) {
				$string .= preg_replace('/^/', '  ', $line) . "\n";
			}
		}
		else {
			$string .= sprintf("%s: %s\n", $key, $value);
		}
	}
	return $string;
}

/**
	@return a \a $length long pseudo random string made of digits and letters.
*/
function pseudo_random_string($length = 10) {
	static $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$string = '';
	for ($i = 0; $i < $length; ++ $i) {
		$string .= $characters[rand(0, strlen($characters) - 1)];
	}
	return $string;
}

/**
	@param $type Job type
	@param $name Job name
	@return the path to the state file for the given job, suffixed with \a $suffix
*/
function state_file_path($type, $name, $suffix = 'state') {
	return sprintf('%s/%s-%s.job.%s', constant('JOBS_STATE_DIR'), $type, $name, $suffix);
}

/**
	Write job state to file.
	@param $data An array describing the state of a job
	@param $type Job type
	@param $name Job name
	@return TRUE if the file writing went perfectly, FALSE otherwise.
*/
function write_state_file($data, $type, $name) {
	$state_file_path = state_file_path($type, $name);
	$tmp_state_file_path = $state_file_path . pseudo_random_string();
	
	$tmp_state_file_handle = fopen($tmp_state_file_path, 'w');
	if ($tmp_state_file_handle === FALSE) return FALSE;
	
	$write = fwrite($tmp_state_file_handle, serialize($data));
	if ($write === FALSE) return FALSE;
	
	$close = fclose($tmp_state_file_handle);
	if ($close === FALSE) return FALSE;
	
	return rename($tmp_state_file_path, $state_file_path);
}

/**
	Read job state from file.
	@param $type Job type
	@param $name Job name
	@return an array describing the state of the given job if the file reading
	went perfectly, FALSE otherwise.
*/
function read_state_file($type, $name) {
	$state_file_path = state_file_path($type, $name);
	return read_state_file_from_path($state_file_path);
}

/**
	@return an array with the contents of the given state file if the file
	reading went perfectly, FALSE otherwise.
*/
function read_state_file_from_path($state_file_path) {
	$read = file_get_contents($state_file_path);
	if ($read === FALSE) return FALSE;
	
	return unserialize($read);
}

/**
	@return an associative array, indexed by .state filenames, holding the
	states of all known jobs, or FALSE in case something went wrong.
*/
function read_all_state_files() {
	$files = scandir(constant('JOBS_STATE_DIR'));
	if ($files === FALSE) return FALSE;
	
	$states = array();
	foreach ($files as $file) {
		if (preg_match('/\.state$/', $file)) {
			$data = read_state_file_from_path(constant('JOBS_STATE_DIR') . '/' . $file);
			if ($data === FALSE) continue;
			$states[$file] = $data;
		}
	}
	return $states;
}

/**
	@param $pid Process ID
	@return an array holding environment variables of the given process.
*/
function get_proc_environment($pid) {
	$environ_contents = file_get_contents("/proc/${pid}/environ");
	if ($environ_contents === FALSE) return FALSE;
	
	$env_vars = array();
	foreach (preg_split('/\x00/', $environ_contents, -1, PREG_SPLIT_NO_EMPTY) as $env_var) {
		$split = explode('=', $env_var, 2);
		if (count($split) == 2) {
			$env_vars[$split[0]] = $split[1];
		}
		else {
			$env_vars[] = $env_var;
		}
	}
	return $env_vars;
}

/**
	@return the name of the user the current process runs as.
*/
function get_username() {
	// According to the PHP documentation: "POSIX functions are enabled by
	// default. You can disable POSIX-like functions with --disable-posix."
	if (function_exists('posix_geteuid')) {
		$effective_uid = posix_geteuid();
		$user_info = posix_getpwuid($effective_uid);
		return $user_info['name'];
	}
	else {
		// fallback to forking whoami, using a hardcoded path
		return trim(`/usr/bin/whoami`);
	}
}
