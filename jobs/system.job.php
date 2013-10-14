<?php
/**
	@file system.job.php
*/
// Ensure this file is not executed directly
if (@constant('JOBS_CONTEXT') != 'worker') exit();

// require at least one "command" argument before even starting the job
$command = NULL;
if (isset($arguments['command']) && strlen(trim($arguments['command']))) {
	$command = $arguments['command'];
}
else if (restore_post_data() && isset($_POST['command']) && strlen(trim($_POST['command']))) {
	$command = $_POST['command'];
}
if (is_null($command)) {
	$job_state_data['result'] = 'error';
	$job_state_data['error'] = 'no command provided';
	return TRUE;
}

job_start();

// ensure the most common bin and sbin directories appear in the PATH
$minimal_path = '/usr/local/bin:/usr/bin:/bin:/sbin:/usr/sbin';
putenv(sprintf('PATH=%s:%s', getenv('PATH'), $minimal_path));

if (isset($arguments['cwd']) && strlen($arguments['cwd'])) {
	chdir($arguments['cwd']);
}
else if (isset($_POST['cwd']) && strlen($_POST['cwd'])) {
	chdir($_POST['cwd']);
}

$rc = -1;
system($command, $rc);
$job_state_data['return_code'] = $rc;
