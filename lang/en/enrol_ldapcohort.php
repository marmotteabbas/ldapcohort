<?php

$string['auth_ldap_noconnect_all'] = 'LDAP-module cannot connect to any servers';


$string['by_groups_key']='Synch with LDAP groups ';
$string['by_groups']='Synch Moodle\'s cohorts with LDAP groups and not with LDAP user attribute';
$string['debug_mode_key']='Verbose mode';
$string['debug_mode']='Turn on or off the verbose mode when running the script';
$string['autocreate_cohorts_key']='Autocreate missing cohorts';
$string['autocreate_cohorts']='if false will not create missing cohorts (admin must create them before) ';
$string['login_sync_key']='Sync at login user';
$string['login_sync']='Enable syncing during interactive login ';

$string['autocreate_users_key']='Autocreate missing users';
$string['autocreate_users']='if false will not create missing users ';


$string['bind_dn'] = 'If you want to use a bind user to search users, specify it here. Someting like \'cn=ldapuser,ou=public,o=org\'';
$string['bind_dn_key'] = 'Bind user distinguished name';
$string['bind_pw'] = 'Password for the bind user';
$string['bind_pw_key'] = 'Password';
$string['bind_settings'] = 'Bind settings';

$string['cohort_context'] = 'Default context for newly created cohorts';
$string['cohort_context_key'] = 'Cohort context';
$string['cohort_contexts'] = 'List of contexts where cohorts are located. Separate different contexts with \';\'. For example: \'ou=users,o=org; ou=others,o=org\'';
$string['cohort_contexts_key'] = 'Contexts';
$string['cohort_created'] = 'Cohort "{$a}" created';
$string['cohort_description'] = 'LDAP attribute to get the cohort description from';
$string['cohort_description_key'] = 'Cohort description';
$string['cohort_existing'] = 'Cohort "{$a}" already exists, skipping';
$string['cohort_found_users'] = 'Found {$a} users';
$string['cohort_idnumber'] = 'LDAP attribute to get the cohort ID number from. Usually \'cn\' or \'uid\'.';
$string['cohort_idnumber_key'] = 'Cohort ID.';
$string['cohort_lookup'] = 'Cohort lookup settings';
$string['cohort_name'] = 'LDAP attribute to get the cohort name from';
$string['cohort_name_key'] = 'Cohort name';
$string['cohort_no_users'] = 'no users found';
$string['cohort_member_attribute'] = 'Group membership attribute in cohort entry. This denotes the attribute of the cohort which should be used to search users. Usual values: \'member\', \'uid\'';
$string['cohort_member_attribute_key'] = 'Cohort member attribute';
$string['cohort_syncing_field'] = 'Cohort field to synchronzing. Usual values: \'name\', \'idnumber\'';
$string['cohort_syncing_field_key'] = 'Cohort field to synchronzing';
$string['cohort_search_sub'] = 'Search cohorts from subcontexts';
$string['cohort_sync_users'] = 'Synchronizing users...';
$string['connectingldap'] = 'Connecting ldap...';

$string['cron_enabled_key'] = 'Enable sync on crontab?';
$string['cron_enabled'] = 'If set to yes, the plugin will synchronize users and cohorts every time the moodle cron job is executed. Select No to disable this feature. You can also run the import process manually by clicking {$a}';

$string['email_report'] = 'When a synchronization is completed, the system will send an email containing a report to this address';
$string['email_report_key'] = 'Email address for reports';
$string['email_report_enabled'] = 'Select "Yes" to enable email report messages. If you select "No", then the "Email address for reports" field will be ignored.';
$string['email_report_enabled_key'] = 'Enable email reports';

$string['err_create_cohort'] = 'Cannot create cohort with name: {$a}';
$string['err_create_user'] = 'Cannot create user with uid: {$a}';
$string['err_member_attribute'] = 'EMPTY MEMBER ATTRIBUTE FOR USER LOOKUP, PLEASE REVIEW SETTINGS';
$string['err_invalid_cohort_name'] = 'Empty LDAP group attribute (cohort name) : {$a}, skipping...';
$string['err_invalid_cohort_name'] = 'Empty LDAP group attribute (cohort id) : {$a}, skipping...';
$string['err_user_empty_uid'] = 'Empty uid in LDAP entry: {$a}';
$string['err_user_exists_in_cohort'] = 'User {$a->user} exists in cohort {$a->cohort}';

$string['general_settings'] = 'General settings';
$string['here'] = 'here';
$string['host_url'] = 'Specify LDAP host in URL-form like \'ldap://ldap.myorg.com/\' or \'ldaps://ldap.myorg.com/\'';
$string['host_url_key'] = 'Host URL';

$string['ldap_encoding'] = 'Specify encoding used by LDAP server. Most probably utf-8, MS AD v2 uses default platform encoding such as cp1252, cp1250, etc.';
$string['ldap_encoding_key'] = 'LDAP encoding';
$string['objectclass'] = 'objectClass used to search cohorts. Usually \'groupOfNames\' or \'posixGroup\'';
$string['objectclass_key'] = 'Object class';
$string['filter'] = 'LDAP filter used to search cohorts. Usually \'cn=*\' or \'cn=*2013*\'';
$string['filter_key'] = 'Filter';

$string['phpldap_noextension'] = '<em>The PHP LDAP module does not seem to be present. Please ensure it is installed and enabled if you want to use this enrolment plugin.</em>';
$string['pluginname'] = 'LDAP Cohort Synchronization';

$string['pluginname_desc'] = '<p>You can use an LDAP server to automatically create users and put them into Moodle cohorts.</p><p>In the given LDAP path there are groups containing users. Every group will generate a cohort in Moodle.</p><p>The synchronization process will be executed within the moodle cron.</p><p>You can run it manually by clicking {$a}</p>';
$string['report_email_html'] = '<p>{$a->ca} cohorts have been added</p><p>{$a->ce} cohorts already exist</p><p>{$a->ua} new users added into cohorts</p><p>{$a->ue} users already exist.</p>';
$string['report_email_text'] = '{$a->ca} cohorts have been added, {$a->ce} cohorts already exist; {$a->ua} new users added into cohorts, {$a->ue} users already exist.';
$string['report_email_subject'] = 'Finished synchronizing cohorts with LDAP server';

$string['memberattribute_is'] = 'If the group membership contains distinguised names, you need to specify it here. If it does, you also need to configure the remaining settings of this section';
$string['memberattribute_is_key'] = 'Member attribute uses dn';
$string['memberofattribute_is'] = 'If the user member of contains distinguised names, you need to specify it here. If it does, you also need to configure the remaining settings of this section';
$string['memberofattribute_is_key'] = 'Member of attribute uses ';$string['nested_groups'] = 'Do you want to use nested groups (groups of groups) for enrolment?';
$string['nested_groups_key'] = 'Nested groups';
$string['nested_groups_settings'] = 'Nested groups settings';


$string['search_subcontexts_key'] = 'Search subcontexts';
$string['server_settings'] = 'LDAP server settings';

$string['synchronized_cohorts'] = 'Done. Synchornized {$a} cohorts.';
$string['synchronizing_cohorts'] = 'Synchronizing cohorts...';
$string['cohort_synchronized_with_group']='Cohort synchronized with LDAP group {$a}';
$string['cohort_synchronized_with_attribute']='Cohort synchronized with LDAP attribute {$a}';

$string['user_idnumber'] = 'Field to map with ID Number for a user entry. (Usually, this is uid or uidNumber)';
$string['user_idnumber_key'] = 'User ID Number';
$string['user_username'] = 'Optional: Overrides the attribute used to name/search users. Usually \'cn\'.';
$string['user_username_key'] = 'User attribute';
$string['user_firstname'] = 'Field to map with firstname for a user entry.';
$string['user_firstname_key'] = 'User firstname';
$string['user_lastname'] = 'Field to map with lastname for a user entry.';
$string['user_lastname_key'] = 'User lastname';
$string['user_email'] = 'Field to map with email for a user entry.';
$string['user_email_key'] = 'User email';
$string['user_contexts'] = 'List of contexts where users are located. Separate different contexts with \';\'. For example: \'ou=users,o=org; ou=others,o=org\'';
$string['user_contexts_key'] = 'Contexts';
$string['user_created'] = 'User "{$a}" created';
$string['user_dbinsert'] = 'Inserted user {$a->name} with id {$a->id}';
$string['user_dereference'] = 'Determines how aliases are handled during search. Select one of the following values: "No" (LDAP_DEREF_NEVER) or "Yes" (LDAP_DEREF_ALWAYS)';
$string['user_dereference_key'] = 'Dereference aliases';
$string['user_lookup'] = 'User lookup settings';
$string['memberof_attribute'] = 'Group membership attribute in user entry. This denotes the user group(s) memberhsips. Usually \'member\', or \'memberUid\'';
$string['memberof_attribute_key'] = 'User member attribute';
$string['user_objectclass'] = 'Optional: Overrides objectClass used to name/search users on ldap_user_type. Usually you dont need to change this.';
$string['user_search_sub'] = 'Search users from subcontexts';
$string['user_synchronized'] = 'Synchronized {$a->count} users for cohort "{$a->cohort}"';
$string['user_type'] = 'Select how users are stored in LDAP. This setting also specifies how login expiration, grace logins and user creation will work.';
$string['user_type_key'] = 'User type';
$string['version'] = 'The version of the LDAP protocol your server is using';
$string['version_key'] = 'Version';
