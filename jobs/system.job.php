<?php
/**
	@file system.job.php
*/
// Ensure this file is not executed directly
if (@constant('JOBS_CONTEXT') != 'worker') exit();

$post_data_restored = restore_post_data();

// require at least one "command" argument before even starting the job
$command = NULL;
if (isset($arguments['command']) && strlen(trim($arguments['command']))) {
	$command = $arguments['command'];
}
else if ($post_data_restored && isset($_POST['command']) && strlen(trim($_POST['command']))) {
	$command = $_POST['command'];
}
if (is_null($command)) {
	$job_state_data['result'] = 'error';
	$job_state_data['error'] = 'no command provided';
	return TRUE;
}

job_start();

// Take POST environment variables into account
if ($post_data_restored) {
	for ($i = 0; ; ++ $i) {
		$arg_name = 'env' . $i;
		if (!isset($_POST[$arg_name])) break;
		if (strlen($_POST[$arg_name])) putenv($_POST[$arg_name]);
	}
}

// Take GET environment variables into account
for ($i = 0; ; ++ $i) {
	$arg_name = 'env' . $i;
	if (!isset($arguments[$arg_name])) break;
	if (strlen($arguments[$arg_name])) putenv($arguments[$arg_name]);
}

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
