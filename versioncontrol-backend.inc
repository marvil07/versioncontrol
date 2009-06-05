<?php
// $Id$
/**
 * @file
 * Version Control API - An interface to version control systems
 * whose functionality is provided by pluggable back-end modules.
 *
 * This file contains the backend-only side of the Version Control API.
 * It is public API, but not meant to be used by non-backend modules.
 *
 * Copyright 2007, 2008 by Jakob Petsovits ("jpetso", http://drupal.org/user/56020)
 */

//TODO: define if we want to do the load each time, per use, or all-in-one like views.inc
require_once drupal_get_path('module', 'versioncontrol') .'/includes/VersioncontrolAccount.php';

function _versioncontrol_insert_item_revision($repository, $item) {
  $item['repo_id'] = $repository['repo_id']; // for drupal_write_record() only

  if (isset($item['item_revision_id'])) {
    // The item already exists in the database, update the record.
    drupal_write_record('versioncontrol_item_revisions', $item, 'item_revision_id');
  }
  else {
    // The label does not yet exist, create it.
    // drupal_write_record() also adds the 'item_revision_id' to the $item array.
    drupal_write_record('versioncontrol_item_revisions', $item);
  }
  unset($item['repo_id']);
  return $item;
}
