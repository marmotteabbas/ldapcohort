<?php

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    /*error_reporting(E_ALL);
    $__do = optional_param('__do', null, PARAM_ALPHA);
    
    if (null !== $__do && $__do == 'ldapcohortsync') {
        $enrol = enrol_get_plugin('ldapcohort');
		$trace = new text_progress_trace();
		// Update enrolments -- these handlers should autocreate cohortes if required.
		
		$enrol->sync_cohorts($trace);
		
		exit(0);
    }
    */
    require_once(dirname(__FILE__) . '/settingslib.php');
    
    $run_sync = '<a target="_blank" href="' . $CFG->wwwroot . '/enrol/ldapcohort/sync.php"><strong style="font-size: 110%">' . get_string('here', 'enrol_ldapcohort') . '</strong></a>';
    
    //--- heading ---
    $settings->add(new admin_setting_heading('enrol_ldapcohort_settings', '', get_string('pluginname_desc', 'enrol_ldapcohort', $run_sync)));
    
    if (!function_exists('ldap_connect')) {
        $settings->add(new admin_setting_heading('enrol_phpldapcohort_noextension', '', get_string('phpldap_noextension', 'enrol_ldapcohort')));
    } else {
        require_once($CFG->dirroot.'/enrol/ldapcohort/settingslib.php');
        require_once($CFG->libdir.'/ldaplib.php');
        require_once(dirname(__FILE__).'/lib.php');
        

$userfields = array('username'=>'uid','idnumber'=>'uid','firstname'=>'givenName','lastname'=>'sn','email'=>'mail' );
$cohortfields = array ('name'=>'cn', 'idnumber'=>'cn', 'description'=>'description');

        $yesno = array(get_string('no'), get_string('yes'));
        
        //--- general settings ---
        $settings->add(new admin_setting_heading('enrol_ldapgitgeneral_settings', get_string('general_settings', 'enrol_ldapcohort'), ''));
        $options = array(0=>'cron', 1=>'login');
        $settings->add(new admin_setting_configselect('enrol_ldapcohort/login_sync', get_string('login_sync_key', 'enrol_ldapcohort'), get_string('login_sync', 'enrol_ldapcohort'), 1,$options));
        $settings->add(new admin_setting_configselect('enrol_ldapcohort/cron_enabled', get_string('cron_enabled_key', 'enrol_ldapcohort'), get_string('cron_enabled', 'enrol_ldapcohort', $run_sync), 1, $yesno));
        $settings->add(new admin_setting_configselect('enrol_ldapcohort/email_report_enabled', get_string('email_report_enabled_key', 'enrol_ldapcohort'), get_string('email_report_enabled', 'enrol_ldapcohort'), 1, $yesno));
        $settings->add(new admin_setting_ldapcohort_trim_lower('enrol_ldapcohort/email_report', get_string('email_report_key', 'enrol_ldapcohort'), get_string('email_report', 'enrol_ldapcohort'), '', true));
        $settings->add(new admin_setting_configcheckbox('enrol_ldapcohort/autocreate_cohorts', get_string('autocreate_cohorts_key', 'enrol_ldapcohort'), get_string('autocreate_cohorts', 'enrol_ldapcohort'), false));
        $settings->add(new admin_setting_configcheckbox('enrol_ldapcohort/autocreate_users', get_string('autocreate_users_key', 'enrol_ldapcohort'), get_string('autocreate_users', 'enrol_ldapcohort'), false));
        $settings->add(new admin_setting_configcheckbox('enrol_ldapcohort/debug_mode', get_string('debug_mode_key', 'enrol_ldapcohort'), get_string('debug_mode', 'enrol_ldapcohort'), false));
        
        
        //--- connection settings ---
        $settings->add(new admin_setting_heading('enrol_ldap_cohort_server_settings', get_string('server_settings', 'enrol_ldapcohort'), ''));
        $settings->add(new admin_setting_configtext('enrol_ldapcohort/host_url', get_string('host_url_key', 'enrol_ldapcohort'), get_string('host_url', 'enrol_ldapcohort'), ''));
        $settings->add(new admin_setting_configselect('enrol_ldapcohort/start_tls', get_string('start_tls_key', 'auth_ldap'), get_string('start_tls', 'auth_ldap'), 0, $yesno));
        // Set LDAPv3 as the default. Nowadays all the servers support it and it gives us some real benefits.
        $options = array(3=>'3', 2=>'2');
        $settings->add(new admin_setting_configselect('enrol_ldapcohort/ldap_version', get_string('version_key', 'enrol_ldapcohort'), get_string('version', 'enrol_ldapcohort'), 3, $options));
        $settings->add(new admin_setting_configtext('enrol_ldapcohort/ldapencoding', get_string('ldap_encoding_key', 'enrol_ldapcohort'), get_string('ldap_encoding', 'enrol_ldapcohort'), 'utf-8'));
        $settings->add(new admin_setting_configtext_trim_lower('enrol_ldapcohort/pagesize', get_string('pagesize_key', 'auth_ldap'), get_string('pagesize', 'auth_ldap'), LDAP_DEFAULT_PAGESIZE, true));

        //--- bind settings
        $settings->add(new admin_setting_heading('enrol_ldapcohort_bind_settings', get_string('bind_settings', 'enrol_ldapcohort'), ''));
        $settings->add(new admin_setting_configtext_trim_lower('enrol_ldapcohort/bind_dn', get_string('bind_dn_key', 'enrol_ldapcohort'), get_string('bind_dn', 'enrol_ldapcohort'), ''));
        $settings->add(new admin_setting_configpasswordunmask('enrol_ldapcohort/bind_pw', get_string('bind_pw_key', 'enrol_ldapcohort'), get_string('bind_pw', 'enrol_ldapcohort'), ''));
        
        //--- cohort lookup settings
        $settings->add(new admin_setting_heading('enrol_ldapcohort_cohort', get_string('cohort_lookup', 'enrol_ldapcohort'), ''));
        $settings->add(new admin_setting_configtext('enrol_ldapcohort/group_objectclass', get_string('objectclass_key', 'enrol_ldapcohort'), get_string('objectclass', 'enrol_ldapcohort'), 'posixGroup'));
        $settings->add(new admin_setting_configtext('enrol_ldapcohort/group_filter', get_string('filter_key', 'enrol_ldapcohort'), get_string('filter', 'enrol_ldapcohort'), '(cn=*)'));
        $settings->add(new admin_setting_configtext('enrol_ldapcohort/group_contexts', get_string('group_contexts_key', 'enrol_ldapcohort'), get_string('group_contexts', 'enrol_ldapcohort'), ''));
        $settings->add(new admin_setting_configselect('enrol_ldapcohort/group_search_sub', get_string('search_subcontexts_key', 'enrol_ldapcohort'), get_string('group_search_sub', 'enrol_ldapcohort'), key($yesno), $yesno));
        $settings->add(new admin_setting_ldapcohort_trim_lower('enrol_ldapcohort/group_member_attribute', get_string('group_member_attribute_key', 'enrol_ldapcohort'), get_string('group_member_attribute', 'enrol_ldapcohort'), 'member', true));
        $settings->add(new admin_setting_configtext('enrol_ldapcohort/memberattribute_is', get_string('memberattribute_is_key', 'enrol_ldapcohort'), get_string('memberattribute_is', 'enrol_ldapcohort'), 'dn'));
        $settings->add(new admin_setting_ldapcohort_trim_lower('enrol_ldapcohort/cohort_syncing_field', get_string('cohort_syncing_field_key', 'enrol_ldapcohort'), get_string('cohort_syncing_field', 'enrol_ldapcohort'), 'idnumber', true));
        foreach ($cohortfields as $key => $field) {
            $settings->add(new admin_setting_ldapcohort_trim_lower('enrol_ldapcohort/cohort_'.$key, get_string('cohort_'.$key.'_key', 'enrol_ldapcohort'), get_string('cohort_'.$key, 'enrol_ldapcohort'), $field , true));
        }
        
        if (!during_initial_install()) {
            require_once($CFG->dirroot.'/course/lib.php');
            $options = get_category_options();
            $settings->add(new admin_setting_configselect('enrol_ldapcohort/context', get_string('cohort_context_key', 'enrol_ldapcohort'), get_string('cohort_context', 'enrol_ldapcohort'), key($options), $options));
        }
        
        //--- user lookup settings
        $settings->add(new admin_setting_heading('enrol_ldap_cohort_user_settings', get_string('user_lookup', 'enrol_ldapcohort'), ''));
        $usertypes = ldap_supported_usertypes();
        $settings->add(new admin_setting_configselect('enrol_ldapcohort/user_type', get_string('user_type_key', 'enrol_ldapcohort'), get_string('user_type', 'enrol_ldapcohort'), end($usertypes), $usertypes));
        $opt_deref = array();
        $opt_deref[LDAP_DEREF_NEVER] = get_string('no');
        $opt_deref[LDAP_DEREF_ALWAYS] = get_string('yes');
        $settings->add(new admin_setting_configselect('enrol_ldapcohort/user_deref', get_string('user_dereference_key', 'enrol_ldapcohort'), get_string('user_dereference', 'enrol_ldapcohort'), key($opt_deref), $opt_deref));
        $settings->add(new admin_setting_configtext('enrol_ldapcohort/user_contexts', get_string('user_contexts_key', 'enrol_ldapcohort'), get_string('user_contexts', 'enrol_ldapcohort'), ''));
        $settings->add(new admin_setting_configselect('enrol_ldapcohort/user_search_sub', get_string('search_subcontexts_key', 'enrol_ldapcohort'), get_string('user_search_sub', 'enrol_ldapcohort'), key($yesno), $yesno));
        $settings->add(new admin_setting_ldapcohort_trim_lower('enrol_ldapcohort/memberof_attribute', get_string('memberof_attribute_key', 'enrol_ldapcohort'), get_string('memberof_attribute', 'enrol_ldapcohort'), 'memberUid', true));
        $settings->add(new admin_setting_configtext('enrol_ldapcohort/memberofattribute_is', get_string('memberofattribute_is_key', 'enrol_ldapcohort'), get_string('memberofattribute_is', 'enrol_ldapcohort'), 'dn'));
        $settings->add(new admin_setting_configtext('enrol_ldapcohort/user_objectclass', get_string('objectclass_key', 'enrol_ldapcohort'), get_string('user_objectclass', 'enrol_ldapcohort'), ''));
        foreach ($userfields as $key =>  $field) {
            $settings->add(new admin_setting_ldapcohort_trim_lower('enrol_ldapcohort/user_'.$key, get_string('user_'.$key.'_key', 'enrol_ldapcohort'), get_string('user_'.$key, 'enrol_ldapcohort'), $field , true));
        }
        $settings->add(new admin_setting_heading('enrol_ldapcohort_nested_groups_settings', get_string('nested_groups_settings', 'enrol_ldapcohort'), ''));
        $options = $yesno;
        $settings->add(new admin_setting_configselect('enrol_ldapcohort/nested_groups', get_string('nested_groups_key', 'enrol_ldapcohort'), get_string('nested_groups', 'enrol_ldapcohort'), 0, $options));
        
    }
}
