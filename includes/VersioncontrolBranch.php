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
  public function __construct($name, $id=NULL, $repository=NULL) {
    parent::__construct($name, $id=NULL, $repository=NULL);
    $this->type = VERSIONCONTROL_LABEL_BRANCH;
  }

}
