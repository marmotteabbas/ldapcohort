<?php
define('CLI_SCRIPT', true);

require(__DIR__.'/../../../config.php');
require_once("$CFG->libdir/clilib.php");

// Ensure errors are well explained.
$CFG->debug = DEBUG_DEVELOPER;

if (!enrol_is_enabled('ldapcohort')) {
    cli_error(get_string('pluginnotenabled', 'enrol_ldapcohort'), 2);
}

/** @var enrol_ldap_plugin $enrol */
$enrol = enrol_get_plugin('ldapcohort');

$trace = new text_progress_trace();

// Update enrolments -- these handlers should autocreate cohortes if required.

        $enrol->sync_cohorts($trace);



exit(0);
