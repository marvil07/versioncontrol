<?php

require_once drupal_get_path('module', 'versioncontrol') . '/includes/VersioncontrolBackend.php';

class VersioncontrolFakeBackend extends VersioncontrolBackend {

  public function __construct() {
    $this->name = 'FakeVCS';
    $this->description = t('FakeVCS is a version control system that is specifically capable in doing everything that any other version control system might ever do.');
    // Our example VCS supports all possible capabilities.
    $this->capabilities = array(
        // Able to cancel commits if the committer lacks permissions
        // to commit to specific paths and/or branches.
        VERSIONCONTROL_CAPABILITY_COMMIT_RESTRICTIONS,

        // Able to cancel branch or tag assignments if the committer lacks
        // permissions to create/update/delete those.
        VERSIONCONTROL_CAPABILITY_BRANCH_TAG_RESTRICTIONS,

        // Able to retrieve a file or its revision number based on a global
        // revision identifier.
        VERSIONCONTROL_CAPABILITY_ATOMIC_COMMITS,

        // The version control system assigns revisions not only to files
        // but also to directories.
        VERSIONCONTROL_CAPABILITY_DIRECTORY_REVISIONS,
    );
    $this->classes = array(
      'repo'      => 'VersioncontrolFakeRepository',
      'account'   => 'VersioncontrolFakeAccount',
      'operation' => 'VersioncontrolFakeOperation',
      'item'      => 'VersioncontrolFakeItem',
    );
  }

}

class VersioncontrolFakeRepository extends VersioncontrolRepository {

  public function formatRevisionIdentifier($revision, $format = 'full') {
    switch ($format) {
    case 'full':
    case 'short':
    default:
      // Suppose we're a distributed VCS backend and have an SHA-1 hash:
      // $revision == '30581e4ec3347d1294ec05a91eec1a8588e5993c'
      // Let's return only the first 12 characters of the revision identifier,
      // like Mercurial (including hgweb) does by default.
      return substr($revision, 0, 12);

      // SVN also wants to format revisions in a slightly custom way:
      return 'r'. $revision;
    }
  }

}

class VersioncontrolFakeAccount extends VersioncontrolAccount {

  /**
   * Overwrite
   */
  public function usernameSuggestion($user) {
    // For distributed version control systems, the user's email address
    // might be a more appropriate username than the actual nick - it
    // guarantees unique identification (= uid association for
    // operations), even though it might need to be replaced by or
    // amended with the full name on page display.
    return $user->mail;
  }

  /**
   * Overwrite
   */
  function isUsernameValid(&$username) {
    // Continuing the email-style username example from above.
    return valid_email_address($username);
  }

}

class VersioncontrolFakeOperation extends VersioncontrolOperation {
}

class VersioncontrolFakeItem
extends VersioncontrolItem
implements VersioncontrolItemParallelItems, VersioncontrolItemDirectoryContents, VersioncontrolItemExportFile, VersioncontrolItemExportDirectory, VersioncontrolItemGetFileAnnotation {

  /**
   * Method for VersioncontrolItemParallelItems interface.
   * TODO: convert to OOP
   */
  function _getParallelItems($label_type_filter = NULL) {
    // a fake repo for our fake item in this example
    $_repo = array(
      'name' => 'A fake repo',
      'vcs'  => 'fakevcs',
      'root' => '/path/to/the/repo',
      'authorization_method' => 'versioncontrol_admin',
    );
    $repo = new VersioncontrolFakeRepository(1, $_repo, FALSE);

    // How CVS would probably do it, if for example
    // $item['path'] == '/contributions/modules/versioncontrol/versioncontrol.module':
    return array(
      array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/contributions/modules/versioncontrol/versioncontrol.module',
          '1.23',
          NULL,
          $repo
        ),
        'selected_label' => new VersioncontrolBranch('HEAD', NULL),
      ),
      array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/contributions/modules/versioncontrol/versioncontrol.module',
          '1.23.2.42',
          NULL,
          $repo
        ),
        'selected_label' => new VersioncontrolBranch('DRUPAL-5--1', NULL),
      ),
      array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/contributions/modules/versioncontrol/versioncontrol.module',
          '1.23.2.42',
          NULL,
          $repo
        ),
        'selected_label' => new VersioncontrolBranch('DRUPAL-5--1-2', NULL),
      ),
    );

    // How SVN could also do it, if for example
    // $item['path'] == '/trunk/contributions/modules/versioncontrol':
    return array(
      array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_DIRECTORY,
          '/trunk/contributions/modules/versioncontrol',
          '23',
          NULL,
          $repo
        ),
        'selected_label' => new VersioncontrolBranch('trunk', NULL),
      ),
      array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_DIRECTORY,
          '/branches/5.x-1.x/contributions/modules/versioncontrol',
          '42',
          NULL,
          $repo
        ),
        'selected_label' => new VersioncontrolBranch('5.x-1.x', NULL),
      ),
    );

  }

  /**
   * Method for VersioncontrolItemDirectoryContents interface.
   */
  public function _getDirectoryContents($recursive = FALSE) {
    // a fake repo for our fake item in this example
    $_repo = array(
      'name' => 'A fake repo',
      'vcs'  => 'fakevcs',
      'root' => '/path/to/the/repo',
      'authorization_method' => 'versioncontrol_admin',
    );
    $repo = new VersioncontrolFakeRepository(1, $_repo, FALSE);

    // Assuming this object looks like this:
    $directory_item = new VersioncontrolFakeItem(
      VERSIONCONTROL_ITEM_DIRECTORY, '/trunk/contributions/modules/versioncontrol', '777', NULL, $repo
    );
    // $repository is some SVN repository and $recursive == FALSE. SVN without
    // branch/tag emulation can return NULL for all the selected labels.

    return array(
      '/trunk/contributions/modules/versioncontrol' => array(
        'item' => new VersioncontrolFakeItem( // == rtrim($path, '/')
          VERSIONCONTROL_ITEM_DIRECTORY,
          '/trunk/contributions/modules/versioncontrol',
          '502',
          NULL,
          $repo
        ),
        'selected_label' => NULL,
      ),
      '/trunk/contributions/modules/versioncontrol/versioncontrol.module' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/trunk/contributions/modules/versioncontrol/versioncontrol.module',
          '502',
          NULL,
          $repo
        ),
        'selected_label' => NULL,
      ),
      '/trunk/contributions/modules/versioncontrol/versioncontrol.info' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/trunk/contributions/modules/versioncontrol/versioncontrol.info',
          '404',
          NULL,
          $repo
        ),
        'selected_label' => NULL,
      ),
      '/trunk/contributions/modules/versioncontrol/versioncontrol.install' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/trunk/contributions/modules/versioncontrol/versioncontrol.install',
          '404',
          NULL,
          $repo
        ),
        'selected_label' => NULL,
      ),
      '/trunk/contributions/modules/versioncontrol/README.txt' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/trunk/contributions/modules/versioncontrol/README.txt',
          '404',
          NULL,
          $repo
        ),
        'selected_label' => NULL,
      ),
      '/trunk/contributions/modules/versioncontrol/versioncontrol_fakevcs' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_DIRECTORY,
          '/trunk/contributions/modules/versioncontrol/versioncontrol_fakevcs.txt',
          '497',
          NULL,
          $repo
        ),
        'selected_label' => NULL,
      ),
    );

    // Or the same thing in CVS - note that 'revision' is an empty string
    // for directories, as CVS doesn't support versioned directories.
    $directory_item = new VersioncontrolFakeItem(
      VERSIONCONTROL_ITEM_DIRECTORY, '/contributions/modules/versioncontrol', '', NULL, $repo
    );
    // $repository is some CVS repository and $recursive == TRUE.
    // For the purpose of example, we use the same label as the directory item.
    // (Real-life usage might or might not require more correctness checks.)
    $selected_label = $this->getSelectedLabel();

    return array(
      '/contributions/modules/versioncontrol' => array(
        'item' => new VersioncontrolFakeItem( // == rtrim($path, '/')
          VERSIONCONTROL_ITEM_DIRECTORY,
          '/contributions/modules/versioncontrol',
          '',
          NULL,
          $repo
        ),
        'selected_label' => $selected_label,
      ),
      '/contributions/modules/versioncontrol/versioncontrol.module' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/contributions/modules/versioncontrol/versioncontrol.module',
          '1.19',
          NULL,
          $repo
        ),
        'selected_label' => $selected_label,
      ),
      '/contributions/modules/versioncontrol/versioncontrol.info' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/contributions/modules/versioncontrol/versioncontrol.info',
          '1.1',
          NULL,
          $repo
        ),
        'selected_label' => $selected_label,
      ),
      '/contributions/modules/versioncontrol/versioncontrol.install' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/contributions/modules/versioncontrol/versioncontrol.install',
          '1.5',
          NULL,
          $repo
        ),
        'selected_label' => $selected_label,
      ),
      '/contributions/modules/versioncontrol/README.txt' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/contributions/modules/versioncontrol/README.txt',
          '1.1',
          NULL,
          $repo
        ),
        'selected_label' => $selected_label,
      ),
      '/contributions/modules/versioncontrol/fakevcs_backend' => array(
        'item' => new VersioncontrolFakeItem( // == rtrim($path, '/')
          VERSIONCONTROL_ITEM_DIRECTORY,
          '/contributions/modules/versioncontrol/fakevcs_backend',
          '',
          NULL,
          $repo
        ),
        'selected_label' => $selected_label,
      ),
      '/contributions/modules/versioncontrol/fakevcs_backend/fakevcs_backend.module' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE_DELETED, // only for non-atomic-commit VCSs (= CVS),
          '/contributions/modules/versioncontrol/fakevcs_backend/fakevcs_backend.module',
          '1.11',
          NULL,
          $repo
        ),
        'selected_label' => $selected_label,
      ),
      '/contributions/modules/versioncontrol/versioncontrol_fakevcs' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_DIRECTORY,
          '/contributions/modules/versioncontrol/versioncontrol_fakevcs',
          '',
          NULL,
          $repo
        ),
        'selected_label' => $selected_label,
      ),
      '/contributions/modules/versioncontrol/versioncontrol_fakevcs/versioncontrol_fakevcs.module' => array(
        'item' => new VersioncontrolFakeItem(
          VERSIONCONTROL_ITEM_FILE,
          '/contributions/modules/versioncontrol/versioncontrol_fakevcs/versioncontrol_fakevcs.module',
          '1.2',
          NULL,
          $repo
        ),
        'selected_label' => $selected_label,
      ),
    );

  }

  /**
   * Method for VersioncontrolItemExportFile interface.
   */
  public function _exportFile($destination) {
    exec('fakevcs cat '. $this->repository->root . $this->path .' > '. $destination,
      $output, $return_code);

    if ($return_code != 0) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Method for VersioncontrolItemExportDirectory interface.
   */
  public function _exportDirectory($destination_dirpath) {
    exec('fakevcs export '. $this->repository->root . $this->path
      .' '. $destination_dirpath, $output, $return_code);

    if ($return_code != 0) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Method for VersioncontrolItemGetFileAnnotation interface.
   */
  function _getFileAnnotation() {
    // In case the file is marked as text file:
    return array( // using Drupal COPYRIGHT.txt file as example
      1 => array(
        'username' => 'dries',
        'line'     => '// $Id$',
      ),
      2 => array(
        'username' => 'dries',
        'line'     => '',
      ),
      3 => array(
        'username' => 'dries',
        'line'     => 'All Drupal code is Copyright 2001 - 2008 by the original authors.',
      ),
    );
  }

}
