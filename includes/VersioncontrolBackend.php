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
     * XXX
     *
     * @var    array
     */
    public $flags;

    // Associations
    // Operations
}

?>
