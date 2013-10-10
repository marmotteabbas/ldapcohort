<?php
if (defined('STDIN')) {
	    fwrite(STDERR, "ERROR: This script no longer supports CLI, please use admin/cli/cron.php instead\n");
	        exit(1);
}

// This is a fake CLI script, it is a really ugly hack which emulates
// CLI via web interface, please do not use this hack elsewhere
define('CLI_SCRIPT', true);
define('WEB_CRON_EMULATED_CLI', 'defined'); // ugly ugly hack, do not use elsewhere please
define('NO_OUTPUT_BUFFERING', true);

require('../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->libdir.'/cronlib.php');
// extra safety
session_get_instance()->write_close();

// Ensure errors are well explained.
$CFG->debug = DEBUG_DEVELOPER;

if (!enrol_is_enabled('ldapcohort')) {
    cli_error(get_string('pluginnotenabled', 'enrol_ldapcohort'), 2);
}
$starttime = microtime();
/** @var enrol_ldap_plugin $enrol */
$enrol = enrol_get_plugin('ldapcohort');

$trace = new text_progress_trace();

// Update enrolments -- these handlers should autocreate cohortes if required.

        $enrol->sync_cohorts($trace);

$difftime = microtime_diff($starttime, microtime());
$trace->output("Execution took ".$difftime." seconds");
$trace->finished();
exit(0);
