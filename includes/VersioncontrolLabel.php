<?php
require_once 'VersioncontrolRepository.php';

/**
 * The parent of branches and tags classes
 *
 */
class VersioncontrolLabel {
    // Attributes
    /**
     * The label identifier (a simple integer), used for unique
     * identification of branches and tags in the database.
     *
     * @var    int
     * @access public
     */
    public $id;

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

    // Associations
    // Operations

    /**
     * Constructor
     */
    public function __construct($name, $id=NULL, $repository=NULL) {
      $this->name = $name;
      $this->id = $id;
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
    public function ensure($label) {
      if (!empty($label['label_id'])) { // already in the database
        return $label;
      }
      $result = db_query(
        "SELECT label_id, repo_id, name, type FROM {versioncontrol_labels}
          WHERE repo_id = %d AND name = '%s' AND type = %d",
        $this->repository['repo_id'], $label['name'], $label['type']
      );
      while ($row = db_fetch_object($result)) {
        // Replace / fill in properties that were not in the WHERE condition.
        $label['label_id'] = $row->label_id;
        return $label;
      }
      // The item doesn't yet exist in the database, so create it.
      return $this->_insert($label);
    }

    /**
     * Insert label to db
     *
     * @access private
     */
    private function _insert($label) {
      $label['repo_id'] = $this->repository['repo_id']; // for drupal_write_record() only

      if (isset($label['label_id'])) {
        // The label already exists in the database, update the record.
        drupal_write_record('versioncontrol_labels', $label, 'label_id');
      }
      else {
        // The label does not yet exist, create it.
        // drupal_write_record() also adds the 'label_id' to the $label array.
        drupal_write_record('versioncontrol_labels', $label);
      }
      unset($label['repo_id']);
      return $label;
    }

}

?>
