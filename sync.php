<?php

require('../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->libdir.'/cronlib.php');
// extra safety
//session_get_instance()->write_close();

// Ensure errors are well explained.
$CFG->debug = DEBUG_DEVELOPER;

if (!enrol_is_enabled('ldapcohort')) {
    cli_error(get_string('pluginnotenabled', 'enrol_ldapcohort'), 2);
}
$starttime = microtime();
/** @var enrol_ldap_plugin $enrol */
$enrol = enrol_get_plugin('ldapcohort');

$trace = new html_progress_trace();

// Update enrolments -- these handlers should autocreate cohortes if required.

        $enrol->update_users($trace);

$difftime = microtime_diff($starttime, microtime());
$trace->output("Execution took ".$difftime." seconds");
$trace->finished();
exit(0);
