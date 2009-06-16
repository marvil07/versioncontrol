<?php
require_once 'VersioncontrolLabel.php';

/**
 * Represents a tag of code(not changing state)
 *
 */
class VersioncontrolTag extends VersioncontrolLabel {

  // Operations
  /**
   * Constructor
   */
  public function __construct($name, $id=NULL, $repository=NULL) {
    parent::__construct($name, $id=NULL, $repository=NULL);
    $this->type = VERSIONCONTROL_LABEL_TAG;
  }

}
