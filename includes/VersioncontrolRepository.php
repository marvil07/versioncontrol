<?php
require_once 'VersioncontrolOperation.php';
require_once 'VersioncontrolBackend.php';

/**
 * Contain fundamental information about the repository.
 *
 */
class VersioncontrolRepository implements ArrayAccess {
  // Attributes
  /**
   * db identifier
   *
   * @var    int
   * @access public
   */
  public $repo_id;

  /**
   * repository name inside drupal
   *
   * @var    string
   * @access public
   */
  public $name;

  /**
   * VCS string identifier
   *
   * @var    string
   * @access public
   */
  public $vcs;

  /**
   * where it is
   *
   * @var    string
   * @access public
   */
  public $root;

  /**
   * how ot authenticate
   *
   * @var    string
   * @access public
   */
  public $authorization_method;

  /**
   * XXX
   *
   * @var    string
   * @access public
   */
  public $url_backend;

  /**
   * list of urls associated with this repository
   *
   * @var    array
   * @access public
   */
  public $urls;

  /**
   * cache for already loaded repositories
   *
   * @var    array
   * @access private
   */
  private static $repository_cache = array();

  // Associations
  // Operations
  /**
   * Constructor
   */
  public function __construct() {
    $argv = func_get_args();
    switch (func_num_args()) {
    case 1:
      self::__construct_by_id($argv[0]);
      break;
    case 6:
      self::__construct_by_all($argv[0], $argv[1], $argv[2], $argv[3], $argv[4], $argv[5]);
      break;
    case 7:
      self::__construct_by_all($argv[0], $argv[1], $argv[2], $argv[3], $argv[4], $argv[5], $argv[6]);
      break;
    }
  }

  /**
   * minimal constructor
   */
  private function __construct_by_id($repo_id) {
    $this->repo_id = $repo_id;
  }

  /**
   * minimal constructor
   */
  private function __construct_by_all($id, $name, $vcs, $root, $authorization_method, $url_backend, $urls = array()) {
    $this->repo_id = $id;
    $this->name = $name;
    $this->vcs = $vcs;
    $this->root = $root;
    $this->authorization_method = $authorization_method;
    $this->url_backend = $url_backend;
    $this->urls = $urls;
  }

  /**
   * Title callback for repository arrays.
   * 
   * @access public
   */
  public function titleCallback($repository) {
    return check_plain($repository['name']);
  }

  /**
   * Convenience function for retrieving one single repository by repository id.
   * 
   * @access public
   * @static
   * @return
   *   A single repository array that consists of the following elements:
   *
   *   - 'repo_id': The unique repository id.
   *   - 'name': The user-visible name of the repository.
   *   - 'vcs': The unique string identifier of the version control system
   *        that powers this repository.
   *   - 'root': The root directory of the repository. In most cases,
   *        this will be a local directory (e.g. '/var/repos/drupal'),
   *        but it may also be some specialized string for remote repository
   *        access. How this string may look like depends on the backend.
   *   - 'authorization_method': The string identifier of the repository's
   *        authorization method, that is, how users may register accounts
   *        in this repository. Modules can provide their own methods
   *        by implementing hook_versioncontrol_authorization_methods().
   *   - 'url_backend': The prefix (excluding the trailing underscore)
   *        for URL backend retrieval functions.
   *   - '[xxx]_specific': An array of VCS specific additional repository
   *        information. How this array looks like is defined by the
   *        corresponding backend module (versioncontrol_[xxx]).
   *
   *   If no repository corresponds to the given repository id, NULL is returned.
   */
  public static function getRepository($repo_id) {
    $repos = self::getRepositories(array('repo_ids' => array($repo_id)));

    foreach ($repos as $repo_id => $repository) {
      return $repository;
    }
    return NULL; // in case of empty($repos)
  }

  /**
   * Retrieve a set of repositories that match the given constraints.
   *
   * @access public
   * @static
   * @param $constraints
   *   An optional array of constraints. Possible array elements are:
   *
   *   - 'vcs': An array of strings, like array('cvs', 'svn', 'git').
   *       If given, only repositories for these backends will be returned.
   *   - 'repo_ids': An array of repository ids.
   *       If given, only the corresponding repositories will be returned.
   *   - 'names': An array of repository names, like
   *       array('Drupal CVS', 'Experimental SVN'). If given,
   *       only repositories with these repository names will be returned.
   *   - '[xxx]_specific': An array of VCS specific constraints. How this array
   *       looks like is defined by the corresponding backend module
   *       (versioncontrol_[xxx]). Other backend modules won't get to see this
   *       constraint, so in theory you can provide one of those for each backend
   *       in one single query.
   *
   * @return
   *   An array of repositories where the key of each element is the
   *   repository id. The corresponding value contains a structured array
   *   with the following keys:
   *
   *   - 'repo_id': The unique repository id.
   *   - 'name': The user-visible name of the repository.
   *   - 'vcs': The unique string identifier of the version control system
   *        that powers this repository.
   *   - 'root': The root directory of the repository. In most cases,
   *        this will be a local directory (e.g. '/var/repos/drupal'),
   *        but it may also be some specialized string for remote repository
   *        access. How this string may look like depends on the backend.
   *   - 'authorization_method': The string identifier of the repository's
   *        authorization method, that is, how users may register accounts
   *        in this repository. Modules can provide their own methods
   *        by implementing hook_versioncontrol_authorization_methods().
   *   - 'url_backend': The prefix (excluding the trailing underscore)
   *        for URL backend retrieval functions.
   *   - '[xxx]_specific': An array of VCS specific additional repository
   *        information. How this array looks like is defined by the
   *        corresponding backend module (versioncontrol_[xxx]).
   *
   *   If not a single repository matches these constraints,
   *   an empty array is returned.
   */
  public static function getRepositories($constraints = array()) {
    $backends = versioncontrol_get_backends();
    $auth_methods = versioncontrol_get_authorization_methods();

    // "Normalize" repo_ids to integers so the cache doesn't distinguish
    // between string and integer values.
    if (isset($constraints['repo_ids'])) {
      $repo_ids = array();
      foreach ($constraints['repo_ids'] as $repo_id) {
        $repo_ids[] = (int) $repo_id;
      }
      $constraints['repo_ids'] = $repo_ids;
    }

    $constraints_serialized = serialize($constraints);
    if (isset($repository_cache[$constraints_serialized])) {
      return $repository_cache[$constraints_serialized];
    }

    list($and_constraints, $params) =
      _versioncontrol_construct_repository_constraints($constraints, $backends);

    // All the constraints have been gathered, assemble them to a WHERE clause.
    $where = empty($and_constraints) ? '' : ' WHERE '. implode(' AND ', $and_constraints);

    $result = db_query('SELECT * FROM {versioncontrol_repositories} r'. $where, $params);

    // Sort the retrieved repositories by backend.
    $repositories_by_backend = array();

    while ($repository = db_fetch_array($result)) {
      if (!isset($backends[$repository['vcs']])) {
        // don't include repositories for which no backend module exists
        continue;
      }
      if (!isset($auth_methods[$repository['authorization_method']])) {
        $repository['authorization_method'] = _versioncontrol_get_fallback_authorization_method();
      }
      if (!isset($repositories_by_backend[$repository['vcs']])) {
        $repositories_by_backend[$repository['vcs']] = array();
      }
      $repository[$repository['vcs'] .'_specific'] = array();
      $repositories_by_backend[$repository['vcs']][$repository['repo_id']] = $repository;
    }

    $repositories_by_backend = self::_amend_repositories(
      $repositories_by_backend, $backends
    );

    // Add the fully assembled repositories to the result array.
    $result_repositories = array();
    foreach ($repositories_by_backend as $vcs => $vcs_repositories) {
      foreach ($vcs_repositories as $repository) {
        $vcs_repository = new VersioncontrolRepository($repository['repo_id'], $repository['name'], $repository['vcs'], $repository['root'], $repository['authorization_method'], $repository['url_backend']);
        //FIXME: another idea for this?
        $vcs_specific_key = $repository['vcs'] .'_specific';
        $vcs_repository->$vcs_specific_key = $repository[$repository['vcs'] .'_specific'];
        $result_repositories[$repository['repo_id']] = $vcs_repository;
      }
    }

    $repository_cache[$constraints_serialized] = $result_repositories; // cache the results
    return $result_repositories;
  }

  /**
   * Fetch VCS specific repository data additions, either by ourselves (if the
   * VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES flag has been set by the backend)
   * and/or by calling [vcs_backend]_alter_repositories().
   *
   * @access private
   * @static
   * @param $repositories_by_backend
   * @param $backends
   * @param $constraints
   */
  private function _amend_repositories($repositories_by_backend, $backends, $constraints = array()) {
    foreach ($repositories_by_backend as $vcs => $vcs_repositories) {
      $is_autoadd = in_array(VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES,
                             $backends[$vcs]['flags']);

      if ($is_autoadd) {
        $repo_ids = array();
        foreach ($vcs_repositories as $repo_id => $repository) {
          $repo_ids[] = $repo_id;
        }
        $additions = _versioncontrol_db_get_additions(
          'versioncontrol_'. $vcs .'_repositories', 'repo_id', $repo_ids
        );

        foreach ($additions as $repo_id => $addition) {
          if (isset($vcs_repositories[$repo_id])) {
            $vcs_repositories[$repo_id][$vcs .'_specific'] = $addition;
          }
        }
      }

      $vcs_specific_constraints = isset($constraints[$vcs .'_specific'])
                                  ? $constraints[$vcs .'_specific']
                                  : array();

      // Provide an opportunity for the backend to add its own stuff.
      if (versioncontrol_backend_implements($vcs, 'alter_repositories')) {
        $function = 'versioncontrol_'. $vcs .'_alter_repositories';
        $function($vcs_repositories, $vcs_specific_constraints);
      }
      $repositories_by_backend[$vcs] = $vcs_repositories;
    }
    return $repositories_by_backend;
  }

  /**
   * Retrieve known branches and/or tags in a repository as a set of label arrays.
   *
   * @access public
   * @param $repository
   *   The repository of which the labels should be retrieved.
   * @param $constraints
   *   An optional array of constraints. If no constraints are given, all known
   *   labels for a repository will be returned. Possible array elements are:
   *
   *   - 'label_ids': An array of label ids. If given, only labels with one of
   *        these identifiers will be returned.
   *   - 'type': Either VERSIONCONTROL_OPERATION_BRANCH or
   *        VERSIONCONTROL_OPERATION_TAG. If given, only labels of this type
   *        will be returned.
   *   - 'names': An array of label names to search for. If given, only labels
   *        matching one of these names will be returned. Matching is done with
   *        SQL's LIKE operator, which means you can use the percentage sign
   *        as wildcard.
   *
   * @return
   *   An array of label arrays, where a label array consists of the following
   *   array elements:
   *
   *   - 'label_id': The label identifier (a simple integer), used for unique
   *        identification of branches and tags in the database.
   *   - 'name': The branch or tag name (a string).
   *   - 'type': Whether this label is a branch (indicated by the
   *        VERSIONCONTROL_OPERATION_BRANCH constant) or a tag
   *        (VERSIONCONTROL_OPERATION_TAG).
   *
   *   If not a single known label in the given repository matches these
   *   constraints, an empty array is returned.
   */
  public function getLabels($repository, $constraints = array()) {
    $and_constraints = array('repo_id = %d');
    $params = array($repository['repo_id']);

    // Filter by label id.
    if (isset($constraints['label_ids'])) {
      if (empty($constraints['label_ids'])) {
        return array();
      }
      $or_constraints = array();
      foreach ($constraints['label_ids'] as $label_id) {
        $or_constraints[] = 'label_id = %d';
        $params[] = $label_id;
      }
      $and_constraints[] = '('. implode(' OR ', $or_constraints) .')';
    }

    // Filter by label name.
    if (isset($constraints['names'])) {
      if (empty($constraints['names'])) {
        return array();
      }
      $or_constraints = array();
      foreach ($constraints['names'] as $name) {
        $or_constraints[] = "name LIKE '%s'";
        // Escape the percentage sign in order to get it to appear as '%' in the
        // actual query, as db_query() uses the single '%' also for replacements
        // like '%d' and '%s'.
        $params[] = str_replace('%', '%%', $name);
      }
      $and_constraints[] = '('. implode(' OR ', $or_constraints) .')';
    }

    // Filter by type.
    if (isset($constraints['type'])) {
      // There are only two types of labels (branches and tags), so a list of
      // types doesn't make a lot of sense for this constraint. So, this one is
      // simpler than the other ones.
      $and_constraints[] = 'type = %d';
      $params[] = $constraints['type'];
    }

    // All the constraints have been gathered, assemble them to a WHERE clause.
    $and_constraints = implode(' AND ', $and_constraints);

    // Execute the query.
    $result = db_query('SELECT label_id, name, type FROM {versioncontrol_labels}
                        WHERE '. $and_constraints .'
                        ORDER BY uid', $params);

    // Assemble the return value.
    $labels = array();
    while ($label = db_fetch_array($result)) {
      $labels[] = $label;
    }
    return $labels;
  }

  /**
   * Return TRUE if the account is authorized to commit to the given
   * repository, or FALSE otherwise. Only call this function on existing
   * accounts or uid 0, the return value for all other
   * uid/repository combinations is undefined.
   *
   * @access public
   * @param $repository
   *   The repository where the status should be checked. (Note that the user's
   *   authorization status may differ for each repository.)
   * @param $uid
   *   The user id of the checked account.
   */
  public function isAccountAuthorized($uid) {
    if (!$uid) {
      return FALSE;
    }
    $approved = array();

    foreach (module_implements('versioncontrol_is_account_authorized') as $module) {
      $function = $module .'_versioncontrol_is_account_authorized';

      // If at least one hook_versioncontrol_is_account_authorized()
      // returns FALSE, the account is assumed not to be approved.
      if ($function($this, $uid) === FALSE) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Update a repository in the database, and call the necessary hooks.
   * The 'repo_id' and 'vcs' properties of the repository array must stay
   * the same as the ones given on repository creation,
   * whereas all other values may change.
   *
   * @access public
   * @param $repository
   *   The repository array containing the new or existing repository.
   *   It's a single repository array like the one returned by
   *   versioncontrol_get_repository(), so it consists of the following elements:
   *
   *   - 'repo_id': The unique repository id.
   *   - 'name': The user-visible name of the repository.
   *   - 'vcs': The unique string identifier of the version control system
   *        that powers this repository.
   *   - 'root': The root directory of the repository. In most cases,
   *        this will be a local directory (e.g. '/var/repos/drupal'),
   *        but it may also be some specialized string for remote repository
   *        access. How this string may look like depends on the backend.
   *   - 'authorization_method': The string identifier of the repository's
   *        authorization method, that is, how users may register accounts
   *        in this repository. Modules can provide their own methods
   *        by implementing hook_versioncontrol_authorization_methods().
   *   - 'url_backend': The prefix (excluding the trailing underscore)
   *        for URL backend retrieval functions.
   *   - '[xxx]_specific': An array of VCS specific additional repository
   *        information. How this array looks like is defined by the
   *        corresponding backend module (versioncontrol_[xxx]).
   *        If the backend has registered itself with the
   *        VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES option, all items of
   *        this array will automatically be inserted into the
   *        {versioncontrol_[xxx]_commits} table.
   *
   * @param $repository_urls
   *   An array of repository viewer URLs. How this array looks like is
   *   defined by the corresponding URL backend.
   */
  public function update($repository, $repository_urls) {
    drupal_write_record('versioncontrol_repositories', $repository, 'repo_id');

    $repository_urls['repo_id'] = $repository['repo_id']; // for drupal_write_record()
    drupal_write_record('versioncontrol_repository_urls', $repository_urls, 'repo_id');
    unset($repository_urls['repo_id']);

    // Auto-add commit info from $commit['[xxx]_specific'] into the database.
    $backends = versioncontrol_get_backends();
    $vcs = $repository['vcs'];
    $is_autoadd = in_array(VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES,
                           $backends[$vcs]['flags']);
    if ($is_autoadd) {
      $table_name = 'versioncontrol_'. $vcs .'_repositories';
      $elements = $repository[$vcs .'_specific'];
      $elements['repo_id'] = $repository['repo_id'];
      _versioncontrol_db_update_additions($table_name, 'repo_id', $elements);
    }

    // Provide an opportunity for the backend to add its own stuff.
    if (versioncontrol_backend_implements($vcs, 'repository')) {
      _versioncontrol_call_backend($vcs, 'repository', array('update', $repository));
    }

    // Everything's done, let the world know about it!
    module_invoke_all('versioncontrol_repository', 'update', $repository);

    watchdog('special',
      'Version Control API: updated repository @repository',
      array('@repository' => $repository['name']),
      WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-repositories')
    );
  }

  /**
   * Insert a repository into the database, and call the necessary hooks.
   *
   * @access public
   * @param $repository
   *   The repository array containing the new or existing repository.
   *   It's a single repository array like the one returned by
   *   versioncontrol_get_repository(), so it consists of the following elements:
   *
   *   - 'name': The user-visible name of the repository.
   *   - 'vcs': The unique string identifier of the version control system
   *        that powers this repository.
   *   - 'root': The root directory of the repository. In most cases,
   *        this will be a local directory (e.g. '/var/repos/drupal'),
   *        but it may also be some specialized string for remote repository
   *        access. How this string may look like depends on the backend.
   *   - 'authorization_method': The string identifier of the repository's
   *        authorization method, that is, how users may register accounts
   *        in this repository. Modules can provide their own methods
   *        by implementing hook_versioncontrol_authorization_methods().
   *   - 'url_backend': The prefix (excluding the trailing underscore)
   *        for URL backend retrieval functions.
   *   - '[xxx]_specific': An array of VCS specific additional repository
   *        information. How this array looks like is defined by the
   *        corresponding backend module (versioncontrol_[xxx]).
   *        If the backend has registered itself with the
   *        VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES option, all items of
   *        this array will automatically be inserted into the
   *        {versioncontrol_[xxx]_commits} table.
   *
   * @param $repository_urls
   *   An array of repository viewer URLs. How this array looks like is
   *   defined by the corresponding URL backend.
   *
   * @return
   *   The finalized repository array, including the 'repo_id' element.
   */
  public function insert($repository_urls) {
    if (isset($this->repo_id)) {
      // This is a new repository, it's not supposed to have a repo_id yet.
      unset($this->repo_id);
    }
    drupal_write_record('versioncontrol_repositories', $this);
    // drupal_write_record() has now added the 'repo_id' to the $repository array.

    $repository_urls['repo_id'] = $this->repo_id; // for drupal_write_record()
    drupal_write_record('versioncontrol_repository_urls', $repository_urls);
    unset($repository_urls['repo_id']);

    // Auto-add repository info from $repository['[xxx]_specific'] into the database.
    $backends = versioncontrol_get_backends();
    $vcs = $this->vcs;
    $is_autoadd = in_array(VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES,
                           $backends[$vcs]['flags']);
    if ($is_autoadd) {
      $table_name = 'versioncontrol_'. $vcs .'_repositories';
      $vcs_specific = $vcs .'_specific';
      $elements = $this->$vcs_specific;
      $elements['repo_id'] = $this->repo_id;
      $this->_dbInsertAdditions($table_name, $elements);
    }

    // Provide an opportunity for the backend to add its own stuff.
    if (versioncontrol_backend_implements($vcs, 'repository')) {
      _versioncontrol_call_backend($vcs, 'repository', array('insert', $this));
    }

    // Everything's done, let the world know about it!
    module_invoke_all('versioncontrol_repository', 'insert', $this);

    watchdog('special',
      'Version Control API: added repository @repository',
      array('@repository' => $this->name),
      WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-repositories')
    );
    return $this;
  }

  /**
   * Delete a repository from the database, and call the necessary hooks.
   * Together with the repository, all associated commits and accounts are
   * deleted as well.
   *
   * @access public
   * @param $repository
   *   The repository array containing the repository that is to be deleted.
   *   It's a single repository array like the one returned by
   *   versioncontrol_get_repository().
   */
  public function delete($repository) {
    // Delete operations.
    $operations = versioncontrol_get_operations(array('repo_ids' => array($repository['repo_id'])));
    foreach ($operations as $operation) {
      versioncontrol_delete_operation($operation);
    }
    unset($operations); // conserve memory, this might get quite large

    // Delete labels.
    db_query('DELETE FROM {versioncontrol_labels}
              WHERE repo_id = %d', $repository['repo_id']);

    // Delete item revisions and related source item entries.
    $result = db_query('SELECT item_revision_id
                        FROM {versioncontrol_item_revisions}
                        WHERE repo_id = %d', $repository['repo_id']);
    $item_ids = array();
    $placeholders = array();

    while ($item_revision = db_fetch_object($result)) {
      $item_ids[] = $item_revision->item_revision_id;
      $placeholders[] = '%d';
    }
    if (!empty($item_ids)) {
      $placeholders = '('. implode(',', $placeholders) .')';

      db_query('DELETE FROM {versioncontrol_source_items}
                WHERE item_revision_id IN '. $placeholders, $item_ids);
      db_query('DELETE FROM {versioncontrol_source_items}
                WHERE source_item_revision_id IN '. $placeholders, $item_ids);
      db_query('DELETE FROM {versioncontrol_item_revisions}
                WHERE repo_id = %d', $repository['repo_id']);
    }
    unset($item_ids); // conserve memory, this might get quite large
    unset($placeholders); // ...likewise

    // Delete accounts.
    $accounts = VersioncontrolAccount::getAccounts(
      array('repo_ids' => array($repository['repo_id'])), TRUE
    );
    foreach ($accounts as $uid => $usernames_by_repository) {
      foreach ($usernames_by_repository as $repo_id => $account) {
        versioncontrol_delete_account($repository, $uid, $account->vcs_username);
      }
    }

    // Announce deletion of the repository before anything has happened.
    module_invoke_all('versioncontrol_repository', 'delete', $repository);

    $vcs = $repository['vcs'];

    // Provide an opportunity for the backend to delete its own stuff.
    if (versioncontrol_backend_implements($vcs, 'repository')) {
      _versioncontrol_call_backend($vcs, 'repository', array('delete', $repository));
    }

    // Auto-delete repository info from $repository['[xxx]_specific'] from the database.
    $backends = versioncontrol_get_backends();
    if (isset($backends[$vcs])) { // not the case when called from uninstall
      $is_autoadd = in_array(VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES,
                             $backends[$vcs]['flags']);
    }
    if ($is_autoadd) {
      $table_name = 'versioncontrol_'. $vcs .'_repositories';
      _versioncontrol_db_delete_additions($table_name, 'repo_id', $repository['repo_id']);
    }

    // Phew, everything's cleaned up. Finally, delete the repository.
    db_query('DELETE FROM {versioncontrol_repositories} WHERE repo_id = %d',
             $repository['repo_id']);
    db_query('DELETE FROM {versioncontrol_repository_urls} WHERE repo_id = %d',
             $repository['repo_id']);
    db_query('DELETE FROM {versioncontrol_repository_metadata} WHERE repo_id = %d',
             $repository['repo_id']);

    watchdog('special',
      'Version Control API: deleted repository @repository',
      array('@repository' => $repository['name']),
      WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-repositories')
    );
  }


  /**
   * Export a repository's authenticated accounts to the version control system's
   * password file format.
   *
   * @access public
   * @param $repository
   *   The repository array of the repository whose accounts should be exported.
   *
   * @return
   *   The plaintext result data which could be written into the password file
   *   as is.
   */
  public function exportAccounts($repository) {
    $accounts = VersioncontrolAccount::getAccounts(array(
      'repo_ids' => array($repository['repo_id']),
    ));
    return _versioncontrol_call_backend($repository['vcs'], 'export_accounts',
                                        array($repository, $accounts));
  }

  /**
   * Generate and execute a DELETE query for the given table
   * based on name and value of the primary key.
   * In order to avoid unnecessary complexity, the primary key may not consist
   * of multiple columns and has to be a numeric value.
   * 
   * @access private
   */
  private function _dbDeleteAdditions($table_name, $primary_key_name, $primary_key) {
    db_query('DELETE FROM {'. $table_name .'}
              WHERE '. $primary_key_name .' = %d', $primary_key);
  }

  /**
   * Generate and execute an INSERT query for the given table based on key names,
   * values and types of the given array elements. This function basically
   * accomplishes the insertion part of Version Control API's 'autoadd' feature.
   * 
   * @access private
   */
  private function _dbInsertAdditions($table_name, $elements) {
    $keys = array();
    $params = array();
    $types = array();

    foreach ($elements as $key => $value) {
      $keys[] = $key;
      $params[] = is_numeric($value) ? $value : serialize($value);
      $types[] = is_numeric($value) ? '%d' : "'%s'";
    }

    db_query(
      'INSERT INTO {'. $table_name .'} ('. implode(', ', $keys) .')
       VALUES ('. implode(', ', $types) .')', $params
    );
  }

  /**
   * Generate and execute an UPDATE query for the given table based on key names,
   * values and types of the given array elements. This function basically
   * accomplishes the update part of Version Control API's 'autoadd' feature.
   * In order to avoid unnecessary complexity, the primary key may not consist
   * of multiple columns and has to be a numeric value.
   * 
   * @access private
   */
  private function _dbUpdateAdditions($table_name, $primary_key_name, $elements) {
    $set_statements = array();
    $params = array();

    foreach ($elements as $key => $value) {
      if ($key == $primary_key_name) {
        continue;
      }
      $type = is_numeric($value) ? '%d' : "'%s'";
      $set_statements[] = $key .' = '. $type;
      $params[] = is_numeric($value) ? $value : serialize($value);
    }
    $params[] = $elements[$primary_key_name];

    if (empty($set_statements)) {
      return; // no use updating the database if no values are assigned.
    }

    db_query(
      'UPDATE {'. $table_name .'}
       SET '. implode(', ', $set_statements) .'
       WHERE '. $primary_key_name .' = %d', $params
    );
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
