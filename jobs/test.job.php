<?php
/**
	@file test.job.php This is a dummy test job that simply sleeps for 60
	seconds.
	The following functions are made available to any job:
	  * update_job_state() provides a convenient way to update $job_state_data and
	sync its contents to the job state file;
	  * write_error() provides a convenient way to log an error message
	The following global variables are also available:
	  * $arguments, an array providing received parameters
	  * $job_state_data, the array describing the current job state.
*/

// jobs have to update the state to "running"
update_job_state(array('state' => 'running'));

$sleep_time = 60;
if (isset($arguments['sleep_time']) && preg_match('/^[0-9]+$/', $arguments['sleep_time'])) {
	$sleep_time = $arguments['sleep_time'];
}
sleep($sleep_time);
