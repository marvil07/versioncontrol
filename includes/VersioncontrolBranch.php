<?php
require_once 'VersioncontrolLabel.php';

/**
 * Represents a branch of code
 *
 */
class VersioncontrolBranch extends VersioncontrolLabel {
  // Operations
  /**
   * Constructor
   */
  public function __construct($type, $name, $action, $label_id=NULL, $repository=NULL) {
    parent::__construct($type, $name, $action, $label_id, $repository);
    $this->type = VERSIONCONTROL_LABEL_BRANCH;
  }

}
