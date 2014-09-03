<?php
/**
	@file jobs.functions.php This file provides generic functions that can be
	used by all Jobs-related scripts.
*/

/**
	Ensure we can work without PHP's magic quotes interfering in the process.
*/
function handle_php_magic_quotes() {
	// Do nothing if the "get_magic_quotes_gpc" function does not exist.
	if (!function_exists('get_magic_quotes_gpc')) return;

	// What follows is a mere copy/paste from
	// http://www.php.net/manual/en/security.magicquotes.disabling.php
	if (get_magic_quotes_gpc()) {
		$process = array(&$_GET, &$_POST, &$_COOKIE, &$_REQUEST);
		while (list($key, $val) = each($process)) {
			foreach ($val as $k => $v) {
				unset($process[$key][$k]);
				if (is_array($v)) {
					$process[$key][stripslashes($k)] = $v;
					$process[] = &$process[$key][stripslashes($k)];
				}
				else {
					$process[$key][stripslashes($k)] = stripslashes($v);
				}
			}
		}
		unset($process);
	}
}

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
	$required_format = isset($_GET['format']) ? trim($_GET['format']) : '';
	if ($required_format == 'json' && function_exists('json_encode')) {
		return json_encode($result);
	}
	else if ($required_format == 'yaml' && function_exists('yaml_emit')) {
		return yaml_emit($result);
	}
	else if ($required_format == 'print_r') {
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
	$read = @file_get_contents($state_file_path);
	if ($read === FALSE) return FALSE;
	
	return unserialize($read);
}

/**
	Detect whether a set of filters actually refer to a single job.
	@param $filters An array of job filters.
	@param $suffix Suffix to use for the state file
	@return the theoretical path to the state file when there are only one
	'eq' filter on the job type and one 'eq' filter on the job name, i.e. when
	we can directly compute the path to the state file. Note that the existence
	and readability of the state file is not checked by this function. Otherwise
	return FALSE.
*/
function filters_refer_to_single_job($filters, $suffix = 'state') {
	if (count($filters) != 2) return FALSE;

	$type = $name = NULL;
	foreach ($filters as $filter) {
		if ($filter->op != 'eq') return FALSE;

		if ($filter->filter == 'type') {
			$type = $filter->token;
		}
		else if ($filter->filter == 'name') {
			$name = $filter->token;
		}
	}

	if (is_null($type) || is_null($name)) return FALSE;
	return state_file_path($type, $name, $suffix);
}

/**
	@param $filters An array of level 1 job filters (i.e. filters on type or
	name).
	@return an associative array, indexed by .state filenames, holding the
	states of all known jobs, or FALSE in case something went wrong.
*/
function read_all_state_files($filters = array()) {
	$states = array();

	// In order to avoid calling scandir() (which can take quite some time), we
	// intercept an apparently specific but actually very common case: when
	// filters refer to a single job.
	$single_file = filters_refer_to_single_job($filters);
	if ($single_file) {
		$data = read_state_file_from_path($single_file);
		if ($data !== FALSE) {
			$states[basename($single_file)] = $data;
		}
		return $states;
	}

	$files = scandir(constant('JOBS_STATE_DIR'));
	if ($files === FALSE) return FALSE;

	$matches = array();
	foreach ($files as $file) {
		// Ignore directory entries which are not job state files and compute
		// job name and job type from filename.
		// Note: this regexp must match the code from state_file_path().
		if (preg_match('/^([^-]+)-([^.]+)\.job\.state$/', $file, $matches)) {
			// Create a fake, state-like array in order to ...
			$pseudo_job = array('type' => $matches[1], 'name' => $matches[2]);
			// ... apply provided filters. This is important in order to reduce
			// the numbers of state files that will be opened, read and parsed,
			// especially since we already paid the cost of a scandir().
			foreach ($filters as $filter) {
				// Ignore the file as soon as a filter does not match
				if (!$filter->filter($pseudo_job)) continue 2;
			}
			$data = read_state_file_from_path(constant('JOBS_STATE_DIR') . '/' . $file);
			if ($data === FALSE) continue;
			$states[$file] = $data;
		}
	}
	return $states;
}

/**
	Try to remove all files related to a given job: state job, out and err log
	files. Note: error messages are discarded.
	@param $type Job type
	@param $name Job name
	@return FALSE if something went wrong, TRUE otherwise.
*/
function purge_job($type, $name) {
	$return = TRUE;
	foreach (array('state', 'out', 'err') as $extension) {
		$return &= @unlink(state_file_path($type, $name, $extension));
	}
	return $return;
}

/**
	@param $job_state Array describing the state of the job
	@return the time the given job finished, or FALSE if it could not be
	determined.
*/
function get_job_finish_time($job_state) {
	$finish_time = FALSE;
	
	// get finish_time from state file
	if (isset($job_state['finish_time'])) {
		if (preg_match('/^[0-9]{10}$/', $job_state['finish_time'])) {
			$finish_time = $job_state['finish_time'];
		}
	}
	
	// fallback on state file mtime if needed
	if ($finish_time === FALSE) {
		$state_mtime = filemtime(state_file_path($job_state['type'], $job_state['name']));
		if ($state_mtime !== FALSE) $finish_time = $state_mtime;
	}
	
	return $finish_time;
}

/**
	Purge a given job if it is older than \a $max_age seconds.
	@param $job_state Array describing the state of the job
	@see get_job_finish_time()
	@return FALSE if something went wrong, TRUE otherwise.
*/
function purge_job_if_older($job_state, $max_age) {
	$finish_time = get_job_finish_time($job_state);
	if ($finish_time === FALSE) return FALSE;
	
	if (time() > $finish_time + abs($max_age)) {
		return purge_job($job_state['type'], $job_state['name']);
	}
	return TRUE;
}

/**
	Purge former (i.e. finished) jobs according to the PURGE_FORMER_JOBS and
	FORMER_JOBS_MAX_AGE constants.
	Note that invalid state files (i.e. state files missing minimal fields) and
	the associated log files will remain untouched.
*/
function purge_former_jobs() {
	// do nothing unless PURGE_FORMER_JOBS was explicitly set to yes
	if (constant('PURGE_FORMER_JOBS') != 'yes') return;
	
	// require FORMER_JOBS_MAX_AGE to be an integer value
	if (!is_int(constant('FORMER_JOBS_MAX_AGE'))) return;
	
	$jobs = read_all_state_files();
	if ($jobs === FALSE) return;
	
	foreach ($jobs as $job) {
		// ensure all required fields are present
		if (!isset($job['type'], $job['name'], $job['state'])) continue;
		
		// purge only finished jobs
		if ($job['state'] != 'finished') continue;
		
		purge_job_if_older($job, constant('FORMER_JOBS_MAX_AGE'));
	}
}

/**
	@param $pid Process ID
	@return an array holding environment variables of the given process.
*/
function get_proc_environment($pid) {
	$environ_contents = @file_get_contents("/proc/${pid}/environ");
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

/**
	Deliver \a $filepath according to the Range HTTP header.
	Please note this function assumes the file exists and is readable.
	@param $complete_size File size for $filepath
	@return this function exits the script as part of its normal behaviour; it
	returns only in case \a $filepath should be delivered completely.
*/
function deliver_file($filepath, $complete_size) {
	// we simply ignore any Range header if the file is actually empty
	if ($complete_size && isset($_SERVER['HTTP_RANGE'])) {
		$matches = array();
		// No, we do not handle all and every possible range variants, why do you ask?
		if (!preg_match('/bytes=([0-9]+)-([0-9]+)?/', $_SERVER['HTTP_RANGE'], $matches)) {
			header('HTTP/1.1 400 Bad Request');
			exit();
		}
		else {
			// read end of range, fix it if necessary
			if (isset($matches[2])) {
				$end = min($matches[2], $complete_size - 1);
			}
			else {
				$end = $complete_size - 1;
			}
			// read and check start of range
			$start = $matches[1];
			if (($start > $end) || ($start > $complete_size - 1)) {
				header('HTTP/1.1 416 Requested Range Not Satisfiable');
				exit();
			}
			$buffer_size = 4096;
			$bytes_to_read = $end - $start + 1;
			header('HTTP/1.1 206 Partial Content');
			header(sprintf('Content-Range: %d-%d/%d', $start, $end, $complete_size));
			header(sprintf('Content-Length: %d', $bytes_to_read));
			if ($bytes_to_read) {
				$fd = fopen($filepath, 'r');
				if (!$fd) {
					header('HTTP/1.1 500 Internal Server Error');
					exit();
				}
				@fseek($fd, $start);
				while ($bytes_to_read >= $buffer_size) {
					print fread($fd, $buffer_size);
					$bytes_to_read -= $buffer_size;
				}
				if ($bytes_to_read) {
					print fread($fd, $bytes_to_read);
				}
				@fclose($fd);
				exit();
			}
		}
	}
	else {
		header(sprintf('Content-Length: %d', $complete_size));
		readfile($filepath);
	}
}

/**
	Simple class to match a particular field of a given job state against an
	operator and another value.
*/
class JobFilter {
	/**
		@return TRUE if \a $job matches this filter.
	*/
	public function filter($job) {
		// the field used to filter must exist, whatever the operand
		if (!isset($job[$this->filter])) return FALSE;
		$lval = $job[$this->filter];
		
		switch ($this->op) {
			case 'lt':
				return $lval < $this->token;
			case 'le':
				return $lval <= $this->token;
			case 'gt':
				return $lval > $this->token;
			case 'ge':
				return $lval >= $this->token;
			case 'eq':
				return $lval == $this->token;
			case 'ne':
				return $lval != $this->token;
			case 'nm':
				return stripos($lval, $this->token) === FALSE;
			case 'm':
			default:
				return stripos($lval, $this->token) !== FALSE;
		}
	}
	
	/**
		@param $f Filter, i.e. name of the field that will be inspected by this
		filter object.
		@param $t Token, i.e. second operand used when inspecting the \a $f
		field.
		@param $op Operator applied to \a $f and \a $t to determine whether a
		job matches this filter.
	*/
	public function setFilter($f, $t, $o = 'm') {
		$this->filter = $f;
		$this->token = $t;
		$this->op = $o;
	}
	
	public $filter;
	public $token;
	public $op;
};

/**
	Apply NSM (native security mechanism).
	@param $conf Array holding NSM configuration directives.
	TODO cleanup of old sessions?
*/
function nsm_apply($conf) {
	// Arbitrarily considered the request is received when this function is
	// called.
	$conf['request_time'] = time();
	
	// The native security mechanism relies on four HTTP headers.
	// They must be present...
	nsm_require_headers(array('HOST', 'SECURITY', 'SESSION', 'TIMESTAMP'));
	
	// ... and valid.
	nsm_validate_host_header($conf);
	$session = nsm_validate_session_header($conf);
	nsm_validate_timestamp_header($session, $conf);
	nsm_validate_security_header();
	
	// Once headers are verified, compute the security hash.
	$hash = nsm_hash($conf);
	
	// Compare this hash against the provided one.
	if ($hash != $_SERVER['HTTP_X_PHPJOBS_SECURITY']) {
		header('HTTP/1.1 403 Forbidden');
		exit();
	}
	else {
		header('X-PHPJobs-Security: allowed');
	}
}

/**
	Ensure all headers required by the NSM are present.
*/
function nsm_require_headers($headers) {
	if (!is_array($headers)) $headers = array($headers);
	foreach ($headers as $header_name) {
		$full_header_name = 'HTTP_X_PHPJOBS_' . strtoupper($header_name);
		if (!isset($_SERVER[$full_header_name]) || !strlen($_SERVER[$full_header_name])) {
			exit_with_error('Missing security header');
		}
	}
}

/**
	Validate the X-PHPJobs-Host header.
*/
function nsm_validate_host_header($conf) {
	// if enabled, try to validate the provided host against the system hostname
	if ($conf['accept_real_hostname']) {
		$real_hostname = constant('JOBS_HOSTNAME');
		if ($_SERVER['HTTP_X_PHPJOBS_HOST'] == $real_hostname) return TRUE;
		
		$matches = array();
		if (preg_match('/^([^.]+)\./', $real_hostname, $matches)) {
			if ($_SERVER['HTTP_X_PHPJOBS_HOST'] == $matches[1]) return TRUE;
		}
	}
	
	// try to validate the provided host against a list of regular strings
	foreach ($conf['accepted_hosts'] as $accepted_host) {
		if ($_SERVER['HTTP_X_PHPJOBS_HOST'] == $accepted_host) return TRUE;
	}
	
	// try to validate the provided host against a list of regular expressions
	foreach ($conf['accepted_hosts_regexps'] as $accepted_hosts_regexp) {
		if (preg_match($accepted_hosts_regexp, $_SERVER['HTTP_X_PHPJOBS_HOST'])) {
			return TRUE;
		}
	}
	
	exit_with_error('Malformed security header');
}

/**
	Validate the X-PHPJobs-Host header.
*/
function nsm_validate_session_header($conf) {
	$matches = array();
	if (preg_match('/^([A-Za-z0-9][A-Za-z0-9-]{0,254})-([A-Za-z0-9]{24})$/', $_SERVER['HTTP_X_PHPJOBS_SESSION'], $matches)) {
		$dirpath = $conf['nsm_session_dir'] . DIRECTORY_SEPARATOR . $matches[1];
		return array(
			'session' => $_SERVER['HTTP_X_PHPJOBS_SESSION'],
			'hostname' => $matches[1],
			'id' => $matches[2],
			'dirpath' => $dirpath,
			'filepath' => $dirpath . DIRECTORY_SEPARATOR . $matches[2] . '.session'
		);
	}
	
	exit_with_error('Malformed security header');
}

/**
	@return the last known timestamp for the given \a $session.
	TODO file locking
*/
function nsm_get_session_timestamp($session) {
	mkpath($session['dirpath']);
	if (!is_dir($session['dirpath'])) {
		exit_with_error('unable to create session directory ' . $session['dirpath'] );
	}
	
	if (file_exists($session['filepath'])) {
		$session_contents = @file_get_contents($session['filepath']);
		if (!nsm_validate_timestamp($session_contents)) {
			exit_with_error('invalid session');
		}
		return $session_contents;
	}
	return 0;
}

/**
	Set \a $timestamp as last known timestamp for \a $session.
	TODO file locking
*/
function nsm_set_session_timestamp($session, $timestamp) {
	mkpath($session['dirpath']);
	if (!is_dir($session['dirpath'])) {
		exit_with_error('unable to create session directory');
	}
	
	$tmp_filepath = $session['filepath'] . pseudo_random_string();
	if (file_put_contents($tmp_filepath, $timestamp) === FALSE) {
		exit_with_error('unable to write session');
	}
	if (!rename($tmp_filepath, $session['filepath'])) {
		exit_with_error('unable to write session');
	}
}

/**
	Validate the X-PHPJobs-Timestamp header.
*/
function nsm_validate_timestamp_header($session, $conf) {
	// the provided timestamp header must look like a timestamp
	if (!nsm_validate_timestamp($_SERVER['HTTP_X_PHPJOBS_TIMESTAMP'])) {
		exit_with_error('Malformed security header');
	}
	
	// the provided timestamp must not be older than max_age
	$timestamp = @array_shift(explode('.', $_SERVER['HTTP_X_PHPJOBS_TIMESTAMP']));
	if ($conf['request_time'] - $timestamp > $conf['max_age']) {
		exit_with_error('provided timestamp is too old (trying to reuse former request?)');
	}
	
	// retrieve the timestamp of the last request for the provided session
	$session_timestamp = nsm_get_session_timestamp($session);
	
	if ($session_timestamp !== 0) {
		// get rid of the dot in both timestamps
		$session_timestamp = str_replace('.', '', $session_timestamp);
		$user_timestamp = str_replace('.', '', $_SERVER['HTTP_X_PHPJOBS_TIMESTAMP']);
		
		// the provided timestamp must be strictly greater than the current one for this session
		if ($user_timestamp <= $session_timestamp) {
			exit_with_error('provided timestamp is too old for this session (trying to reuse former request?)');
		}
	}
	
	// update last known timestamp for this session
	nsm_set_session_timestamp($session, $_SERVER['HTTP_X_PHPJOBS_TIMESTAMP']);
	
	return TRUE;
}

/**
	@return TRUE if \a $timestamp matches the expected format, false otherwise.
*/
function nsm_validate_timestamp($timestamp) {
	return preg_match('/^[0-9]{10}\.[0-9]{4}$/', $timestamp);
}

/**
	Validate the X-PHPJobs-Security header.
*/
function nsm_validate_security_header() {
	if (preg_match('/^[0-9a-f]{64}$/', $_SERVER['HTTP_X_PHPJOBS_SECURITY'])) {
		return TRUE;
	}
	
	exit_with_error('Malformed security header');
}

/**
	@return the SHA256 hash of all sent data.
	This hash is meant to be compared with the X-PHPJobs-Security header.
*/
function nsm_hash($conf) {
	$hash_ctx = hash_init('sha256');
	
	// Data are concatenated following a particular syntax before being hashed
	$hash_string = sprintf(
		'%s:%s@%s?%s&%s&',
		$_SERVER['HTTP_X_PHPJOBS_SESSION'],
		$_SERVER['HTTP_X_PHPJOBS_TIMESTAMP'],
		$_SERVER['HTTP_X_PHPJOBS_HOST'],
		$_SERVER['QUERY_STRING'],
		$conf['secret']
	);
	
	hash_update($hash_ctx, $hash_string);
	// hash POST data without allocating a potentially huge string for them
	hash_update_file($hash_ctx, 'php://input');
	return hash_final($hash_ctx);
}
