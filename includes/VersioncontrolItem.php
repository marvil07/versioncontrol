<?php
require_once 'VersioncontrolRepository.php';

/**
 * @name VCS item types.
 */
//@{
define('VERSIONCONTROL_ITEM_FILE',              1);
define('VERSIONCONTROL_ITEM_DIRECTORY',         2);
/**
 * @name VCS "Deleted" item types. 
 * Only used for items that don't exist in the repository (anymore), at least
 * not in the given revision. That is mostly the case with items that were
 * deleted by a commit and are returned as result by
 * versioncontrol_get_operation_items(). A "deleted file" can also be returned
 * by directory listings for CVS, representing "dead files".
 */
//@{
define('VERSIONCONTROL_ITEM_FILE_DELETED',      3);
define('VERSIONCONTROL_ITEM_DIRECTORY_DELETED', 4);
//@}
//@}

/**
 * Represent an Items (a.k.a. item revisions)
 *
 * Files or directories inside a specific repository, including information
 * about the path, type ("file" or "directory") and (file-level) revision, if
 * applicable. Most item revisions, but probably not all of them, are recorded
 * in the database.
 */
class VersioncontrolItem implements ArrayAccess {
    // Attributes
    /**
     * db identifier
     *
     * @var    int
     * @access public
     */
    public $item_revision_id;

    /**
     * The path of the item.
     *
     * @var    string
     * @access public
     */
    public $path;

    /**
     * Deleted status
     *
     * @var    boolean
     * @access public
     */
    public $deleted;

    /**
     * A specific revision for the requested item, in the same VCS-specific
     * format as $item['revision']. A repository/path/revision combination is
     * always unique, so no additional information is needed.
     *
     * @var    string
     * @access public
     */
    public $revision;

    /**
     * FIXME: ?
     *
     * @var    array
     * @access public
     */
    public $source_items = array();

    /**
     * @name VCS actions
     * for a single item (file or directory) in a commit, or for branches and tags.
     * either VERSIONCONTROL_ACTION_{ADDED,MODIFIED,MOVED,COPIED,MERGED,DELETED,
     * REPLACED,OTHER}
     *
     * @var    array
     * @access public
     */
    public $action;

    /**
     * FIXME: ?
     *
     * @var    array
     * @access public
     */
    public $lines_changes = array();

    /**
     * FIXME: ?
     *
     * @var    VersioncontrolRepository
     * @access public
     */
    public $repository;

    //TODO subclass per type?
    public $selected_label;
    public $commit_operation;

    // Associations
    // Operations
  /**
   * Constructor
   */
  public function __construct($type, $path, $revision, $action, $repository, $deleted=NULL, $item_revision_id=NULL) {
    $this->type = $type;
    $this->path = $path;
    $this->revision = $revision;
    $this->action = $action;
    $this->repository = $repository;
    $this->deleted = $deleted;
    $this->item_revision_id = $item_revision_id;
  }

    /**
     * Return TRUE if the given item is an existing or an already deleted file,
     * or FALSE if it's not.
     *
     * @access public
     */
    public function isFile() {
      if ($this->type == VERSIONCONTROL_ITEM_FILE
          || $this->type == VERSIONCONTROL_ITEM_FILE_DELETED) {
        return TRUE;
      }
      return FALSE;
    }

    /**
     * Return TRUE if the given item is an existing or an already deleted directory,
     * or FALSE if it's not.
     *
     * @access public
     */
    public function isDirectory($item) {
      if ($this->type == VERSIONCONTROL_ITEM_DIRECTORY
          || $this->type == VERSIONCONTROL_ITEM_DIRECTORY_DELETED) {
        return TRUE;
      }
      return FALSE;
    }

    /**
     * Return TRUE if the given item is marked as deleted, or FALSE if it exists.
     * 
     * @access public
     */
    public function isDeleted($item) {
      if ($this->type == VERSIONCONTROL_ITEM_FILE_DELETED
          || $this->type == VERSIONCONTROL_ITEM_DIRECTORY_DELETED) {
        return TRUE;
      }
      return FALSE;
    }

    /**
     * Retrieve the commit operation corresponding to each item in a list of items.
     *
     * @access public
     * @param $repository
     *   The repository that the items are located in.
     * @param $items
     *   An array of item arrays, for example as returned by
     *   versioncontrol_get_operation_items().
     *
     * @return
     *   This function does not have a return value; instead, it alters the
     *   given item arrays and adds additional information about their
     *   corresponding commit operation in an 'commit_operation' property.
     *   If no corresponding commit was found, this property will not be set.
     */
    public function fetchCommitOperations($repository, &$items) {
      $placeholders = array();
      $ids = array();
      $item_keys = array();

      $fetch_by_revision_id = FALSE;

      // If there are atomic commits and versioned directories (= SVN), we'll miss
      // out on operations for directory items if those are not (always) captured
      // in the {versioncontrol_operation_items} table.
      // So fetch by revision id instead in that case.
      $backend = versioncontrol_get_backend($repository);

      if (in_array(VERSIONCONTROL_CAPABILITY_ATOMIC_COMMITS, $backend['capabilities'])) {
        $fetch_by_revision_id = TRUE;
      }

      foreach ($items as $key => $item) {
        if (!empty($item['commit_operation'])) {
          continue; // No need to insert an operation if it's already there.
        }
        if ($fetch_by_revision_id && !empty($item['revision'])) {
          $ids[$item['revision']] = TRUE; // automatic duplicate elimination
        }
        // If we don't yet know the item_revision_id (required for db queries), try
        // to retrieve it. If we don't find it, we can't fetch this item's sources.
        if (versioncontrol_fetch_item_revision_id($repository, $item)) {
          $placeholders[] = '%d';
          $ids[] = $item['item_revision_id'];
          $item_keys[$item['item_revision_id']] = $key;
        }
      }
      if (empty($ids)) {
        return;
      }

      if ($fetch_by_revision_id) {
        $commit_operations = versioncontrol_get_commit_operations(array(
          'repo_ids' => array($repository['repo_id']),
          'revisions' => array_keys($ids),
        ));
        // Associate the commit operations to the items.
        foreach ($items as $key => $item) {
          foreach ($commit_operations as $commit_operation) {
            if ($item['revision'] == $commit_operation['revision']) {
              $items[$key]['commit_operation'] = $commit_operation;
            }
          }
        }
      }
      else { // fetch by operation/item association
        $result = db_query(
          'SELECT item_revision_id, vc_op_id
          FROM {versioncontrol_operation_items}
          WHERE item_revision_id IN ('. implode(',', $placeholders) .')', $ids);

        $operation_item_mapping = array();
        while ($opitem = db_fetch_object($result)) {
          $operation_item_mapping[$opitem->vc_op_id][] = $opitem->item_revision_id;
        }
        $commit_operations = versioncontrol_get_commit_operations(array(
          'vc_op_ids' => array_keys($operation_item_mapping),
        ));

        // Associate the commit operations to the items.
        foreach ($commit_operations as $commit_operation) {
          $item_revision_ids = $operation_item_mapping[$commit_operation['vc_op_id']];

          foreach ($item_revision_ids as $item_revision_id) {
            $item_key = $item_keys[$item_revision_id];
            $items[$item_key]['commit_operation'] = $commit_operation;
          }
        }
      }
    }

    /**
     * Retrieve the revisions where the given item has been changed,
     * in reverse chronological order.
     *
     * Only one direct source or successor of each item will be retrieved, which
     * means that you won't get parallel history logs with a single function call.
     * In order to retrieve the log for this item in a different branch, you need
     * to switch the selected label of the item by retrieving a different version
     * of it with a call of versioncontrol_get_parallel_items() (if the backend
     * supports this function).
     *
     * @access public
     * @param $repository
     *   The repository that the item is located in.
     * @param $item
     *   The item whose history should be retrieved.
     *
     * @return
     *   An array containing a list of item arrays, each one specifying a revision
     *   of the same item that was given as argument. The array is sorted in
     *   reverse chronological order, so the newest revision comes first. Each
     *   element has its (file-level) item revision as key, and a standard item
     *   array (as the ones retrieved by versioncontrol_get_operation_items())
     *   as value. All items except for the oldest one will also have the 'action'
     *   and 'source_items' properties filled in, the oldest item might or
     *   might not have them. (If they exist for the oldest item, 'action' will be
     *   VERSIONCONTROL_ACTION_ADDED and 'source_items' an empty array.)
     *
     *   NULL is returned if the given item is not under version control,
     *   or was not under version control at the time of the given revision,
     *   or if no history could be retrieved for any other reason.
     */
    public function getItemHistory($repository, &$item, $successor_item_limit = NULL, $source_item_limit = NULL) {
      // Items without revision have no history, don't even try to fetch it.
      if (empty($item['revision'])) {
        return NULL;
      }
      // If we don't yet know the item_revision_id (required for db queries), try
      // to retrieve it. If we don't find it, we can't go on with this function.
      if (!versioncontrol_fetch_item_revision_id($repository, $item)) {
        return NULL;
      }

      // Make sure we don't run into infinite loops when passed bad arguments.
      if (is_numeric($successor_item_limit) && $successor_item_limit < 0) {
        $successor_item_limit = 0;
      }
      if (is_numeric($source_item_limit) && $source_item_limit < 0) {
        $source_item_limit = 0;
      }

      // Naive implementation - can probably be improved by sticking to the same
      // repo_id/path until an action other than "modified" or "other" appears.
      // (With the drawback that code will probably need to be duplicated among
      // this function and versioncontrol_fetch_{source,successor}_items().

      // Find (recursively) all successor items within the successor item limit.
      $history_successor_items = array();
      $source_item = $item;
      static $successor_action_priority = array(
        VERSIONCONTROL_ACTION_MOVED => 10,
        VERSIONCONTROL_ACTION_MODIFIED => 10,
        VERSIONCONTROL_ACTION_COPIED => 8,
        VERSIONCONTROL_ACTION_MERGED => 9,
        VERSIONCONTROL_ACTION_OTHER => 1,
        VERSIONCONTROL_ACTION_DELETED => 1,
        VERSIONCONTROL_ACTION_ADDED => 0, // does not happen, guard nonetheless
        VERSIONCONTROL_ACTION_REPLACED => 0, // does not happen, guard nonetheless
      );

      while ((!isset($successor_item_limit) || ($successor_item_limit > 0))) {
        $source_items = array($source_item['path'] => $source_item);
        versioncontrol_fetch_successor_items($repository, $source_items);
        $source_item = $source_items[$source_item['path']];

        // If there are no successor items, we are obviously at the end of the log.
        if (empty($source_item['successor_items'])) {
          break;
        }
        // There might be multiple successor items - in most cases, the first one is
        // the only one so that's ok except for "merged" actions.
        $successor_item = NULL;
        $highest_priority_so_far = 0;
        foreach ($source_item['successor_items'] as $path => $succ_item) {
          if (!isset($successor_item)
              || $successor_action_priority[$succ_item['action']] > $highest_priority_so_far) {
            $successor_item = $succ_item;
            $highest_priority_so_far = $successor_action_priority[$succ_item['action']];
          }
        }
        $history_successor_items[$successor_item['revision']] = $successor_item;
        $source_item = $successor_item;

        // Decrement the counter until the item limit is reached.
        if (isset($successor_item_limit)) {
          --$successor_item_limit;
        }
      }
      // We want the newest revisions first, so reverse the successor array.
      $history_successor_items = array_reverse($history_successor_items, TRUE);

      // Find (recursively) all source items within the source item limit.
      $history_source_items = array();
      $successor_item = $item;

      while (!isset($source_item_limit) || ($source_item_limit > 0)) {
        $successor_items = array($successor_item['path'] => $successor_item);
        versioncontrol_fetch_source_items($repository, $successor_items);
        $successor_item = $successor_items[$successor_item['path']];

        // If there are no source items, we are obviously at the end of the log.
        if (empty($successor_item['source_items'])) {
          break;
        }
        // There might be multiple source items - in most cases, the first one is
        // the only one so that's ok except for "merged" actions.
        $source_item = NULL;
        if ($successor_item['action'] == VERSIONCONTROL_ACTION_MERGED) {
          if (isset($successor_item['source_items'][$successor_item['path']])) {
            $source_item = $successor_item['source_items'][$successor_item['path']];
          }
        }
        if (!isset($source_item)) {
          $source_item = reset($successor_item['source_items']); // first item
        }
        $history_source_items[$source_item['revision']] = $source_item;
        $successor_item = $source_item;

        // Decrement the counter until the item limit is reached.
        if (isset($source_item_limit)) {
          --$source_item_limit;
        }
      }

      return $history_successor_items + array($item['revision'] => $item) + $history_source_items;
    }

    /**
     * Make sure that the 'item_revision_id' database identifier is among an item's
     * properties, and if it's not then try to add it.
     *
     * @access public
     *
     * @return
     *   TRUE if the 'item_revision_id' exists after calling this function,
     *   FALSE if not.
     */
    public function fetchItemRevisionId() {
      if (!empty($this->item_revision_id)) {
        return TRUE;
      }
      $id = db_result(db_query(
        "SELECT item_revision_id FROM {versioncontrol_item_revisions}
          WHERE repo_id = %d AND path = '%s' AND revision = '%s'",
        $this->repository->repo_id, $this->path, $this->revision
      ));
      if (empty($id)) {
        return FALSE;
      }
      $this->item_revision_id = $id;
      return TRUE;
    }

    /**
     * Retrieve an item's selected label.
     *
     * When first retrieving an item, the selected label is initialized with a
     * sensible value - for example, versioncontrol_get_operation_items() assigns
     * the affected branch or tag of that operation to all the items. (This is
     * especially important for version control systems like Subversion where there
     * is a need to specify the label per item and not per operation, as a single
     * commit can affect multiple branches or tags at once.)
     *
     * The selected label is also meant to help with branch/tag-based navigation,
     * so item navigation functions will try to preserve it as good as possible, as
     * far as it's accurate.
     *
     * @access public
     *
     * @return
     *   In case no branch or tag applies to that item or could not be retrieved
     *   for whatever reasons, the selected label can also be NULL. Otherwise, it's
     *   a label array describing the selected label, with the following keys:
     *
     *   - 'label_id': The label identifier (a simple integer), used for unique
     *        identification of branches and tags in the database.
     *   - 'name': The branch or tag name (a string).
     *   - 'type': Whether this label is a branch (indicated by the
     *        VERSIONCONTROL_OPERATION_BRANCH constant) or a tag
     *        (VERSIONCONTROL_OPERATION_TAG).
     *  FIXME remove params and do not return, oop
     */
    public function getSelectedLabel() {
      // If the label is already retrieved, we can return it just that way.
      if (isset($this->selected_label->label)) {
        return ($this->selected_label->label === FALSE)
                ? NULL : $this->selected_label->label;
      }
      if (!isset($this->selected_label->get_from)) {
        $this->selected_label->label = FALSE;
        return NULL;
      }
      $function_prefix = 'versioncontrol_'. $this->repository->vcs;

      // Otherwise, determine how we might be able to retrieve the selected label.
      switch ($this->selected_label->get_from) {
        case 'operation':
          $function = $function_prefix .'_get_selected_label_from_operation';
          $selected_label = $function($this->selected_label->operation, $this);
          break;
        case 'other_item':
          $function = $function_prefix .'_get_selected_label_from_other_item';
          $selected_label = $function($this->repository, $this, $this->selected_label->other_item, $this->selected_label->other_item_tags);
          unset($this->selected_label->other_item_tags);
          break;
      }

      if (isset($selected_label)) {
        // Just to make sure that we only pass applicable info:
        // 'action' might make sense in an operation, but not in an item array.
        if (isset($selected_label->action)) {
        //FIXME we are returning a label here, not an item; so, is it ok to have an action on label?
        //  unset($selected_label->action);
        }
        $selected_label->ensure();
        $this->selected_label->label = $selected_label;
      }
      else {
        $this->selected_label->label = FALSE;
      }

      // Now that we've got the real label, we can get rid of the retrieval recipe.
      if (isset($this->selected_label->{$this->selected_label->get_from})) {
        unset($this->selected_label->{$this->selected_label->get_from});
      }
      unset($this->selected_label->get_from);

      return $this->selected_label->label;
    }

    /**
     * Check if the @p $path_regexp applies to the path of the given @p $item.
     * This function works just like preg_match(), with the single difference that
     * it also accepts a trailing slash for item paths if the item is a directory.
     *
     * @access public
     * @return
     *   The number of times @p $path_regexp matches. That will be either 0 times
     *   (no match) or 1 time because preg_match() (which is what this function
     *   uses internally) will stop searching after the first match.
     *   FALSE will be returned if an error occurred.
     */
    public function pregItemMatch($path_regexp, $item) {
      $path = $item['path'];

      if (versioncontrol_is_directory_item($item) && $path != '/') {
        $path .= '/';
      }
      return preg_match($path_regexp, $path);
    }

    /**
     * Print out a "Bad item received from VCS backend" warning to watchdog.
     * 
     * @access public
     */
    public function _badItemWarning($repository, $item, $message) {
      watchdog('special', "<p>Bad item received from VCS backend: !message</p>
        <pre>Item array: !item\nRepository array: !repository</pre>", array(
          '!message' => $message,
          '!item' => print_r($item, TRUE),
          '!repository' => print_r($repository, TRUE),
        ), WATCHDOG_ERROR
      );
    }

    /**
     * Retrieve the parent (directory) item of a given item.
     *
     * @access public
     * @param $repository
     *   The repository that the item is located in.
     * @param $item
     *   The item whose parent should be retrieved.
     * @param $parent_path
     *   NULL if the direct parent of the given item should be retrieved,
     *   or a parent path that is further up the directory tree.
     *
     * @return
     *   The parent directory item at the same revision as the given item.
     *   If $parent_path is not set and the item is already the topmost one
     *   in the repository, the item is returned as is. It also stays the same
     *   if $parent_path is given and the same as the path of the given item.
     *   If the given directory path does not correspond to a parent item,
     *   NULL is returned.
     */
    public function getParentItem($parent_path = NULL) {
      if (!isset($parent_path)) {
        $path = dirname($this->path);
      }
      else if ($this->path == $parent_path) {
        return $this;
      }
      else if ($parent_path == '/' || strpos($this->path .'/', $parent_path .'/') !== FALSE) {
        $path = $parent_path;
      }
      else {
        return NULL;
      }

      $backend = versioncontrol_get_backend($this->repository);

      $revision = '';
      if (in_array(VERSIONCONTROL_CAPABILITY_DIRECTORY_REVISIONS, $backend['capabilities'])) {
        $revision = $this->revision;
      }

      $parent_item = new VersioncontrolItem(VERSIONCONTROL_ITEM_DIRECTORY,
        $path, $revision, NULL, $this->repository);

      $parent_item->selected_label = new stdClass();
      $parent_item->selected_label->get_from = 'other_item';
      $parent_item->selected_label->other_item = &$this;
      $parent_item->selected_label->other_item_tags = array('same_revision');

      return $parent_item;
    }

    /**
     * Given an item in a repository, retrieve related versions of that item on all
     * different branches and/or tags where the item exists.
     *
     * This function is optional for VCS backends to implement, be sure to check
     * with versioncontrol_backend_implements($repository['vcs'], 'get_parallel_items')
     * if the particular backend actually implements it.
     *
     * @access public
     * @param $repository
     *   The repository that the item is located in.
     * @param $item
     *   The item whose parallel sibling should be retrieved.
     * @param $label_type
     *   If unset, siblings will be retrieved both on branches and tags.
     *   If set to VERSIONCONTROL_OPERATION_BRANCH or VERSIONCONTROL_OPERATION_TAG,
     *   results are limited to just that label type.
     *
     * @return
     *   An item array of parallel items on all branches and tags, possibly
     *   including the original item itself (if appropriate for the given
     *   @p $label_type_filter). Array keys do not convey any specific meaning,
     *   item values are again structured arrays and consist of elements with the
     *   following keys:
     *
     *   - 'type': Specifies the item type, which should be the same as the type
     *        of the given @p $item.
     *   - 'path': The path of the item at the specific revision.
     *   - 'revision': The (file-level) revision when the item was last changed.
     *        If there is no such revision (which may be the case for
     *        directory items) then the 'revision' element is an empty string.
     *
     *   Branch and tag names are implicitely stored and can be retrieved by
     *   calling versioncontrol_get_item_selected_label() on each item in the
     *   result array.
     *
     *   NULL is returned if the given item is not inside the repository,
     *   or has not been inside the repository at the specified revision.
     *   An empty array is returned if the item is valid, but no parallel sibling
     *   items can be found for the given @p $label_type.
     */
    public function getParallelItems($repository, $item, $label_type_filter = NULL) {
      $results = _versioncontrol_call_backend(
        $repository['vcs'], 'get_parallel_items',
        array($repository, $item, $label_type_filter)
      );
      if (is_null($results)) {
        return NULL;
      }
      $items = array();

      foreach ($results as $key => $result) {
        $items[$key] = $result['item'];
        $items[$key]['selected_label'] = new stdClass();
        $items[$key]['selected_label']->label = is_null($result['selected_label'])
                                                ? NULL
                                                : $result['selected_label'];
      }
      return $items;
    }

    /**
     * Retrieve the set of files and directories that exist at a specified revision
     * inside the given directory in the repository.
     *
     * This function is optional for VCS backends to implement, be sure to check
     * with versioncontrol_backend_implements($repository['vcs'], 'get_directory_contents')
     * if the particular backend actually implements it.
     *
     * @access public
     * @param $repository
     *   The repository that the directory item is located in.
     * @param $directory_item
     *   The parent item of the items that should be listed.
     * @param $recursive
     *   If FALSE, only the direct children of $path will be retrieved.
     *   If TRUE, you'll get every single descendant of $path.
     *
     * @return
     *   A structured item array of items that have been inside the directory in
     *   its given state, including the directory item itself. Array keys are the
     *   current/new paths. The corresponding item values are again structured
     *   arrays and consist of elements with the following keys:
     *
     *   - 'type': Specifies the item type, which is either
     *        VERSIONCONTROL_ITEM_FILE or VERSIONCONTROL_ITEM_DIRECTORY.
     *   - 'path': The path of the item at the specific revision.
     *   - 'revision': The (file-level) revision when the item was last changed.
     *        If there is no such revision (which may be the case for
     *        directory items) then the 'revision' element is an empty string.
     *
     *   NULL is returned if the given item is not inside the repository,
     *   or if it is not a directory item at all.
     *
     *   A real-life example of such a result array can be found
     *   in the FakeVCS example module.
     */
    public function getDirectoryContents($repository, $directory_item, $recursive = FALSE) {
      if (!versioncontrol_is_directory_item($directory_item)) {
        return NULL;
      }
      $contents = _versioncontrol_call_backend(
        $repository['vcs'], 'get_directory_contents',
        array($repository, $directory_item, $recursive)
      );
      if (!isset($contents)) {
        return NULL;
      }
      $items = array();

      foreach ($contents as $path => $content) {
        $items[$path] = $content['item'];
        $items[$path]['selected_label'] = new stdClass();
        $items[$path]['selected_label']->label = is_null($content['selected_label'])
                                                  ? NULL
                                                  : $content['selected_label'];
      }
      return $items;
    }

    /**
     * Retrieve a copy of the contents of a given file item in the repository.
     *
     * (You won't get the original because repositories can often be remote.)
     *
     * The caller should make sure to delete the file when it's not needed anymore.
     * That requirement might change in the future though.
     *
     * This function is optional for VCS backends to implement, be sure to check
     * with versioncontrol_backend_implements($repository['vcs'], 'export_file')
     * if the particular backend actually implements it.
     *
     * @access public
     * @param $repository
     *   The repository that the file item is located in.
     * @param $file_item
     *   The file item whose contents should be retrieved.
     *
     * @return
     *   The local path of the created copy, if successful.
     *   NULL is returned if the given item is not under version control,
     *   or was not under version control at the time of the given revision.
     */
    public function exportFile($repository, $file_item) {
      if (!versioncontrol_is_file_item($file_item)) {
        return NULL;
      }
      $filename = basename($file_item['path']);
      $destination = file_directory_temp() .'/versioncontrol-'. mt_rand() .'-'. $filename;
      $success = _versioncontrol_call_backend(
        $repository['vcs'], 'export_file', array($repository, $file_item, $destination)
      );
      if ($success) {
        return $destination;
      }
      @unlink($destination);
      return NULL;
    }

    /**
     * Retrieve a copy of the given directory item in the repository.
     *
     * (You won't get the original because repositories can often be remote.)
     *
     * The caller should make sure to delete the directory when it's not needed
     * anymore.
     *
     * This function is optional for VCS backends to implement, be sure to check
     * with versioncontrol_backend_implements($repository['vcs'], 'export_directory')
     * if the particular backend actually implements it.
     *
     * @access public
     * @param $repository
     *   The repository that the directory item is located in.
     * @param $directory_item
     *   The directory item whose contents should be exported.
     * @param $destination_dirpath
     *   The path of the directory that will receive the contents of the exported
     *   repository item. If that directory already exists, it will be replaced.
     *   If that directory doesn't yet exist, it will be created by the backend.
     *   (This directory will directly correspond to the @p $directory_item - there
     *   are no artificial subdirectories, even if the @p $destination_dirpath has
     *   a different basename than the original path of the @p $directory_item.)
     *
     * @return
     *   TRUE if successful, or FALSE if not.
     *   FALSE can be returned if the given item is not under version control,
     *   or was not under version control at the time of the given revision,
     *   or simply cannot be exported to the destination directory for any reason.
     */
    public function exportDirectory($repository, $directory_item, $destination_dirpath) {
      if (!versioncontrol_is_directory_item($directory_item)) {
        return FALSE;
      }
      // Unless file.inc provides a nice function for recursively deleting
      // directories, let's just go for the straightforward portable method.
      $rm = (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN') ? 'rd /s' : 'rm -rf';
      exec("$rm $destination_dirpath");

      $success = _versioncontrol_call_backend(
        $repository['vcs'], 'export_directory',
        array($repository, $directory_item, $destination_dirpath)
      );
      if (!$success) {
        exec("$rm $destination_dirpath");
        return FALSE;
      }
      return TRUE;
    }

    /**
     * Retrieve an array where each element represents a single line of the
     * given file in the specified commit, annotated with the committer who last
     * modified that line. Note that annotations are generally a quite slow
     * operation, so expect this function to take a bit more time as well.
     *
     * This function is optional for VCS backends to implement, be sure to check
     * with versioncontrol_backend_implements($repository['vcs'], 'get_file_annotation')
     * if the particular backend actually implements it.
     *
     * @access public
     * @param $repository
     *   The repository that the file item is located in.
     * @param $file_item
     *   The file item whose annotation should be retrieved.
     *
     * @return
     *   A structured array that consists of one element per line, with
     *   line numbers as keys (starting from 1) and a structured array as values,
     *   where each of them consists of elements with the following keys:
     *
     *   - 'username': The system specific VCS username of the last committer.
     *   - 'line': The contents of the line, without linebreak characters.
     *
     *   NULL is returned if the given item is not under version control,
     *   or was not under version control at the time of the given revision,
     *   or if it is not a file item at all, or if it is marked as binary file.
     *
     *   A real-life example of such a result array can be found
     *   in the FakeVCS example module.
     */
    public function getFileAnnotation($repository, $file_item) {
      if (!versioncontrol_is_file_item($file_item)) {
        return NULL;
      }
      return _versioncontrol_call_backend(
        $repository['vcs'], 'get_file_annotation', array($repository, $file_item)
      );
    }

    /**
     * Check and if necessary correct item arrays so that item type and
     * the number of source items correspond to specified actions.
     *
     * @access public
     */
    public function sanitize() {
      if (isset($this->action)) {
        // Make sure the number of source items corresponds with the action.
        switch ($this->action) {
          // No source items for "added" actions.
          case VERSIONCONTROL_ACTION_ADDED:
            if (count($this->source_items) > 0) {
              _versioncontrol_bad_item_warning($this->repository, $this, 'At least one source item exists although the "added" action was set (which mandates an empty \'source_items\' array.');
              $this->source_items = array(reset($this->source_items)); // first item
              $this->source_items = array();
            }
            break;
          // Exactly one source item for actions other than "added", "merged" or "other".
          case VERSIONCONTROL_ACTION_MODIFIED:
          case VERSIONCONTROL_ACTION_MOVED:
          case VERSIONCONTROL_ACTION_COPIED:
          case VERSIONCONTROL_ACTION_DELETED:
            if (count($this->source_items) > 1) {
              _versioncontrol_bad_item_warning($this->repository, $this, 'More than one source item exists although a "modified", "moved", "copied" or "deleted" action was set (which allows only one of those).');
              $item->source_items = array(reset($item->source_items)); // first item
            }
            // fall through
          case VERSIONCONTROL_ACTION_MERGED:
            if (empty($this->source_items)) {
              _versioncontrol_bad_item_warning($this->repository, $this, 'No source item exists although a "modified", "moved", "copied", "merged" or "deleted" action was set (which requires at least or exactly one of those).');
            }
            break;
          default:
            break;
        }
        // For a "delete" action, make sure the item type is also a "deleted" one.
        // That's quite a minor error, so don't complain but rather fix it quietly.
        if ($this->action == VERSIONCONTROL_ACTION_DELETED) {
          if ($this->type == VERSIONCONTROL_ITEM_FILE) {
            $this->type = VERSIONCONTROL_ITEM_FILE_DELETED;
          }
          else if ($this->type == VERSIONCONTROL_ITEM_DIRECTORY) {
            $this->type = VERSIONCONTROL_ITEM_DIRECTORY_DELETED;
          }
        }
      }
    }

    /**
     * Insert an item entry into the {versioncontrol_source_items} table.
     * Both target and source items are expected to have an 'item_revision_id'
     * property already. For "added" actions, it's also possible to pass 0 as the
     * @p $source_item parameter instead of a full item array.
     *
     * @access public
     */
    public function insertSourceRevision($source_item, $action) {
      if ($action == VERSIONCONTROL_ACTION_ADDED && $source_item === 0) {
        $source_item = array('item_revision_id' => 0);
      }
      // Before inserting that item entry, make sure it doesn't exist already.
      db_query("DELETE FROM {versioncontrol_source_items}
                WHERE item_revision_id = %d AND source_item_revision_id = %d",
                $this->item_revision_id, $source_item['item_revision_id']);

      $line_changes = !empty($item['line_changes']);
      db_query("INSERT INTO {versioncontrol_source_items}
                (item_revision_id, source_item_revision_id, action,
                 line_changes_recorded, line_changes_added, line_changes_removed)
                VALUES (%d, %d, %d, %d, %d, %d)",
                $this->item_revision_id, $source_item['item_revision_id'],
                $action, ($line_changes ? 1 : 0),
                ($line_changes ? $item['line_changes']['added'] : 0),
                ($line_changes ? $item['line_changes']['removed'] : 0));
    }

    /**
     * Insert an item entry into the {versioncontrol_item_revisions} table,
     * or retrieve the same one that's already there on the object.
     *
     * @access public
     */
    public function ensure() {
      $result = db_query(
        "SELECT item_revision_id, type
         FROM {versioncontrol_item_revisions}
         WHERE repo_id = %d AND path = '%s' AND revision = '%s'",
        $this->repository->repo_id, $this->path, $this->revision
      );
      while ($item_revision = db_fetch_object($result)) {
        // Replace / fill in properties that were not in the WHERE condition.
        $this->item_revision_id = $item_revision->item_revision_id;

        if ($this->type == $item_revision->type) {
          return; // no changes needed - otherwise, replace the existing item.
        }
      }
      // The item doesn't yet exist in the database, so create it.
      $this->insert();
    }

    /**
     * Insert an item revision entry into the {versioncontrol_items_revisions} table.
     * FIXME: ?
     */
    public function insert() {
      $this->repo_id = $this->repository->repo_id; // for drupal_write_record() only

      if (isset($this->item_revision_id)) {
        // The item already exists in the database, update the record.
        drupal_write_record('versioncontrol_item_revisions', $this, 'item_revision_id');
      }
      else {
        // The label does not yet exist, create it.
        // drupal_write_record() also adds the 'item_revision_id' to the $item array.
        drupal_write_record('versioncontrol_item_revisions', $this);
      }
      unset($this->repo_id);
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
