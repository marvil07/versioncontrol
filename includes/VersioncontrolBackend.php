<?php
// $Id$
/**
 * @file
 * Backend class
 */

/**
 * Backend base class
 *
 * @abstract
 */
abstract class VersioncontrolBackend implements ArrayAccess {
  // Attributes
  /**
   * simple name
   *
   * @var    string
   */
  public $name;

  /**
   * simple description
   *
   * @var    string
   */
  public $description;

  /**
   * what the backend can do, probably deprecated after interfaces approach
   *
   * @var    array
   */
  public $capabilities;

  /**
   * classes which this backend overwrite
   */
  public $classes;

  // Operations

  /**
   * Reference constructor for backends
   */
  public function __construct($name, $description, $capabilities = array(), $classes = array()) {
    $this->name = $name;
    $this->description = $description;
    $this->capabilities = $capabilities;
    $this->classes = $classes;
  }

  //ArrayAccess interface implementation
  public function offsetExists($offset) {
    return isset($this->$offset);
  }
  public function offsetGet($offset) {
    return $this->$offset;
  }
  public function offsetSet($offset, $value) {
    $this->$offset = $value;
  }
  public function offsetUnset($offset) {
    unset($this->$offset);
  }
}
