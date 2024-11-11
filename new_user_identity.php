<?php

/**
 * New user identity but for Rest, not LDAP
 *
 * Populates a new user's default identity from http GET JSON endpoint on their first visit.
 *
 * This plugin requires that a JSON returner URL be configured .
 *
 * @author Kris Steinhoff
 * @author Tim Frost
 * @license GNU GPLv3+
 */
class new_user_identity_rest extends rcube_plugin
{
    public $task = 'login';

    private $rc;

    /**
     * Plugin initialization. API hooks binding.
     */
    #[Override]
    public function init()
    {
        $this->rc = rcmail::get_instance();

        $this->add_hook('user_create', [$this, 'lookup_user_name']);
        $this->add_hook('login_after', [$this, 'login_after']);
    }

// 

    /**
     * 'user_create' hook handler.
     */
    public function lookup_user_name($args)
    {
	$get_response = fetch_userinfo($args['user']);
	if (get_response === False) {
		return $args;
		// TODO: make some error handling
		// The API does not support errors in that way.
	}
	$dataarray = json_decode($get_response, $assoc = True);
        $user_name = is_array($dataarray['name']) ? $dataarray['name'][0] : $dataarray['name'];
        $user_email = is_array($dataarray['email']) ? $dataarray['email'][0] : $dataarray['email'];
	
        $args['user_name'] = $user_name;
        $args['email_list'] = [];

        if (empty($args['user_email']) && strpos($user_email, '@')) {
	        $args['user_email'] = rcube_utils::idn_to_ascii($user_email);
        }

        if (!empty($args['user_email'])) {
        	$args['email_list'][] = $args['user_email'];
        }

        foreach (array_keys($dataarray) as $key) {
	        if (!preg_match('/^email($|:)/', $key)) {
                        continue;
                }

                foreach ((array) $dataarray[$key] as $alias) {
        	        if (strpos($alias, '@')) {
                            $args['email_list'][] = rcube_utils::idn_to_ascii($alias);
                        }
                }
        }

        $args['email_list'] = array_unique($args['email_list']);

        return $args;
    }

    /**
     * 'login_after' hook handler. This is where we create identities for
     * all user email addresses.
     */
    public function login_after($args)
    {

	$this->load_config();
        if (!$this->rc->config->get('new_user_rest_identity_onlogin')) { 
            return $args; 
	}

        $identities = $this->rc->user->list_emails();
        $user_entry = $this->lookup_user_name([
            'user' => $this->rc->user->data['username'],
        ]);
	
        if (empty($user_entry['email_list'])) {
            return $args;
	}

	foreach ((array) $user_entry['email_list'] as $email) {
            foreach ($identities as $identity) {
                if ($identity['email'] == $email) {
                    continue 2;
                }
            }

            $plugin = $this->rc->plugins->exec_hook('identity_create', [
                'login' => true,
                'record' => [
                    'user_id' => $this->rc->user->ID,
                    'standard' => 0,
                    'email' => $email,
                    'name' => $user_entry['user_name'],
                ],
            ]);

            if (!$plugin['abort'] && !empty($plugin['record']['email'])) {
                $this->rc->user->insert_identity($plugin['record']);
            }
        }



        return $args;
    }

    /**
     * Fetch user info
     * Returns the raw string or false
     */
    private function fetch_userinfo($user)
    {
	    $this->load_config();
	    $baseurl = $this->rc->config->get('new_user_rest_api_url');
	    $curl_handle = curl_init($baseurl . $user);
	    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
	    curl_setopt($curl_handle, CURLOPT_FAILONERROR, 1);
	    $curl_output = curl_exec($curl_handle);
	    curl_close($curl_handle);

	    return $curl_output
    }
}
