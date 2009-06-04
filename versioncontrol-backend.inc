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

/**
 * Insert a repository into the database, and call the necessary hooks.
 *
 * @param $repository
 *   The repository array containing the new or existing repository.
 *   It's a single repository array like the one returned by
 *   versioncontrol_get_repository(), so it consists of the following elements:
 *
 *   - 'name': The user-visible name of the repository.
 *   - 'vcs': The unique string identifier of the version control system
 *        that powers this repository.
 *   - 'root': The root directory of the repository. In most cases,
 *        this will be a local directory (e.g. '/var/repos/drupal'),
 *        but it may also be some specialized string for remote repository
 *        access. How this string may look like depends on the backend.
 *   - 'authorization_method': The string identifier of the repository's
 *        authorization method, that is, how users may register accounts
 *        in this repository. Modules can provide their own methods
 *        by implementing hook_versioncontrol_authorization_methods().
 *   - 'url_backend': The prefix (excluding the trailing underscore)
 *        for URL backend retrieval functions.
 *   - '[xxx]_specific': An array of VCS specific additional repository
 *        information. How this array looks like is defined by the
 *        corresponding backend module (versioncontrol_[xxx]).
 *        If the backend has registered itself with the
 *        VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES option, all items of
 *        this array will automatically be inserted into the
 *        {versioncontrol_[xxx]_commits} table.
 *
 * @param $repository_urls
 *   An array of repository viewer URLs. How this array looks like is
 *   defined by the corresponding URL backend.
 *
 * @return
 *   The finalized repository array, including the 'repo_id' element.
 */
function versioncontrol_insert_repository($repository, $repository_urls) {
  if (isset($repository['repo_id'])) {
    // This is a new repository, it's not supposed to have a repo_id yet.
    unset($repository['repo_id']);
  }
  drupal_write_record('versioncontrol_repositories', $repository);
  // drupal_write_record() has now added the 'repo_id' to the $repository array.

  $repository_urls['repo_id'] = $repository['repo_id']; // for drupal_write_record()
  drupal_write_record('versioncontrol_repository_urls', $repository_urls);
  unset($repository_urls['repo_id']);

  // Auto-add repository info from $repository['[xxx]_specific'] into the database.
  $backends = versioncontrol_get_backends();
  $vcs = $repository['vcs'];
  $is_autoadd = in_array(VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES,
                         $backends[$vcs]['flags']);
  if ($is_autoadd) {
    $table_name = 'versioncontrol_'. $vcs .'_repositories';
    $elements = $repository[$vcs .'_specific'];
    $elements['repo_id'] = $repository['repo_id'];
    _versioncontrol_db_insert_additions($table_name, $elements);
  }

  // Provide an opportunity for the backend to add its own stuff.
  if (versioncontrol_backend_implements($vcs, 'repository')) {
    _versioncontrol_call_backend($vcs, 'repository', array('insert', $repository));
  }

  // Everything's done, let the world know about it!
  module_invoke_all('versioncontrol_repository', 'insert', $repository);

  watchdog('special',
    'Version Control API: added repository @repository',
    array('@repository' => $repository['name']),
    WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-repositories')
  );
  return $repository;
}

/**
 * Update a repository in the database, and call the necessary hooks.
 * The 'repo_id' and 'vcs' properties of the repository array must stay
 * the same as the ones given on repository creation,
 * whereas all other values may change.
 *
 * @param $repository
 *   The repository array containing the new or existing repository.
 *   It's a single repository array like the one returned by
 *   versioncontrol_get_repository(), so it consists of the following elements:
 *
 *   - 'repo_id': The unique repository id.
 *   - 'name': The user-visible name of the repository.
 *   - 'vcs': The unique string identifier of the version control system
 *        that powers this repository.
 *   - 'root': The root directory of the repository. In most cases,
 *        this will be a local directory (e.g. '/var/repos/drupal'),
 *        but it may also be some specialized string for remote repository
 *        access. How this string may look like depends on the backend.
 *   - 'authorization_method': The string identifier of the repository's
 *        authorization method, that is, how users may register accounts
 *        in this repository. Modules can provide their own methods
 *        by implementing hook_versioncontrol_authorization_methods().
 *   - 'url_backend': The prefix (excluding the trailing underscore)
 *        for URL backend retrieval functions.
 *   - '[xxx]_specific': An array of VCS specific additional repository
 *        information. How this array looks like is defined by the
 *        corresponding backend module (versioncontrol_[xxx]).
 *        If the backend has registered itself with the
 *        VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES option, all items of
 *        this array will automatically be inserted into the
 *        {versioncontrol_[xxx]_commits} table.
 *
 * @param $repository_urls
 *   An array of repository viewer URLs. How this array looks like is
 *   defined by the corresponding URL backend.
 */
function versioncontrol_update_repository($repository, $repository_urls) {
  drupal_write_record('versioncontrol_repositories', $repository, 'repo_id');

  $repository_urls['repo_id'] = $repository['repo_id']; // for drupal_write_record()
  drupal_write_record('versioncontrol_repository_urls', $repository_urls, 'repo_id');
  unset($repository_urls['repo_id']);

  // Auto-add commit info from $commit['[xxx]_specific'] into the database.
  $backends = versioncontrol_get_backends();
  $vcs = $repository['vcs'];
  $is_autoadd = in_array(VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES,
                         $backends[$vcs]['flags']);
  if ($is_autoadd) {
    $table_name = 'versioncontrol_'. $vcs .'_repositories';
    $elements = $repository[$vcs .'_specific'];
    $elements['repo_id'] = $repository['repo_id'];
    _versioncontrol_db_update_additions($table_name, 'repo_id', $elements);
  }

  // Provide an opportunity for the backend to add its own stuff.
  if (versioncontrol_backend_implements($vcs, 'repository')) {
    _versioncontrol_call_backend($vcs, 'repository', array('update', $repository));
  }

  // Everything's done, let the world know about it!
  module_invoke_all('versioncontrol_repository', 'update', $repository);

  watchdog('special',
    'Version Control API: updated repository @repository',
    array('@repository' => $repository['name']),
    WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-repositories')
  );
}

/**
 * Delete a repository from the database, and call the necessary hooks.
 * Together with the repository, all associated commits and accounts are
 * deleted as well.
 *
 * @param $repository
 *   The repository array containing the repository that is to be deleted.
 *   It's a single repository array like the one returned by
 *   versioncontrol_get_repository().
 */
function versioncontrol_delete_repository($repository) {
  // Delete operations.
  $operations = versioncontrol_get_operations(array('repo_ids' => array($repository['repo_id'])));
  foreach ($operations as $operation) {
    versioncontrol_delete_operation($operation);
  }
  unset($operations); // conserve memory, this might get quite large

  // Delete labels.
  db_query('DELETE FROM {versioncontrol_labels}
            WHERE repo_id = %d', $repository['repo_id']);

  // Delete item revisions and related source item entries.
  $result = db_query('SELECT item_revision_id
                      FROM {versioncontrol_item_revisions}
                      WHERE repo_id = %d', $repository['repo_id']);
  $item_ids = array();
  $placeholders = array();

  while ($item_revision = db_fetch_object($result)) {
    $item_ids[] = $item_revision->item_revision_id;
    $placeholders[] = '%d';
  }
  if (!empty($item_ids)) {
    $placeholders = '('. implode(',', $placeholders) .')';

    db_query('DELETE FROM {versioncontrol_source_items}
              WHERE item_revision_id IN '. $placeholders, $item_ids);
    db_query('DELETE FROM {versioncontrol_source_items}
              WHERE source_item_revision_id IN '. $placeholders, $item_ids);
    db_query('DELETE FROM {versioncontrol_item_revisions}
              WHERE repo_id = %d', $repository['repo_id']);
  }
  unset($item_ids); // conserve memory, this might get quite large
  unset($placeholders); // ...likewise

  // Delete accounts.
  $accounts = VersioncontrolAccount::getAccounts(
    array('repo_ids' => array($repository['repo_id'])), TRUE
  );
  foreach ($accounts as $uid => $usernames_by_repository) {
    foreach ($usernames_by_repository as $repo_id => $username) {
      versioncontrol_delete_account($repository, $uid, $username);
    }
  }

  // Announce deletion of the repository before anything has happened.
  module_invoke_all('versioncontrol_repository', 'delete', $repository);

  $vcs = $repository['vcs'];

  // Provide an opportunity for the backend to delete its own stuff.
  if (versioncontrol_backend_implements($vcs, 'repository')) {
    _versioncontrol_call_backend($vcs, 'repository', array('delete', $repository));
  }

  // Auto-delete repository info from $repository['[xxx]_specific'] from the database.
  $backends = versioncontrol_get_backends();
  if (isset($backends[$vcs])) { // not the case when called from uninstall
    $is_autoadd = in_array(VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES,
                           $backends[$vcs]['flags']);
  }
  if ($is_autoadd) {
    $table_name = 'versioncontrol_'. $vcs .'_repositories';
    _versioncontrol_db_delete_additions($table_name, 'repo_id', $repository['repo_id']);
  }

  // Phew, everything's cleaned up. Finally, delete the repository.
  db_query('DELETE FROM {versioncontrol_repositories} WHERE repo_id = %d',
           $repository['repo_id']);
  db_query('DELETE FROM {versioncontrol_repository_urls} WHERE repo_id = %d',
           $repository['repo_id']);
  db_query('DELETE FROM {versioncontrol_repository_metadata} WHERE repo_id = %d',
           $repository['repo_id']);

  watchdog('special',
    'Version Control API: deleted repository @repository',
    array('@repository' => $repository['name']),
    WATCHDOG_NOTICE, l('view', 'admin/project/versioncontrol-repositories')
  );
}


/**
 * Export a repository's authenticated accounts to the version control system's
 * password file format.
 *
 * @param $repository
 *   The repository array of the repository whose accounts should be exported.
 *
 * @return
 *   The plaintext result data which could be written into the password file
 *   as is.
 */
function versioncontrol_export_accounts($repository) {
  $accounts = VersioncontrolAccount::getAccounts(array(
    'repo_ids' => array($repository['repo_id']),
  ));
  return _versioncontrol_call_backend($repository['vcs'], 'export_accounts',
                                      array($repository, $accounts));
}


/**
 * Generate and execute an INSERT query for the given table based on key names,
 * values and types of the given array elements. This function basically
 * accomplishes the insertion part of Version Control API's 'autoadd' feature.
 */
function _versioncontrol_db_insert_additions($table_name, $elements) {
  $keys = array();
  $params = array();
  $types = array();

  foreach ($elements as $key => $value) {
    $keys[] = $key;
    $params[] = is_numeric($value) ? $value : serialize($value);
    $types[] = is_numeric($value) ? '%d' : "'%s'";
  }

  db_query(
    'INSERT INTO {'. $table_name .'} ('. implode(', ', $keys) .')
     VALUES ('. implode(', ', $types) .')', $params
  );
}

/**
 * Generate and execute an UPDATE query for the given table based on key names,
 * values and types of the given array elements. This function basically
 * accomplishes the update part of Version Control API's 'autoadd' feature.
 * In order to avoid unnecessary complexity, the primary key may not consist
 * of multiple columns and has to be a numeric value.
 */
function _versioncontrol_db_update_additions($table_name, $primary_key_name, $elements) {
  $set_statements = array();
  $params = array();

  foreach ($elements as $key => $value) {
    if ($key == $primary_key_name) {
      continue;
    }
    $type = is_numeric($value) ? '%d' : "'%s'";
    $set_statements[] = $key .' = '. $type;
    $params[] = is_numeric($value) ? $value : serialize($value);
  }
  $params[] = $elements[$primary_key_name];

  if (empty($set_statements)) {
    return; // no use updating the database if no values are assigned.
  }

  db_query(
    'UPDATE {'. $table_name .'}
     SET '. implode(', ', $set_statements) .'
     WHERE '. $primary_key_name .' = %d', $params
  );
}

/**
 * Generate and execute a DELETE query for the given table
 * based on name and value of the primary key.
 * In order to avoid unnecessary complexity, the primary key may not consist
 * of multiple columns and has to be a numeric value.
 */
function _versioncontrol_db_delete_additions($table_name, $primary_key_name, $primary_key) {
  db_query('DELETE FROM {'. $table_name .'}
            WHERE '. $primary_key_name .' = %d', $primary_key);
}
