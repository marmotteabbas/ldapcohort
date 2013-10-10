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
 
	protected $userfields = array('username','idnumber','firstname','lastname','email' );
	protected $cohortfields = array ('name', 'idnumber', 'description');

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
    protected function ldap_check_members($ldapmembers){
        if (!$this->ldap_connect()) {
                        return;
                                }
        $ldapconnection=$this->ldapconnection;
            // If we have enabled nested groups, we need to expand
            // the groups to get the real user list. We need to do
            // this before dealing with 'memberattribute_isdn'.
        if ($this->config->nested_groups) {
            $users = array();
            foreach ($ldapmembers as $ldapmember) {
                $grpusers = $this->ldap_explode_group($ldapmember,
                                                      $this->config->cohort_member_attribute);

                $users = array_merge($users, $grpusers);
            }
            $ldapmembers = array_unique($users); // There might be duplicates.
        }

        // Deal with the case where the member attribute holds distinguished names,
        // but only if the user attribute is not a distinguished name itself.
        if ($this->config->memberattribute_isdn
            && ($this->config->user_username !== 'dn')
            && ($this->config->user_username !== 'distinguishedname')) {
            // We need to retrieve the idnumber for all the users in $ldapmembers,
            // as the idnumber does not match their dn and we get dn's from membership.
            $memberidnumbers = array();
            foreach ($ldapmembers as $ldapmember) {
                $result = ldap_read($this->ldapconnection, $ldapmember, '(objectClass='.$this->config->user_objectclass.')',
                                    array($this->config->user_username));
		if ($result){
			$entry = ldap_first_entry($this->ldapconnection, $result);
            $values = ldap_get_values($this->ldapconnection, $entry, $this->config->user_username);
			array_push($memberidnumbers, $values[0]);
		}
            }

            $ldapmembers = $memberidnumbers;
        } 
        
       $this->ldap_close(); 
        return $ldapmembers;
    
    
    }
    protected function ldap_check_members2($ldapmembers,$from,$trace){
        $users = array();
        $groups = array();
        foreach ($ldapmembers as $ldapmember) {
            if ($ldapmember!="cn=Agalan groups fake member"){
                $pos=strpos ($ldapmember,"group");
                if ($pos===false){
                    if ($this->config->memberattribute_isdn
                        && ($this->config->user_username !== 'dn')
                        && ($this->config->user_username !== 'distinguishedname')) {
                        $contexts=explode(";",$this->config->user_contexts);
			$pos2=strlen($ldapmember);
			foreach ($contexts as $context){
				$pos3=strpos($ldapmember,$context);
				if ($pos3!==false){
				$pos2=min($pos2,$pos3);}
                        }    
                        if ($pos2!==false){
				$ldapmember=substr($ldapmember,0,$pos2-1);
                        }
                        $pos2=strpos($ldapmember,$this->config->user_username.'=');
                        if ($pos2!==false){
                            $ldapmember=substr($ldapmember,$pos2+strlen($this->config->user_username.'='));
			}
                    }
                    array_push($users,$ldapmember);
                }else{
                    //it's a group
                    if ($this->config->nested_groups) {
                        $contexts=explode(";",$this->config->cohort_contexts);
                        $pos2=strlen($ldapmember);
			foreach ($contexts as $context){
				$pos3=strpos($ldapmember,$context);
				if ($pos3!==false){
				$pos2=min($pos2,$pos3);}
                        }    
                        if ($pos2!==false){
                            $ldapmember=substr($ldapmember,0,$pos2-1);
                        }
                        $pos2=strpos($ldapmember,$this->config->cohort_username.'=');
                        if ($pos2!==false){
                            $ldapmember=substr($ldapmember,$pos2+strlen($this->config->cohort_username.'='));
                        }
                        array_push($groups,$ldapmember);
                    }
                }
            }
        }
        
        return $users;
    }    
        
    /**
     * Find the groups a given distinguished name belongs to, both directly
     * and indirectly via nested groups membership.
     *
     * @param string $memberdn distinguished name to search
     * @return array with member groups' distinguished names (can be emtpy)
     */
    protected function ldap_get_members_groups($groups,$from) {
        $groups = array();

        $this->ldap_find_user_groups_recursively($memberdn, $groups);
        return $members;
    }

    
    /**
     * Find the groups a given distinguished name belongs to, both directly
     * and indirectly via nested groups membership.
     *
     * @param string $memberdn distinguished name to search
     * @return array with member groups' distinguished names (can be emtpy)
     */
    protected function ldap_find_user_groups($memberdn) {
        $groups = array();

        $this->ldap_find_user_groups_recursively($memberdn, $groups);
        return $groups;
    }

    /**
     * Recursively process the groups the given member distinguished name
     * belongs to, adding them to the already processed groups array.
     *
     * @param string $memberdn distinguished name to search
     * @param array reference &$membergroups array with already found
     *                        groups, where we'll put the newly found
     *                        groups.
     */
    protected function ldap_find_user_groups_recursively($memberdn, &$membergroups) {
        $result = @ldap_read($this->ldapconnection, $memberdn, '(objectClass=*)', array($this->get_config('group_memberofattribute')));
        if (!$result) {
            return;
        }

        if ($entry = ldap_first_entry($this->ldapconnection, $result)) {
            do {
                $attributes = ldap_get_attributes($this->ldapconnection, $entry);
                for ($j = 0; $j < $attributes['count']; $j++) {
                    $groups = ldap_get_values_len($this->ldapconnection, $entry, $attributes[$j]);
                    foreach ($groups as $key => $group) {
                        if ($key === 'count') {  // Skip the entries count
                            continue;
                        }
                        if(!in_array($group, $membergroups)) {
                            // Only push and recurse if we haven't 'seen' this group before
                            // to prevent loops (MS Active Directory allows them!!).
                            array_push($membergroups, $group);
                            $this->ldap_find_user_groups_recursively($group, $membergroups);
                        }
                    }
                }
            }
            while ($entry = ldap_next_entry($this->ldapconnection, $entry));
        }
    }

    /**
     * Given a group name (either a RDN or a DN), get the list of users
     * belonging to that group. If the group has nested groups, expand all
     * the intermediate groups and return the full list of users that
     * directly or indirectly belong to the group.
     *
     * @param string $group the group name to search
     * @param string $memberattibute the attribute that holds the members of the group
     * @return array the list of users belonging to the group. If $group
     *         is not actually a group, returns array($group).
     */
    protected function ldap_explode_group($group, $memberattribute) {
        switch ($this->get_config('user_type')) {
            case 'ad':
                // $group is already the distinguished name to search.
                $dn = $group;

                $result = ldap_read($this->ldapconnection, $dn, '(objectClass=*)', array('objectClass'));
		if ($result){
			$entry = ldap_first_entry($this->ldapconnection, $result);
                	$objectclass = ldap_get_values($this->ldapconnection, $entry, 'objectClass');

                	if (!in_array('group', $objectclass)) {
                    	// Not a group, so return immediately.
                   	 return array($group);
                	}
		}

                $result = ldap_read($this->ldapconnection, $dn, '(objectClass=*)', array($memberattribute));
		if ($result){
			$entry = ldap_first_entry($this->ldapconnection, $result);
                	$members = @ldap_get_values($this->ldapconnection, $entry, $memberattribute); // Can be empty and throws a warning
                	if ($members['count'] == 0) {
                    	// There are no members in this group, return nothing.
                   	 return array();
                	}	
                	unset($members['count']);

                	$users = array();
                	foreach ($members as $member) {
                    		$group_members = $this->ldap_explode_group($member, $memberattribute);
                   		 $users = array_merge($users, $group_members);
                	}

                	return ($users);
		}
                break;
            default:
                error_log($this->errorlogtag.get_string('explodegroupusertypenotsupported', 'enrol_ldap',
                                                        $this->get_config('user_type_name')));

                return array($group);
        }
    }
/**
     * Given a group name (either a RDN or a DN), get the list of users
     * belonging to that group. If the group has nested groups, expand all
     * the intermediate groups and return the full list of users that
     * directly or indirectly belong to the group.
     *
     * @param string $group the group name to search
     * @param string $memberattibute the attribute that holds the members of the group
     * @return array the list of users belonging to the group. If $group
     *         is not actually a group, returns array($group).
     */
    protected function ldap_explode_group2($group, $memberattribute) {
        switch ($this->get_config('user_type')) {
            case 'ad':
                // $group is already the distinguished name to search.
                $dn = $group;

                $result = ldap_read($this->ldapconnection, $dn, '(objectClass=*)', array('objectClass'));
		if ($result){
			$entry = ldap_first_entry($this->ldapconnection, $result);
                	$objectclass = ldap_get_values($this->ldapconnection, $entry, 'objectClass');

                	if (!in_array('group', $objectclass)) {
                    	// Not a group, so return immediately.
                   	 return array($group);
                	}
		}

                $result = ldap_read($this->ldapconnection, $dn, '(objectClass=*)', array($memberattribute));
		if ($result){
			$entry = ldap_first_entry($this->ldapconnection, $result);
                	$members = @ldap_get_values($this->ldapconnection, $entry, $memberattribute); // Can be empty and throws a warning
                	if ($members['count'] == 0) {
                    	// There are no members in this group, return nothing.
                   	 return array();
                	}	
                	unset($members['count']);

                	$users = array();
                	foreach ($members as $member) {
                    		$group_members = $this->ldap_explode_group($member, $memberattribute);
                   		 $users = array_merge($users, $group_members);
                	}

                	return ($users);
		}
                break;
            default:
                error_log($this->errorlogtag.get_string('explodegroupusertypenotsupported', 'enrol_ldap',
                                                        $this->get_config('user_type_name')));

                return array($group);
        }
    }

	protected function ldap_search($type,$list=array(),$trace){
        if (!$this->ldap_connect()) {
                return;
            }
		$ldap_pagedresults = ldap_paged_results_supported($this->get_config('ldap_version'));
        $ldapconnection = $this->ldapconnection;
        $wanted_fields = array();
        foreach ($this->{$type.'fields'} as $field){
	    $mapfield="{$type}_{$field}";	
	    if (!empty($this->config->{$mapfield})) {
                array_push($wanted_fields, $this->config->{$mapfield});
            }
        }
		
		if (empty($this->config->{$type.'_member_attribute'})) {
			if ($this->config->debug_mode){$trace->output(get_string('err_member_attribute', 'enrol_ldapcohort'));}
			return;
		}

		array_push($wanted_fields, $this->get_config($type.'_member_attribute', 'member'));

		//contexts for searching 
		$contexts = explode(';', $this->config->{$type.'_contexts'});
        if (empty($list)){
            if ($type=='cohort') {
                if (!empty($this->config->filter)) {
                    $filter = "(&({$this->config->filter}')";
                }else{
                    $filter = '(&(cn=*)';
                }
            }
        }else{
            $filter = '(&(|';
            unset($list['count']); // Remove oddity ;
            foreach ($list as $item) {
                switch ($type){
                    case 'cohort':
                        if ($item->{$this->config->cohort_syncing_field}){
                            $filter .= '(' . $this->config->{'cohort_'.$this->config->cohort_syncing_field} . '=' . $item->{$this->config->cohort_syncing_field} . ')';
                        }
                        break;
                    case 'user':
                     $filter .= '(' . $this->config->user_username . '=' . $item . ')';
		     break;
		}
            }
            $filter .= ')';
			}
		if (!empty($this->config->{$type.'_objectclass'})) {
            $filter .= '(objectClass='.$this->config->{$type.'_objectclass'}.'))';
        }else{
            $filter .= '(objectClass=*))';
        }

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

				if ($this->config->{$type.'_search_sub'}) {
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

		// we may need a lot of memory here
		@set_time_limit(0);
		raise_memory_limit(MEMORY_HUGE);

		$trace->output(get_string('connectingldap', 'enrol_ldapcohort'));
		$trace->output(get_string('synchronizing_cohorts', 'enrol_ldapcohort'));

		
        if ($this->config->autocreate_cohorts) {
            $listcohorts=array();
        }else{
            $listcohorts=$this->cohort_get_all_cohorts();
        }
	  
    $flat_results=$this->ldap_search('cohort',$listcohorts,$trace);

	if (count($flat_results)) {
				foreach ($flat_results as $ldapgroup) {
					$ldapgroup = array_change_key_case($ldapgroup, CASE_LOWER);

					if (empty($ldapgroup[ $this->config->{'cohort_'.$this->config->cohort_syncing_field}][0])) {
						if ($this->config->debug_mode){$trace->output(get_string('err_invalid_cohort_name', 'enrol_ldapcohort',  $this->config->{'cohort_'.$this->config->cohort_syncing_field}));}
																continue;
					}

                    $ldapmembers = array();
					$ldapgroupname = $ldapgroup[ $this->config->{'cohort_'.$this->config->cohort_syncing_field}][0];
                    $moodle_cohort=$this->search_cohort($ldapgroupname,$trace);
					//$this->_cohorts[$moodle_cohort->idnumber] = $moodle_cohort;
			if (!$moodle_cohort){
				if ($this->config->debug_mode){$trace->output(get_string('err_create_cohort', 'enrol_ldapcohort', $ldapgroupname));}
				continue;
			}
					if (!empty($ldapgroup[$this->config->cohort_member_attribute])) {
						$ldapmembers = $ldapgroup[$this->config->cohort_member_attribute];
						unset($ldapmembers['count']); // Remove oddity ;)

                        if (($this->config->memberattribute_isdn
                                && ($this->config->user_username !== 'dn')
                                && ($this->config->user_username !== 'distinguishedname'))
                            || ($this->config->nested_groups)){
                            $ldapmembers=$this->ldap_check_members2($ldapmembers,$ldapgroupname,$trace);
                        }                        
                        $this->sync_users($moodle_cohort, $ldapmembers,$trace);
						$this->stamp_cohort($moodle_cohort,$ldapgroup[ $this->config->cohort_name][0]);
					}
				}
			}
		$trace->output(get_string('synchronized_cohorts', 'enrol_ldapcohort', $this->_cohorts_added + $this->_cohorts_existing));
	   
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
				return false;
			}
		} else {

			$this->_cohorts_existing++;
			$trace->output(get_string('cohort_existing', 'enrol_ldapcohort', $moodle_cohort->name));
		}

		if (empty($moodle_cohort->id)) {
			if ($this->config->debug_mode){$trace->output(get_string('err_create_cohort', 'enrol_ldapcohort', $ldapgroupname));}
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
	/*		if (!$this->ldap_connect($trace)) {
				$trace->finished();
				return;
			}
	 */	global $CFG, $DB;
	//		$ldapconnection = $this->ldapconnection;
            if (!is_object($user) or !property_exists($user, 'id')) {
                throw new coding_exception('Invalid $user parameter in sync_user_enrolments()');
            }
    
            if (!property_exists($user, 'idnumber')) {
                debugging('Invalid $user parameter in sync_user_enrolments(), missing idnumber');
                $user = $DB->get_record('user', array('id'=>$user->id));
            }
    
            // We may need a lot of memory here
            @set_time_limit(0);
            raise_memory_limit(MEMORY_HUGE);

			$wanted_fields = array();
            foreach ($this->userfields as $field){
            $ldap_field=$this->config->{'user_'.$field};       
            if (!empty($lsap_field)) {
                    array_push($wanted_fields, $ldap_field);
                }
            }
			//contexts for searching cohorts
			$contexts = explode(';', $this->config->user_contexts);
			$user_filter = '(&';
			$user_filter .= '(' . $this->config->user_username . '=' . $user->username . ')';
			$user_filter .= ')(objectClass='.$this->config->user_objectclass.'))';
			$ldap_user=$this->ldap_search($contexts,$user_filter,$wanted_fields,$this->config->user_search_sub);

			if (count($ldap_users)) {

				$ldap_user = array_change_key_case($ldap_user, CASE_LOWER);

				if (empty($ldap_user[$this->config->user_username][0])) {
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
							if ($this->config->debug_mode){$trace->output(get_string('err_create_cohort', 'enrol_ldapcohort', $ldapgroupname));}
							continue;
						}

						try {
							cohort_add_member($moodle_cohort->id, $user->id);
						} catch (Exception $e) {
							if ($this->config->debug_mode){$trace->output("\t" . get_string('err_user_exists_in_cohort', 'enrol_ldapcohort', array ('cohort' => $moodle_cohort->name, 'user' => $ldap_user['uid'][0])));}
						}
						$this->stamp_cohort($moodle_cohort);

					}
				}
			}
			$this->ldap_close();

			$trace->finished();
		}
	}

	private function stamp_cohort($cohort,$name=null){
		global $DB;
        if (strpos($cohort->description, '<strong>[LDAP Cohort Sync]</strong>') === false) {
            $cohort->description = '<strong>[LDAP Cohort Sync]</strong> ' . date("d/m/Y H:i:s").$cohort->description; 
            
        }else{
            $cohort->description = '<strong>[LDAP Cohort Sync]</strong> ' . date("d/m/Y H:i:s").substr($cohort->description,55); 
            
        }
        if (($name)&&(strpos($cohort->name, $name) === false)) {
            $cohort->name = $name; 
        }
        $DB->update_record('cohort', $cohort);

	}

	public function sync_users($moodle_cohort, $uid_in = array(),progress_trace $trace){

        $cohort_members=$this->get_cohort_members($moodle_cohort->id);
		
      /*  if (!$this->ldap_connect()) {
			return;
		}
       */
		if (empty($uid_in)) {
			return false ;
		}
		$trace->output(get_string('cohort_sync_users', 'enrol_ldapcohort'), 4);
		global $CFG, $DB;
	//	$ldapconnection = $this->ldapconnection;

		$count = 0;
		
        $ldap_users=$this->ldap_search('user',$uid_in,$trace);
    
        if (count($ldap_users)) {
			foreach ($ldap_users as $i => $ldap_user) {
				$ldap_user = array_change_key_case($ldap_user, CASE_LOWER);

				if (empty($ldap_user[$this->config->user_username][0])) {
					if ($this->config->debug_mode){$trace->output("\t" . get_string('err_user_empty_uid', 'enrol_ldapcohort', $ldap_user['cn'][0]));}
															continue;
				}

				$moodle_user = $DB->get_record( 'user', array ( 'username' => $ldap_user[$this->config->user_username][0] ) );
                if (empty($moodle_user)) {
					if ($this->config->autocreate_users) {
						if (false != ($userid = $this->create_user($ldap_user))) {
							$moodle_user = $DB->get_record( 'user', array ('id' => $userid) );
							$this->_users_added++;
						}
                    }else{
                        continue;
                    }
				} else {
					$this->_users_existing++;
                    $update=false;
                    foreach ($this->userfields as $field){
                        $ldap_field=$this->config->{'user_'.$field};
                        if ((!$moodle_user->{$field})&& (isset($ldap_user[$ldap_field]))) {
                            $textlib =new textlib();
                            if (is_array($ldap_user[$ldap_field])) {
                                $newval = $textlib->convert($ldap_user[$ldap_field][0], $this->config->ldapencoding, 'utf-8');
                            } else {
                                $newval = $textlib->convert($ldap_user[$ldap_field], $this->config->ldapencoding, 'utf-8');
                            }
                            $moodle_user->{$field} = $newval;
                            $update=true;                    //update user
                        }    
                    }
                    if ($update) {
                            $DB->update_record('user', $moodle_user);                    //update user
                        }
                    unset($cohort_members[$moodle_user->id]);
                    

				}

				if (empty($moodle_user->id)) {
					if ($this->config->debug_mode){$trace->output("\t" . get_string('err_create_user', 'enrol_ldapcohort', $ldap_user['uid'][0]));}
															continue;
				}

				try {
					cohort_add_member($moodle_cohort->id, $moodle_user->id);
				} catch (Exception $e) {
					if ($this->config->debug_mode){$trace->output("\t" . get_string('err_user_exists_in_cohort', 'enrol_ldapcohort', array ('cohort' => $moodle_cohort->name, 'user' => $ldap_user['uid'][0])));}
														}
				$count++;
			}
		}
        /*foreach ($cohort_members as $userid => $user) {
	        cohort_remove_member($moodle_cohort->id, $userid);
	            
	        }
	 */    
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
		$user_filter = '(&('.$this->config->user_username.'=*)';
		$user_filter .= $this->config->user_objectclass.')';



		return ldap_find_userdn($this->ldapconnection, $userid, $contexts,$user_filter,
								$this->get_config('idnumber_attribute'), $this->get_config('user_search_sub'));
	}
	private function create_user($ldap_user)
	{
		global $CFG, $DB;
        $textlib =new textlib();
        $user = new stdClass();
        //$user->username = trim(textlib::strtolower($ldap_user['uid'][0]));
        foreach ($this->userfields as $field){
                $ldap_field=$this->config->{'user_'.$field};
            if (isset($ldap_user[$ldap_field])) {
                    if (is_array($ldap_user[$ldap_field])) {
                        $newval = $textlib->convert($ldap_user[$ldap_field][0], $this->config->ldapencoding, 'utf-8');
                    } else {
                        $newval = $textlib->convert($ldap_user[$ldap_field], $this->config->ldapencoding, 'utf-8');
                    }
                    if ($field=="username"){
                        $newval=trim(textlib::strtolower($newval));
                    }
                    $user->{$field} = $newval;
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
        foreach ($this->cohortfields as $field){
            $ldap_field=$this->config->{'cohort_'.$field};
            isset ($ldap_entry[$ldap_field][0]) ? $ldap_entry[$ldap_field][0] : '';
        }
		
		$cohort->description    = '<strong>[LDAP Cohort Sync]</strong> ' . date("d/m/Y H:i:s"). $cohort->description;

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
        parent::cron();

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
    function cohort_get_all_cohorts()
    {
        global $DB;
    
        // Add some additional sensible conditions
       
    
        $fields = "SELECT *";
        $sql = " FROM {cohort}";
        $order = " ORDER BY name ASC, idnumber ASC";
        $cohorts = $DB->get_records_sql($fields . $sql . $order, null, 0, 0);
    
        return $cohorts;
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
