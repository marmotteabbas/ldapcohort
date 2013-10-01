<?php

defined('MOODLE_INTERNAL') || die();

class enrol_ldapcohort_plugin extends enrol_plugin
{
    protected $enrol_localcoursefield = 'idnumber';
    protected $enroltype = 'enrol_ldapcohort';
    protected $errorlogtag = '[ENROL LDAPCOHORT] ';
    private $_cohorts_added = 0;
    private $_cohorts_existing = 0;
    private $_users_added = 0;
    private $_users_existing = 0;

    /**
     * cohorts that will get synchronize
     * @var array
     */
    private $_cohorts = array();
    /**
     * Constructor for the plugin. In addition to calling the parent
     * constructor, we define and 'fix' some settings depending on the
     * real settings the admin defined.
     */
    public function __construct() {
        global $CFG;
        require_once($CFG->libdir.'/ldaplib.php');
        
		if (is_enabled_auth('cas')) {
            $this->authtype = 'cas';
            $this->roleauth = 'auth_cas';
            
        } else if (is_enabled_auth('ldap')){ 
            $this->authtype = 'ldap';
            $this->roleauth = 'auth_ldap';
            
        } else {
            error_log('[SYNCH COHORTS] ' . get_string('pluginnotenabled', 'auth_ldap'));
            die;
        }
        // Do our own stuff to fix the config (it's easier to do it
        // here than using the admin settings infrastructure). We
        // don't call $this->set_config() for any of the 'fixups'
        // (except the objectclass, as it's critical) because the user
        // didn't specify any values and relied on the default values
        // defined for the user type she chose.
        $this->load_config();

        // Make sure we get sane defaults for critical values.
        $this->config->ldapencoding = $this->get_config('ldapencoding', 'utf-8');
        $this->config->user_type = $this->get_config('user_type', 'default');

        $ldap_usertypes = ldap_supported_usertypes();
        $this->config->user_type_name = $ldap_usertypes[$this->config->user_type];
        unset($ldap_usertypes);

        $default = ldap_getdefaults();
        // Remove the objectclass default, as the values specified there are for
        // users, and we are dealing with groups here.
        unset($default['objectclass']);

        // Use defaults if values not given. Dont use this->get_config()
        // here to be able to check for 0 and false values too.
        foreach ($default as $key => $value) {
            // Watch out - 0, false are correct values too, so we can't use $this->get_config()
            if (!isset($this->config->{$key}) or $this->config->{$key} == '') {
                $this->config->{$key} = $value[$this->config->user_type];
            }
        }

        if (empty($this->config->objectclass)) {
            // Can't send empty filter. Fix it for now and future occasions
            $this->set_config('objectclass', '(objectClass=*)');
        } else if (stripos($this->config->objectclass, 'objectClass=') === 0) {
            // Value is 'objectClass=some-string-here', so just add ()
            // around the value (filter _must_ have them).
            // Fix it for now and future occasions
            $this->set_config('objectclass', '('.$this->config->objectclass.')');
        } else if (stripos($this->config->objectclass, '(') !== 0) {
            // Value is 'some-string-not-starting-with-left-parentheses',
            // which is assumed to be the objectClass matching value.
            // So build a valid filter with it.
            $this->set_config('objectclass', '(objectClass='.$this->config->objectclass.')');
        } else {
            // There is an additional possible value
            // '(some-string-here)', that can be used to specify any
            // valid filter string, to select subsets of users based
            // on any criteria. For example, we could select the users
            // whose objectClass is 'user' and have the
            // 'enabledMoodleUser' attribute, with something like:
            //
            //   (&(objectClass=user)(enabledMoodleUser=1))
            //
            // In this particular case we don't need to do anything,
            // so leave $this->config->objectclass as is.
        }
    }

    /**
     * Connect to the LDAP server, using the plugin configured
     * settings. It's actually a wrapper around ldap_connect_moodle()
     *
     * @param progress_trace $trace
     * @return bool success
     */
    protected function ldap_connect(progress_trace $trace = null) {
        global $CFG;
        require_once($CFG->libdir.'/ldaplib.php');

        if (isset($this->ldapconnection)) {
            return true;
        }

        if ($ldapconnection = ldap_connect_moodle($this->get_config('host_url'), $this->get_config('ldap_version'),
                                                  $this->get_config('user_type'), $this->get_config('bind_dn'),
                                                  $this->get_config('bind_pw'), $this->get_config('user_deref'),
                                                  $debuginfo, $this->get_config('start_tls'))) {
            $this->ldapconnection = $ldapconnection;
            return true;
        }

        if ($trace) {
            $trace->output($debuginfo);
        } else {
            error_log($this->errorlogtag.$debuginfo);
        }

        return false;
    }

    /**
     * Disconnects from a LDAP server
     *
     */
    protected function ldap_close() {
        if (isset($this->ldapconnection)) {
            @ldap_close($this->ldapconnection);
            $this->ldapconnection = null;
        }
        return;
    }

    public function is_cron_required()
    {
        $_enabled = intval($this->get_config('cron_enabled'));

        return $_enabled == 1 ? true : false;
    }

    public function sync_cohorts(progress_trace $trace){
        global $CFG, $DB;

        require_once("{$CFG->dirroot}/cohort/lib.php");
        if (!$this->ldap_connect($trace)) {
            $trace->finished();
            return;
        }
        $ldap_pagedresults = ldap_paged_results_supported($this->get_config('ldap_version'));

        // we may need a lot of memory here
        @set_time_limit(0);
        raise_memory_limit(MEMORY_HUGE);

        $trace->output(get_string('connectingldap', 'enrol_ldapcohort'));
		$ldapconnection = $this->ldapconnection;
        
        $trace->output(get_string('synchronizing_cohorts', 'enrol_ldapcohort'));

        $wanted_fields = array();
        if (!empty($this->config->cohort_name)) {
            array_push($wanted_fields, $this->config->cohort_name);
        }

        if (!empty($this->config->cohort_idnumber)) {
            array_push($wanted_fields, $this->config->cohort_idnumber);
        }

        if (!empty($this->config->cohort_description)) {
            array_push($wanted_fields, $this->config->cohort_description);
        }

        if (empty($this->config->user_member_attribute)) {
            $trace->output(get_string('err_member_attribute', 'enrol_ldapcohort'));
            return;
        }

        array_push($wanted_fields, $this->get_config('cohort_member_attribute', 'member'));

        //contexts for searching cohorts
        $contexts = explode(';', $this->config->cohort_contexts);

        $filter = '(&('.$this->config->cohort_name.'=*)'.$this->config->cohort_objectclass.')';
		$ldap_cookie = '';
        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty($context)) {
                continue;
            }
            $flat_records = array();

			do {
				if ($ldap_pagedresults) {
					ldap_control_paged_result($this->ldapconnection, $this->config->pagesize, true, $ldap_cookie);
				}

				if ($this->config->cohort_search_sub) {
					// Use ldap_search to find first user from subtree
					$ldap_result = @ldap_search($this->ldapconnection,
												$context,
												$filter,
												$wanted_fields);
				} else {
					// Search only in this context
					$ldap_result = @ldap_list($this->ldapconnection,
											  $context,
											  $filter,
											  $wanted_fields);
				}
				if (!$ldap_result) {
					continue; // Next
				}

				if ($ldap_pagedresults) {
					ldap_control_paged_result_response($this->ldapconnection, $ldap_result, $ldap_cookie);
				}

				// Check and push results
				$records = ldap_get_entries($this->ldapconnection, $ldap_result);

				// LDAP libraries return an odd array, really. fix it:
				for ($c = 0; $c < $records['count']; $c++) {
					array_push($flat_records, $records[$c]);
				}
				// Free some mem
				unset($records);
			} while ($ldap_pagedresults && !empty($ldap_cookie));

			// If LDAP paged results were used, the current connection must be completely
			// closed and a new one created, to work without paged results from here on.
			if ($ldap_pagedresults) {
				$this->ldap_close();
				$this->ldap_connect($trace);
			}

			if (count($flat_records)) {
	            foreach ($flat_records as $cohort) {
	                $cohort = array_change_key_case($cohort, CASE_LOWER);
	
	                if (empty($cohort[$this->config->cohort_name][0])) {
	                    $trace->output(get_string('err_invalid_cohort_name', 'enrol_ldapcohort', $this->config->cohort_name));
											                    continue;
	                }
	
	                if (empty($cohort[$this->config->cohort_idnumber][0])) {
	                    $trace->output(get_string('err_invalid_cohort_idnumber', 'enrol_ldapcohort', $this->config->cohort_idnumber));
											                    continue;
	                }
	
	                $cohortname = strtoupper($cohort[$this->config->cohort_name][0]);
	
	                $moodle_cohort = $DB->get_record('cohort', array ( 'name' => $cohortname ));
	                if (empty($moodle_cohort)) {
						if ($this->config->autocreate_cohorts) {
		                    if (false != ($cohortid = $this->create_cohort($cohort))) {
		                        $moodle_cohort = $DB->get_record('cohort', array ('id' => $cohortid));
		                        $trace->output(get_string('cohort_created', 'enrol_ldapcohort', $moodle_cohort->name));
		                        $this->_cohorts_added++;
		                    }else {
								if ($this->config->debug_mode){
									$trace->output("No create cohorte: ".$cohortname."\n");
								}
								continue;
							}
		                }    
	                } else {
	                    if (strpos($moodle_cohort->description, '<strong>[LDAP Cohort Sync]</strong>') === false) {
	                        $moodle_cohort->description = '<strong>[LDAP Cohort Sync]</strong> ' . $moodle_cohort->description;
	                        $DB->update_record('cohort', $moodle_cohort);
	                    }
	                    $this->_cohorts_existing++;
	                    $trace->output(get_string('cohort_existing', 'enrol_ldapcohort', $moodle_cohort->name));
	                }
	
	                if (empty($moodle_cohort->id)) {
	                    $trace->output(get_string('err_create_cohort', 'enrol_ldapcohort', $cohortname));
	                    continue;
	                }
	                $this->_cohorts [$moodle_cohort->idnumber] = $moodle_cohort;
	
	                if (!empty($cohort[$this->config->cohort_member_attribute])) {
						$membership = array($cohort[$this->config->cohort_member_attribute]);
						$this->sync_users($moodle_cohort, $membership,$trace);
	                }
	            }
	        }    
        }
        $trace->output(get_string('synchronized_cohorts', 'enrol_ldapcohort', $this->_cohorts_added + $this->_cohorts_existing));
        $this->ldap_close();
        $trace->finished();
    }
	public function sync_user_enrolments($user) {
        global $DB;
		if ($this->config->login_sync) {
	        // Do not try to print anything to the output because this method is called during interactive login.
	        $trace = new error_log_progress_trace($this->errorlogtag);
	        if (!$this->ldap_connect($trace)) {
	            $trace->finished();
	            return;
	        }
	        
	        
	        
	        $this->ldap_close();
	
	        $trace->finished();
		}
	}

    public function sync_users($moodle_cohort, $uid_in = array(),progress_trace $trace){
        
        if (empty($uid_in)) {
            continue;
        }
        $trace->output(get_string('cohort_sync_users', 'enrol_ldapcohort'), "");
		global $CFG, $DB;
        $ldapconnection = $this->ldapconnection;

        $count = 0;
        //contexts for searching cohorts
        $contexts = explode(';', $this->config->user_contexts);
        $user_filter = '(&('.$this->config->user_attribute.'=*)(|';
        foreach ($uid_in as $uid) {
            $user_filter .= '(' . $this->config->user_member_attribute . '=' . $uid . ')';
        }
        $user_filter .= ')'.$this->config->user_objectclass.')';

        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty($context)) {
                continue;
            }

            if ($this->config->user_search_sub) {
                //use ldap_search to find first user from subtree
                $ldap_result = ldap_search($ldapconnection, $context,
                                           $user_filter);
            } else {
                //search only in this context
                $ldap_result = ldap_list($ldapconnection, $context,
                                         $user_filter);
            }

            if (!$ldap_result) {
                continue;
            }

            $records = ldap_get_entries($ldapconnection, $ldap_result);

            $ldap_users = array();
            for ($i = 0; $i < $records['count']; $i++) {
                $ldap_users []= $records[$i];
            }

            foreach ($ldap_users as $i => $ldap_user) {
				$ldap_user = array_change_key_case($ldap_user, CASE_LOWER);

                if (empty($ldap_user['uid'][0])) {
                    $trace->output("\t" . get_string('err_user_empty_uid', 'enrol_ldapcohort', $ldap_user['cn'][0]));
										                    continue;
                }

                $moodle_user = $DB->get_record( 'user', array ( 'username' => $ldap_user['uid'][0] ) );
				if (empty($moodle_user)) {
	                if ($this->config->autocreate_users) { 
	                    if (false != ($userid = $this->create_user($ldap_user))) {
	                        $moodle_user = $DB->get_record( 'user', array ('id' => $userid) );
	                        $this->_users_added++;
	                    }
	                }    
                } else {
                    $this->_users_existing++;
                }

                if (empty($moodle_user->id)) {
                    $trace->output("\t" . get_string('err_create_user', 'enrol_ldapcohort', $ldap_user['uid'][0]));
										                    continue;
                }

                try {
                    cohort_add_member($moodle_cohort->id, $moodle_user->id);
                } catch (Exception $e) {
                    $trace->output("\t" . get_string('err_user_exists_in_cohort', 'enrol_ldapcohort', array ('cohort' => $moodle_cohort->name, 'user' => $ldap_user['uid'][0])));
										                }
                $count++;
            }
        }
        $trace->output(get_string('user_synchronized', 'enrol_ldapcohort', array('count' => $count, 'cohort' => $moodle_cohort->name)));
				    }

    public function create_user($ldap_user)
    {
        global $CFG, $DB;

        $textlib = textlib_get_instance();
        $user = new stdClass();
        $user->username = trim(textlib::strtolower($ldap_user['uid'][0]));

        $values = array (
            'givenname'         => 'firstname',
            'sn'                => 'lastname',
            'mail'              => 'email',
            'logindisabled'     => 'suspended',
            'description'       => 'description'
        );

        if ($this->config->user_idnumber) {
            $values[$this->config->user_idnumber] = 'idnumber';
        }

        //TODO: should these be configurable ?
        foreach ($values as $ldap_key => $moodle_field) {
            if (isset($ldap_user[$ldap_key])) {
                if (is_array($ldap_user[$ldap_key])) {
                    $newval = $textlib->convert($ldap_user[$ldap_key][0], $this->config->ldapencoding, 'utf-8');
                } else {
                    $newval = $textlib->convert($ldap_user[$ldap_key], $this->config->ldapencoding, 'utf-8');
                }
                $user->{$moodle_field} = $newval;
            }
        }

        // Prep a few params
        $user->timecreated =  $user->timemodified   = time();
        $user->confirmed  = 1;
        $user->auth       = $this->authtype;
        $user->mnethostid = $CFG->mnet_localhost_id;
		$_s = strtolower(trim($user->suspended));
		if ($_s == 'false') {
			$_s = 0;
		} elseif ($_s == 'true') {
			$_s = 1;
		}
		$user->suspended = intval($_s);

        if (empty($user->lang)) {
            $user->lang = $CFG->lang;
        }
		try {
			$id = $DB->insert_record('user', $user);
		} catch (Exception $e) {
			$trace->output("\n\t Error creating user: " . $e->getMessage());
		}
        $trace->output("\n\t" . get_string('user_dbinsert', 'enrol_ldapcohort', array('name'=>$user->username, 'id'=>$id)));

        return $id;
    }

    public function create_cohort($ldap_entry)
    {
        $cohort = new stdClass();

        $cohort->idnumber       = isset ($ldap_entry[$this->config->cohort_idnumber][0]) ? $ldap_entry[$this->config->cohort_idnumber][0] : 0;
        $cohort->name           = isset ($ldap_entry[$this->config->cohort_name][0]) ? $ldap_entry[$this->config->cohort_name][0] : '';
        $cohort->description    = isset ($ldap_entry[$this->config->cohort_description][0]) ? $ldap_entry[$this->config->cohort_description][0] : '';

        $cohort->description    = '<strong>[LDAP Cohort Sync]</strong> ' . $cohort->description;

        $cohort->contextid      = $this->config->context;

        if (empty($cohort->idnumber) || empty($cohort->name)) {
            return false;
        }

        return cohort_add_cohort($cohort);

    }

    public function cron(){
        $this->load_config();
        $trace = new error_log_progress_trace($this->errorlogtag);
        $this->sync_cohorts($trace);
        

        if ( (!empty($this->config->email_report_enabled) && !empty($this->config->email_report))) {
            //send email just in case something new was added
            if ($this->_cohorts_added || $this->_users_added) {
                $this->send_report_email();
            }
        }
        $trace->finished();
    }

    public function send_report_email()
    {
        global $CFG, $FULLME;

        if (!empty($CFG->noemailever)) {
            // hidden setting for development sites, set in config.php if needed
            $trace->output('Error: lib/moodlelib.php email_to_user(): Not sending email due to noemailever config setting');
            return true;
        }

        $mail = get_mailer();

        $temprecipients = array();
        $tempreplyto = array();

        $supportuser = generate_email_supportuser();

        $mail->Sender   = $supportuser->email;
        $mail->From     = $CFG->noreplyaddress;
        $mail->FromName = $supportuser->firstname;

        $mail->Subject = get_string('report_email_subject', 'enrol_ldapcohort');

        $mail->WordWrap = 79;                   // set word wrap

        $messagehtml = get_string('report_email_html', 'enrol_ldapcohort', array ('ca' => $this->_cohorts_added, 'ce' => $this->_cohorts_existing, 'ua' => $this->_users_added, 'ue' => $this->_users_existing));
        $messagetext = get_string('report_email_text', 'enrol_ldapcohort', array ('ca' => $this->_cohorts_added, 'ce' => $this->_cohorts_existing, 'ua' => $this->_users_added, 'ue' => $this->_users_existing));
        $mail->IsHTML(true);
        $mail->Encoding = 'quoted-printable';           // Encoding to use
        $mail->Body    =  $messagehtml;
        $mail->AltBody =  "\n$messagetext\n";

        $mail->AddAddress($this->config->email_report);

        if ($mail->Send()) {
            $mail->IsSMTP();                               // use SMTP directly
            return true;
        } else {
            $trace->output('ERROR: '. $mail->ErrorInfo);
            return false;
        }
    }
}

function get_category_options()
{
    $displaylist = array();
    $parentlist = array();
    coursecat::make_categories_list($displaylist, $parentlist, 'moodle/cohort:manage');
    $options = array();
    $syscontext = get_context_instance(CONTEXT_SYSTEM);
    if (has_capability('moodle/cohort:manage', $syscontext)) {
        $options[$syscontext->id] = print_context_name($syscontext);
    }
    foreach ($displaylist as $cid=>$name) {
        $context = get_context_instance(CONTEXT_COURSECAT, $cid, MUST_EXIST);
        $options[$context->id] = $name;
    }

    return $options;
}

function enrol_ldapcohort_supports($feature)
{
    switch($feature) {
        case ENROL_RESTORE_TYPE: return ENROL_RESTORE_NOUSERS;

        default: return null;
    }
}
