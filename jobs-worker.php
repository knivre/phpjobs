<?php
/**
	@file jobs-worker.php This script implements a Jobs worker -- it is
	typically forked by the Jobs manager in order to start and follow
	the job execution itself.
*/

// Who am I?
define('JOBS_CONTEXT', 'worker');

// Where am I?
define('JOBS_SCRIPT_PATH', realpath(__FILE__));
define('JOBS_BASE_PATH', dirname(constant('JOBS_SCRIPT_PATH')));

set_time_limit(0);
require_once(constant('JOBS_BASE_PATH') . '/jobs.common.php');

// What job are we supposed to run?
$arguments = parse_cli_arguments();

// ensure type and name were provided
if (!isset($arguments['name']) || !strlen(trim($arguments['name']))) {
	exit_with_error('no name provided');
}
if (!isset($arguments['type']) || !strlen(trim($arguments['type']))) {
	exit_with_error('no type provided');
}
$type = $arguments['type'];
$name = $arguments['name'];

update_job_state(array('state' => 'acknowledged-by-worker'));

$job_path = path_for_job_type($arguments['type']);
if (!include($job_path)) {
	$error = sprintf('unknown job type: %s', $type);
	update_job_state(array('state' => 'failed', 'error' => $error));
	exit_with_error($error);
}
else {
	$job_state_data['state'] = 'finished';
	$job_state_data['finish_time'] = time();
	// set default result attribute in case the job did not set it.
	if (!isset($job_state_data['result'])) {
		$job_state_data['result'] = 'ok';
	}
	write_state_file($job_state_data, $type, $name);
}

// clean up old .state, .out and .err files
purge_former_jobs();

/**
	@return an associative array, indexed with arguments names, holding
	arguments values.
*/
function parse_cli_arguments() {
	$argv = $GLOBALS['argv'];
	$arguments = array();
	for ($i = 1; $i < count($argv); ++ $i) {
		$arg = $argv[$i];
		$matches = array();
		if (preg_match('/^([a-zA-Z0-9-_.]+)=(.+)?$/', $arg, $matches)) {
			$arg_name = $matches[1];
			$arg_value = $matches[2];
			$arguments[$arg_name] = $arg_value;
		}
	}
	return $arguments;
}

/**
	Output a timestamp \a $error_message along with the worker pid on stderr.
*/
function write_error($error_message) {
	foreach (preg_split("/\n/", $error_message) as $line) {
		fprintf(STDERR, "[%s][worker-%d] %s\n", strftime('%F:%T'), getmypid(), $error_message);
	}
}

/**
	Output a timestamp \a $error_message along with the worker pid on stderr
	then exit with a non-zero return-code.
*/
function exit_with_error($error_message) {
	write_error($error_message);
	exit(2);
}

/**
	Update the $job_state_data global array with the contents of \a $array
	then update the state file.
*/
function update_job_state($array) {
	if (!isset($GLOBALS['job_state_data'])) {
		$GLOBALS['job_state_data'] = array(
			'type' => $GLOBALS['type'],
			'name' => $GLOBALS['name'],
			'worker-pid' => getmypid(),
			'last_update_time' => time(),
		);
	}
	// update the global-scope object
	foreach ($array as $key => $value) {
		$GLOBALS['job_state_data'][$key] = $value;
	}
	// update the state file accordingly
	write_state_file($GLOBALS['job_state_data'], $GLOBALS['type'], $GLOBALS['name']);
}

/**
	Set the job as freshly started; this will store the start time in the
	adequate state file and change the job state to "running".
	@see update_job_state
*/
function job_start() {
	update_job_state(
		array(
			'state' => 'running',
			'start_time' => time(),
		)
	);
}
