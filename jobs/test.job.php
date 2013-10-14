<?php
/**
	@file test.job.php This is a dummy test job that simply sleeps for 60
	seconds.
	The following functions are made available to any job:
	  * job_start() should be called at the beginning of the treatment
	  * update_job_state() provides a convenient way to update $job_state_data and
	sync its contents to the job state file;
	  * restore_post_data() will restore any $_POST and $_FILES data
	  * write_error() provides a convenient way to log an error message
	The following global variables are also available:
	  * $arguments, an array providing received parameters
	  * $job_state_data, the array describing the current job state.
*/
// Ensure this file is not executed directly
if (@constant('JOBS_CONTEXT') != 'worker') exit();

// this will update the adequate state file with start time.
job_start();

// GET parameters passed to the job manager through HTTP are handed as command
// line arguments.
$sleep_time = 60;
if (isset($arguments['sleep_time']) && preg_match('/^[0-9]+$/', $arguments['sleep_time'])) {
	$sleep_time = $arguments['sleep_time'];
}

// example of stderr output
fprintf(STDERR, "Remember, kids, jobs can write on both stdout and stderr.\n");

// example of stdout output + dummy treatment
print "About to sleep for ${sleep_time} seconds\n";
sleep($sleep_time);
print "Slept for ${sleep_time} seconds\n";
