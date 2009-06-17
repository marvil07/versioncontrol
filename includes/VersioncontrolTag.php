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
  public function __construct($type, $name, $action, $label_id=NULL, $repository=NULL) {
    parent::__construct($type, $name, $action, $label_id, $repository);
    $this->type = VERSIONCONTROL_LABEL_TAG;
  }

}
