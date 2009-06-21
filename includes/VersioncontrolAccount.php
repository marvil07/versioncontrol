<?php
require_once 'VersioncontrolAccount.php';
require_once 'VersioncontrolRepository.php';

/**
 * Account class
 *
 * This class provides the way to manage users accounts.
 */
class VersioncontrolAccount implements ArrayAccess {
  // Attributes
  /**
    * VCS's username
    *
    * @var    string
    * @access public
    */
  public $vcs_username;

  /**
    * Drupal user id
    *
    * @var    int
    * @access public
    */
  public $uid;

  /**
   * Repo user id
   *
   * @var    VersioncontrolRepository
   * @access public
   */
  public $repository;

  // Operations
  /**
   * Constructor
   */
  public function __construct($vcs_username, $uid, $repository=NULL) {
    $this->vcs_username = $vcs_username;
    $this->uid = $uid;
    $this->repository = $repository;
  }

  /**
    * Retrieve a set of Drupal uid / VCS username mappings
    * that match the given constraints.
    *
    * @access public
    * @static      
    * @param $constraints
    *   An optional array of constraints. Possible array elements are:
    *
    *   - 'uids': An array of Drupal user ids. If given, only accounts that
    *        correspond to these Drupal users will be returned.
    *   - 'repo_ids': An array of repository ids. If given, only accounts
    *        in the corresponding repositories will be returned.
    *   - 'usernames': An array of system specific VCS usernames,
    *        like array('dww', 'jpetso'). If given, only accounts
    *        with these VCS usernames will be returned.
    *   - 'usernames_by_repository': A structured array that looks like
    *        array($repo_id => array('dww', 'jpetso'), ...).
    *        You might want this if you combine multiple username and repository
    *        constraints, otherwise you can well do without.
    *
    * @param $include_unauthorized
    *   If FALSE (which is the default), this function does not return accounts
    *   that are pending, queued, disabled, blocked, or otherwise non-approved.
    *   If TRUE, all accounts are returned, regardless of their status.
    *
    * @return
    *   A structured array that looks like
    *   array($drupal_uid => array($repo_id => 'VCS username', ...), ...).
    *   If not a single account matches these constraints,
    *   an empty array is returned.
    */
  public static function getAccounts($constraints = array(), $include_unauthorized = FALSE) {
    $and_constraints = array();
    $params = array();

    // Filter by Drupal user id.
    if (isset($constraints['uids'])) {
      if (empty($constraints['uids'])) {
        return array();
      }
      $or_constraints = array();
      foreach ($constraints['uids'] as $uid) {
        $or_constraints[] = 'uid = %d';
        $params[] = $uid;
      }
      $and_constraints[] = '('. implode(' OR ', $or_constraints) .')';
    }

    // Filter by repository id.
    if (isset($constraints['repo_ids'])) {
      if (empty($constraints['repo_ids'])) {
        return array();
      }
      $or_constraints = array();
      foreach ($constraints['repo_ids'] as $repo_id) {
        $or_constraints[] = 'repo_id = %d';
        $params[] = $repo_id;
      }
      $and_constraints[] = '('. implode(' OR ', $or_constraints) .')';
    }

    // Filter by VCS username.
    if (isset($constraints['usernames'])) {
      if (empty($constraints['usernames'])) {
        return array();
      }
      $or_constraints = array();
      foreach ($constraints['usernames'] as $username) {
        $or_constraints[] = "username = '%s'";
        $params[] = $username;
      }
      $and_constraints[] = '('. implode(' OR ', $or_constraints) .')';
    }

    // Filter by usernames-by-repository.
    if (isset($constraints['usernames_by_repository'])) {
      if (empty($constraints['usernames_by_repository'])) {
        return array();
      }
      $or_constraints = array();
      foreach ($usernames_by_repository as $repo_id => $usernames) {
        $repo_constraint = 'repo_id = %d';
        $params[] = $repo_id;

        $username_constraints = array();
        foreach ($usernames as $username) {
          $username_constraints[] = "username = '%s'";
          $params[] = $username;
        }

        $or_constraints[] = '('. $repo_constraint
                            .' AND ('. implode(' OR ', $username_constraints) .'))';
      }
      $and_constraints[] = '('. implode(' OR ', $or_constraints) .')';
    }

    // All the constraints have been gathered, assemble them to a WHERE clause.
    $where = empty($and_constraints) ? '' : ' WHERE '. implode(' AND ', $and_constraints);

    // Execute the query.
    $result = db_query('SELECT uid, repo_id, username
                        FROM {versioncontrol_accounts}
                        '. $where .'
                        ORDER BY uid', $params);

    // Assemble the return value.
    $account_rows = array();
    $repo_ids = array();
    while ($account = db_fetch_object($result)) {
      $repo_ids[] = $account->repo_id;
      $account_rows[] = new VersioncontrolAccount($account->username, $account->uid, new VersioncontrolRepository($account->repo_id));
    }
    if (empty($repo_ids)) {
      return array();
    }
    $repo_ids = array_unique($repo_ids);

    $repositories = VersioncontrolRepository::getRepositories(array('repo_ids' => $repo_ids));
    $accounts = array();

    foreach ($account_rows as $account) {
      $account->repository = $repositories[$account->repository->repo_id];
      // Only include approved accounts, except in case the caller said otherwise.
      if ($include_unauthorized
          || $account->repository->isAccountAuthorized($account->uid)) {
        if (!isset($accounts[$account->uid])) {
          $accounts[$account->uid] = array();
        }
        $accounts[$account->uid][$account->repository->repo_id] = $account;
      }
    }
    return $accounts;
  }

  /**
   * Return the most accurate guess on what the VCS username for a Drupal user
   * might look like in the given repository.
   *
   * @access public
   * @param $repository
   *   The repository where the the VCS account exists or will be located.
   * @param $user
   *  The Drupal user who wants to register an account.
   */
  public function usernameSuggestion($user) {
    if (versioncontrol_backend_implements($this->repository['vcs'], 'account_username_suggestion')) {
      return _versioncontrol_call_backend($this->repository['vcs'],
        'account_username_suggestion', array($this->repository, $user)
      );
    }
    return strtr(drupal_strtolower($user->name),
      array(' ' => '', '@' => '', '.' => '', '-' => '', '_' => '', '.' => '')
    );
  }

  /**
   * Determine if the given repository allows a username to exist.
   *
   * @access public
   * @param $vcs
   *   The repository where the the VCS account exists or will be located.
   * @param $username
   *  The username to check. It is passed by reference so if the username is
   *  valid but needs minor adaptions (such as cutting away unneeded parts) then
   *  it the backend can modify it before returning the result.
   *
   * @return
   *   TRUE if the username is valid, FALSE if not.
   */
  public function isUsernameValid(&$username) {
    if (versioncontrol_backend_implements($this->repository->vcs, 'is_account_username_valid')) {
      // Because $username is a by-reference argument, make it a direct call.
      $function = 'versioncontrol_'. $this->repository->vcs .'_is_account_username_valid';
      return $function($this->repository, $username);
    }
    else if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
      return FALSE;
    }
    return TRUE;
  }


  /**
   * Update a VCS user account in the database, and call the necessary
   * module hooks. The @p $repository and @p $uid parameters must stay the same
   * values as the one given on account creation, whereas @p $username and
   * @p $additional_data may change.
   *
   * @access public
   * @param $uid
   *   The Drupal user id corresponding to the VCS username.
   * @param $username
   *   The VCS specific username (a string).
   * @param $repository
   *   The repository where the user has its VCS account.
   * @param $additional_data
   *   An array of additional author information. Modules can fill this array
   *   by implementing hook_versioncontrol_account_submit().
   */
  public function update($repository, $uid, $username, $additional_data = array()) {
    $old_username = versioncontrol_get_account_username_for_uid($repository['repo_id'], $uid, TRUE);
    $username_changed = ($username != $old_username);

    if ($username_changed) {
      db_query("UPDATE {versioncontrol_accounts}
                SET username = '%s'
                WHERE uid = %d AND repo_id = %d",
                $username, $uid, $repository['repo_id']
      );
    }

    // Provide an opportunity for the backend to add its own stuff.
    if (versioncontrol_backend_implements($repository['vcs'], 'account')) {
      _versioncontrol_call_backend(
        $repository['vcs'], 'account',
        array('update', $uid, $username, $repository, $additional_data)
      );
    }

    // Update the operations table.
    if ($username_changed) {
      db_query("UPDATE {versioncontrol_operations}
                SET uid = 0
                WHERE uid = %d AND repo_id = %d",
                $uid, $repository['repo_id']);
      db_query("UPDATE {versioncontrol_operations}
                SET uid = %d
                WHERE username = '%s' AND repo_id = %d",
                $uid, $username, $repository['repo_id']);
    }

    // Everything's done, let the world know about it!
    module_invoke_all('versioncontrol_account',
      'update', $uid, $username, $repository, $additional_data
    );

    watchdog('special',
      'Version Control API: updated @username account in repository @repository',
      array('@username' => $username, '@repository' => $repository['name']),
      WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-accounts')
    );
  }


  /**
   * Insert a VCS user account into the database,
   * and call the necessary module hooks.
   *
   * @access public
   * @param $additional_data
   *   An array of additional author information. Modules can fill this array
   *   by implementing hook_versioncontrol_account_submit().
   */
  public function insert($additional_data = array()) {
    db_query(
      "INSERT INTO {versioncontrol_accounts} (uid, repo_id, username)
       VALUES (%d, %d, '%s')", $this->uid, $this->repository->repo_id, $this->vcs_username
    );

    // Provide an opportunity for the backend to add its own stuff.
    if (versioncontrol_backend_implements($this->repository->vcs, 'account')) {
      _versioncontrol_call_backend(
        $this->repository->vcs, 'account',
        array('insert', $this->uid, $this->vcs_username, $this->repository, $additional_data)
      );
    }

    // Update the operations table.
    // FIXME differenciate author and commiter
    db_query("UPDATE {versioncontrol_operations}
              SET uid = %d
              WHERE author = '%s' AND repo_id = %d",
              $this->uid, $this->vcs_username, $this->repository->repo_id);

    // Everything's done, let the world know about it!
    module_invoke_all('versioncontrol_account',
      'insert', $this->uid, $this->vcs_username, $this->repository, $additional_data
    );

    watchdog('special',
      'Version Control API: added @vcs_username account in repository @repository',
      array('@vcs_username' => $this->vcs_username, '@repository' => $this->repository->name),
      WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-accounts')
    );
  }

  /**
   * Delete a VCS user account from the database, set all commits with this
   * account as author to user 0 (anonymous), and call the necessary hooks.
   *
    * @access public
   * @param $repository
   *   The repository where the user has its VCS account.
   * @param $uid
   *   The Drupal user id corresponding to the VCS username.
   * @param $username
   *   The VCS specific username (a string).
   */
  public function delete($repository, $uid, $username) {
    // Update the operations table.
    db_query('UPDATE {versioncontrol_operations}
              SET uid = 0
              WHERE uid = %d AND repo_id = %d',
              $this->uid, $this->repository->repo_id);

    // Announce deletion of the account before anything has happened.
    module_invoke_all('versioncontrol_account',
      'delete', $this->uid, $this->vcs_username, $this->repository, array()
    );

    // Provide an opportunity for the backend to delete its own stuff.
    if (versioncontrol_backend_implements($this->repository->vcs, 'account')) {
      _versioncontrol_call_backend(
        $this->repository->vcs, 'account',
        array('delete', $this->uid, $this->vcs_username, $this->repository, array())
      );
    }

    db_query('DELETE FROM {versioncontrol_accounts}
              WHERE uid = %d AND repo_id = %d',
              $this->uid, $this->repository->repo_id);

    watchdog('special',
      'Version Control API: deleted @username account in repository @repository',
      array('@username' => $this->vcs_username, '@repository' => $this->repository->name),
      WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-accounts')
    );
  }

  /**
    * Menu wildcard loader for '%versioncontrol_user_accounts':
    * Load all VCS accounts of a given user (in the format that
    * VersioncontrolAccount::getAccounts() returns) and return either that
    * or FALSE if no VCS accounts exist for this user.
    *
    * @access public
    * @static
    * @param $uid
    *   Drupal user id of the user whose VCS accounts should be loaded.
    * @param $include_unauthorized
    *   Will be passed on to VersioncontrolAccount::getAccounts(), see the
    *   API documentation of that function.
    */
  public static function userAccountsLoad($uid, $include_unauthorized = FALSE) {
    $accounts = VersioncontrolAccount::getAccounts(array('uids' => array($uid)), $include_unauthorized);
    return empty($accounts) ? FALSE : $accounts;
  }

  //ArrayAccess interface implementation
  public function offsetExists($offset) {
    return isset($this->$offset);
  }
  public function offsetGet($offset) {
    return $this->$offset;
  }
  public function offsetSet($offset, $value) {
    $this->$offset = $value;
  }
  public function offsetUnset($offset) {
    unset($this->$offset);
  }

}
