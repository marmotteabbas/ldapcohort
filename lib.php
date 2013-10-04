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


	protected function ldap_search($contexts,$filter,$wanted_fields,$search_sub){
	if (!$this->ldap_connect()) {
			return;
		}
		$ldap_pagedresults = ldap_paged_results_supported($this->get_config('ldap_version'));
	$ldapconnection = $this->ldapconnection;



	$ldap_cookie = '';
		foreach ($contexts as $context) {
			$context = trim($context);
			if (empty($context)) {
				continue;
			}
			$flat_results = array();

			do {
				if ($ldap_pagedresults) {
					ldap_control_paged_result($this->ldapconnection, $this->config->pagesize, true, $ldap_cookie);
				}

				if ($search_sub) {
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
				$results = ldap_get_entries($this->ldapconnection, $ldap_result);

				// LDAP libraries return an odd array, really. fix it:
				for ($c = 0; $c < $results['count']; $c++) {
					array_push($flat_results, $results[$c]);
				}
				// Free some mem
				unset($results);
			} while ($ldap_pagedresults && !empty($ldap_cookie));

			// If LDAP paged results were used, the current connection must be completely
			// closed and a new one created, to work without paged results from here on.
			if ($ldap_pagedresults) {
				$this->ldap_close();
				$this->ldap_connect();
			}
	}
	$this->ldap_close();
		return $flat_results;
	}




	public function sync_cohorts(progress_trace $trace){
		global $CFG, $DB;

		require_once("{$CFG->dirroot}/cohort/lib.php");
		require_once("{$CFG->dirroot}/user/lib.php");

		/*
		if ($this->config->autocreate_cohorts) {
		}else{
		}
			$listcohorts=cohort_get_cohorts(context_system::instance()->id)['cohorts'];
			if ($CFG->debug_ldap_groupes){
			pp_print_object('list of cohorts ',$listcohorts);
			}
			foreach ($listcohorts as $cohortid=>$cohort) {
			$groupname=$cohort->idnumber;
			print "syncyng group " . $groupname .PHP_EOL;
				if (!empty ($groupname)&& ($groupname!=null)&&($groupname!="")){
				$ldap_members = $this->ldap_get_group_members($groupname);
				if (count($ldap_members)==0) {
					print "not updating empty LDAP group " . $cohort->name .PHP_EOL;
				}else{
					$this->ldap_update_cohort_group_members($ldap_members,$cohortid);
				}
			}
		}
		*/
		// we may need a lot of memory here
		@set_time_limit(0);
		raise_memory_limit(MEMORY_HUGE);

		$trace->output(get_string('connectingldap', 'enrol_ldapcohort'));
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

		if (empty($this->config->cohort_member_attribute)) {
			$trace->output(get_string('err_member_attribute', 'enrol_ldapcohort'));
			return;
		}

		array_push($wanted_fields, $this->get_config('cohort_member_attribute', 'member'));

		//contexts for searching cohorts
		$contexts = explode(';', $this->config->cohort_contexts);

		$filter = '(&('.$this->config->cohort_name.'=*)'.$this->config->cohort_objectclass.')';

	$flat_results=$this->ldap_search($contexts,$filter,$wanted_fields,$this->config->cohort_search_sub);

	if (count($flat_results)) {
				foreach ($flat_results as $ldapgroup) {
					$ldapgroup = array_change_key_case($ldapgroup, CASE_LOWER);

					if (empty($ldapgroup[$this->config->cohort_name][0])) {
						$trace->output(get_string('err_invalid_cohort_name', 'enrol_ldapcohort', $this->config->cohort_name));
																continue;
					}

					if (empty($ldapgroup[$this->config->cohort_idnumber][0])) {
						$trace->output(get_string('err_invalid_cohort_idnumber', 'enrol_ldapcohort', $this->config->cohort_idnumber));
																continue;
					}

					$ldapgroupname = strtoupper($ldapgroup[ $this->config->{'cohort_'.$this->config->cohort_syncing_field}][0]);
                    $trace->output("ldapgroupname ".$ldapgroupname);
					$moodle_cohort=$this->search_cohort($ldapgroupname,$trace);
					//$this->_cohorts[$moodle_cohort->idnumber] = $moodle_cohort;
			if (!$moodle_cohort){
				$trace->output(get_string('err_create_cohort', 'enrol_ldapcohort', $ldapgroupname));
				continue;
			}
					if (!empty($ldapgroup[$this->config->cohort_member_attribute])) {
						$membership = $ldapgroup[$this->config->cohort_member_attribute];
						$this->sync_users($moodle_cohort, $membership,$trace);
						$this->stamp_cohort($moodle_cohort);
					}
				}
			}
		$trace->output(get_string('synchronized_cohorts', 'enrol_ldapcohort', $this->_cohorts_added + $this->_cohorts_existing));
	   $trace->finished();
	}
	private function search_cohort($ldapgroupname,$trace){
		global $CFG, $DB;


		$moodle_cohort = $DB->get_record('cohort', array ( $this->config->cohort_syncing_field => $ldapgroupname ));
		if (empty($moodle_cohort)) {
			if ($this->config->autocreate_cohorts) {
				if (false != ($cohortid = $this->create_cohort($ldapgroupname))) {
					$moodle_cohort = $DB->get_record('cohort', array ('id' => $cohortid));
					$trace->output(get_string('cohort_created', 'enrol_ldapcohort', $moodle_cohort->name));
					$this->_cohorts_added++;
				}
			} else{
			if ($this->config->debug_mode){
						$trace->output("No create cohorte: ".$ldapgroupname);
					}
			return false;
			}
		} else {

			$this->_cohorts_existing++;
			$trace->output(get_string('cohort_existing', 'enrol_ldapcohort', $moodle_cohort->name));
		}

		if (empty($moodle_cohort->id)) {
			$trace->output(get_string('err_create_cohort', 'enrol_ldapcohort', $ldapgroupname));
			return false;
		}
		return $moodle_cohort;



	}
	private function get_cohort_members($cohortid) {
		global $DB;
		$sql = " SELECT u.id,u.username
						  FROM {user} u
						 JOIN {cohort_members} cm ON (cm.userid = u.id AND cm.cohortid = :cohortid)
						WHERE u.deleted=0";
		$params['cohortid'] = $cohortid;
		return $DB->get_records_sql($sql, $params);
	}
	 /**
	 *
	 * update user of cohort with  members of LDAP group
	 * @returns nothing
	 */
	function ldap_update_cohort_group_members($cohort, $ldap_members,progress_trace $trace){


		global $CFG;
		foreach ($ldap_members as $member) {
			$params = array (
				'username' => $member
			);
			if ($user = $DB->get_record('user', $params, 'id,username')) {
				$members[$user->id] = $user->username;
				//remove all LDAP users unkown to Moodle
			}else{
				if ($this->config->autocreate_users) {
					//add all LDAP users unkown to Moodle

				}
			}
		}
		$cohort_members = $this->get_cohort_members($cohort->id);

		foreach ($cohort_members as $userid => $user) {
			if (!isset ($ldap_members[$userid])) {
				cohort_remove_member($cohortid, $userid);
				$trace->output( "removing " .$user->username ." from cohort " .$cohortid );
			}
		}

		foreach ($ldap_members as $userid => $username) {
			if (!$this->cohort_is_member($cohortid, $userid)) {
				cohort_add_member($cohortid, $userid);
				$trace->output( "adding " . $username . " to cohort " . $cohortid );
			}
		}


	}
	public function sync_user_enrolments($user) {

		if ($this->config->login_sync) {
			// Do not try to print anything to the output because this method is called during interactive login.
			$trace = new error_log_progress_trace($this->errorlogtag);
			if (!$this->ldap_connect($trace)) {
				$trace->finished();
				return;
			}
			global $CFG, $DB;
			$ldapconnection = $this->ldapconnection;


			$wanted_fields = "";//all

			//contexts for searching cohorts
			$contexts = explode(';', $this->config->user_contexts);
			$user_filter = '(&('.$this->config->user_attribute.'=*)(|';
			$user_filter .= '(' . $this->config->user_member_attribute . '=' . $user->username . ')';
			$user_filter .= ')'.$this->config->user_objectclass.')';
			$ldap_user=$this->ldap_search($contexts,$user_filter,$wanted_fields,$this->config->user_search_sub);

			if (count($ldap_users)) {

				$ldap_user = array_change_key_case($ldap_user, CASE_LOWER);

				if (empty($ldap_user['uid'][0])) {
					$trace->output("\t" . get_string('err_user_empty_uid', 'enrol_ldapcohort', $ldap_user['cn'][0]));
															continue;
				}
				if (!empty($ldap_user[$this->config->user_member_attribute])) {
					$memberof = $ldap_user[$this->config->user_member_attribute];
					foreach ($menberof as $$ldapgroupname){
						$pos=strpos($ldapgroupname,"=");
						if ($pos !== false) {
							$ldapgroupname=explode("=",$ldapgroupname);
							$ldapgroupname= $ldapgroupname[1];

						}
						$moodle_cohort=$this->search_cohort($ldapgroup,$trace);
						if (!$moodle_cohort){
							$trace->output(get_string('err_create_cohort', 'enrol_ldapcohort', $ldapgroupname));
							continue;
						}

						try {
							cohort_add_member($moodle_cohort->id, $user->id);
						} catch (Exception $e) {
							$trace->output("\t" . get_string('err_user_exists_in_cohort', 'enrol_ldapcohort', array ('cohort' => $moodle_cohort->name, 'user' => $ldap_user['uid'][0])));
						}
						$this->stamp_cohort($moodle_cohort);

					}
				}
			}
			$this->ldap_close();

			$trace->finished();
		}
	}

	private function stamp_cohort($cohort){
	if (strpos($cohort->description, '<strong>[LDAP Cohort Sync]</strong>') === false) {
							$cohort->description = '<strong>[LDAP Cohort Sync]</strong> ' . date("d/m/Y H:i:s").substr($cohort->description,55); //ajouter la date
							$DB->update_record('cohort', $cohort);
						}

	}

	public function sync_users($moodle_cohort, $uid_in = array(),progress_trace $trace){

        $cohort_members=$this->get_cohort_members($moodle_cohort->id);

        if (!$this->ldap_connect()) {
			return;
		}

		if (empty($uid_in)) {
			continue;
		}
		$trace->output(get_string('cohort_sync_users', 'enrol_ldapcohort'), "");
		global $CFG, $DB;
		$ldapconnection = $this->ldapconnection;

		$count = 0;
		$wanted_fields = "";//all

		//contexts for searching cohorts
		$contexts = explode(';', $this->config->user_contexts);
		$user_filter = '(&('.$this->config->user_attribute.'=*)(|';
		foreach ($uid_in as $uid) {
            $pos=strpos($uid,",");
            if ($pos === false) {
                $user_filter .= '(' . $this->config->user_member_attribute . '=' . $uid . ')';
            }else{
                $uid=explode(",",$uid);
                $user_filter .= '(' .  $uid[0] . ')';
    
            }
		}
		$user_filter .= ')'.$this->config->user_objectclass.')';
        $ldap_users=$this->ldap_search($contexts,$user_filter,$wanted_fields,$this->config->user_search_sub);
    
        if (count($ldap_users)) {
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
                    }else{
                        if ($this->config->debug_mode){
                        $trace->output("No create user: ".$ldap_user['uid'][0]."  ".$ldap_user['cn'][0]);
                        }
                        continue;
                    }
				} else {
					$this->_users_existing++;
					unset($cohort_members[$moodle_user->id]);
                    
                    //update user
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
        foreach ($cohort_members as $userid => $user) {
	        cohort_remove_member($cohortid, $userid);
	            
	        }
	    }
        $trace->output(get_string('user_synchronized', 'enrol_ldapcohort', array('count' => $count, 'cohort' => $moodle_cohort->name)));
	}

	/**
	 * Search specified contexts for the specified userid and return the
	 * user dn like: cn=username,ou=suborg,o=org. It's actually a wrapper
	 * around ldap_find_userdn().
	 *
	 * @param string $userid the userid to search for (in external LDAP encoding, no magic quotes).
	 * @return mixed the user dn or false
	 */
	protected function ldap_find_userdn($userid) {
		global $CFG;

		$ldap_contexts = explode(';', $this->get_config('user_contexts'));
		$ldap_defaults = $this->config->objectclass;
		$contexts = explode(';', $this->config->user_contexts);
		$user_filter = '(&('.$this->config->user_attribute.'=*)';
		$user_filter .= $this->config->user_objectclass.')';



		return ldap_find_userdn($this->ldapconnection, $userid, $contexts,$user_filter,
								$this->get_config('idnumber_attribute'), $this->get_config('user_search_sub'));
	}
	private function create_user($ldap_user)
	{
		global $CFG, $DB;
	$textlib =new textlib();
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
		if (empty($user->lang)) {
			$user->lang = $CFG->lang;
		}

		return user_create_user($user);
	}

	private function create_cohort($ldap_entry)
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
		$trace = new text_progress_trace($this->errorlogtag);
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
