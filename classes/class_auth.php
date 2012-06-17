<?php
/**
* Pandora v1
* @license GPLv3 - http://www.opensource.org/licenses/GPL-3.0
* @copyright (c) 2012 KDE. All rights reserved.
*/

class auth
{
    // Global vars
    var $username;
    var $sid;
    var $is_logged_in;
    var $is_admin;
    var $max_age;

    // Constructor
    function __construct()
    {
        $this->username = null;
        $this->sid = null;
        $this->is_admin = false;
        $this->is_logged_in = false;
        $this->max_age = time() - 3600;
    }

    // Method for creating a new session
    function create_session($username, $is_admin = false)
    {
        global $core, $db;

        $this->sid = sha1(time() . $core->remote_ip() . $username);
        $admin_flag = $is_admin ? 1 : 0;
        $db->escape($username);

        // Update/insert the session ID into the DB
        $sql = "UPDATE {$db->prefix}session " .
               "SET sid = '{$this->sid}', " .
               "    is_admin = {$admin_flag}, " .
               "    timestamp = {$core->timestamp} " .
               "WHERE username = '{$username}'";
        $db->query($sql);

        if ($db->affected_rows() <= 0)
        {
            $sql = "INSERT INTO {$db->prefix}session " .
                   "(username, is_admin, sid, timestamp) " .
                   "VALUES ('{$username}', $admin_flag, " .
                   "        '{$this->sid}', {$core->timestamp})";
            $db->query($sql);
        }

        // Save username and session ID to a cookie
        $core->set_cookie('username', $username);
        $core->set_cookie('session_id', $this->sid);
    }

    // Method for verifying the current session
    function verify()
    {
        global $core, $db;

        // Read username and session ID from a cookie
        $username = $core->variable('username', '', true);
        $sid = $core->variable('session_id', '', true);

        // Escape session ID and username
        $db->escape($username);
        $db->escape($sid);

        // Get current session data
        $sql = "SELECT * FROM {$db->prefix}session " .
               "WHERE username = '{$username}' " .
               "AND sid = '{$sid}'";
        $row = $db->query($sql, true);

        if ($row != null)
        {
            // Update the DB with current time
            $sql = "UPDATE {$db->prefix}session " .
                   "SET timestamp = {$core->timestamp} " .
                   "WHERE username = '{$username}' " .
                   "AND sid = '{$sid}'";
            $db->query($sql);

            // Set context data
            $this->username = $row['username'];
            $this->sid = $row['sid'];
            $this->is_admin = ($row['is_admin'] == 1);
            $this->is_logged_in = true;
        }
    }

    // Get details of the user from LDAP
    function get_user_details($username, $ldap = false)
    {
        global $config, $db;

        if ($ldap === false)
        {
            // Connect to the LDAP server
            if (!empty($config->ldap_port))
            {
                $ldap = @ldap_connect($config->ldap_server, (int)$config->ldap_port);
            }
            else
            {
                $ldap = @ldap_connect($config->ldap_server);
            }

            // Check if connection failed
            if (!$ldap)
            {
                return false;
            }

            @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        }


    }

    // Method for authenticating a user
    function login($username, $password)
    {
        global $config, $db;

        // Connect to the LDAP server
        if (!empty($config->ldap_port))
        {
            $ldap = @ldap_connect($config->ldap_server, (int)$config->ldap_port);
        }
        else
        {
            $ldap = @ldap_connect($config->ldap_server);
        }

        // Check if connection failed
        if (!$ldap)
        {
            return false;
        }

        @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

        // Try to bind with the user DN and password, if provided
        if ($config->ldap_user_dn || $config->ldap_password)
        {
            if (!@ldap_bind($ldap, htmlspecialchars_decode($config->ldap_user_dn), htmlspecialchars_decode($config->ldap_password)))
            {
                return false;
            }
        }

        // Look up for the user
        $filter = $config->ldap_uid . '=' . $this->ldap_escape(htmlspecialchars_decode($username));
        $search = @ldap_search($ldap, htmlspecialchars_decode($config->ldap_base_dn), $filter);
        $user_entry = @ldap_first_entry($ldap, $search);

        // Was the user found?
        if ($user_entry !== false)
        {
            // Validate credentials by binding with user's password
            $user_dn = @ldap_get_dn($ldap, $user_entry);
            
            if (@ldap_bind($ldap, htmlspecialchars_decode($user_dn), htmlspecialchars_decode($password)))
            {
                // Check if user is an admin
                if (!empty($config->ldap_group) && !empty($config->ldap_admin_group))
                {
                    $user_values = @ldap_get_values($ldap, $user_entry, $config->ldap_group);
                    $is_admin = in_array($config->ldap_admin_group, $user_values);
                }
                else
                {
                    $is_admin = false;
                }

                // Create a new session for the user
                $this->create_session($username, $is_admin);

                @ldap_close($ldap);
                unset($user_entry);

                // Authentication was successful
                return true;
            }
            else
            {
                @ldap_close($ldap);
                unset($user_entry);

                // Password was wrong
                return false;
            }
        }

        @ldap_close($ldap);

        // Username was not found
        return false;
    }

    // Method for logging a user out
    function logout()
    {
        global $core, $db;
        
        // Get username and session ID from cookie
        $username = $core->variable('username', '', true);
        $sid = $core->variable('session_id', '', true);

        // Delete session cookies
        $core->unset_cookie('username');
        $core->unset_cookie('session_id');

        // Escape the username and session ID
        $db->escape($username);
        $db->escape($sid);

        // Delete session data from the DB
        $sql = "DELETE FROM {$db->prefix}session " .
            "WHERE username = '{$username}' " .
            "AND sid = '{$sid}'";
        $db->query($sql);
    }

    // Escapes the auth string in LDAP authentication
    function ldap_escape($string)
    {
        return str_replace(array('*', '\\', '(', ')'), array('\\*', '\\\\', '\\(', '\\)'), $string);
    }
}

?>
