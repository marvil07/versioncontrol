<?php
// $Id$
/**
 * @file
 * We need to declare this file to avoid a not defined class, because autoload
 * is executed __after__ bootstrap, where drupal_load get all php
 */

class VersioncontrolTestBackend extends VersioncontrolBackend {
  public function __construct() {
    $this->name = 'TestVCS';
    $this->description = t('TestVCS backend for Version Control API.');
    $this->capabilities = array(
      // Use the commit hash for to identify the commit instead of an individual
      // revision for each file.
      VERSIONCONTROL_CAPABILITY_ATOMIC_COMMITS
    );
    $this->classes = array(
      'repo' => 'VersioncontrolTestRepository',
      'account' => 'VersioncontrolTestAccount',
      'operation' => 'VersioncontrolTestOperation',
      'item' => 'VersioncontrolTestItem',
    );
  }
}

class VersioncontrolTestRepository extends VersioncontrolRepository {
}

class VersioncontrolTestAccount extends VersioncontrolAccount {
}

class VersioncontrolTestOperation extends VersioncontrolOperation {
}

class VersioncontrolTestItem extends VersioncontrolItem {
}
