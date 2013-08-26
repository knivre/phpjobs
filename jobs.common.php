<?php
/**
	@file jobs.common.php This file provides the common code executed by all
	Jobs scripts at startup.
*/

// Ensure generic functions are available.
require_once(constant('JOBS_BASE_PATH') . '/jobs.functions.php');

// Retrieve username and hostname to compose "identifiers".
define('JOBS_USERNAME', get_username());
define('JOBS_HOSTNAME', php_uname('n'));

// We need to distinguish ourselves, typically to determine where to store
// state files -- create a list of possible identifiers.
$identifiers = array(
	constant('JOBS_USERNAME') . '-' . constant('JOBS_HOSTNAME'),
	constant('JOBS_HOSTNAME'),
	'default'
);

// Include init script -- this script is expected to take care of security
// checks and environment initialization for the rest of execution.
foreach ($identifiers as $identifier) {
	$init_script = constant('JOBS_BASE_PATH') . '/jobs.' . $identifier . '.init.php';
	if (file_exists($init_script) && is_readable($init_script)) {
		if (include($init_script)) {
			define('JOBS_INIT_SCRIPT', $init_script);
			break;
		}
	}
}
if (!defined('JOBS_INIT_SCRIPT')) {
	exit_with_error('No init script found, aborting.');
}

if (!defined('JOBS_IDENTIFIER')) {
	// damn, the init script did not bother defining the identifier
	define('JOBS_IDENTIFIER', $identifiers[0]);
}

if (!defined('JOBS_DEFINITION_DIR')) {
	// This directory is expected to provide definitions of known jobs
	define('JOBS_DEFINITION_DIR', constant('JOBS_BASE_PATH') . '/jobs');
}
if (!defined('JOBS_STATE_DIR')) {
	// This directory will be used to keep track of running jobs
	define('JOBS_STATE_DIR', constant('JOBS_BASE_PATH') . '/run/' .constant('JOBS_IDENTIFIER'));
}

// default value for the path to PHP
if (!defined('PHP_BIN_PATH')) {
	define('PHP_BIN_PATH', '/usr/bin/php');
}

// default value for the path to Perl
if (!defined('PERL_BIN_PATH')) {
	define('PERL_BIN_PATH', '/usr/bin/perl');
}

// ensure JOBS_STATE_DIR exists
if (!mkpath(constant('JOBS_STATE_DIR'))) {
	exit_with_error(sprintf('unable to create jobs state directory %s', constant('JOBS_STATE_DIR')));
}
