<?php

require_once 'VersioncontrolItem.php';
require_once 'VersioncontrolBranch.php';
require_once 'VersioncontrolTag.php';

/**
 * @name VCS operations
 * a.k.a. stuff that is recorded for display purposes.
 */
//@{
define('VERSIONCONTROL_OPERATION_COMMIT', 1);
define('VERSIONCONTROL_OPERATION_BRANCH', 2);
define('VERSIONCONTROL_OPERATION_TAG',    3);
//@}

/**
 * Stuff that happened in a repository at a specific time
 *
 */
class VersioncontrolOperation implements ArrayAccess {
    // Attributes
    /**
     * db identifier (before vc_op_id)
     *
     * The Drupal-specific operation identifier (a simple integer)
     * which is unique among all operations (commits, branch ops, tag ops)
     * in all repositories.
     *
     * @var    int
     * @access public
     */
    public $vc_op_id;

    /**
     * who actually perform the change
     *
     * @var    string
     * @access public
     */
    public $committer;

    /**
     * The time when the operation was performed, given as
     * Unix timestamp. (For commits, this is the time when the revision
     * was committed, whereas for branch/tag operations it is the time
     * when the files were branched or tagged.)
     *
     * @var    timestamp
     * @access public
     */
    public $date;

    /**
     * The VCS specific repository-wide revision identifier,
     * like '' in CVS, '27491' in Subversion or some SHA-1 key in various
     * distributed version control systems. If there is no such revision
     * (which may be the case for version control systems that don't support
     * atomic commits) then the 'revision' element is an empty string.
     * For branch and tag operations, this element indicates the
     * (repository-wide) revision of the files that were branched or tagged.
     *
     * @var    string
     * @access public
     */
    public $revision;

    /**
     * The log message for the commit, tag or branch operation.
     * If a version control system doesn't support messages for the current
     * operation type, this element should be empty.
     *
     * @var    string
     * @access public
     */
    public $message;

    /**
     * The system specific VCS username of the user who executed
     * this operation(aka who write the change)
     *
     * @var    string
     * @access public
     */
    public $author;

    /**
     * The repository where this operation occurs,
     * given as a structured array, like the return value
     * of Versioncontrolrepository::getRepository().
     *
     * @var    VersioncontrolRepository
     * @access public
     */
    public $repository;

    /**
     * The type of the operation - one of the
     * VERSIONCONTROL_OPERATION_{COMMIT,BRANCH,TAG} constants.
     *
     * @var    string
     * @access public
     */
    public $type;

    /**
     * An array of branches or tags that were affected by this
     * operation. Branch and tag operations are known to only affect one
     * branch or tag, so for these there will be only one element (with 0
     * as key) in 'labels'. Commits might affect any number of branches,
     * including none. Commits that emulate branches and/or tags (like
     * in Subversion, where they're not a native concept) can also include
     * add/delete/move operations for labels, as detailed below.
     * Mind that the main development branch - e.g. 'HEAD', 'trunk'
     * or 'master' - is also considered a branch. Each element in 'labels'
     * is a structured array with the following keys:
     * FIXME: VersioncontrolLabel's array?
     *
     *        - 'name': The branch or tag name (a string).
     *        - 'type': Whether this label is a branch (indicated by the
     *             VERSIONCONTROL_OPERATION_BRANCH constant) or a tag
     *             (VERSIONCONTROL_OPERATION_TAG).
     *        - 'action': Specifies what happened to this label in this operation.
     *             For plain commits, this is always VERSIONCONTROL_ACTION_MODIFIED.
     *             For branch or tag operations (or commits that emulate those),
     *             it can be either VERSIONCONTROL_ACTION_ADDED or
     *             VERSIONCONTROL_ACTION_DELETED.
     *
     * @var    array
     * @access public
     */
    public $labels;

    /**
     * All possible operation constraints.
     * Each constraint is identified by its key which denotes the array key within
     * the $constraints parameter that is given to self::getOperations().
     * The array value of each element is a description array containing the
     * elements 'callback' and 'cardinality'.
     *
     */
    private static $constraint_info = array();

    /**
     * FIXME: ?
     */
    private static $error_messages = array();

    /**
     * Constructor
     */
    public function __construct($type, $committer, $date, $revision, $message, $author=NULL, $repository=NULL, $vc_op_id=NULL) {
      $this->type = $type;
      $this->committer = $committer;
      $this->date = $date;
      $this->revision = $revision;
      $this->message = $message;
      $this->author = (is_null($author))? $committer: $author;
      $this->repository = $repository;
      $this->vc_op_id = $vc_op_id;
    }

    // Associations
    // Operations
    /**
     * Retrieve a set of commit, branch or tag operations that match
     * the given constraints.
     *
     * @access public
     * @static
     * @param $constraints
     *   An optional array of constraints. Possible array elements are:
     *
     *   - 'vcs': An array of strings, like array('cvs', 'svn', 'git').
     *        If given, only operations for these backends will be returned.
     *   - 'repo_ids': An array of repository ids. If given, only operations
     *        for the corresponding repositories will be returned.
     *   - 'types': An array containing any combination of the three
     *        VERSIONCONTROL_OPERATION_{COMMIT,BRANCH,TAG} constants, like
     *        array(VERSIONCONTROL_OPERATION_COMMIT, VERSIONCONTROL_OPERATION_TAG).
     *        If given, only operations of this type will be returned.
     *   - 'branches': An array of strings, like array('HEAD', 'DRUPAL-5').
     *        If given, only commits or branch operations on one of these branches
     *        will be returned.
     *   - 'tags': An array of strings, like array('DRUPAL-6-1', 'DRUPAL-6--1-0').
     *        If given, only tag operations with one of these tag names will be
     *        returned.
     *   - 'revisions': An array of strings, each containing a VCS-specific
     *        (global) revision, like '27491' for Subversion or some SHA-1 key in
     *        various distributed version control systems. If given, only
     *        operations with that revision identifier will be returned. Note that
     *        this constraint only works for version control systems that support
     *        global revision identifiers, so this will filter out all
     *        CVS operations.
     *   - 'labels': A combination of the 'branches' and 'tags' constraints.
     *   - 'paths': An array of strings (item locations), like
     *          array(
     *            '/trunk/contributions/modules/versioncontrol',
     *            '/trunk/contributions/themes/b2',
     *          ).
     *        If given, only operations affecting one of these items
     *        (or its children, in case the item is a directory) will be returned.
     *   - 'message': A string, or an array of strings (which will be combined with
     *        an "OR" operator). If given, only operations containing the string(s)
     *        in their log message will be returned.
     *   - 'item_revision_ids': An array of item revision ids. If given, only
     *        operations affecting one of the items with that id will be returned.
     *   - 'item_revisions': An array of strings, each containing a VCS-specific
     *        file-level revision, like '1.15.2.3' for CVS, '27491' for Subversion,
     *        or some SHA-1 key in various  distributed version control systems.
     *        If given, only operations affecting one of the items with that
     *        item revision will be returned.
     *   - 'vc_op_ids': An array of operation ids. If given, only operations
     *        matching those ids will be returned.
     *   - 'date_lower': A Unix timestamp. If given, no operations will be
     *        retrieved that were performed earlier than this lower bound.
     *   - 'date_lower': A Unix timestamp. If given, no operations will be
     *        retrieved that were performed later than this upper bound.
     *   - 'uids': An array of Drupal user ids. If given, the result set will only
     *        contain operations that were performed by any of the specified users.
     *   - 'usernames': An array of system-specific usernames (the ones that the
     *        version control systems themselves get to see), like
     *        array('dww', 'jpetso'). If given, the result set will only contain
     *        operations that were performed by any of the specified users.
     *   - 'user_relation': If set to VERSIONCONTROL_USER_ASSOCIATED, only
     *        operations whose authors can be associated to Drupal users will be
     *        returned. If set to VERSIONCONTROL_USER_ASSOCIATED_ACTIVE, only users
     *        will be considered that are not blocked.
     *
     * @param $options
     *   An optional array of additional options for retrieving the operations.
     *   The following array keys are supported:
     *
     *   - 'query_type': If unset, the standard db_query() function is used to
     *        retrieve all operations that match the given constraints.
     *        Can be set to 'range' or 'pager' to use the db_query_range()
     *        or pager_query() functions instead. Additional options are required
     *        in this case.
     *   - 'count': Required if 'query_type' is either 'range' or 'pager'.
     *        Specifies the number of operations to be returned by this function.
     *   - 'from': Required if 'query_type' is 'range'. Specifies the first
     *        result row to return. (Usually you want to pass 0 for this one.)
     *   - 'pager_element': Optional for 'pager' as 'query_type'. An optional
     *        integer to distinguish between multiple pagers on one page.
     *
     * @return
     *   An array of operations, reversely sorted by the time of the operation.
     *   Each element contains an "operation array" with the 'vc_op_id' identifier
     *   as key (which doesn't influence the sorting) and the following keys:
     *
     *   - 'vc_op_id': The Drupal-specific operation identifier (a simple integer)
     *        which is unique among all operations (commits, branch ops, tag ops)
     *        in all repositories.
     *   - 'type': The type of the operation - one of the
     *        VERSIONCONTROL_OPERATION_{COMMIT,BRANCH,TAG} constants.
     *        Note that if you pass branch or tag constraints, this function might
     *        nevertheless return commit operations too - that happens for version
     *        control systems without native branches or tags (like Subversion)
     *        when a branch or tag is affected by the commit.
     *   - 'repository': The repository where this operation occurred.
     *        This is a structured "repository array", like is returned
     *        by versioncontrol_get_repository().
     *   - 'date': The time when the operation was performed, given as
     *        Unix timestamp. (For commits, this is the time when the revision
     *        was committed, whereas for branch/tag operations it is the time
     *        when the files were branched or tagged.)
     *   - 'uid': The Drupal user id of the operation author, or 0 if no
     *        Drupal user could be associated to the author.
     *   - 'username': The system specific VCS username of the author.
     *   - 'message': The log message for the commit, tag or branch operation.
     *        If a version control system doesn't support messages for any of them,
     *        this element contains an empty string.
     *   - 'revision': The VCS specific repository-wide revision identifier,
     *        like '' in CVS, '27491' in Subversion or some SHA-1 key in various
     *        distributed version control systems. If there is no such revision
     *        (which may be the case for version control systems that don't support
     *        atomic commits) then the 'revision' element is an empty string.
     *        For branch and tag operations, this element indicates the
     *        (repository-wide) revision of the files that were branched or tagged.
     *
     *   - 'labels': An array of branches or tags that were affected by this
     *        operation. Branch and tag operations are known to only affect one
     *        branch or tag, so for these there will be only one element (with 0
     *        as key) in 'labels'. Commits might affect any number of branches,
     *        including none. Commits that emulate branches and/or tags (like
     *        in Subversion, where they're not a native concept) can also include
     *        add or delete operations for labels, as detailed below.
     *        Mind that the main development branch - e.g. 'HEAD', 'trunk'
     *        or 'master' - is also considered a branch. Each element in 'labels'
     *        is a structured array with the following keys:
     *
     *        - 'label_id': The label identifier (a simple integer), used for unique
     *             identification of branches and tags in the database.
     *        - 'name': The branch or tag name (a string).
     *        - 'type': Whether this label is a branch (indicated by the
     *             VERSIONCONTROL_OPERATION_BRANCH constant) or a tag
     *             (VERSIONCONTROL_OPERATION_TAG).
     *        - 'action': Specifies what happened to this label in this operation.
     *             For plain commits, this is always VERSIONCONTROL_ACTION_MODIFIED.
     *             For branch or tag operations (or commits that emulate those),
     *             it can be either VERSIONCONTROL_ACTION_ADDED or
     *             VERSIONCONTROL_ACTION_DELETED.
     *
     *   If not a single operation matches these constraints,
     *   an empty array is returned.
     */
    public static function getOperations($constraints = array(), $options = array()) {
      $tables = array(
        'versioncontrol_operations' => array('alias' => 'op'),
        'versioncontrol_repositories' => array(
          'alias' => 'r',
          'join_on' => 'op.repo_id = r.repo_id',
        ),
      );
      // Construct the actual query, and let other modules provide "native"
      // custom constraints as well.
      $query_info = self::_constructQuery(
        $constraints, $tables
      );
      if (empty($query_info)) {
        return array();
      }

      $query = 'SELECT DISTINCT(op.vc_op_id), op.type, op.date, op.uid,
                op.author, op.committer, op.message, op.revision, r.repo_id, r.vcs
                FROM '. $query_info['from'] .
                (empty($query_info['where']) ? '' : ' WHERE '. $query_info['where']) .'
                ORDER BY op.date DESC, op.vc_op_id DESC';

      $result = _versioncontrol_query($query, $query_info['params'], $options);

      $operations = array();
      $op_id_placeholders = array();
      $op_ids = array();
      $repo_ids = array();

      while ($row = db_fetch_object($result)) {
        // Remember which repositories and backends are being used for the
        // results of this query.
        if (!in_array($row->repo_id, $repo_ids)) {
          $repo_ids[] = $row->repo_id;
        }

        // Construct the operation array - nearly done already.
        $operations[$row->vc_op_id] = new VersioncontrolOperation($row->type,
          $row->committer, $row->date, $row->revision, $row->message,
          $row->author, NULL, $row->vc_op_id);
        // 'repo_id' is replaced by 'repository' further down
        $operations[$row->vc_op_id]->repo_id = $row->repo_id;
        $operations[$row->vc_op_id]->labels = array();
        $operations[$row->vc_op_id]->uid = $row->uid;
        $op_ids[] = $row->vc_op_id;
        $op_id_placeholders[] = '%d';
      }
      if (empty($operations)) {
        return array();
      }

      // Add the corresponding repository array to each operation.
      $repositories = VersioncontrolRepository::getRepositories(array('repo_ids' => $repo_ids));
      foreach ($operations as $vc_op_id => $operation) {
        $operations[$vc_op_id]->repository = $repositories[$operation->repo_id];
        unset($operations[$vc_op_id]->repo_id);
      }

      // Add the corresponding labels to each operation.
      $result = db_query('SELECT op.vc_op_id, oplabel.action,
                                 label.label_id, label.name, label.type
                          FROM {versioncontrol_operations} op
                           INNER JOIN {versioncontrol_operation_labels} oplabel
                            ON op.vc_op_id = oplabel.vc_op_id
                           INNER JOIN {versioncontrol_labels} label
                            ON oplabel.label_id = label.label_id
                          WHERE op.vc_op_id IN
                            ('. implode(',', $op_id_placeholders) .')', $op_ids);

      while ($row = db_fetch_object($result)) {
        switch($row->type) {
        case VERSIONCONTROL_LABEL_TAG:
          $operations[$row->vc_op_id]->labels[] = new VersioncontrolTag(
            $row->name, $row->action, $row->label_id=NULL,
            $operations[$row->vc_op_id]->repository
          );
          break;
        case VERSIONCONTROL_LABEL_BRANCH:
          $operations[$row->vc_op_id]->labels[] = new VersioncontrolBranch(
            $row->name, $row->action, $row->label_id,
            $operations[$row->vc_op_id]->repository
          );
          break;
        }
      }
      return $operations;
    }

    /**
     * Convenience function, calling versioncontrol_get_operations() with a preset
     * of array(VERSIONCONTROL_OPERATION_COMMIT) for the 'types' constraint
     * (so only commits are returned). Parameters and result array are the same
     * as those from versioncontrol_get_operations().
     *
     * @access public
     * @static
     */
    public static function getCommits($constraints = array(), $options = array()) {
      if (isset($constraints['types']) && !in_array(VERSIONCONTROL_OPERATION_COMMIT, $constraints['types'])) {
        return array(); // no commits in the original constraints, intersects to empty
      }
      $constraints['types'] = array(VERSIONCONTROL_OPERATION_COMMIT);
      return VersioncontrolOperation::getOperations($constraints, $options);
    }

    /**
     * Convenience function, calling versioncontrol_get_operations() with a preset
     * of array(VERSIONCONTROL_OPERATION_TAG) for the 'types' constraint
     * (so only tag operations or commits affecting emulated tags are returned).
     * Parameters and result array are the same as those
     * from versioncontrol_get_operations().
     * 
     * @access public
     * @static
     */
    public static function getTags($constraints = array(), $options = array()) {
      if (isset($constraints['types']) && !in_array(VERSIONCONTROL_OPERATION_TAG, $constraints['types'])) {
        return array(); // no tags in the original constraints, intersects to empty
      }
      $constraints['types'] = array(VERSIONCONTROL_OPERATION_TAG);
      return VersioncontrolOperation::getOperations($constraints, $options);
    }

    /**
     * Convenience function, calling versioncontrol_get_operations() with a preset
     * of array(VERSIONCONTROL_OPERATION_BRANCH) for the 'types' constraint
     * (so only branch operations or commits affecting emulated branches
     * are returned). Parameters and result array are the same as those
     * from versioncontrol_get_operations().
     *
     * @access public
     * @static
     */
    public static function getBranches($constraints = array(), $options = array()) {
      if (isset($constraints['types']) && !in_array(VERSIONCONTROL_OPERATION_BRANCH, $constraints['types'])) {
        return array(); // no branches in the original constraints, intersects to empty
      }
      $constraints['types'] = array(VERSIONCONTROL_OPERATION_BRANCH);
      return VersioncontrolOperation::getOperations($constraints, $options);
    }

    /**
     * Retrieve all items that were affected by an operation.
     *
     * @access public
     * @param $fetch_source_items
     *   If TRUE, source and replaced items will be retrieved as well,
     *   and stored as additional properties inside each item array.
     *   If FALSE, only current/new items will be retrieved.
     *   If NULL (default), source and replaced items will be retrieved for commits
     *   but not for branch or tag operations.
     *
     * @return
     *   A structured array containing all items that were affected by the given
     *   operation. Array keys are the current/new paths, even if the item doesn't
     *   exist anymore (as is the case with delete actions in commits).
     *   The associated array elements are structured item arrays and consist of
     *   the following elements:
     *
     *   - 'type': Specifies the item type, which is either
     *        VERSIONCONTROL_ITEM_FILE or VERSIONCONTROL_ITEM_DIRECTORY for items
     *        that still exist, or VERSIONCONTROL_ITEM_FILE_DELETED respectively
     *        VERSIONCONTROL_ITEM_DIRECTORY_DELETED for items that have been
     *        removed (by a commit's delete action).
     *   - 'path': The path of the item at the specific revision.
     *   - 'revision': The (file-level) revision when the item was changed.
     *        If there is no such revision (which may be the case for
     *        directory items) then the 'revision' element is an empty string.
     *   - 'item_revision_id': Identifier of this item revision in the database.
     *        Note that you can only rely on this element to exist for
     *        operation items - functions that interface directly with the VCS
     *        (such as versioncontrol_get_directory_contents() or
     *        versioncontrol_get_parallel_items()) might not include
     *        this identifier, for obvious reasons.
     *
     *   If the @p $fetch_source_items parameter is TRUE,
     *   versioncontrol_fetch_source_items() will be called on the list of items
     *   in order to retrieve additional information about their origin.
     *   The following elements will be set for each item in addition
     *   to the ones listed above:
     *
     *   - 'action': Specifies how the item was changed.
     *        One of the predefined VERSIONCONTROL_ACTION_* values.
     *   - 'source_items': An array with the previous revision(s) of the affected
     *        item. Empty if 'action' is VERSIONCONTROL_ACTION_ADDED. The key for
     *        all items in this array is the respective item path.
     *   - 'replaced_item': The previous but technically unrelated item at the
     *        same location as the current item. Only exists if this previous item
     *        was deleted and replaced by a different one that was just moved
     *        or copied to this location.
     *   - 'line_changes': Only exists if line changes have been recorded for this
     *        action - if so, this is an array containing the number of added lines
     *        in an element with key 'added', and the number of removed lines in
     *        the 'removed' key.
     * FIXME refactor me to oo
     */
    public function getItems($fetch_source_items = NULL) {
      $items = array();
      $result = db_query(
        'SELECT ir.item_revision_id, ir.path, ir.revision, ir.type
         FROM {versioncontrol_operation_items} opitem
          INNER JOIN {versioncontrol_item_revisions} ir
           ON opitem.item_revision_id = ir.item_revision_id
         WHERE opitem.vc_op_id = %d AND opitem.type = %d',
        $this->vc_op_id, VERSIONCONTROL_OPERATION_MEMBER_ITEM);

      while ($item_revision = db_fetch_object($result)) {
        $items[$item_revision->path] = new VersioncontrolItem($item_revision->type, $item_revision->path, $item_revision->revision, NULL, $this->repository, NULL, $item_revision->item_revision_id);
        $items[$item_revision->path]->selected_label = new stdClass();
        $items[$item_revision->path]->selected_label->get_from = 'operation';
        $items[$item_revision->path]->selected_label->operation = &$this;

        //TODO inherit from operation class insteadof types?
        if ($this->type == VERSIONCONTROL_OPERATION_COMMIT) {
          $items[$item_revision->path]->commit_operation = $this;
        }
      }

      if (!isset($fetch_source_items)) {
        // By default, fetch source items for commits but not for branch or tag ops.
        $fetch_source_items = ($this->type == VERSIONCONTROL_OPERATION_COMMIT);
      }
      if ($fetch_source_items) {
        versioncontrol_fetch_source_items($this->repository, $items);
      }
      ksort($items); // similar paths should be next to each other
      return $items;
    }

    /**
     * Replace the set of affected labels of the actual object with the one in
     * @p $labels. If any of the given labels does not yet exist in the
     * database, a database entry (including new 'label_id' array element) will
     * be written as well.
     *
     * @access public
     */
    public function updateLabels($labels) {
      module_invoke_all('versioncontrol_operation_labels',
        'update', $this, $labels
      );
      $this->_setLabels($labels);
    }

    /**
     * Insert a commit, branch or tag operation into the database, and call the
     * necessary module hooks. Only call this function after the operation has been
     * successfully executed.
     *
     * @access public
     *
     * @param $operation_items
     *   A structured array containing the exact details of happened to each
     *   item in this operation. The structure of this array is the same as
     *   the return value of versioncontrol_get_operation_items() - that is,
     *   elements for 'type', 'path' and 'revision' - but doesn't include the
     *   'item_revision_id' element, that one will be filled in by this function.
     *
     *   For commit operations, you also have to fill in the 'action' and
     *   'source_items' elements (and optionally 'replaced_item') that are also
     *   described in the versioncontrol_get_operation_items() API documentation.
     *   The 'line_changes' element, as in versioncontrol_get_operation_items(),
     *   is optional to provide.
     *
     *   This parameter is passed by reference as the insert operation will
     *   check the validity of a few item properties and will also assign an
     *   'item_revision_id' property to each of the given items. So when this
     *   function returns with a result other than NULL, the @p $operation_items
     *   array will also be up to snuff for further processing.
     *
     * @return
     *   The finalized operation array, with all of the 'vc_op_id', 'repository'
     *   and 'uid' properties filled in, and 'repo_id' removed if it existed before.
     *   Labels are now equipped with an additional 'label_id' property.
     *   (For more info on these labels, see the API documentation for
     *   versioncontrol_get_operations() and versioncontrol_get_operation_items().)
     *   In case of an error, NULL is returned instead of the operation array.
     */
    public function insert(&$operation_items) {
      $this->_fill(TRUE);

      if (!isset($this->repository)) {
        return NULL;
      }

      // Ok, everything's there, insert the operation into the database.
      $this->repo_id = $this->repository->repo_id; // for drupal_write_record()
      //FIXME $this->uid = 0;
      drupal_write_record('versioncontrol_operations', $this);
      unset($this->repo_id);
      // drupal_write_record() has now added the 'vc_op_id' to the $operation array.

      // Insert labels that are attached to the operation.
      $this->_setLabels($this->labels);

      $vcs = $this->repository->vcs;

      // So much for the operation itself, now the more verbose part: items.
      ksort($operation_items); // similar paths should be next to each other

      foreach ($operation_items as $path => $item) {
        $item->sanitize();
        $item->ensure();
        $this->_insert_operation_item($item,
          VERSIONCONTROL_OPERATION_MEMBER_ITEM);
        $item['selected_label'] = new stdClass();
        $item['selected_label']->get_from = 'operation';
        $item['selected_label']->successor_item = &$this;

        // If we've got source items (which is the case for commit operations),
        // add them to the item revisions and source revisions tables as well.
        foreach ($item->source_items as $key => $source_item) {
          $source_item->ensure();
          $item->insertSourceRevision($source_item, $item->action);

          // Cache other important items in the operations table for 'path' search
          // queries, because joining the source revisions table is too expensive.
          switch ($item['action']) {
            case VERSIONCONTROL_ACTION_MOVED:
            case VERSIONCONTROL_ACTION_COPIED:
            case VERSIONCONTROL_ACTION_MERGED:
              if ($item->path != $source_item->path) {
                $this->_insert_operation_item($source_item,
                  VERSIONCONTROL_OPERATION_CACHED_AFFECTED_ITEM);
              }
              break;
            default: // No additional caching for added, modified or deleted items.
              break;
          }

          $source_item->selected_label = new stdClass();
          $source_item->selected_label->get_from = 'other_item';
          $source_item->selected_label->other_item = &$item;
          $source_item->selected_label->other_item_tags = array('successor_item');

          $item->source_items[$key] = $source_item;
        }
        // Plus a special case for the "added" action, as it needs an entry in the
        // source items table but contains no items in the 'source_items' property.
        if ($item->action == VERSIONCONTROL_ACTION_ADDED) {
          $item->insertSourceRevision(0, $item['action']);
        }

        // If we've got a replaced item (might happen for copy/move commits),
        // add it to the item revisions and source revisions table as well.
        if (isset($item->replaced_item)) {
          $item->replaced_item->ensure();
          $item->insertSourceRevision($item->replaced_item,
            VERSIONCONTROL_ACTION_REPLACED);
          $item->replaced_item->selected_label = new stdClass();
          $item->replaced_item->selected_label->get_from = 'other_item';
          $item->replaced_item->selected_label->other_item = &$item;
          $item->replaced_item->selected_label->other_item_tags = array('successor_item');
        }
        $operation_items[$path] = $item;
      }

      // Notify the backend first.
      if (versioncontrol_backend_implements($vcs, 'operation')) {
        _versioncontrol_call_backend($vcs, 'operation', array(
          'insert', $this, $operation_items
        ));
      }
      // Everything's done, let the world know about it!
      module_invoke_all('versioncontrol_operation',
        'insert', $this, $operation_items
      );

      // This one too, as there is also an update function & hook for it.
      // Pretend that the labels didn't exist beforehand.
      $labels = $this->labels;
      $this->labels = array();
      module_invoke_all('versioncontrol_operation_labels',
        'insert', $this, $labels
      );
      $this->labels = $labels;

      // Rules integration, because we like to enable people to be flexible.
      // FIXME change callback
      if (module_exists('rules')) {
        rules_invoke_event('versioncontrol_operation_insert', array(
          'operation' => $this,
          'items' => $operation_items,
        ));
      }

      //FIXME avoid return, it's on the object
      return $this;
    }

    /**
     * Delete a commit, a branch operation or a tag operation from the database,
     * and call the necessary hooks.
     *
     * @access public
     * @param $operation
     *   The commit, branch operation or tag operation array containing
     *   the operation that should be deleted.
     */
    public function delete() {
      $operation_items = $this->getItems();

      // As versioncontrol_update_operation_labels() provides an update hook for
      // operation labels, we should also have a delete hook for completeness.
      module_invoke_all('versioncontrol_operation_labels',
                        'delete', $this, array());
      // Announce deletion of the operation before anything has happened.
      // Calls hook_versioncontrol_commit(), hook_versioncontrol_branch_operation()
      // or hook_versioncontrol_tag_operation().
      module_invoke_all('versioncontrol_operation',
                        'delete', $this, $operation_items);

      $vcs = $this->repository->vcs;

      // Provide an opportunity for the backend to delete its own stuff.
      if (versioncontrol_backend_implements($vcs, 'operation')) {
        _versioncontrol_call_backend($vcs, 'operation', array(
          'delete', $this, $operation_items
        ));
      }

      db_query('DELETE FROM {versioncontrol_operation_labels}
                WHERE vc_op_id = %d', $this->vc_op_id);
      db_query('DELETE FROM {versioncontrol_operation_items}
                WHERE vc_op_id = %d', $this->vc_op_id);
      db_query('DELETE FROM {versioncontrol_operations}
                WHERE vc_op_id = %d', $this->vc_op_id);
    }

    /**
     * Assemble a list of query constraints from the given @p $constraints and
     * @p $tables arrays. Both of these are likely to be altered to match the
     * actual query, although in practice you probably won't need them anymore.
     *
     * @access private
     * @static
     * @return
     *   A query information array with keys 'from', 'where' and 'params', or an
     *   empty array if the constraints were invalid or will return an empty result
     *   set anyways. The 'from' and 'where' elements are strings to be used inside
     *   an SQL query (but don't include the actual FROM and WHERE keywords),
     *   and the 'params' element is an array with query parameter values for the
     *   returned WHERE clause.
     */
    private static function _constructQuery(&$constraints, &$tables) {
      // Let modules alter the query by transforming custom constraints into
      // stuff that Version Control API can understand.
      drupal_alter('versioncontrol_operation_constraints', $constraints);

      $and_constraints = array();
      $params = array();
      $constraint_info = self::_constraintInfo();
      $join_callbacks = array();

      foreach ($constraints as $key => $constraint_value) {
        if (!isset($constraint_info[$key])) {
          return array(); // No such constraint -> empty result.
        }

        // Standardization: put everything into an array if it isn't already.
        if ($constraint_info[$key]['cardinality'] == VERSIONCONTROL_CONSTRAINT_SINGLE) {
          $constraints[$key] = array($constraints[$key]);
        }
        elseif ($constraint_info[$key]['cardinality'] == VERSIONCONTROL_CONSTRAINT_SINGLE_OR_MULTIPLE && !is_array($constraint_value)) {
          $constraints[$key] = array($constraints[$key]);
        }

        if (empty($constraints[$key])) {
          return array(); // Empty set of constraint options -> empty result.
        }
        // Single-value constraints get the originally provided constraint value.
        // All others get the multiple-value constraint array.
        if ($constraint_info[$key]['cardinality'] == VERSIONCONTROL_CONSTRAINT_SINGLE) {
          $constraints[$key] = reset($constraints[$key]);
        }

        // If the constraint unconditionally requires extra tables, add them to
        // the $tables array by calling the join callback.
        if (!empty($constraint_info[$key]['join callback'])) {
          $function = $constraint_info[$key]['join callback'];

          if (!isset($join_callbacks[$function])) { // no need to call it twice
            $join_callbacks[$function] = TRUE;
            $function($tables);
          }
        }

        $function = $constraint_info[$key]['callback'];
        $function($constraints[$key], $tables, $and_constraints, $params);
      }

      // Now that we have all the information, let's construct some usable query parts.
      $from = array();
      foreach ($tables as $table_name => $table_info) {
        if (!empty($table_info['real_table'])) {
          $table_name = $table_info['real_table'];
        }
        $table_string = '{'. $table_name .'} '. $table_info['alias'];
        if (isset($table_info['join_on'])) {
          $table_string .= ' ON '. $table_info['join_on'] .' ';
        }
        $from[] = $table_string;
      }

      return array(
        'from' => implode(' INNER JOIN ', $from),
        'where' => '('. implode(' AND ', $and_constraints) .')',
        'params' => $params,
      );
    }

    /**
     * Gather a list of all possible operation constraints.
     * Each constraint is identified by its key which denotes the array key within
     * the $constraints parameter that is given to versioncontrol_get_operations().
     * The array value of each element is a description array containing the
     * elements 'callback' and 'cardinality'.
     *
     * @access private
     * @static
     */
    private static function _constraintInfo() {
      if (empty(self::$constraint_info)) {
        foreach (module_implements('versioncontrol_operation_constraint_info') as $module) {
          $function = $module .'_versioncontrol_operation_constraint_info';
          $constraints = $function();

          foreach ($constraints as $key => $info) {
            self::$constraint_info[$key] = $info;
            if (!isset($info['callback'])) {
              self::$constraint_info[$key]['callback'] = $module .'_operation_constraint_'. $key;
            }
            if (!isset($info['cardinality'])) {
              self::$constraint_info[$key]['cardinality'] = VERSIONCONTROL_CONSTRAINT_MULTIPLE;
            }
          }
        }
      }
      return self::$constraint_info;
    }

    /**
     * Fill in various operation members into the object(commit, branch op or tag
     * op), in case those values are not given.
     *
     * @access private
     * @param $operation
     *   The plain operation array that might lack have some properties yet.
     * @param $include_unauthorized
     *   If FALSE, the 'uid' property will receive a value of 0 for known
     *   but unauthorized users. If TRUE, all known users are mapped to their uid.
     */
    private function _fill($include_unauthorized = FALSE) {
      // If not already there, retrieve the full repository object.
      // FIXME: take one always set member, not sure if root is one | set other condition here
      if (!isset($this->repository->root) && isset($this->repository->repo_id)) {
        $this->repository = VersioncontrolRepository::getRepository($this->repository->repo_id);
        unset($this->repository->repo_id);
      }

      // If not already there, retrieve the Drupal user id of the committer.
      if (!isset($this->author)) {
        $uid = versioncontrol_get_account_uid_for_username(
          $this->repository->repo_id, $this->author, $include_unauthorized
        );
        // If no uid could be retrieved, blame the commit on user 0 (anonymous).
        $this->author = isset($this->author) ? $this->author : 0;
      }

      // For insertions (which have 'date' set, as opposed to write access checks),
      // fill in the log message if it's unset. We don't want to do this for
      // write access checks because empty messages are denied access,
      // which requires distinguishing between unset and empty.
      if (isset($this->date) && !isset($this->message)) {
        $this->message = '';
      }
    }

    /**
     * Retrieve or set the list of access errors.
     * 
     * @access private
     */
    private function _accessErrors($new_messages = NULL) {
      if (isset($new_messages)) {
        self::$error_messages = $new_messages;
      }
      return self::$error_messages;
    }

    /**
     * Write @p $labels to the database as set of affected labels of the
     * actual operation object. Label ids are not required to exist yet.
     * After this the set of labels, all of them with 'label_id' filled in.
     *
     * @access private
     * @return
     */
    private function _setLabels($labels) {
      db_query("DELETE FROM {versioncontrol_operation_labels}
                WHERE vc_op_id = %d", $this->vc_op_id);

      foreach ($labels as &$label) {
        $label->ensure();
        db_query("INSERT INTO {versioncontrol_operation_labels}
                  (vc_op_id, label_id, action) VALUES (%d, %d, %d)",
                  $this->vc_op_id, $label->label_id, $label->action);
      }
      $this->labels = $labels;
    }

    /**
     * Insert an operation item entry into the {versioncontrol_operation_items} table.
     * The item is expected to have an 'item_revision_id' property already.
     *
     * @access private
     */
    private function _insert_operation_item($item, $type) {
      // Before inserting that item entry, make sure it doesn't exist already.
      db_query("DELETE FROM {versioncontrol_operation_items}
                WHERE vc_op_id = %d AND item_revision_id = %d",
                $this->vc_op_id, $item->item_revision_id);

      db_query("INSERT INTO {versioncontrol_operation_items}
                (vc_op_id, item_revision_id, type) VALUES (%d, %d, %d)",
                $this->vc_op_id, $item->item_revision_id, $type);
    }

    /**
     * If versioncontrol_has_commit_access(), versioncontrol_has_branch_access()
     * or versioncontrol_has_tag_access() returned FALSE, you can use this function
     * to retrieve the list of error messages from the various access checks.
     * The error messages do not include trailing linebreaks, it is expected that
     * those are inserted by the caller.
     *
     * @access protected
     */
    protected function getAccessErrors() {
      return $this->_accessErrors();
    }


    /**
     * Determine if a commit, branch or tag operation may be executed or not.
     * Call this function inside a pre-commit hook.
     *
     * @access protected
     * @param $operation
     *   A single operation array like the ones returned by
     *   versioncontrol_get_operations(), but leaving out on a few details that
     *   will instead be determined by this function. This array describes
     *   the operation that is about to happen. Here's the allowed elements:
     *
     *   - 'type': The type of the operation - one of the
     *        VERSIONCONTROL_OPERATION_{COMMIT,BRANCH,TAG} constants.
     *   - 'repository': The repository where this operation occurs,
     *        given as a structured array, like the return value
     *        of versioncontrol_get_repository().
     *        You can either pass this or 'repo_id'.
     *   - 'repo_id': The repository where this operation occurs, given as a simple
     *        integer id. You can either pass this or 'repository'.
     *   - 'uid': The Drupal user id of the committer. Passing this is optional -
     *        if it isn't set, this function will determine the uid.
     *   - 'username': The system specific VCS username of the committer.
     *   - 'message': The log message for the commit, tag or branch operation.
     *        If a version control system doesn't support messages for the current
     *        operation type, this element must not be set. Operations with
     *        log messages that are set but empty will be denied access.
     *
     *   - 'labels': An array of branches or tags that will be affected by this
     *        operation. Branch and tag operations are known to only affect one
     *        branch or tag, so for these there will be only one element (with 0
     *        as key) in 'labels'. Commits might affect any number of branches,
     *        including none. Commits that emulate branches and/or tags (like
     *        in Subversion, where they're not a native concept) can also include
     *        add/delete/move operations for labels, as detailed below.
     *        Mind that the main development branch - e.g. 'HEAD', 'trunk'
     *        or 'master' - is also considered a branch. Each element in 'labels'
     *        is a structured array with the following keys:
     *
     *        - 'name': The branch or tag name (a string).
     *        - 'type': Whether this label is a branch (indicated by the
     *             VERSIONCONTROL_OPERATION_BRANCH constant) or a tag
     *             (VERSIONCONTROL_OPERATION_TAG).
     *        - 'action': Specifies what happened to this label in this operation.
     *             For plain commits, this is always VERSIONCONTROL_ACTION_MODIFIED.
     *             For branch or tag operations (or commits that emulate those),
     *             it can be either VERSIONCONTROL_ACTION_ADDED or
     *             VERSIONCONTROL_ACTION_DELETED.
     *
     * @param $operation_items
     *   A structured array containing the exact details of what is about to happen
     *   to each item in this commit. The structure of this array is the same as
     *   the return value of versioncontrol_get_operation_items() - that is,
     *   elements for 'type', 'path', 'revision', 'action', 'source_items' and
     *   'replaced_item' - but doesn't include the 'item_revision_id' element as
     *   there's no relation to the database yet.
     *
     *   The 'action', 'source_items', 'replaced_item' and 'revision' elements
     *   of each item are optional and may be left unset.
     *
     * @return
     *   TRUE if the operation may happen, or FALSE if not.
     *   If FALSE is returned, you can retrieve the concerning error messages
     *   by calling versioncontrol_get_access_errors().
     */
    protected function hasWriteAccess($operation, $operation_items) {
      $operation = _versioncontrol_fill_operation($operation);

      // If we can't determine this operation's repository,
      // we can't really allow the operation in the first place.
      if (!isset($operation['repository'])) {
        switch ($operation['type']) {
          case VERSIONCONTROL_OPERATION_COMMIT:
            $type = t('commit');
            break;
          case VERSIONCONTROL_OPERATION_BRANCH:
            $type = t('branch');
            break;
          case VERSIONCONTROL_OPERATION_TAG:
            $type = t('tag');
            break;
        }
        $this->_accessErrors(array(t(
    '** ERROR: Version Control API cannot determine a repository
    ** for the !commit-branch-or-tag information given by the VCS backend.',
          array('!commit-branch-or-tag' => $type)
        )));
        return FALSE;
      }

      // If the user doesn't have commit access at all, we can't allow this as well.
      $repo_data = $operation['repository']['data']['versioncontrol'];

      if (!$repo_data['allow_unauthorized_access']) {

        if (!versioncontrol_is_account_authorized($operation['repository'], $operation['uid'])) {
          $this->_accessErrors(array(t(
            '** ERROR: !user does not have commit access to this repository.',
            array('!user' => $operation['username'])
          )));
          return FALSE;
        }
      }

      // Don't let people do empty log messages, that's as evil as it gets.
      if (isset($operation['message']) && empty($operation['message'])) {
        $this->_accessErrors(array(
          t('** ERROR: You have to provide a log message.'),
        ));
        return FALSE;
      }

      // Also see if other modules have any objections.
      $error_messages = array();

      foreach (module_implements('versioncontrol_write_access') as $module) {
        $function = $module .'_versioncontrol_write_access';

        // If at least one hook_versioncontrol_write_access returns TRUE,
        // the commit goes through. (This is for admin or sandbox exceptions.)
        $outcome = $function($operation, $operation_items);
        if ($outcome === TRUE) {
          return TRUE;
        }
        else { // if !TRUE, $outcome is required to be an array with error messages
          $error_messages = array_merge($error_messages, $outcome);
        }
      }

      // Let the operation fail if there's more than zero error messages.
      if (!empty($error_messages)) {
        $this->_accessErrors($error_messages);
        return FALSE;
      }
      return TRUE;
    }


  /**
   * Retrieve the number of operations that match the given constraints,
   * plus some details about the first and last matching operation.
   *
   * @access public
   * @static
   * @param $constraints
   *   An optional array of constraints. This array has the same format as the
   *   one in versioncontrol_get_operations(), see the API documentation of that
   *   function for a detailed list of possible constraints.
   * @param $group_options
   *   An optional array of further options that change the returned value.
   *   All of these are only used if the 'group_by' element is set.
   *   The following array keys are recognized:
   *
   *   - 'group_by': If given, the result will be a list of statistics grouped by
   *        the given {versioncontrol_operations} columns instead of a single
   *        statistics object, with the grouping columns as array keys.
   *        (In case multiple grouping columns are given, they will be
   *        concatenated with "\t" to make up the array key.)
   *        For example, if a non-grouped function call returned a single
   *        statistics object, a call specifying array('uid') for this option
   *        will return an array of multiple statistics objects with the Drupal
   *        user id as array key. You can also group by columns from other
   *        tables. In order to do that, an array needs to be passed instead of a
   *        simple column name, containing the keys 'table', 'column' and
   *        'join callback' - the latter being a join callback like the ones
   *        in hook_versioncontrol_operation_constraint_info().
   *   - 'order_by': An array of columns to sort on. Allowed columns are
   *        'total_operations', 'first_operation_date', 'last_operation_date'
   *        as well as any of the columns given in @p $group_by.
   *   - 'order_ascending': The default is to sort with DESC if sort columns
   *        are given, but ASC sorting will be used if this is set to TRUE.
   *   - 'query_type', 'count', 'from' and 'pager_element': Specifies different
   *        query types to execute and their associated options. The set of
   *        allowed values for these options is the same as in the $options array
   *        of versioncontrol_get_operations(), see the API documentation of that
   *        function for a detailed description.
   *
   * @return
   *   A statistics object with integers for the keys 'total_operations',
   *   'first_operation_date' and 'last_operation_date' (the latter two being
   *   Unix timestamps). If grouping columns were given, an array of such
   *   statistics objects is returned, with the grouping columns' values as
   *   additional properties for each object.
   *
   * @see versioncontrol_get_operations()
   */
  public static function getStatistics($constraints = array(), $group_options = array()) {
    $calculated_columns = array(
      'total_operations', 'first_operation_date', 'last_operation_date'
    );
    $tables = array(
      'versioncontrol_operations' => array('alias' => 'op'),
    );
    $qualified_group_by = array();

    // Resolve table aliases for the group-by and sort-by columns.
    if (!empty($group_options['group_by'])) {
      foreach ($group_options['group_by'] as &$column) {
        $table = is_string($column) ? 'versioncontrol_operations' : $column['table'];

        if (is_array($column)) {
          $table_callback = $column['join callback'];
          $table_callback($tables);
          $column = $column['column'];
        }
        $qualified_group_by[] = $tables[$table]['alias'] .'.'. $column;
      }
      if (!empty($group_options['order_by'])) {
        foreach ($group_options['order_by'] as &$column) {
          if (in_array($column, $calculated_columns)) {
            continue; // We don't want to prefix those with "op.".
          }
          $table = is_string($column) ? 'versioncontrol_operations' : $column['table'];
          $column = $tables[$table]['alias'] .'.'.
            (is_string($column) ? $column : $column['column']);
        }
      }
    }

    // Construct the actual query, and let other modules provide "native"
    // custom constraints as well.
    $query_info = self::_constructQuery(
      $constraints, $tables
    );
    if (empty($query_info)) { // query won't yield any results
      return empty($group_options['group_by'])
        ? (object) array_fill_keys($calculated_columns, 0)
        : array();
    }

    $group_by_select = '';
    $group_by_clause = '';
    $order_by_clause = '';
    if (!empty($group_options['group_by'])) {
      $group_by_select = implode(', ', $qualified_group_by) .', ';
      $group_by_clause = ' GROUP BY '. implode(', ', $qualified_group_by);

      if (!empty($group_options['order_by'])) {
        $order_by_clause = ' ORDER BY '. implode(', ', $group_options['order_by'])
          . (empty($group_options['order_ascending']) ? ' DESC' : ' ASC');
      }
    }

    $query = '
      SELECT '. $group_by_select .'COUNT(op.vc_op_id) AS total_operations,
      MIN(op.date) AS first_operation_date, MAX(op.date) AS last_operation_date
      FROM '. $query_info['from'] .
      (empty($query_info['where']) ? '' : ' WHERE '. $query_info['where'])
      . $group_by_clause . $order_by_clause;

    // The query has been built, now execute it.
    $result = _versioncontrol_query($query, $query_info['params'], $group_options);
    $statistics = array();

    // Construct the result value.
    while ($row = db_fetch_object($result)) {
      if ($row->total_operations == 0) {
        $row->first_operation_date = 0;
        $row->last_operation_date = 0;
      }
      if (empty($group_options['group_by'])) {
        $statistics = $row;
        break; // Without grouping, it's just one result row anyways.
      }
      else {
        $group_values = array();
        foreach ($group_options['group_by'] as $column) {
          $group_values[$column] = $row->$column;
        }
        $key = implode("\t", $group_values);
        $statistics[$key] = $row;
      }
    }
    return $statistics;
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

?>
