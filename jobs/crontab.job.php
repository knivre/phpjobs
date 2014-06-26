<?php
/**
	@file crontab.job.php
*/
// Ensure this file is not executed directly
if (@constant('JOBS_CONTEXT') != 'worker') exit();

$post_data_restored = restore_post_data();

// require the "command" argument to be provided before even starting the job
if (!isset($arguments['command']) || !strlen(trim($arguments['command']))) {
	$job_state_data['result'] = 'error';
	$job_state_data['error'] = 'no process argument (get or set) provided';
	return TRUE;
}
if ($arguments['command'] != 'get' && $arguments['command'] != 'set') {
	$job_state_data['result'] = 'error';
	$job_state_data['error'] = 'invalid value for process argument provided instead of get or set';
	return TRUE;
}
$command = $arguments['command'];

// also ensure the crontab itself was posted for set operations
if ($command == 'set') {
	if (!$post_data_restored || !isset($_POST['crontab'])) {
		$job_state_data['result'] = 'error';
		$job_state_data['error'] = 'no crontab data';
		return TRUE;
	}
}

job_start();

// ensure the most common bin and sbin directories appear in the PATH
$minimal_path = '/usr/local/bin:/usr/bin:/bin:/sbin:/usr/sbin';
putenv(sprintf('PATH=%s:%s', getenv('PATH'), $minimal_path));

if (!defined('CRONTAB_PATH')) {
	define('CRONTAB_PATH', 'crontab');
}

if ($command == 'get') {
	$rc = -1;
	passthru(constant('CRONTAB_PATH') . ' -l', $rc);
	$job_state_data['return_code'] = $rc;
}
else {
	// Fork "crontab -e" with a pipe to its stdin so we can feed it with the
	// posted crontab.
	$pipes = array();
	$crontab_process = @proc_open(
		constant('CRONTAB_PATH') . ' -',
		array(
			0 /* stdin  */ => array('pipe', 'r'),
			1 /* stdout */ => STDOUT,
			2 /* stderr */ => STDERR,
		),
		$pipes
	);
	if (!is_resource($crontab_process)) {
		$job_state_data['result'] = 'error';
		$job_state_data['error'] = 'unable to fork "crontab -e"';
	}
	else {
		// write the posted crontab to the process' standard input.
		$write = fwrite($pipes[0], $_POST['crontab']);
		if ($write === FALSE) {
			$job_state_data['result'] = 'error';
			$job_state_data['error'] = 'an error occurred when passing data to "crontab -e"';
		}
		$job_state_data['return_code'] = proc_close($crontab_process);
	}
}
return TRUE;
