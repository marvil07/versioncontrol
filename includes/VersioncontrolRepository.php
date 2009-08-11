<?php
// $Id$
/**
 * @file
 * Repo class
 */


require_once 'VersioncontrolOperation.php';
require_once 'VersioncontrolBackend.php';

/**
 * Contain fundamental information about the repository.
 */
abstract class VersioncontrolRepository implements ArrayAccess {
  // Attributes
  /**
   * db identifier
   *
   * @var    int
   */
  public $repo_id;

  /**
   * repository name inside drupal
   *
   * @var    string
   */
  public $name;

  /**
   * VCS string identifier
   *
   * @var    string
   */
  public $vcs;

  /**
   * where it is
   *
   * @var    string
   */
  public $root;

  /**
   * how ot authenticate
   *
   * @var    string
   */
  public $authorization_method = 'versioncontrol_admin';

  /**
   * name of the url backend for this repo
   *
   * @var    string
   */
  public $url_backend;

  /**
   * url handler for this repo
   *
   * @var    VersioncontrolRepositoryUrlHandler
   */
  public $url_handler;

  /**
   * An array of additional per-repository settings, mostly populated by
   * third-party modules. It is serialized on DB.
   */
  public $data = array();

  protected $built = FALSE;

  // Associations
  /**
   * The backend associated with this repository
   *
   * @var VersioncontrolBackend
   */
  public $backend;

  // Operations
  /**
   * Constructor
   */
  public function __construct($repo_id, $args = array(), $buildSelf = TRUE) {
    $this->repo_id = $repo_id;
    if ($buildSelf) {
      $this->buildSelf();
    }
    else {
      $this->build($args);
    }
    $this->built = TRUE;
  }

  protected function buildSelf() {
    $data = db_fetch_array(db_query("
      SELECT
      vr.name, vr.root, vr.authorization_method, vr.url_backend, vr.data
      FROM {versioncontrol_repositories} vr
      WHERE vr.repo_id = %d",
      $this->repo_id));
    $this->build($data);
  }

  protected function build($args = array()) {
    foreach ($args as $prop => $value) {
      $this->$prop = $value;
    }
    if (is_string($this->data)) {
      $this->data = unserialize($this->data);
    }
  }

  /**
   * Title callback for repository arrays.
   */
  public function titleCallback() {
    return check_plain($repository->name);
  }

  /**
   * Retrieve known branches and/or tags in a repository as a set of label arrays.
   *
   * @param $constraints
   *   An optional array of constraints. If no constraints are given, all known
   *   labels for a repository will be returned. Possible array elements are:
   *
   *   - 'label_ids': An array of label ids. If given, only labels with one of
   *        these identifiers will be returned.
   *   - 'type': Either VERSIONCONTROL_LABEL_BRANCH or
   *        VERSIONCONTROL_LABEL_TAG. If given, only labels of this type
   *        will be returned.
   *   - 'names': An array of label names to search for. If given, only labels
   *        matching one of these names will be returned. Matching is done with
   *        SQL's LIKE operator, which means you can use the percentage sign
   *        as wildcard.
   *
   * @return
   *   An array of VersioncontrolLabel objects
   *   If not a single known label in the given repository matches these
   *   constraints, an empty array is returned.
   */
  public function getLabels($constraints = array()) {
    $and_constraints = array('repo_id = %d');
    $params = array($this->repo_id);

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
      switch ($label['type']) {
      case VERSIONCONTROL_LABEL_BRANCH:
        $labels[] = new VersioncontrolBranch($label['name'], NULL, $label['label_id'], $this);
        break;
      case VERSIONCONTROL_LABEL_TAG:
        $labels[] = new VersioncontrolTag($label['name'], NULL, $label['label_id'], $this);
        break;
      }
    }
    return $labels;
  }

  /**
   * Return TRUE if the account is authorized to commit in the actual
   * repository, or FALSE otherwise. Only call this function on existing
   * accounts or uid 0, the return value for all other
   * uid/repository combinations is undefined.
   *
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
   * Let child backend repo classes add information that _is not_ in
   * VersioncontrolRepository::data
   */
  public function _getRepository() {
  }

  /**
   * Update a repository in the database, and call the necessary hooks.
   * The 'repo_id' and 'vcs' properties of the repository object must stay
   * the same as the ones given on repository creation,
   * whereas all other values may change.
   */
  public final function update() {
    drupal_write_record('versioncontrol_repositories', $this, 'repo_id');

    $this->url_handler->update();

    $this->_update();

    // Everything's done, let the world know about it!
    module_invoke_all('versioncontrol_repository', 'update', $this);

    watchdog('special',
      'Version Control API: updated repository @repository',
      array('@repository' => $this->name),
      WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-repositories')
    );
  }

  /**
   * Let child backend repo classes update information that _is not_ in
   * VersioncontrolRepository::data without modifying general flow if
   * necessary.
   */
  protected function _update() {
  }

  /**
   * Insert a repository into the database, and call the necessary hooks.
   *
   * @return
   *   The finalized repository array, including the 'repo_id' element.
   */
  public final function insert() {
    if (isset($this->repo_id)) {
      // This is a new repository, it's not supposed to have a repo_id yet.
      unset($this->repo_id);
    }
    drupal_write_record('versioncontrol_repositories', $this);
    // drupal_write_record() has now added the 'repo_id' to the $repository array.

    // urls
    $this->url_handler->insert();

    $this->_insert();

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
   * Let child backend repo classes add information that _is not_ in
   * VersioncontrolRepository::data without modifying general flow if
   * necessary.
   */
  protected function _insert() {
  }

  /**
   * Delete a repository from the database, and call the necessary hooks.
   * Together with the repository, all associated commits and accounts are
   * deleted as well.
   */
  public final function delete() {
    // Delete operations.
    $operations = VersioncontrolOperation::getOperations(array('repo_ids' => array($this->repo_id)));
    foreach ($operations as $operation) {
      $operation->delete();
    }
    unset($operations); // conserve memory, this might get quite large

    // Delete labels.
    db_query('DELETE FROM {versioncontrol_labels}
              WHERE repo_id = %d', $this->repo_id);

    // Delete item revisions and related source item entries.
    $result = db_query('SELECT item_revision_id
                        FROM {versioncontrol_item_revisions}
                        WHERE repo_id = %d', $this->repo_id);
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
                WHERE repo_id = %d', $this->repo_id);
    }
    unset($item_ids); // conserve memory, this might get quite large
    unset($placeholders); // ...likewise

    // Delete accounts.
    $accounts = VersioncontrolAccountCache::getInstance()->getAccounts(
      array('repo_ids' => array($this->repo_id)), TRUE
    );
    foreach ($accounts as $uid => $usernames_by_repository) {
      foreach ($usernames_by_repository as $repo_id => $account) {
        $account->delete();
      }
    }

    // Announce deletion of the repository before anything has happened.
    module_invoke_all('versioncontrol_repository', 'delete', $this);

    $this->_delete();

    // Phew, everything's cleaned up. Finally, delete the repository.
    db_query('DELETE FROM {versioncontrol_repositories} WHERE repo_id = %d',
      $this->repo_id);
    $this->url_handler->delete();

    watchdog('special',
      'Version Control API: deleted repository @repository',
      array('@repository' => $this->name),
      WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-repositories')
    );
  }

  /**
   * Let child backend repo classes delete information that _is not_ in
   * VersioncontrolRepository::data without modifying general flow if
   * necessary.
   */
  protected function _delete() {
  }

  /**
   * Export a repository's authenticated accounts to the version control system's
   * password file format.
   *
   * @param $repository
   *   The repository array of the repository whose accounts should be exported.
   *
   * @return
   *   The plaintext result data which could be written into the password file
   *   as is.
   */
  public function exportAccounts() {
    $accounts = VersioncontrolAccountCache::getInstance()->getAccounts(array(
      'repo_ids' => array($this->repo_id),
    ));
    return _versioncontrol_call_backend($repository->vcs, 'export_accounts',
                                        array($this, $accounts));
  }


  /**
   * Try to retrieve a given item in a repository.
   *
   * This function is optional for VCS backends to implement, be sure to check
   * with versioncontrol_backend_implements($repository['vcs'], 'get_item')
   * if the particular backend actually implements it.
   *
   * @param $path
   *   The path of the requested item.
   * @param $constraints
   *   An optional array specifying one of two possible array keys which specify
   *   the exact revision of the item:
   *
   *   - 'revision': A specific revision for the requested item, in the same
   *        VCS-specific format as $item['revision']. A repository/path/revision
   *        combination is always unique, so no additional information is needed.
   *   - 'label': A label array with at least 'name' and 'type' elements
   *        filled in. If a label is provided, it should be incorporated into the
   *        result item as 'selected_label' (see return value docs), and will
   *        cause the most recent item on the label to be fetched. If the label
   *        includes an additional 'date' property holding a Unix timestamp, the
   *        item at that point of time will be retrieved instead of the most
   *        recent one. (For tag labels, there is only one item anyways, so
   *        nevermind the "most recent" part in that case.)
   *
   * @return
   *   If the item with the given path and revision cannot be retrieved, NULL is
   *   returned. Otherwise the result is an item array, consisting of the
   *   following elements:
   *
   *   - 'type': Specifies the item type, which is either
   *        VERSIONCONTROL_ITEM_FILE or VERSIONCONTROL_ITEM_DIRECTORY for items
   *        that still exist, or VERSIONCONTROL_ITEM_FILE_DELETED respectively
   *        VERSIONCONTROL_ITEM_DIRECTORY_DELETED for items that have been
   *        removed.
   *   - 'path': The path of the item at the specific revision.
   *   - 'revision': The currently selected (file-level) revision of the item.
   *        If there is no such revision (which may be the case for directory
   *        items) then the 'revision' element is an empty string.
   *
   *   If the returned item is already present in the database, the
   *   'item_revision_id' database identifier might also be filled in
   *   (optionally, depending on the VCS backend).
   */
  public function getItem($path, $constraints = array()) {
    $info = _versioncontrol_call_backend(
      $this->vcs, 'get_item', array($this, $path, $constraints)
    );
    if (empty($info)) {
      return NULL;
    }
    $item = $info['item'];
    $item['selected_label'] = new stdClass();
    $item['selected_label']->label = is_null($info['selected_label'])
      ? FALSE : $info['selected_label'];
    return $item;
  }

  /**
   * Get the user-visible version of a revision identifier (for an operation or
   * an item), as plaintext. By default, this function simply returns $revision.
   *
   * Version control backends can, however, choose to implement their own version
   * of this function, which for example makes it possible to cut the SHA-1 hash
   * in distributed version control systems down to a readable length.
   *
   * @param $revision
   *   The unformatted revision, as given in $operation['revision']
   *   or $item['revision'] (or the respective table columns for those values).
   * @param $format
   *   Either 'full' for the original version, or 'short' for a more compact form.
   *   If the revision identifier doesn't need to be shortened, the results can
   *   be the same for both versions.
   */
  public function formatRevisionIdentifier($revision, $format = 'full') {
    return $revision;
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

/**
 * Contains the urls mainly for displaying.
 */
class VersioncontrolRepositoryUrlHandler {

  /**
   * Repository where this urls belongs.
   *
   * @var    VersioncontrolRepository
   */
  public $repository;

  /**
   * An array of repository viewer URLs.
   *
   * @var    array
   */
  public $urls;

  public function __construct($repository, $urls) {
    $this->repository = $repository;
    $this->urls = $urls;
  }

  public function insert() {
    $this->urls['repo_id'] = $this->repository->repo_id; // for drupal_write_record()
    drupal_write_record('versioncontrol_repository_urls', $this->urls);
    unset($this->urls['repo_id']);
  }

  public function update() {
    if (!is_null($this->urls)) {
      $this->urls['repo_id'] = $this->repository->repo_id; // for drupal_write_record()
      drupal_write_record('versioncontrol_repository_urls', $this->urls, 'repo_id');
      unset($this->urls['repo_id']);
    }
  }

  public function delete() {
    db_query('DELETE FROM {versioncontrol_repository_urls} WHERE repo_id = %d',
      $this->repo_id);
  }

}
