<?php
require_once 'VersioncontrolRepository.php';

/**
 * @name VCS label types
 * Use same values as VERSIONCONTROL_OPERATION_* for backward compatibility
 * TODO: change all involved label['type'] usages
 */
//@{
define('VERSIONCONTROL_LABEL_BRANCH', 2);
define('VERSIONCONTROL_LABEL_TAG',    3);
//@}

/**
 * The parent of branches and tags classes
 *
 */
abstract class VersioncontrolLabel implements ArrayAccess {
    // Attributes
    /**
     * The label identifier (a simple integer), used for unique
     * identification of branches and tags in the database.
     *
     * @var    int
     * @access public
     */
    public $label_id;

    /**
     * The branch or tag name.
     *
     * @var    string
     * @access public
     */
    public $name;

    /**
     * The repository where the label is located.
     *
     * @var    VersioncontrolRepository
     * @access public
     */
    public $repository;

    /**
     *  Whether this label is a branch (indicated by the
     *  VERSIONCONTROL_OPERATION_BRANCH constant) or a tag
     *  (VERSIONCONTROL_OPERATION_TAG).
     *
     * @var    int
     * @access public
     */
    public $type;

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

    // Associations
    // Operations

    /**
     * Constructor
     */
    public function __construct($type, $name, $action, $label_id=NULL, $repository=NULL) {
      $this->type = $type;
      $this->name = $name;
      $this->action = $action;
      $this->label_id = $label_id;
      $this->repository = $repository;
    }

    /**
     * Insert a label entry into the {versioncontrol_labels} table,
     * or retrieve the same one that's already there.
     *
     * @access public
     * @param $label
     *   A structured array describing the branch or tag that should be inserted
     *   into the database. A label array contains (at least) the following keys:
     *
     *   - 'name': The branch or tag name (a string).
     *   - 'type': Whether this label is a branch (indicated by the
     *        VERSIONCONTROL_OPERATION_BRANCH constant) or a tag
     *        (VERSIONCONTROL_OPERATION_TAG).
     *   - 'label_id': Optional - if it doesn't exist yet, it will afterwards.
     *        The label identifier (a simple integer), used for unique
     *        identification of branches and tags in the database.
     *
     * @return
     *   The @p $label variable, enhanced with the newly added property 'label_id'
     *   specifying the database identifier for that label. There may be labels
     *   with a similar 'name' but different 'type' properties, those are considered
     *   to be different and will both go into the database side by side.
     */
    public function ensure() {
      if (!empty($this->label_id)) { // already in the database
        return;
      }
      $result = db_query(
        "SELECT label_id, repo_id, name, type FROM {versioncontrol_labels}
          WHERE repo_id = %d AND name = '%s' AND type = %d",
        $this->repository->repo_id, $this->name, $this->type
      );
      while ($row = db_fetch_object($result)) {
        // Replace / fill in properties that were not in the WHERE condition.
        $this->label_id = $row->label_id;
        return;
      }
      // The item doesn't yet exist in the database, so create it.
      $this->_insert();
    }

    /**
     * Insert label to db
     *
     * @access private
     */
    private function _insert() {
      $this->repo_id = $this->repository->repo_id; // for drupal_write_record() only

      if (isset($this->label_id)) {
        // The label already exists in the database, update the record.
        drupal_write_record('versioncontrol_labels', $this, 'label_id');
      }
      else {
        // The label does not yet exist, create it.
        // drupal_write_record() also adds the 'label_id' to the $label array.
        drupal_write_record('versioncontrol_labels', $this);
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
