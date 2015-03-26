<?php

// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Web service local plugin template external functions and service definitions.
 *
 * @package    localcodehandinws
 * @copyright  2014 Samuel Deane
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/////////////////////////////////
// see moodle.mdl_external_functions table for more function fields
// may need to include some capabilite, such as 'capabilites ' => 'mod/assign:update, mod/assign:read' 
// see moodle.mdl_external_services table for more service fields
////////////////////////////////
// We defined the web service functions to install.
$functions = array(
    //Token files
    'local_codehandinws_create_student_tokens' => array(
        'classname' => 'local_codehandinws_external',
        'methodname' => 'create_student_tokens',
        'classpath' => 'local/codehandinws/externallib.php',
        'description' => 'create tokens for students to use to access this '
        . 'web service',
        'type' => 'read',
        'capabilities' => 'mod/assign:addinstance',
    ),
    //Fetching Services
    'local_codehandinws_fetch_assignments' => array(
        'classname' => 'local_codehandinws_external',
        'methodname' => 'fetch_assignments',
        'classpath' => 'local/codehandinws/externallib.php',
        'description' => 'Return all or only the basic details of all or one '
        . 'of the available assignments for a specified user.',
        'type' => 'read',
        'capabilities' => 'mod/assign:view',
    ),
    'local_codehandinws_fetch_assignment_file_list' => array(
        'classname' => 'local_codehandinws_external',
        'methodname' => 'fetch_assignment_file_list',
        'classpath' => 'local/codehandinws/externallib.php',
        'description' => 'Return any files provided for an assignment',
        'type' => 'read',
        'capabilities' => 'mod/assign:view',
    ),
    'local_codehandinws_fetch_submission_file_list' => array(
        'classname' => 'local_codehandinws_external',
        'methodname' => 'fetch_submission_file_list',
        'classpath' => 'local/codehandinws/externallib.php',
        'description' => 'Return files submitted for an assignment',
        'type' => 'read',
        'capabilities' => 'mod/assign:view',
    ),
    //Testing Services
    'local_codehandinws_set_and_test_submission' => array(
        'classname' => 'local_codehandinws_external',
        'methodname' => 'set_and_test_submission',
        'classpath' => 'local/codehandinws/externallib.php',
        'description' => 'Upload a submission to Moodle with an option to '
        . 'test and or make the final submission. also transfers files from '
        . 'the user filearea to assignsubmission_file',
        'type' => 'write',
        'capabilities' => 'mod/assign:submit',
    ),
    'local_codehandinws_insert_or_update_codehandin' => array(
        'classname' => 'local_codehandinws_external',
        'methodname' => 'insert_or_update_codehandin',
        'classpath' => 'local/codehandinws/externallib.php',
        'description' => 'Updates a CodeHandIn to match the provided '
        . 'JSONObject and test files.',
        'type' => 'write',
        'capabilities' => 'mod/assign:addinstance',
    )
);

// define the service
$services = array(
    'Codehandin Service' => array(
        'functions' => array_keys($functions),
        'restrictedusers' => 0,
        'shortname' => 'codehandin',
        'enabled' => 1,
        'downloadfiles' => 1,
        'uploadfiles' => 1
    )
);
