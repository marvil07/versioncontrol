<?php
// $Id$
/**
 * @file
 * Version Control API - An interface to version control systems
 * whose functionality is provided by pluggable back-end modules.
 *
 * This file contains the administrative user interface
 * for accounts and repositories.
 *
 * Copyright 2006, 2007 Derek Wright ("dww" , http://drupal.org/user/46549)
 * Copyright 2007 by Jakob Petsovits ("jpetso", http://drupal.org/user/56020)
 */


/**
 * Form callback for 'admin/project/versioncontrol-accounts':
 * A list of accounts with filtering possibilities.
 */
function versioncontrol_admin_account_list() {
  $form = array();
  $users = array();
  $accounts = versioncontrol_get_accounts();

  // Retrieve all repositories that users are listed in.
  $repo_ids = array();
  foreach ($accounts as $uid => $usernames_by_repository) {
    $user = user_load(array('uid' => $uid));
    if (!$user) {
      continue;
    }
    $users[$uid] = $user;
    foreach ($usernames_by_repository as $repo_id => $username) {
      $repo_ids[] = $repo_id;
    }
  }

  if (empty($users)) {
    $form['empty'] = array(
      '#value' => '<p>'. t('No Drupal users are currently associated to VCS user accounts. Please do a mass import of VCS users or add/approve them one by one.') .'</p>'
    );
    return $form;
  }

  $repositories = versioncontrol_get_repositories(array('repo_ids' => $repo_ids));

  // Retrieve total, first and last commits of each user.
  $result = db_query("SELECT uid, COUNT(commit_id) AS number_commits,
                       MIN(date) AS first_commit_date, MAX(date) AS last_commit_date,
                       MIN(commit_id) AS first_commit_id, MAX(commit_id) AS last_commit_id
                      FROM {versioncontrol_commits}
                      GROUP BY uid");

  $number_commits = array();
  $first_commits = array();
  $last_commits = array();

  while ($statistics = db_fetch_object($result)) {
    $number_commits[$statistics->uid] = $statistics->number_commits;
    $first_commits[$statistics->uid] = t('!time ago', array(
      '!time' => format_interval(time() - $statistics->first_commit_date, 1))
    );
    $last_commits[$statistics->uid] = t('!time ago', array(
      '!time' => format_interval(time() - $statistics->last_commit_date, 1))
    );
    if (module_exists('commitlog')) {
      $number_commits[$statistics->uid] =
        l($number_commits[$statistics->uid], 'user/'. $statistics->uid .'/track/code');
      $first_commits[$statistics->uid] =
        theme('commitlog_commit_id', $statistics->first_commit_id, $first_commits[$statistics->uid]);
      $last_commits[$statistics->uid] =
        theme('commitlog_commit_id', $statistics->last_commit_id, $last_commits[$statistics->uid]);
    }
  }

  // Construct the user account table.
  $header = array(t('User'), t('Accounts'), t('Commits'), t('First commit'), t('Last commit'));
  $rows = array();
  foreach ($accounts as $uid => $usernames_by_repository) {
    $user = $users[$uid];

    // Present a list of all VCS usernames and the repository where they're in.
    $repo_usernames = array();
    foreach ($usernames_by_repository as $repo_id => $username) {
      if (!isset($repositories[$repo_id])) { // happens if the backend isn't loaded
        continue;
      }
      if (module_exists('commitlog')) {
        $username = theme('commitlog_account_username',
                          $uid, $username, $repositories[$repo_id], FALSE);
        $repo_name = theme('commitlog_repository', $repositories[$repo_id]);
      }
      else {
        $username = check_plain($username);
        $repo_name = check_plain($repositories[$repo_id]['name']);
      }

      $repo_usernames[] = t('!username in !repository (!edit)', array(
        '!username' => $username,
        '!repository' => $repo_name,
        '!edit' => l(t('edit'), 'user/'. $uid .'/edit/versioncontrol/'. $repo_id),
      ));
    }
    $vcs_usernames = empty($repo_usernames)
                     ? t('VCS backend is currently disabled')
                     : theme('item_list', $repo_usernames);

    $rows[] = array(
      theme('username', $user), $vcs_usernames,
      isset($number_commits[$uid]) ? $number_commits[$uid] : 0,
      isset($first_commits[$uid]) ? $first_commits[$uid] : t('n/a'),
      isset($last_commits[$uid]) ? $last_commits[$uid] : t('n/a'),
    );
  }

  // The finished user account list.
  $form['list'] = array(
    '#value' => theme('table', $header, $rows)
  );
  return $form;
}


/**
 * Form callback for "user/$uid/edit/versioncontrol/$repository['repo_id']",
 * "user/$uid/edit/versioncontrol/$repository['repo_id']/register" and
 * (in a certain case) "user/$uid/edit/versioncontrol/register":
 * Provide a form to edit or register a VCS user account.
 *
 * @param $uid
 *   The Drupal user id of the user whose account is being edited.
 * @param $username
 *   The VCS specific username of that user. If this is an empty string,
 *   it means that a user account for this repository does not yet exist
 *   and should be created.
 * @param $repository
 *   The repository of the added/edited account.
 */
function versioncontrol_user_account_edit_form($uid, $username, $repository) {
  $form = array();
  $backends = versioncontrol_get_backends();
  $vcs_name = $backends[$repository['vcs']]['name'];

  $form['#id'] = 'vcs-account-form';
  $form['#vcs'] = $repository['vcs'];
  $form['#validate'] = array('versioncontrol_user_account_edit_form_validate' => array());

  $form['title'] = array(
    '#value' => t('Create user account in @repository',
                  array('@repository' => $repository['name'])),
    '#prefix' => empty($username) ? '<h4>' : '<!--',
    '#suffix' => empty($username) ? '</h4>' : '-->',
    '#weight' => -10,
  );

  $form['account'] = array(
    '#type' => 'fieldset',
    '#title' => t('@vcs account settings', array('@vcs' => $vcs_name)),
    '#weight' => 0,
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );

  $form['account']['uid'] = array(
    '#type' => 'value',
    '#value' => $uid,
  );
  $form['account']['original_username'] = array(
    '#type' => 'value',
    '#value' => $username,
  );
  $form['account']['repo_id'] = array(
    '#type' => 'value',
    '#value' => $repository['repo_id'],
  );
  $form['account']['vcs_name'] = array(
    '#type' => 'value',
    '#value' => $vcs_name,
  );

  if (empty($username) || user_access('administer version control systems')) {
    // When someone else than the user goes to this page (the admin, that is),
    // the page title is incorrect and would say 'User', which is bad.
    $user = user_load(array('uid' => $uid));
    drupal_set_title(check_plain($user->name));

    if (empty($username)) { // the account creation version
      $description = t('Your @vcs username. This field can only be edited by administrators and is used to link your @vcs messages to your user account.', array('@vcs' => $vcs_name));
    }
    else { // the admin version
      $description = t('The @vcs username associated with this account. This field is used to link commit messages to user accounts.', array('@vcs' => $vcs_name));
    }

    $form['account']['account_name'] = array(
      '#type' => 'textfield',
      '#title' => t('@vcs username', array('@vcs' => $vcs_name)),
      '#description' => $description,
      '#default_value' => $username,
      '#weight' => 0,
      '#size' => 30,
      '#maxlength' => 64,
    );
  }
  else {
    $form['account']['account_name'] = array(
      '#type' => 'item',
      '#title' => t('@vcs username', array('@vcs' => $vcs_name)),
      '#description' => t('The @vcs username associated with this account.',
                          array('@vcs' => $vcs_name)),
      '#value' => $username,
      '#weight' => 0,
    );
  }

  $form['account']['submit'] = array(
    '#type' => 'submit',
    '#value' => empty($username)
                ? t('Create user account')
                : ('Save account settings'),
    '#weight' => 100,
  );
  return $form;
}

/**
 * Validate the edit/register user account form submission before it is submitted.
 */
function versioncontrol_user_account_edit_form_validate($form_id, $form_values) {
  if (!isset($form_values['account_name'])) {
    return;
  }
  $uid = $form_values['uid'];
  $username = trim($form_values['account_name']);
  $repository = versioncontrol_get_repository($form_values['repo_id']);
  $vcs_name = $form_values['vcs_name'];

  if (!isset($repository)) { // admin deletes repo while user fills in form
    form_set_error('account',
      t('The repository has been deleted.', array('@vcs' => $vcs_name))
    );
    return;
  }

  if (empty($username)) {
    form_set_error('account_name',
      t('The @vcs username may not be empty.', array('@vcs' => $vcs_name))
    );
  }
  else {
    // Check for duplicates.
    $existing_uid = versioncontrol_get_account_uid_for_username($username, $repository, TRUE);
    if ($existing_uid && $uid != $existing_uid) {
      if ($existing_user = user_load(array('uid' => $existing_uid))) {
        $existing_username = theme('username', $existing_user);
      }
      else {
        $existing_username = t('user #!id', array('!id' => $existing_uid));
      }
      form_set_error('account_name',
        t('The specified @vcs username is already in use by !existing-user.',
          array('@vcs' => $vcs_name, '!existing-user' => $existing_username))
      );
    }
  }
}

/**
 * Add or update the user account when the edit/register form is submitted.
 */
function versioncontrol_user_account_edit_form_submit($form_id, $form_values) {
  // Reconstruct the user data from the $form_values that were passed.
  $uid = $form_values['uid'];
  $username = trim($form_values['account_name']);
  $repository = versioncontrol_get_repository($form_values['repo_id']);
  $vcs_name = $form_values['vcs_name'];
  $vcs_specific = NULL;

  $additional_data = module_invoke_all(
    'versioncontrol_extract_account_data', $form_values
  );

  if (empty($form_values['original_username'])) {
    versioncontrol_insert_account($uid, $username, $repository, $additional_data);
    $message = drupal_set_message(t(
      'The @vcs account %username has been created.',
      array('@vcs' => $vcs_name, '%username' => $username)
    ));
  }
  else {
    versioncontrol_update_account($uid, $username, $repository, $additional_data);
    $message = drupal_set_message(t(
      'The @vcs account %username has been updated successfully.',
      array('@vcs' => $vcs_name, '%username' => $username)
    ));
  }
  if (user_access('administer version control systems')) {
    return 'admin/project/versioncontrol-accounts';
  }
  else {
    return 'user/'. $uid .'/edit/versioncontrol/'. $repository['repo_id'];
  }
}


/**
 * Form callback for "user/$uid/edit/versioncontrol/register":
 * Provide an indirection that leads to an account registration form.
 *
 * @param $uid
 *   The Drupal user id of the user who wants to register a new account.
 * @param $accounts
 *   All existing accounts of this user. You'll want to pass the result of
 *   <code>versioncontrol_get_accounts(array('uids' => array($uid)))</code>
 *   as this argument.
 * @param $repositories
 *   All repositories where the user does not yet have an account.
 */
function versioncontrol_user_account_register_form($uid, $accounts, $repositories) {
  $form = array();

  if (user_access('administer version control systems')) {
    // When someone else than the user goes to this page (the admin, that is),
    // the page title is incorrect and would say 'User', which is bad.
    $user = user_load(array('uid' => $uid));
    drupal_set_title(check_plain($user->name));
  }

  $form['#id'] = 'vcs-account-indirection-form';

  // Construct the '#options' array.
  $repository_names = array();
  foreach ($repositories as $repo_id => $repository) {
    if (!isset($first_repo_id)) {
      $first_repo_id = $repo_id;
    }
    $repository_names[$repo_id] = $repository['name'];
  }

  $form['create'] = array(
    '#type' => 'fieldset',
  );
  $form['create']['uid'] = array(
    '#type' => 'value',
    '#value' => $uid,
  );
  $form['create']['repo_id'] = array(
    '#type' => 'select',
    '#title' => t('For which repository'),
    '#options' => $repository_names,
    '#default_value' => $first_repo_id,
  );
  $form['create']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Create user account'),
    '#weight' => 100,
  );

  return $form;
}

/**
 * Submit handler for the indirection form.
 * Surprisingly, all it does is redirect to the appropriate registration form.
 */
function versioncontrol_user_account_register_form_submit($form_id, $form_values) {
  return 'user/'. $form_values['uid'] .'/edit/versioncontrol/'.
         $form_values['repo_id'] .'/register';
}


/**
 * Form callback for 'admin/project/versioncontrol-repositories':
 * A simple list of repositories, sorted by version control system.
 */
function versioncontrol_admin_repository_list() {
  $form = array();
  $backends = versioncontrol_get_backends();
  $repositories = versioncontrol_get_repositories();

  if (empty($repositories)) {
    $form['empty'] = array(
      '#value' => '<p>'. t('No repositories are currently known. Please add one or more repositories in order to be able to use version control features on the site.') .'</p>'
    );
    return $form;
  }

  // The header may be modified separately for each VCS,
  // so this is only a template and still missing the Actions column.
  $header_template = array(t('Name'), t('Root'));

  // Sort repositories by backend.
  $repositories_by_backend = array();
  foreach ($repositories as $repo_id => $repository) {
    if (!isset($repositories_by_backend[$repository['vcs']])) {
      $repositories_by_backend[$repository['vcs']] = array();
    }
    $repositories_by_backend[$repository['vcs']][$repo_id] = $repository;
  }

  // Construct the form elements for each VCS separately.
  foreach ($repositories_by_backend as $vcs => $vcs_repositories) {
    $header = $header_template;
    $form[$vcs] = array(
      '#value' => '<h4>'. $backends[$vcs]['name'] .' repositories</h4>',
    );

    // Add the standard items of each repository - name and root - to the rows.
    $rows_by_repo_id = array();
    foreach ($vcs_repositories as $repo_id => $repository) {
      $rows_by_repo_id[$repo_id] = array(
        check_plain($repository['name']), check_plain($repository['root']),
      );
    }
    // Provide a possibility for backends and other modules to modify the list.
    foreach (module_implements('versioncontrol_alter_repository_list') as $module) {
      $function = $module .'_versioncontrol_alter_repository_list';
      $function($vcs, $vcs_repositories, $header, $rows_by_repo_id);
    }
    // Add the Actions column as final column, after all the other ones
    $header[] = array('data' => t('Actions'), 'colspan' => 2);
    foreach ($rows_by_repo_id as $repo_id => $row) {
      $links = array(
        array(
          'title' => t('Edit'),
          'href' => 'admin/project/versioncontrol-repositories/edit/'. $repo_id,
        ),
        array(
          'title' => t('Delete'),
          'href' => 'admin/project/versioncontrol-repositories/delete/'. $repo_id,
        ),
      );
      $rows_by_repo_id[$repo_id][] = theme('links', $links);
    }
    // We don't want the repository ids in the final $rows array.
    $rows = array_values($rows_by_repo_id);

    // The finished table for the currently processed VCS.
    $form[$vcs]['list'] = array(
      '#value' => theme('table', $header, $rows)
    );
  }
  return $form;
}

/**
 * Form callback for 'admin/project/versioncontrol-repositories/edit/$repo_id'
 * and 'admin/project/versioncontrol-repositories/add-$vcs':
 * Provide a form to edit or add a repository.
 *
 * @param $repo_id
 *   The repository id of the repository that is to be edited,
 *   or 0 if the repository doesn't exist yet and should be added.
 * @param $vcs
 *   If $repo_id is 0, this should be the unique identification string
 *   of the backend for which the repository should be added.
 *   Otherwise, this needs to be NULL.
 */
function versioncontrol_admin_repository_edit($repo_id, $vcs = NULL) {
  $backends = versioncontrol_get_backends();

  if (!$repo_id) {
    if (!isset($backends[$vcs])) {
      drupal_goto('admin/project/versioncontrol-repositories');
      return array();
    }
  }
  else {
    $repository = versioncontrol_get_repository($repo_id);
    if (!isset($repository)) {
      drupal_goto('admin/project/versioncontrol-repositories');
      return array();
    }
  }
  $vcs = isset($repository) ? $repository['vcs'] : $vcs;

  $form = array();
  $form['#id'] = 'vcs-repository-form';
  $form['#validate'] = array('versioncontrol_admin_repository_edit_validate' => array());
  $form['#vcs'] = $vcs;
  $form['#repository'] = $repository;

  $form['repository_information'] = array(
    '#type' => 'fieldset',
    '#title' => t('Repository information'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
    '#weight' => 0,
  );
  $form['repository_information']['repo_id'] = array(
    '#type' => 'value',
    '#value' => isset($repository) ? $repository['repo_id'] : 0,
  );
  $form['repository_information']['original_name'] = array(
    '#type' => 'value',
    '#value' => isset($repository) ? check_plain($repository['name']) : 0,
  );
  $form['repository_information']['vcs'] = array(
    '#type' => 'value',
    '#value' => $vcs,
  );
  $form['repository_information']['repo_name'] = array(
    '#type' => 'textfield',
    '#title' => t('Repository name'),
    '#description' => t('A label for the repository that will be used in all user visible messages.'),
    '#default_value' => isset($repository) ? check_plain($repository['name']) : '',
    '#weight' => 0,
    '#size' => 40,
    '#maxlength' => 255,
  );
  $form['repository_information']['root'] = array(
    '#type' => 'textfield',
    '#title' => t('Repository root'),
    '#default_value' => isset($repository) ? $repository['root'] : '',
    '#weight' => 5,
    '#size' => 60,
    '#maxlength' => 255,
    '#description' => t('The location of the repository\'s root directory.'),
  );

  if (isset($repository)) {
    $repo_urls = _versioncontrol_get_repository_urls($repository);
  }

  // TODO: abstract out repository URLs into separate backends
  $form['repository_urls'] = array(
    '#type' => 'fieldset',
    '#title' => t('Repository browser URLs'),
    '#description' =>  t('These URLs will be used to add links to item and commit displays such as the commit log.'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
    '#weight' => 10,
  );
  $form['repository_urls']['commit_view'] = array(
    '#type' => 'textfield',
    '#title' => t('Commit view URL'),
    '#default_value' => isset($repo_urls) ? $repo_urls['commit_view'] : '',
    '#size' => 40,
    '#maxlength' => 255,
    '#description' => t('Enter URL to the commit view web site. Use the %revision variable in the right place to represent the global revision identifier of the commit.'),
  );
  $form['repository_urls']['file_view'] = array(
    '#type' => 'textfield',
    '#title' => t('File view URL'),
    '#default_value' => isset($repo_urls) ? $repo_urls['file_view'] : '',
    '#size' => 40,
    '#maxlength' => 255,
    '#description' => t('Enter URL to the display view of a file in the repository. Use the %path, %revision and %branch variables in the right place to represent the path, revision and branch of the file.'),
  );
  $form['repository_urls']['directory_view'] = array(
    '#type' => 'textfield',
    '#title' => t('Directory view URL'),
    '#default_value' => isset($repo_urls) ? $repo_urls['directory_view'] : '',
    '#size' => 40,
    '#maxlength' => 255,
    '#description' => t('Enter URL to the file listing of a directory in the repository. Use the %path, %revision and %branch variables in the right place to represent the path, revision and branch of the file.'),
  );
  $form['repository_urls']['diff'] = array(
    '#type' => 'textfield',
    '#title' => t('Diff URL'),
    '#default_value' => isset($repo_urls) ? $repo_urls['diff'] : '',
    '#size' => 40,
    '#maxlength' => 255,
    '#description' => t('Enter URL to the diff web site. Use the %path, %new-revision, %old-revision and %branch variables in the right place to represent the file path, old revision and new revision. If the tool supports diffs between wholly different files, you can also use %old-path for the path of the old version of the file.'),
  );
  $form['repository_urls']['tracker'] = array('#type' => 'textfield',
    '#title' => t('Issue tracker URL'),
    '#default_value' => isset($repo_urls) ? $repo_urls['tracker'] : '',
    '#size' => 40,
    '#maxlength' => 255,
    '#description' => t('Enter URL to link to issues in log messages. Use the %d variable in the right place to represent the issue/case/bug id.'),
  );

  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save repository'),
    '#weight' => 100,
  );

  return $form;
}

/**
 * Validate the add/edit repository form before it is submitted.
 */
function versioncontrol_admin_repository_edit_validate($form_id, $form_values) {
  if ($form_values['repo_id'] != 0 && $form_values['repo_name'] == $form_values['original_name']) {
    return;
  }
  $same_repositories = versioncontrol_get_repositories(array(
    'names' => array($form_values['repo_name']),
  ));
  if (!empty($same_repositories)) {
    form_set_error('repo_name',
      t('The repository name %repo-name is already in use, please assign a different one.',
        array('%repo-name' => $form_values['repo_name']))
    );
  }
}

/**
 * Add or update the repository when the add/edit form is submitted.
 */
function versioncontrol_admin_repository_edit_submit($form_id, $form_values) {
  // Reconstruct the repository from the $form_values that were passed.
  $repository = array();
  $vcs_specific = array();
  $repository_urls = array();

  foreach ($form_values as $key => $value) {
    if (in_array($key, array('repo_id', 'vcs', 'root'))) {
      $repository[$key] = $form_values[$key];
    }
    else if ($key == 'repo_name') {
      $repository['name'] = $form_values[$key];
    }
    else if (in_array($key, array('commit_view', 'file_view', 'directory_view', 'diff', 'tracker'))) {
      $repository_urls[$key] = $form_values[$key];
    }
  }
  $repository['url_backend'] = 'versioncontrol_default_urls'; // hardcoded for now

  $additional_data = module_invoke_all(
    'versioncontrol_extract_repository_data', $form_values
  );
  $repository = array_merge($additional_data, $repository);

  if ($repository['repo_id'] > 0) {
    versioncontrol_update_repository($repository, $repository_urls);
  }
  else {
    versioncontrol_insert_repository($repository, $repository_urls);
  }
  return 'admin/project/versioncontrol-repositories';
}


/**
 * Form callback for 'admin/project/versioncontrol-repositories/delete/$repo_id':
 * Provide a form to confirm deletion of a repository.
 */
function versioncontrol_admin_repository_delete_confirm($repo_id) {
  $repository = versioncontrol_get_repository($repo_id);

  if (!isset($repository)) {
    drupal_goto('admin/project/versioncontrol-repositories');
    return array();
  }

  $form = array();
  $form['repo_id'] = array('#type' => 'value', '#value' => $repo_id);

  $form = confirm_form($form,
    t('Are you sure you want to delete %name?', array('%name' => $repository['name'])),
    $_GET['destination'] ? $_GET['destination'] : 'admin/project/versioncontrol-repositories',
    t('Mind that by deleting the repository, all associated data such as commits and account associations will be deleted as well.'),
    t('Delete'), t('Cancel')
  );
  return $form;
}

/**
 * Delete the repository when the confirmation form is submitted.
 */
function versioncontrol_admin_repository_delete_confirm_submit($form_id, $form_values) {
  $repository = versioncontrol_get_repository($form_values['repo_id']);
  versioncontrol_delete_repository($repository);
  drupal_goto('admin/project/versioncontrol-repositories');
}