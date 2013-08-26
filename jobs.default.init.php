<?php
// use hostname as identifier
define('JOBS_IDENTIFIER', constant('JOBS_HOSTNAME'));

if (constant('JOBS_CONTEXT') == 'manager') {
	// This is where you will want to take care of security by yourself:
	// just exit() if you do not find what you expect.
}

if (constant('JOBS_CONTEXT') == 'worker') {
	// You may also want to ensure your worker script is executed with
	// php-cli only... just a hint.
}
