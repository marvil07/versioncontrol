<?php

final class VersioncontrolRepositoryCache {
  private static $instance;
  private $backends = array();
  private $repoCache = array('repo_id' => array(), 'name' => array(), 'vcs' => array());
    /**
   * Internal state variable indicating whether or not all repositories have
   * been fetched (via VersioncontrolRepositoryCache::getInstance()->getAllRepositories()).
   * @var bool
   */
  private $allFetched = FALSE;

  private function __construct() {
    // TODO really oughtta make this better.
    $this->backends = versioncontrol_get_backends();
    $result = db_query('SELECT repo_id, name, vcs FROM {versioncontrol_repositories}');
    // Cache a skeletal, low-mem overhead list of all the repos we have.
    while ($item = db_fetch_object($result)) {
      $this->repoCache['repo_id'][$item->repo_id] = &$item;
      // $this->repoCache['name'][$item->name] = &$item;
      // $this->repoCache['vcs'][$item->vcs] = &$item;
    }

    $this->repoCache['repo_ids'] = &$this->repoCache['repo_id']; // backwards compat
  }

  /**
   * Return the singleton's instance of the VersioncontrolRepositoryCache.
   *
   * @return VersioncontrolRepositoryCache
   */
  public static function getInstance() {
    if (!self::$instance instanceof VersioncontrolRepositoryCache) {
      self::$instance = new VersioncontrolRepositoryCache();
    }
    return self::$instance;
  }

  /**
   * Convenience function for retrieving one single repository by repository id
   * from cache.
   *
   * @return
   *   A single VersioncontroRepository array.
   *   If no repository corresponds to the given repository id, NULL is returned.
   */
  public function getRepository($repo_id) {
    if (!isset($this->repoCache['repo_id'][$repo_id])) {
      // no such repo. bail out.
      return;
    }
    if (!$this->repoCache['repo_id'][$repo_id] instanceof VersioncontrolRepository) {
      $this->cacheRepository(array_shift($this->getRepositories(array('repo_id' => $repo_id))));
    }
    return $this->repoCache['repo_id'][$repo_id];
  }

  /**
   * Retrieve a set of repositories that match the given constraints.
   *
   * @static
   * @param $constraints
   *   An optional array of constraints. Possible array elements are:
   *
   *   - 'vcs': An array of strings, like array('cvs', 'svn', 'git').
   *       If given, only repositories for these backends will be returned.
   *   - 'repo_ids': An array of repository ids.
   *       If given, only the corresponding repositories will be returned.
   *   - 'names': An array of repository names, like
   *       array('Drupal CVS', 'Experimental SVN'). If given,
   *       only repositories with these repository names will be returned.
   *   - '[xxx]_specific': An array of VCS specific constraints. How this array
   *       looks like is defined by the corresponding backend module
   *       (versioncontrol_[xxx]). Other backend modules won't get to see this
   *       constraint, so in theory you can provide one of those for each backend
   *       in one single query.
   *
   * @return
   *   An array of repositories where the key of each element is the repository
   *   id. The corresponding value contains a VersioncontrolRepository object.
   *   If not a single repository matches these constraints,
   *   an empty array is returned.
   */
  public function getRepositories($constraints = array()) {
    // $backends = versioncontrol_get_backends();
    $auth_methods = versioncontrol_get_authorization_methods();

    if (isset($constraints['repo_ids'])) {
      $repo_ids = array();
      foreach ($constraints['repo_ids'] as $repo_id) {
        $repo_ids[] = (int) $repo_id;
      }
      $constraints['repo_ids'] = $repo_ids;
    }

    $constraints_serialized = serialize($constraints);
    if (isset($this->repoCache[$constraints_serialized])) {
      return $this->repoCache[$constraints_serialized];
    }

    list($and_constraints, $params) =
      _versioncontrol_construct_repository_constraints($constraints, $this->backends);

    // All the constraints have been gathered, assemble them to a WHERE clause.
    $where = empty($and_constraints) ? '' : ' WHERE '. implode(' AND ', $and_constraints);

    $result = db_query('SELECT * FROM {versioncontrol_repositories} r'. $where, $params);

    // Sort the retrieved repositories by backend.
    $repositories_by_backend = array();

    while ($repository = db_fetch_array($result)) {
      if (!isset($this->backends[$repository['vcs']])) {
        // don't include repositories for which no backend module exists
        continue;
      }

      if (!isset($repositories_by_backend[$repository['vcs']])) {
        $repositories_by_backend[$repository['vcs']] = array();
      }
      $repository[$repository['vcs'] .'_specific'] = array();
      $repositories_by_backend[$repository['vcs']][$repository['repo_id']] = $repository;
    }

    $repositories_by_backend = $this->_amend_repositories(
      $repositories_by_backend, $this->backends
    );

    // Add the fully assembled repositories to the result array.
    $result_repositories = array();
    foreach ($repositories_by_backend as $vcs => $vcs_repositories) {
      foreach ($vcs_repositories as $repository) {
        // $vcs_repository = new VersioncontrolRepository($repository['repo_id'], $repository['name'], $repository['vcs'], $repository['root'], $repository['authorization_method'], $repository['url_backend'], $repository['data']);
        $vcs_repository = new $this->backends[$repository['vcs']]->classes['repo']($repository['repo_id'], $repository, FALSE);
        //FIXME: another idea for this?
        $vcs_specific_key = $repository['vcs'] .'_specific';
        $vcs_repository->$vcs_specific_key = $repository[$repository['vcs'] .'_specific'];
        $vcs_repository->backend = $this->backends[$repository['vcs']];
        $result_repositories[$repository['repo_id']] = $vcs_repository;
      }
    }

    $this->repoCache[$constraints_serialized] = $result_repositories; // cache the results
    return $result_repositories;
  }

  public function getAllRepositories() {
    if (!$this->allFetched) {
      $this->allFetched = TRUE;
      $included = array_filter($this->repoCache['repo_id'], 'is_array');
      foreach ($included as $item) {
        $this->cacheRepository(new $this->backends[$item->vcs]['classes']['repo']($item->repo_id));
      }
    }
    return $this->repoCache['repo_id'];
  }

  private function cacheRepository(VersioncontrolRepository &$repository) {
    $this->repoCache['repo_id'][$repository->repo_id] = &$repository;
    $this->repoCache['name'][$repository->name][$repository->repo_id] = &$repository;
    $this->repoCache['vcs'][$repository->vcs][$repository->repo_id] = &$repository;
  }

  /**
   * Fetch VCS specific repository data additions, either by ourselves (if the
   * VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES flag has been set by the backend)
   * and/or by calling [vcs_backend]_alter_repositories().
   *
   * @static
   * @param $repositories_by_backend
   * @param $backends
   * @param $constraints
   */
  private function _amend_repositories($repositories_by_backend, $backends, $constraints = array()) {
    foreach ($repositories_by_backend as $vcs => $vcs_repositories) {
      $is_autoadd = in_array(VERSIONCONTROL_FLAG_AUTOADD_REPOSITORIES,
                             $backends[$vcs]['flags']);

      if ($is_autoadd) {
        $repo_ids = array();
        foreach ($vcs_repositories as $repo_id => $repository) {
          $repo_ids[] = $repo_id;
        }
        $additions = $this->_dbGetAdditions(
          'versioncontrol_'. $vcs .'_repositories', 'repo_id', $repo_ids
        );

        foreach ($additions as $repo_id => $addition) {
          if (isset($vcs_repositories[$repo_id])) {
            $vcs_repositories[$repo_id][$vcs .'_specific'] = $addition;
          }
        }
      }

      $vcs_specific_constraints = isset($constraints[$vcs .'_specific'])
                                  ? $constraints[$vcs .'_specific']
                                  : array();

      // Provide an opportunity for the backend to add its own stuff.
      if (versioncontrol_backend_implements($vcs, 'alter_repositories')) {
        $function = 'versioncontrol_'. $vcs .'_alter_repositories';
        $function($vcs_repositories, $vcs_specific_constraints);
      }
      $repositories_by_backend[$vcs] = $vcs_repositories;
    }
    return $repositories_by_backend;
  }

  /**
   * Generate and execute a SELECT query for the given table base on the name
   * and given values of this table's primary key. This function basically
   * accomplishes the retrieval part of Version Control API's 'autoadd' feature.
   * In order to avoid unnecessary complexity, the primary key may not consist
   * of multiple columns and has to be a numeric value.
   */
  private function _dbGetAdditions($table_name, $primary_key_name, $keys) {
    $placeholders = array();

    foreach ($keys as $key) {
      $placeholders[] = '%d';
    }

    $result = db_query('SELECT * FROM {'. $table_name .'}
    WHERE '. $primary_key_name .' IN ('.
    implode(',', $placeholders) .')', $keys);

    $additions = array();
    while ($addition = db_fetch_array($result)) {
      $primary_key = $addition[$primary_key_name];
      unset($addition[$primary_key_name]);

      foreach ($addition as $key => $value) {
        if (!is_numeric($addition[$key])) {
          $addition[$key] = unserialize($addition[$key]);
        }
      }
      $additions[$primary_key] = $addition;
    }
    return $additions;

  }
}
