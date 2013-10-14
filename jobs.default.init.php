<?php
// use hostname as identifier
define('JOBS_IDENTIFIER', constant('JOBS_HOSTNAME'));

if (constant('JOBS_CONTEXT') == 'manager') {
	// Here is a good place to enforce some PHP directives...
	error_reporting(0);
	ini_set('display_errors', '0');
	
	// This is where you will want to take care of security by yourself:
	// just exit() if you do not find what you expect.
	
	// Ok, since you do not look quite enthusiastic about it, we still provide a
	// native security mechanism (NSM). Please refer to the "SECURITY" file for
	// explanations about how it works.
	$use_nsm = TRUE;
	
	$nsm_conf = array(
		// The default value is actually a random string, making the service
		// unusable so you *have* to change it...
		'secret' => substr(md5(rand()), 0, 12),
		// example:
		// 'secret' => 'UyoYf7rZVGI',
		// Control validation of the X-PHPJobs-Host header.
		'accept_real_hostname' => TRUE,
		'accepted_hosts' => array(),
		'accepted_hosts_regexps' => array(),
		// Control validation of the X-PHPJobs-Timestamp header.
		// Requests having a timestamp older than max_age are denied.
		'max_age' => 10,
		// Control validation of the X-PHPJobs-Session header.
		'nsm_session_dir' => dirname(__FILE__) . DIRECTORY_SEPARATOR . 'sessions',
	);
	
	if (isset($use_nsm) && $use_nsm) nsm_apply($nsm_conf);
}

if (constant('JOBS_CONTEXT') == 'worker') {
	// Here is another good place to enforce some PHP directives...
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
	
	// You may also want to ensure your worker script is executed with
	// php-cli only... just a hint.
}

// By default, purge finished jobs that are more than five days old
define('PURGE_FORMER_JOBS', 'yes');
define('FORMER_JOBS_MAX_AGE', 5 * 24 * 3600);
