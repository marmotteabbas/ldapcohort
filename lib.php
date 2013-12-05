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
    private $_users_removed = 0;
    private $_users_existing = 0;
    private $user_sync_field;
    private $textmail="";
    protected $userfields = array('username'=>'uid','idnumber'=>'uid','firstname'=>'givenName','lastname'=>'sn','email'=>'mail' );
    protected $cohortfields = array ('name'=>'cn', 'idnumber'=>'cn', 'description'=>'description');

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
    $this->auth=get_auth_plugin($this->authtype);
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
        foreach ($this->userfields as $key => $field){
            $this->userfields[$key]= $this->config->{'user_'.$key};
        }
        foreach ($this->cohortfields as $key => $field){
            $this->cohortfields[$key]= $this->config->{'cohort_'.$key};
        }
        $objectclass=array('group_objectclass','user_objectclass');
        foreach ($objectclass  as $object){
            if (empty($this->config->{$object})) {
                // Can't send empty filter. Fix it for now and future occasions
                $this->set_config($object, '(objectClass=*)');
            } else if (stripos($this->config->{$object}, 'objectClass=') === 0) {
                // Value is 'objectClass=some-string-here', so just add ()
                // around the value (filter _must_ have them).
                // Fix it for now and future occasions
                $this->set_config($object, '('.$this->config->{$object}.')');
            } else if (stripos($this->config->{$object}, '(') !== 0) {
                // Value is 'some-string-not-starting-with-left-parentheses',
                // which is assumed to be the objectClass matching value.
                // So build a valid filter with it.
                $this->set_config($object, '(objectClass='.$this->config->{$object}.')');
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
        $objectclass=$this->config->group_objectclass;
        $pos=strpos($objectclass,'objectclass=');
        if ($pos!==false){
             $objectclass=substr($objectclass,$pos+12);
        }
        $pos=strpos($objectclass,')');
        if ($pos!==false){
            $objectclass=substr($objectclass,0,$pos-1);
        }

        $this->cohortclass=$objectclass;
        if (!empty($this->config->memberattribute_is)){
            $field=array_search($this->config->memberattribute_is,$this->userfields);
        }else{
             $field=false;
        }
        $this->user_sync_field=$field?$field:'username';
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
        $is_time=parent::is_cron_required();
        $_enabled= $_enabled == 1 ? true : false;
        return $_enabled&&$is_time;
    }

    public function sync_cohorts(progress_trace $trace,$mail=""){
        global $CFG, $DB;

        require_once("{$CFG->dirroot}/cohort/lib.php");
        require_once("{$CFG->dirroot}/user/lib.php");

        // we may need a lot of memory here
        @set_time_limit(0);
        raise_memory_limit(MEMORY_HUGE);

        $trace->output(get_string('connectingldap', 'enrol_ldapcohort'));
        $trace->output(get_string('synchronizing_cohorts', 'enrol_ldapcohort'));

        if (!$this->ldap_connect()) {
                return;
            }


        $ldap_pagedresults = ldap_paged_results_supported($this->get_config('ldap_version'));
        $ldapconnection = $this->ldapconnection;
        $wanted_fields = array();
        foreach ($this->cohortfields as $key => $field){
        if (!empty($field)) {
                array_push($wanted_fields, $field);
            }
        }

        if (empty($this->config->group_member_attribute)) {
            if ($this->config->debug_mode){$trace->output(get_string('err_member_attribute', 'enrol_ldapcohort'));}
            return;
        }

        array_push($wanted_fields, $this->get_config('group_member_attribute', 'member'));

        //contexts for searching
        $contexts = explode(';', $this->config->group_contexts);
        if ($this->config->autocreate_cohorts){

                if (!empty($this->config->filter)) {
                    $filter = '(&('.$this->config->filter.')';
                }else{
                    $filter = '(&(cn=*)';
                }
        }else{
            $filter = '(&(|';
            $listcohorts=$this->cohort_get_all_cohorts();
            unset($listcohorts['count']); // Remove oddity ;
            foreach ($listcohorts as $cohort) {
                if ($cohort->{$this->config->cohort_syncing_field}){
                    $filter .= '(' . $this->config->{'cohort_'.$this->config->cohort_syncing_field} . '=' . $cohort->{$this->config->cohort_syncing_field}. ')';
                }
            }

            $filter .= ')';
        }

            $filter .= $this->config->group_objectclass.')';

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

                if ($this->config->group_search_sub) {
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

    if (count($flat_results)) {
        foreach ($flat_results as $ldapgroup) {
            $ldapgroup = array_change_key_case($ldapgroup, CASE_LOWER);
            $ldapgroupname = $ldapgroup[ $this->config->{'cohort_'.$this->config->cohort_syncing_field}][0];
            if (empty($ldapgroupname)) {
                if ($this->config->debug_mode){
                    $trace->output(get_string('err_invalid_cohort_name', 'enrol_ldapcohort',  $this->config->{'cohort_'.$this->config->cohort_syncing_field}));
                }
                continue;
            }

            $ldapmembers = array();

            $moodle_cohort = $DB->get_record('cohort', array ( $this->config->cohort_syncing_field => $ldapgroupname ));
            if (empty($moodle_cohort)) {
                if ($this->config->autocreate_cohorts) {
                    if (false != ($cohortid = $this->create_cohort($ldapgroupname))) {
                        $moodle_cohort = $DB->get_record('cohort', array ('id' => $cohortid));
                        $trace->output(get_string('cohort_created', 'enrol_ldapcohort', $moodle_cohort->name));
                        $this->_cohorts_added++;
                    }
                } else{
                    continue;
                }
            } else {
                $this->_cohorts_existing++;
                $trace->output(get_string('cohort_existing', 'enrol_ldapcohort', $moodle_cohort->name));
            }

            if (empty($moodle_cohort->id)) {
                if ($this->config->debug_mode){
                    $trace->output(get_string('err_create_cohort', 'enrol_ldapcohort', $ldapgroupname));
                }
                continue;
            }
            //$this->_cohorts[$moodle_cohort->idnumber] = $moodle_cohort;
            if (!$moodle_cohort){
                if ($this->config->debug_mode){
                    $trace->output(get_string('err_create_cohort', 'enrol_ldapcohort', $ldapgroupname));
                }
                continue;
            }

            $ldapmembers =$ldapgroup[$this->config->group_member_attribute];
            $cohort_members=$this->get_cohort_members($moodle_cohort->id,$this->user_sync_field);
            if (!empty($this->config->memberattribute_is)){
                if ($this->user_sync_field=='username'){
                    $ldapmembers = $this->get_ldapgroup_members($ldapmembers,array($ldapgroupname));
                }
            }
            if (count($ldapmembers)){
                $addmembers= array_diff($ldapmembers, $cohort_members);
                $removemembers=array_diff( $cohort_members,$ldapmembers);
                $count=0;
                if (count($addmembers)) {
                    //if nested...
                    $ldapmembers = $this->get_ldapgroup_members($addmembers,array($ldapgroupname));
                    $addmembers2= array_diff($ldapmembers,$cohort_members);
                    $addmembers=array_merge($addmembers,$addmembers2);
                    $removemembers=array_diff($removemembers,$ldapmembers);
                }
                if (count($addmembers)){
                    unset($addmembers['count']);
                    // Deal with the case where the member attribute holds distinguished names,
                    // but only if the user attribute is not a distinguished name itself.
                    foreach ($addmembers as $i => $ldapmember) {
                        $moodle_user = $DB->get_record( 'user', array ( $this->user_sync_field => $ldapmember) );
                        if (empty($moodle_user)) {
                            if ($this->config->autocreate_users) {
                                $ldap_user = $this->ldap_find_user($ldapmember,array_values($this->userfields) ,$this->userfields[$this->user_sync_field]);
                                if (isset($ldap_user)){
                                    if (false != ($userid = $this->create_user($ldap_user))) {
                                        $moodle_user = $DB->get_record( 'user', array ('id' => $userid) );
                                        $this->_users_added++;
                                    }
                                unset($ldap_user);
                                }
                            }else{
                                continue;
                            }
                        }
    
                        if (empty($moodle_user->id)) {
                            if ($this->config->debug_mode){$trace->output("\t" . get_string('err_create_user', 'enrol_ldapcohort', $ldapmember));}
                            continue;
                        }
    
                        try {
                            cohort_add_member($moodle_cohort->id, $moodle_user->id);
                        } catch (Exception $e) {
                            if ($this->config->debug_mode){
                                $trace->output("\t" . get_string('err_user_exists_in_cohort', 'enrol_ldapcohort', array ('cohort' => $moodle_cohort->name, 'user' => $ldap_user['uid'][0])));
                            }
                        }
                        $count++;
                        $this->stamp_cohort($moodle_cohort,$ldapgroup[ $this->config->cohort_name][0]);
                    }
                }
                $discount=0;
                if (count($removemembers)){
                    if ($this->config->unsubscribe_users){
                        $this->mail.=" users unsubscribed: ";
                        }else{
                        $this->mail.=" users should be unsubscribe: ";
                    }
                        foreach ($removemembers as $userid => $user) {
                            if ($this->config->unsubscribe_users){
                               cohort_remove_member($moodle_cohort->id, $userid);
                                $discount++;
                                $this->_users_removed++;
                                $this->stamp_cohort($moodle_cohort,$ldapgroup[ $this->config->cohort_name][0]);
                            }
                            $this->mail.=" ".$user." ";
                        }
                    
                }
            }
            $trace->output(get_string('user_synchronized', 'enrol_ldapcohort', array('count' => $count, 'discount'=>$discount,'cohort' => $moodle_cohort->name)));
            $this->mail.=get_string('user_synchronized', 'enrol_ldapcohort', array('count' => $count, 'discount'=>$discount,'cohort' => $moodle_cohort->name));
            
        }
    }

    $trace->output(get_string('synchronized_cohorts', 'enrol_ldapcohort', $this->_cohorts_added + $this->_cohorts_existing));
    $this->ldap_close();
    }

    private function get_cohort_members($cohortid,$field) {
        global $DB;
        $sql = " SELECT u.id,u.".$field."
                          FROM {user} u
                         JOIN {cohort_members} cm ON (cm.userid = u.id AND cm.cohortid = :cohortid)
                        WHERE u.deleted=0";
        $params['cohortid'] = $cohortid;
        return $DB->get_records_sql_menu($sql, $params);
    }
    function ldap_find_user( $username, $search_attrib,$input_attrib,$type='user') {
        if ( empty($username) || empty($search_attrib)||empty($input_attrib)) {
            return false;
        }
        // Default return value
        $objectclass=$type.'_objectclass';
        $ldap_user = false;
        if ($input_attrib=='dn'){
            $ldap_result = @ldap_read($this->ldapconnection, $username, $this->config->{$objectclass}, $search_attrib);
        }else{
            $contexts=explode(';', $this->config->{$type.'_contexts'});
            // Get all contexts and look for first matching user
            foreach ($contexts as $context) {
                $context = trim($context);
                if (empty($context)) {
                    continue;
                }
                $pos=strpos($username,$input_attrib."=");
                if ($pos === false) {
                    $filter =$input_attrib.'='.$username;
                }else{
                    $filter= $username;
                }
                if ($this->config->{$type.'_search_sub'}) {
                    if (!$ldap_result = @ldap_search($this->ldapconnection, $context,
                                                   '(&'.$this->config->{$objectclass}.'('.$filter.'))',
                                                   $search_attrib)) {
                        break; // Not found in this context.
                    }
                } else {
                    $ldap_result = ldap_list($this->ldapconnection, $context,
                                             '(&'.$this->config->{$objectclass}.'('.$filter.'))',
                                             $search_attrib);
                }
            }
        }
        if ($ldap_result){
        $entry = ldap_first_entry($this->ldapconnection, $ldap_result);
            if ($entry) {
                $ldap_user = ldap_get_attributes($this->ldapconnection, $entry);
            }
        }
        return $ldap_user;
    }
    
    /**
     * Given a group name (either a RDN or a DN), get the list of users
     * belonging to that group. If the group has nested groups, expand all
     * the intermediate groups and return the full list of users that
     * directly or indirectly belong to the group.
     *
     * 
     * @return array the list of users belonging to the group. If $group
     *         is not actually a group, returns array($group).
     */
    private function get_ldapgroup_members($ldapmembers,$from) {
        $users = array();
        if (($this->config->nested_groups)||((!empty($this->config->memberattribute_is))&&($this->user_sync_field=='username'))){
        unset($ldapmembers['count']);
        foreach ($ldapmembers as $ldapmember) {
                if ($ldapmember=="cn=Agalan groups fake member"){continue;}
                $pos=strpos ($ldapmember,"ou=group");
                if ($pos!==false){
                    if ($this->config->nested_groups) {
                        $name=$this->cohortfields[$this->config->cohort_syncing_field];
                        $fields= array($this->config->group_member_attribute, $name);
                        $input=empty($this->config->memberattribute_is)?$name:$this->config->memberattribute_is;
                        $group = $this->ldap_find_user($ldapmember,$fields ,$input,'group');
                        if ($group){
                            if (count($group[$this->config->group_member_attribute])){
                                if (!in_array( $group[$name][0],$from)){
                                    array_push($from, $group[$name][0]);
                                    $group_members=$this->get_ldapgroup_members($group[$this->config->group_member_attribute],$from);
                                    $users = array_merge($users, $group_members);
                                }
                            }
                        }
                    }
                }else{
                    if (!empty($this->config->memberattribute_is)){
                        if ($this->user_sync_field=='username'){
                            $user = $this->ldap_find_user($ldapmember,array($this->userfields['username']) ,$this->config->memberattribute_is);
                            $user=$user?$user[$this->userfields['username']][0]:$user;
                        }else{
                            $user=$ldapmember;
                        }
                    }
                    if ($user){
                        array_push($users, $user);
                    }
                }
            }
            $ldapmembers=$users;
        }
    return $ldapmembers;
    }
/**
     * Given a user name (either a RDN or a DN), get the list of users
     * belonging to that group. If the group has nested groups, expand all
     * the intermediate groups and return the full list of users that
     * directly or indirectly belong to the group.
     *
     * 
     * @return array the list of users belonging to the group. If $group
     *         is not actually a group, returns array($group).
     */
    private function get_user_memberof($memberofgroups,$from) {
        $groups = array();
        if ($this->config->nested_groups){
        unset($memberofgroups['count']);
        foreach ($memberofgroups as $memberof) {
            if ($memberof=="cn=Agalan groups fake member"){continue;}
            $name=$this->cohortfields[$this->config->cohort_syncing_field];
            $fields= array($this->config->memberof_attribute, $name);
            $input=empty($this->config->memberofattribute_is)?$name:$this->config->memberofattribute_is;
            $group = $this->ldap_find_user($memberof,$fields,$input,'group');
                if ($group){
                    if (in_array($this->config->memberof_attribute,$group)){
                    if (count($group[$this->config->memberof_attribute])){
                        if (!in_array( $group[$name][0],$from)){
                            array_push($from, $group[$name][0]);
                            $group_members=$this->get_user_memberof($group[$this->config->memberof_attribute],$from);
                            $groups = array_merge($groups, $group_members);
                        }
                    }       
                    }
                    array_push($groups, $group[$name][0]);
                }
            }
        $memberofgroups=$groups;
        }
    return $memberofgroups;
    }
    public function update_users(progress_trace $trace) {
        global $CFG, $DB;

        print_string('connectingldap', 'auth_ldap');
        $ldapconnection = $this->ldap_connect();
           
        /// User Updates - time-consuming (optional)
 
        // Narrow down what fields we need to update
        $attrmaps = $this->auth->ldap_attributes();
        $updatekeys = array_keys($attrmaps);

        if (!empty($updatekeys)) { // run updates only if relevant
            $users = $DB->get_records_sql('SELECT u.username, u.id,'.implode(",",$updatekeys).' 
                                             FROM {user} u
                                            WHERE u.deleted = 0 AND u.auth = ? AND u.mnethostid = ?',
                                          array($this->authtype, $CFG->mnet_localhost_id));
            if (!empty($users)) {
                $trace->output(get_string('userentriestoupdate', 'auth_ldap', count($users)));
                $sitecontext = context_system::instance();
                foreach ($users as $user) {
                    $trace->output(get_string('auth_dbupdatinguser', 'auth_db', array('name'=>$user->username, 'id'=>$user->id)));
                    // Protect the userid from being overwritten
                    $userid = $user->id;
                    if ($newinfo = $this->ldap_find_user($user->username,array_values($attrmaps),$this->auth->config->user_attribute)) {
			$newinfo=array_change_key_case($newinfo,CASE_LOWER);    
                    $updateuser= new stdClass();
                    $updateuser->id=$userid; 
                        foreach ($attrmaps as $key => $values) {
                            if (isset($newinfo[$values])) {
                                if (is_array($newinfo[$values])) {
                                    $newval = textlib::convert($newinfo[$values][0], $this->config->ldapencoding, 'utf-8');
                                } else {
                                    $newval = textlib::convert($newinfo[$values], $this->config->ldapencoding, 'utf-8');
                                }
                                $updateuser->{$key} = $newval;
                            } 
                        }
		    $DB->update_record('user', $updateuser);
                    $trace->output(get_string('auth_dbsuspenduser', 'auth_db', array('name'=>$user->username, 'id'=>$user->id)));
                    $euser = $DB->get_record('user', array('id' => $user->id));
                    events_trigger('user_updated', $euser);
                    } else {
                        if ($this->auth->config->removeuser == AUTH_REMOVEUSER_FULLDELETE) {
                            if (delete_user($user)) {
                                $trace->output(get_string('auth_dbdeleteuser', 'auth_db', array('name'=>$user->username, 'id'=>$user->id)));
                            } else {
                                $trace->output(get_string('auth_dbdeleteusererror', 'auth_db', $user->username));
                            }
                        } else if ($this->auth->config->removeuser == AUTH_REMOVEUSER_SUSPEND) {
                            $updateuser = new stdClass();
                            $updateuser->id = $user->id;
                            $updateuser->auth = 'nologin';
                            $DB->update_record('user', $updateuser);
                            $trace->output(get_string('auth_dbsuspenduser', 'auth_db', array('name'=>$user->username, 'id'=>$user->id)));
                            $euser = $DB->get_record('user', array('id' => $user->id));
                            events_trigger('user_updated', $euser);
                            
                        }
                    }
                    

                }
                unset($users); // free mem
            }
        } else { // end do updates
            $trace->output(get_string('noupdatestobedone', 'auth_ldap'));
        }
        
        $this->ldap_close();

        return true;
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
            require_once("{$CFG->dirroot}/cohort/lib.php");
            $ldapconnection = $this->ldapconnection;
            if (!is_object($user) or !property_exists($user, 'id')) {
                throw new coding_exception('Invalid $user parameter in sync_user_enrolments()');
            }
            
            // We may need a lot of memory here
            @set_time_limit(0);
            raise_memory_limit(MEMORY_HUGE);

            $field=array_search('dn',$this->userfields);
            $field=$field?$field:'username';
            $memberofgroups = $this->ldap_find_user($user->{$field},array($this->config->memberof_attribute),$this->userfields[$field]);
            $memberofgroups = $memberofgroups[$this->config->memberof_attribute];
            if ($this->config->nested_groups){
                $memberofgroups=$this->get_user_memberof($memberofgroups,array($user->{$field}));
            }

            if (count($memberofgroups)) {

                foreach ($memberofgroups as $memberof){
                    $pos=strpos($memberof,"=");
                    if ($pos !== false) {
                        $memberof=explode("=",$memberof);
                        $memberof= $memberof[1];

                    }
                    $moodle_cohort = $DB->get_record('cohort', array ( $this->config->cohort_syncing_field => $memberof ));
                    if (empty($moodle_cohort)) {
                        if ($this->config->autocreate_cohorts) {
                            if (false != ($cohortid = $this->create_cohort($memberof))) {
                                $moodle_cohort = $DB->get_record('cohort', array ('id' => $cohortid));
                                $trace->output(get_string('cohort_created', 'enrol_ldapcohort', $moodle_cohort->name));
                                $this->_cohorts_added++;
                            }
                        } else{
                            continue;
                        }
                    } else {

                        $this->_cohorts_existing++;
                        $trace->output(get_string('cohort_existing', 'enrol_ldapcohort', $moodle_cohort->name));
                    }

                    if (empty($moodle_cohort->id)) {
                        if ($this->config->debug_mode){$trace->output(get_string('err_create_cohort', 'enrol_ldapcohort', $memberof));}
                        continue;
                    }
                    if (!$moodle_cohort){
                        if ($this->config->debug_mode){$trace->output(get_string('err_create_cohort', 'enrol_ldapcohort', $memberof));}
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
    private function create_user($ldap_user)
    {
        global $CFG, $DB;
        $textlib =new textlib();
        $user = new stdClass();
        //$user->username = trim(textlib::strtolower($ldap_user['uid'][0]));
        foreach ($this->userfields as $key => $field){

            if (isset($ldap_user[$field])) {
                    if (is_array($ldap_user[$field])) {
                        $newval = $textlib->convert($ldap_user[$field][0], $this->config->ldapencoding, 'utf-8');
                    } else {
                        $newval = $textlib->convert($ldap_user[$field], $this->config->ldapencoding, 'utf-8');
                    }
                    if ($key=="username"){
                        $newval=trim(textlib::strtolower($newval));
                    }
                    $user->{$key} = $newval;
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
        foreach ($this->cohortfields as $key => $field){

          $cohort->{$key}=  isset ($ldap_entry[$field][0]) ? $ldap_entry[$field][0] : '';
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
            if ($this->_cohorts_added || $this->_users_added||$this->_users_removed) {
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
        $messagehtml = get_string('report_email_html', 'enrol_ldapcohort', array ('ca' => $this->_cohorts_added, 'ce' => $this->_cohorts_existing, 'ua' => $this->_users_added, 'ur' => $this->_users_removed,'ue' => $this->_users_existing));
        $messagetext = get_string('report_email_text', 'enrol_ldapcohort', array ('ca' => $this->_cohorts_added, 'ce' => $this->_cohorts_existing, 'ua' => $this->_users_added, 'ur' => $this->_users_removed,'ue' => $this->_users_existing));
        $messagehtml .= $this->mail;
        $messagetext .= $this->mail;
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
    $syscontext = context_system::instance();
    if (has_capability('moodle/cohort:manage', $syscontext)) {
        $options[$syscontext->id] = $syscontext->get_context_name();
    }
    foreach ($displaylist as $cid=>$name) {
        $context = get_context_instance(CONTEXT_COURSECAT, $cid, MUST_EXIST);
        $options[$context->id] = $name;
    }

    return $options;
}
?>
