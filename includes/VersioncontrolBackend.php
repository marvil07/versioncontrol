<?php
// $Id$

/**
 * Backend base class
 *
 * @abstract
 */
abstract class VersioncontrolBackend {
    // Attributes
    /**
     * simple name
     *
     * @var    string
     * @access public
     */
    public $name;

    /**
     * simple description
     *
     * @var    string
     * @access public
     */
    public $description;

    /**
     * what the backend can do, probably deprecated after interfaces approach
     *
     * @var    array
     * @access public
     */
    public $capabilities;

    /**
     * XXX
     *
     * @var    array
     * @access public
     */
    public $flags;

    // Associations
    // Operations
}

?>
