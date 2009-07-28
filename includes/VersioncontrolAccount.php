<?php
// $Id$
/**
 * @file
 * Account class
 */

require_once 'VersioncontrolAccount.php';
require_once 'VersioncontrolRepository.php';

/**
 * Account class
 *
 * This class provides the way to manage users accounts.
 */
abstract class VersioncontrolAccount implements ArrayAccess {
  // Attributes
  /**
   * VCS's username
   *
   * @var    string
   */
  public $vcs_username;

  /**
   * Drupal user id
   *
   * @var    int
   */
  public $uid;

  /**
   * Repo user id
   *
   * @var    VersioncontrolRepository
   */
  public $repository;

  // Operations
  /**
   * Constructor
   */
  public function __construct($vcs_username, $uid, $repository = NULL) {
    $this->vcs_username = $vcs_username;
    $this->uid = $uid;
    $this->repository = $repository;
  }

  /**
   * Retrieve a set of Drupal uid / VCS username mappings
   * that match the given constraints.
   *
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
      $account_rows[] = array('username' => $account->username, 'uid' => $account->uid, 'repo_id' => $account->repo_id);
    }
    if (empty($repo_ids)) {
      return array();
    }
    $repo_ids = array_unique($repo_ids);

    $repositories = VersioncontrolRepositoryCache::getInstance()->getRepositories(array('repo_ids' => $repo_ids));
    $accounts = array();

    foreach ($account_rows as $account_raw) {
      $repo = $repositories[$account_raw['repo_id']];
      $accountObj = new $repo->backend->classes['account']($account_raw['username'], $account_raw['uid'], $repo);
      // Only include approved accounts, except in case the caller said otherwise.
      if ($include_unauthorized
          || $accountObj->repository->isAccountAuthorized($accountObj->uid)) {
        if (!isset($accounts[$accountObj->uid])) {
          $accounts[$accountObj->uid] = array();
        }
        $accounts[$accountObj->uid][$accountObj->repository->repo_id] = $accountObj;
      }
    }
    return $accounts;
  }

  /**
   * Return the most accurate guess on what the VCS username for a Drupal user
   * might look like in the repository's account.
   *
   * @param $user
   *  The Drupal user who wants to register an account.
   */
  public function usernameSuggestion($user) {
    if (versioncontrol_backend_implements($this->repository->vcs, 'account_username_suggestion')) {
      return _versioncontrol_call_backend($this->repository->vcs,
        'account_username_suggestion', array($this->repository, $user)
      );
    }
    return strtr(drupal_strtolower($user->name),
      array(' ' => '', '@' => '', '.' => '', '-' => '', '_' => '', '.' => '')
    );
  }

  /**
   * Determine if the account repository allows a username to exist.
   *
   * @param $username
   *  The username to check. It is passed by reference so if the username is
   *  valid but needs minor adaptions (such as cutting away unneeded parts) then
   *  it the backend can modify it before returning the result.
   *
   * @return
   *   TRUE if the username is valid, FALSE if not.
   */
  public function isUsernameValid(&$username) {
    if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Update a VCS user account in the database, and call the necessary
   * module hooks. The account repository and uid must stay the same values as
   * the one given on account creation, whereas vcs_username and
   * @p $additional_data may change.
   *
   * @param $username
   *   The VCS specific username (a string). Here we are using an explicit
   *   parameter instead of taking the vcs_username data member to be able to
   *   verify is it changed, there would be lots of operations, so we do not
   *   want to update them if it's not necessary.
   * @param $additional_data
   *   An array of additional author information. Modules can fill this array
   *   by implementing hook_versioncontrol_account_submit().
   */
  public final function update($username, $additional_data = array()) {
    $repo_id = $this->repository->repo_id;
    $username_changed = ($username != $this->vcs_username);

    if ($username_changed) {
      $this->vcs_username = $username;
      db_query("UPDATE {versioncontrol_accounts}
                SET username = '%s'
                WHERE uid = %d AND repo_id = %d",
                $this->vcs_username, $this->uid, $repo_id
      );
    }

    // Provide an opportunity for the backend to add its own stuff.
    $this->_update($additional_data);

    if ($username_changed) {
      db_query("UPDATE {versioncontrol_operations}
                SET uid = 0
                WHERE uid = %d AND repo_id = %d",
                $this->uid, $repo_id);
      db_query("UPDATE {versioncontrol_operations}
                SET uid = %d
                WHERE committer = '%s' AND repo_id = %d",
                $this->uid, $this->vcs_username, $repo_id);
    }

    // Everything's done, let the world know about it!
    module_invoke_all('versioncontrol_account',
      'update', $this->uid, $this->vcs_username, $this->repository, $additional_data
    );

    watchdog('special',
      'Version Control API: updated @username account in repository @repository',
      array('@username' => $this->vcs_username, '@repository' => $this->repository->name),
      WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-accounts')
    );
  }

  /**
   * Let child backend account classes update information.
   */
  protected function _update($additional_data) {
  }

  /**
   * Insert a VCS user account into the database,
   * and call the necessary module hooks.
   *
   * @param $additional_data
   *   An array of additional author information. Modules can fill this array
   *   by implementing hook_versioncontrol_account_submit().
   */
  public final function insert($additional_data = array()) {
    db_query(
      "INSERT INTO {versioncontrol_accounts} (uid, repo_id, username)
       VALUES (%d, %d, '%s')", $this->uid, $this->repository->repo_id, $this->vcs_username
    );

    // Provide an opportunity for the backend to add its own stuff.
    $this->_insert($additional_data);

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
   * Let child backend account classes add information
   */
  protected function _insert($additional_data) {
  }

  /**
   * Delete a VCS user account from the database, set all commits with this
   * account as author to user 0 (anonymous), and call the necessary hooks.
   */
  public final function delete() {
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
    $this->_delete();

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
   * Let child backend account classes delete information.
   */
  protected function _delete($additional_data) {
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
