<?php
/**
	@file jobs.php This script implements the Jobs manager -- it is intended
	to be reached over HTTP in order to create, monitor and possibly kill
	jobs.
*/

// Who am I?
define('JOBS_CONTEXT', 'manager');

// Where am I?
define('JOBS_SCRIPT_PATH', realpath(__FILE__));
define('JOBS_BASE_PATH', dirname(constant('JOBS_SCRIPT_PATH')));

header('Content-Type: text/plain');
require_once(constant('JOBS_BASE_PATH') . '/jobs.common.php');

// What are we supposed to do?
if (!isset($_GET['action'])) {
	exit_with_error('no action specified.');
}

switch ($_GET['action']) {
	case 'new':
		$result = jobs_new();
		break;
	case 'list':
		$result = jobs_list();
		break;
	case 'status';
		$result = jobs_status();
		break;
	case 'kill';
		$result = jobs_kill();
		break;
	case 'output';
		$result = jobs_output();
		break;
	default:
		exit_with_error('invalid action specified.');
}

print format_result($result);

/**
	@return The type of the job to be created, according to GET parameters.
*/
function new_job_type() {
	return clean_get_parameter('type');
}

/**
	@return The name of the job to be created, according to GET parameters.
	Provided job names get suffixed with a random string as a poor man's
	unique identifier.
*/
function new_job_name() {
	$job_name_prefix = clean_get_parameter('name');
	$job_name = $job_name_prefix ? $job_name_prefix . '-' : '';
	$job_name .= pseudo_random_string();
	return $job_name;
}

/**
	@return data about the newly created job.
*/
function jobs_new() {
	$result = array();
	
	$type = new_job_type();
	if (!$type) {
		exit_with_error('invalid or empty job type given');
	}
	if (!file_exists(path_for_job_type($type))) {
		exit_with_error('no such job type');
	}
	$name = new_job_name();
	$result['job-type'] = $type;
	$result['job-name'] = $name;
	$result['comment'] = sprintf('You requested a %s job; it will be named %s.', $type, $name);
	
	// We want our job to be run by an independent php-cli process named "jobs-worker".
	$worker_path = constant('JOBS_BASE_PATH') . '/jobs-worker.php';
	// compose the adequate PHP command with base arguments
	$php_command = sprintf(
		'%s %s %s %s ',
		constant('PHP_BIN_PATH'),
		escapeshellarg($worker_path),
		escapeshellarg('type=' . $type),
		escapeshellarg('name=' . $name)
	);
	// add extra arguments
	foreach ($_GET as $key => $value) {
		if (in_array($key, array('action', 'type', 'name', 'format'))) continue;
		$php_command .= ' ' . escapeshellarg($key . '=' . $value);
	}
	
	// Redirect the stdout and stderr of the forked process to
	// specific log files.
	$out_log_file = state_file_path($type, $name, 'out');
	$err_log_file = state_file_path($type, $name, 'err');
	
	// We assume Perl is more likely to be available than pcntl_* functions.
	// This small Perl script can be used to daemonize a process
	$perl_one_liner = <<<EOF
use POSIX qw(setsid);
exit() if (fork());
setsid();
open(STDIN, q[/dev/null]);
open(STDOUT, q[/dev/null]);
open(STDERR, q[/dev/null]);
exec(sprintf(q[%s 1> %s 2> %s], join(q[ ], @ARGV), q[${out_log_file}], q[${err_log_file}]));
EOF;
	
	// Use it to daemonize our PHP command.
	$final_command = sprintf(
		'%s -e %s -- %s',
		constant('PERL_BIN_PATH'),
		escapeshellarg($perl_one_liner),
		$php_command
	);
	system($final_command);
	
	return $result;
}

/**
	@return the status of all jobs matching the "filter" and "token" GET
	parameters.
*/
function jobs_list() {
	$jobs = read_all_state_files();
	$filter = clean_get_parameter('filter');
	$token = clean_get_parameter('token');
	if ($filter && $token) {
		$jobs = array_filter(
			$jobs,
			function($state) use ($filter, $token) {
				return (isset($state[$filter]) && stripos($state[$filter], $token) !== FALSE);
			}
		);
	}
	return $jobs;
}

/**
	@return the detailed status of all jobs which
	  * match the "filter" and "token" GET parameters
	  * are still in the "running" state
	The detailed status includes whether the known pid still matches a running
	process along with technical data about this process.
*/
function jobs_status() {
	// status basically extends the "list" action
	$filtered_jobs = jobs_list();
	foreach ($filtered_jobs as &$job_state) {
		// this operation makes sense only for running jobs
		if (!isset($job_state['state']) || $job_state['state'] != 'running') continue;
		
		// also, we need a valid worker PID
		if (!isset($job_state['worker-pid'])) continue;
		if (!preg_match('/^[0-9]+$/', $job_state['worker-pid'])) continue;
		$pid = $job_state['worker-pid'];
		
		if (!file_exists("/proc/${pid}/exe")) {
			$job_state['worker-status'] = 'not-running';
			continue;
		}
		
		$job_state['worker-status'] = 'running';
		$job_state['proc_info'] = `/bin/ls -l --time-style="+%F %T" /proc/${pid}/exe /proc/${pid}/cwd /proc/${pid}/fd`;
		$job_state['proc_cmdline'] = `cat /proc/${pid}/cmdline`;
		$env_vars = get_proc_environment($pid);
		if ($env_vars !== FALSE) $job_state['proc_environ'] = $env_vars;
		$job_state['proc_tree'] = "\n" . `/usr/bin/pstree -napuc ${pid}`;
	}
	return $filtered_jobs;
}

/**
	TODO
*/
function jobs_kill() {
}

/**
	TODO
*/
function jobs_output() {
}

/**
	Output \a $error_message as a HTTP header then exit.
*/
function exit_with_error($error_message) {
	header('HTTP/1.1 412 Precondition failed');
	header('X-jobs-error: ' . $error_message);
	exit();
}
