<?php
require_once 'VersioncontrolAccount.php';
require_once 'VersioncontrolRepository.php';

/**
 * Account class
 *
 * This class provides the way to manage users accounts.
 */
class VersioncontrolAccount {
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

  // Operations
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
      $account_rows[] = $account;
    }
    if (empty($repo_ids)) {
      return array();
    }
    $repo_ids = array_unique($repo_ids);

    $repositories = versioncontrol_get_repositories(array('repo_ids' => $repo_ids));
    $accounts = array();

    foreach ($account_rows as $account) {
      // Only include approved accounts, except in case the caller said otherwise.
      if ($include_unauthorized
          || versioncontrol_is_account_authorized($repositories[$account->repo_id], $account->uid)) {
        if (!isset($accounts[$account->uid])) {
          $accounts[$account->uid] = array();
        }
        $accounts[$account->uid][$account->repo_id] = $account->username;
      }
    }
    return $accounts;
  }

  /**
    * XXX
    * 
    * @access public
    */
  public function usernameSuggestion()
    {
      trigger_error('Not Implemented!', E_USER_WARNING);
  }

  /**
    * XXX
    * 
    * @access public
    */
  public function isUsernameValid()
    {
      trigger_error('Not Implemented!', E_USER_WARNING);
  }

  /**
    * XXX
    * 
    * @access public
    */
  public function update()
    {
      trigger_error('Not Implemented!', E_USER_WARNING);
  }

  /**
    * XXX
    * 
    * @access public
    */
  public function insert()
    {
      trigger_error('Not Implemented!', E_USER_WARNING);
  }

  /**
    * XXX
    * 
    * @access public
    */
  public function delete()
    {
      trigger_error('Not Implemented!', E_USER_WARNING);
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

}
