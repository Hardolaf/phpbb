<?php
/**
 *
 * @package auth
 * @copyright (c) 2012 phpBB Group
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
 * This class holds common functions that all providers must call to in order to
 * login or register.
 *
 * @package auth
 */
abstract class phpbb_auth_common_provider implements phpbb_auth_provider_interface
{
	protected $request;
	protected $db;
	protected $config;
	protected $user;

	/**
	 * Perform phpBB login from data gathered returned from a third party
	 * provider.
	 *
	 *
	 * @return true on success
	 */
	protected function login($user_id, $admin = false, $autologin = false, $viewonline = true)
	{
		// Get user
		$sql = 'SELECT user_id, username, user_password, user_passchg, user_pass_convert, user_email, user_type, user_login_attempts
				FROM ' . USERS_TABLE . '
				WHERE user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if(!$row)
		{
			$this->login_auth_fail($user_id);
			throw new phpbb_auth_exception('User not found.');
		}

		// Delete login attemps
		$sql = 'DELETE FROM ' . LOGIN_ATTEMPT_TABLE . '
				WHERE user_id = ' . $row['user_id'];
		$this->db->sql_query($sql);

		if ($row['user_login_attempts'] != 0)
		{
			// Successful, reset login attempts (the user passed all stages)
			$sql = 'UPDATE ' . USERS_TABLE . '
				SET user_login_attempts = 0
				WHERE user_id = ' . $row['user_id'];
			$this->db->sql_query($sql);
		}

		// User inactive...
		if ($row['user_type'] == USER_INACTIVE || $row['user_type'] == USER_IGNORE)
		{
			throw new phpbb_auth_exception('ACTIVE_ERROR');
		}

		if ($admin)
		{
			// Save the old session id so the session can be deleted.
			$old_session_id = $this->user->session_id;

			// phpbb_session->session_create() needs these to be modified as globals.
			global $SID, $_SID;

			$cookie_expire = time() - 31536000;
			$this->user->set_cookie('u', '', $cookie_expire);
			$this->user->set_cookie('sid', '', $cookie_expire);
			unset($cookie_expire);

			$SID = '?sid=';
			$this->user->session_id = $_SID = '';
		}

		// Create a session.
		$result = $this->user->session_create($row['user_id'], $admin , $autologin, $viewonline);
		if ($result === true)
		{
			// If admin re-authentication we remove the old session entry because a new one has been created.
			if ($admin)
			{
				$sql = 'DELETE FROM ' . SESSIONS_TABLE . '
					WHERE session_id = \'' . $this->db->sql_escape($old_session_id) . '\'
					AND session_user_id = ' . $row['user_id'];
				$this->db->sql_query($sql);
			}
			return true;
		}
		else
		{
			throw new phpbb_auth_exception($result);
		}
	}

	protected function login_auth_fail($user_id, $username = 0, $username_clean = 0)
	{
		if(!is_int($user_id))
		{
			throw new phpbb_auth_exception('Invalid user_id');
		}

		// Password incorrect - increase login attempts
		$sql = 'UPDATE ' . USERS_TABLE . '
			SET user_login_attempts = user_login_attempts + 1
			WHERE user_id = ' . $user_id . '
				AND user_login_attempts < ' . LOGIN_ATTEMPTS_MAX;
		$this->db->sql_query($sql);

		if (($this->user->ip && !$this->config['ip_login_limit_use_forwarded']) ||
		($this->user->forwarded_for && $this->config['ip_login_limit_use_forwarded']))
		{
			$sql = 'SELECT COUNT(*) AS attempts
				FROM ' . LOGIN_ATTEMPT_TABLE . '
				WHERE attempt_time > ' . (time() - (int) $this->config['ip_login_limit_time']);
			if ($this->config['ip_login_limit_use_forwarded'])
			{
				$sql .= " AND attempt_forwarded_for = '" . $this->db->sql_escape($this->user->forwarded_for) . "'";
			}
			else
			{
				$sql .= " AND attempt_ip = '" . $this->db->sql_escape($this->user->ip) . "' ";
			}

			$result = $this->db->sql_query($sql);
			$attempts = (int) $this->db->sql_fetchfield('attempts');
			$this->db->sql_freeresult($result);

			$attempt_data = array(
				'attempt_ip'			=> $this->user->ip,
				'attempt_browser'		=> trim(substr($this->user->browser, 0, 149)),
				'attempt_forwarded_for'	=> $this->user->forwarded_for,
				'attempt_time'			=> time(),
				'user_id'				=> $user_id,
				'username'				=> $username,
				'username_clean'		=> $username_clean,
			);
			$sql = 'INSERT INTO ' . LOGIN_ATTEMPT_TABLE . $this->db->sql_build_array('INSERT', $attempt_data);
			$result = $this->db->sql_query($sql);
		}
		else
		{
			$attempts = 0;
		}

		if ($this->config['ip_login_limit_max'] && $attempts >= $this->config['ip_login_limit_max'])
		{
			throw new phpbb_auth_exception('LOGIN_ERROR_ATTEMPTS');
		}
	}
}