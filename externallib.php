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
 * Codehandin external webservice
 * Used to submit and test code from an uploaded file
 * 
 * Info:
 * May well be contain race conditions
 * Still needs to handle zip files
 * may use assign events in later versions rather than log
 * may still contain print_object($something)
 * some comments may need to be removed
 * cmdCompile:  Ideally, $lang should be an ID to a table in the database
 *              and a small templateing engine used with the following replacements
 *                  %t => $userTempDir (with no trailing slash)
 *                  %e => $ext
 * 
 * re-investigate removedir
 *
 * @package    localcodehandin_webservice
 * @copyright  2014 Moodle Pty Ltd (http://moodle.com) & Samuel Deane & Jonathan Mackenzie
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->libdir . "/externallib.php"); // to validate_context
require_once($CFG->dirroot . "/mod/assign/externallib.php"); // get submission functions
require_once($CFG->dirroot . "/mod/assign/locallib.php");
require_once($CFG->libdir . "/enrollib.php"); // to get courses
require_once($CFG->libdir . "/filestorage/zip_packer.php");
require_once($CFG->dirroot . "/local/codehandin_webservice/locallib.php"); // add the web service libary

/**
 * test if a object's module name is assign
 * @param object $obj the object to be tested
 * @return bool true if its module name is assign otherwise false
 */
function isAssign($obj) {
    return $obj->modulename === "assign";
}

/**
 * extends the external API with the code handin web services functions 
 * @todo rename all create, update and delete methods as plurals i.e. create_tests_
 */
class local_codehandin_webservice_external extends external_api {
    ///////////////////////////////////////////////////////////////////////////
    //// private helper functions
    ///////////////////////////////////////////////////////////////////////////

    /**
     * checks that the user has the capabiltiy and if not returns a message to be outputed
     * possible capability types ($captype)s include
     *  'view'   (Default)
     *  'submit'
     *  'grade'
     *  'exportownsubmission'
     *  'addinstance'
     *  'editothersubmission'
     *  'grantextension'
     *  'revealidentities'
     *  'reviewgrades'
     *  'releasegrades'
     *  'managegrades'
     *  'manageallocations'
     *  'viewgrades'
     * 
     * @global user_Object $USER
     * @param bool/capability type $captype (false is default)
     * @return flase/object the assignment cotext is an id is given
     */
    private static function validate_context_by_assignmentid($functionName, $assignmentids, $ignoreWarnings = false, $captype = false) {
        /* @var $DB mysqli_native_moodle_database */
        global $DB, $USER;

        $warnings = array();
        $contextids = array();

        if (!$captype) {
            $captype = 'view';
        }

        // get contexts
        $placeholders = array();
        list($inorequalsql, $placeholders) = $DB->get_in_or_equal($assignmentids, SQL_PARAMS_NAMED);
        $sql = "SELECT cm.instance, cm.id FROM {course_modules} cm JOIN {modules} md ON md.id = cm.module " .
                "WHERE md.name = :modname AND cm.instance " . $inorequalsql;
        $placeholders['modname'] = 'assign';
        $cms = $DB->get_records_sql($sql, $placeholders);
        // validate contexts
        $validids = array();
        foreach ($assignmentids as $aid) {
            $cm = $cms[$aid];
            if ($cm == null) {// assignment does not exist
                $warnings[] = array(
                    'item' => $functionName,
                    'itemid' => $aid,
                    'warningcode' => 'noIDorPermission',
                    'message' => 'either the id does not exist or you do not have permission to ' . $captype . " this assingment as $USER->username");
            } else {// validate the context
                try {
                    $context = context_module::instance($cm->id);
                    self::validate_context($context); // check if can do somthing
                    require_capability("mod/assign:$captype", $context); // check if can do a specifc something
                    $contextids[$aid] = $context->id; //$context;
                    $validids[] = $aid;
                } catch (Exception $e) {
                    if (!$ignoreWarnings) {// does not have permission to view this
                        $warnings[] = array(
                            'item' => $functionName,
                            'itemid' => $cm->instance,
                            'warningcode' => 'noIDorPermission',
                            'message' => 'either the id does not exist or you do not have permission to ' . $captype . " this assingment as $USER->username");
                    }
                }
            }
        }

        $data = new stdClass();
        $data->warnings = $warnings;
        $data->contextids = $contextids;
        $data->assignmentids = $validids;
        return $data;
    }

    ///////////////////////////////////////////////////////////////////////////
    //// Fetching services
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Describes the parameters for create student tokens
     * @return external_function_parameters
     */
    public static function create_student_tokens_parameters() {
        return new external_function_parameters(
                array('assignmentid' => new external_value(PARAM_FILE, 'Assignment identifier')
                )
        );
    }

    /**
     * create tokesn for all students in a topic
     * @return boolean true if tokens were created else false
     */
    public static function create_student_tokens() {
        return false;
    }

    /**
     * Describes the create student tokens return value
     * @return external_value
     */
    public static function create_student_tokens_returns() {
        return new external_value(PARAM_BOOL, 'if the upload was successful');
    }

    /**
     * Describes the parameters for fetch assignments
     * @return external_function_parameters
     */
    public static function fetch_assignments_parameters() {
        return new external_function_parameters(//
                array(
            'basic' => new external_value(PARAM_BOOL, 'return basic details only', VALUE_OPTIONAL, false),
            'assignmentids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'return details of a specific assignment by its id')
                    , 'list of assignment ids', VALUE_OPTIONAL, false)
                )
        );
    }

    /**
     * fetch the assignments of the current user
     * @param basic 
     * @param assignmentid 
     * @global db_Object $DB the database object that enables access to the underlying database
     * @return jsonObject of the assignments the user has
     * @todo maybe cache the assignment details rather than recreating the assignment every time
     */
    public static function fetch_assignments($basic, $assignmentids = false) {
        $out = new stdClass();
        $ignoreWarnings = false;
        if (empty($assignmentids) || !$assignmentids) {
            $assignmentids = local_codehandin_webservice::getAccessableAssignmentids();
            if (!$assignmentids) {
                $warnings = array();
                $warnings[] = array(
                    'item' => 'fetch_assignments',
                    'itemid' => '0',
                    'warningcode' => 'noIDsInDB',
                    'message' => ' There are currently no codehandin assignments in the database');
                $out->warnings = $warnings;
                return $out;
            }
            $ignoreWarnings = true;
        }
        //return json_encode($assignmentids);
        $data = self::validate_context_by_assignmentid('fetch_assignments', $assignmentids, $ignoreWarnings);
        if (empty($data->assignmentids)) {
            return $data;
        }
        $out = local_codehandin_webservice::fetch_assignments_raw($data->assignmentids, $data->contextids, $basic); // only get the assignments that passed validation
        return $out;
    }

    /**
     * Describes the fetch assignments return value
     * @return external_value
     */
    public static function fetch_assignments_returns() {
        return new external_single_structure(
                array(
            'warnings' => new external_multiple_structure(
                    new external_single_structure(
                    array(
                'item' => new external_value(PARAM_TEXT, 'item', VALUE_OPTIONAL),
                'itemid' => new external_value(PARAM_INT, 'itemid', VALUE_OPTIONAL),
                'warningcode' => new external_value(PARAM_ALPHANUM, 'number or warning code'),
                'message' => new external_value(PARAM_TEXT, 'untranslated english message to explain the warning')
                    )
                    ), 'list of warnings', VALUE_OPTIONAL),
            'courses' => new external_multiple_structure(
                    new external_single_structure(
                    array(
                'id' => new external_value(PARAM_INT, 'function name'),
                'shortname' => new external_value(PARAM_TEXT, 'function name'),
                'codehandins' => new external_multiple_structure(
                        new external_single_structure(
                        array(
                    'id' => new external_value(PARAM_INT, 'function name'),
                    'contextid' => new external_value(PARAM_INT, 'function name'),
                    'assignname' => new external_value(PARAM_TEXT, 'function name'),
                    'intro' => new external_value(PARAM_RAW, 'function name'),
                    'duedate' => new external_value(PARAM_RAW, 'function name'),
                    'funcpercent' => new external_value(PARAM_INT, 'function name'),
                    'spectestonly' => new external_value(PARAM_BOOL, 'function name'),
                    'mustattemptcompile' => new external_value(PARAM_BOOL, 'function name'),
                    'proglang' => new external_value(PARAM_TEXT, 'function name'),
                    'proglangid' => new external_value(PARAM_INT, 'function name'),
                    'checkpoints' => new external_multiple_structure(
                            new external_single_structure(
                            array(
                        'tempid' => new external_value(PARAM_TEXT, 'function name', VALUE_OPTIONAL),
                        'id' => new external_value(PARAM_INT, 'function name', VALUE_OPTIONAL),
                        //'assignmentid' => new external_value(PARAM_INT, 'function name'),
                        'name' => new external_value(PARAM_TEXT, 'function name'),
                        'description' => new external_value(PARAM_RAW, 'function name'),
                        'ordering' => new external_value(PARAM_INT, 'function name'),
                        'marks' => new external_value(PARAM_INT, 'function name'),
                        'tests' => new external_multiple_structure(
                                new external_single_structure(
                                array(
                            'tempid' => new external_value(PARAM_TEXT, 'function name', VALUE_OPTIONAL),
                            'id' => new external_value(PARAM_INT, 'function name', VALUE_OPTIONAL),
                            'status' => new external_value(PARAM_BOOL, 'function name'),
                            'description' => new external_value(PARAM_RAW, 'function name'),
                            'gradeonly' => new external_value(PARAM_BOOL, 'function name'),
                            'runtimeargs' => new external_value(PARAM_TEXT, 'function name'),
                            'ioastext' => new external_value(PARAM_BOOL, 'function name'),
                            'input' => new external_value(PARAM_TEXT, 'function name'),
                            'output' => new external_value(PARAM_TEXT, 'function name'),
                            'outputerr' => new external_value(PARAM_TEXT, 'function name'),
                            'retval' => new external_value(PARAM_INT, 'function name'),
                            'ordering' => new external_value(PARAM_INT, 'function name'),
                            'marks' => new external_value(PARAM_INT, 'function name')
                                )
                                ), 'the codehandin tests', VALUE_OPTIONAL)
                            )
                            ), 'the codehandin checkpoints', VALUE_OPTIONAL)
                        )
                        ), 'the codehandin Assignments', VALUE_OPTIONAL) // there may be no assignments
                    )
                    ), 'the codehandin Assignments', VALUE_OPTIONAL) // there may be no assignments so no courses
                )
        );
    }

    /**
     * Describes the parameters for fetch assignment file list
     * @return external_function_parameters
     */
    public static function fetch_assignment_file_list_parameters() {
        return new external_function_parameters(
                array('assignmentid' => new external_value(PARAM_INT, 'Assignment identifier', VALUE_REQUIRED))
        );
    }

    /**
     * 
     * @global type $CFG
     * @param type $assignmentid
     * @return array
     */
    public static function fetch_assignment_file_list($assignmentid) {
        global $CFG;
        //return core_component::get_component_directory("assignsubmission_codehandin");               
        //return json_encode(mod_assign_external::get_submissions(array($assignmentid)));
        $fs = get_file_storage();
        $result = array();
        $contextData = self::validate_context_by_assignmentid("fetch_assignment_files", array($assignmentid));
        if (isset($contextData->warningcode)) { // if context is really a warning
            return $contextData; // return it!
        }
        $contextid = $contextData->contextids[$assignmentid];
        $files = $fs->get_area_files($contextid, COMPONENT, CODEHANDIN_FILEAREA, $assignmentid, 'timemodified', false);
        if (empty($files)) {
            $result['warning'] = array(
                'item' => 'fetch_assignment_file_list',
                'itemid' => $assignmentid,
                'warningcode' => 'noFiles',
                'message' => 'Assignment of assignmentid:' . $assignmentid . ' does not have any test files ');
        } else {
            $fileurls = array();
            foreach ($files as $file) {
                $pathname = $assignmentid . $file->get_filepath() . $file->get_filename();
                $fileurl = file_encode_url($CFG->wwwroot . '/webservice/pluginfile.php/'
                        . $contextid . '/' . COMPONENT . '/' . CODEHANDIN_FILEAREA . '/'
                        . $pathname);
                $fileurls[] = array(
                    'filepath' => $assignmentid . '/a/' . $file->get_filepath() . $file->get_filename(),
                    'fileurl' => $fileurl
                );
            }
            $result['files'] = $fileurls;
            $result['zipfile'] = array(
                'filepath' => $assignmentid . '/az/' . $assignmentid . '.zip',
                'fileurl' => file_encode_url($CFG->wwwroot . '/webservice/pluginfile.php/'
                        . $contextid . '/' . COMPONENT . '/' . CODEHANDIN_ZIP_FILEAREA . '/'
                        . $assignmentid . '/t' . $assignmentid . '.zip')
            );
        }
        return $result;
    }

    /**
     * Describes the fetch assignment file list return value
     * @return external_value
     */
    public static function fetch_assignment_file_list_returns() {
        return new external_single_structure(
                array(
            'warning' => new external_single_structure(
                    array(
                'item' => new external_value(PARAM_TEXT, 'item', VALUE_OPTIONAL),
                'itemid' => new external_value(PARAM_INT, 'itemid', VALUE_OPTIONAL),
                'warningcode' => new external_value(PARAM_ALPHANUM, 'number or warning code'),
                'message' => new external_value(PARAM_TEXT, 'untranslated english message to explain the warning')
                    ), 'a warning', VALUE_OPTIONAL),
            'zipfile' => new external_single_structure(
                    array(
                'filepath' => new external_value(PARAM_TEXT, 'file path'),
                'fileurl' => new external_value(PARAM_URL, 'file download url', VALUE_OPTIONAL)
                    ), 'all codehandin files as a zip', VALUE_OPTIONAL),
            'files' => new external_multiple_structure(
                    new external_single_structure(
                    array(
                'filepath' => new external_value(PARAM_TEXT, 'file path'),
                'fileurl' => new external_value(PARAM_URL, 'file download url', VALUE_OPTIONAL)
                    )
                    ), 'the codehandin files', VALUE_OPTIONAL) // there may be no assignments
                )
        );
    }

    /**
     * Describes the parameters for fetch submission file list
     * @return \external_function_parameters
     */
    public static function fetch_submission_file_list_parameters() {
        return new external_function_parameters(
                array('assignmentid' => new external_value(PARAM_INT, 'Assignment identifier', VALUE_REQUIRED),
            'createzip' => new external_value(PARAM_BOOL, 'zip all submission files', VALUE_REQUIRED))
        );
    }

    /**
     * 
     * @param type $assignmentid
     * @return type
     */
    public static function fetch_submission_file_list($assignmentid) {
        global $CFG, $USER, $DB;
        $fs = get_file_storage();
        $contextData = self::validate_context_by_assignmentid("fetch_submission_files", array($assignmentid));
        if (isset($contextData->warningcode)) { // if context is really a warning
            return $contextData; // return it!
        }
        $submission = $DB->get_record('assign_submission', array('userid' => $USER->id), $fields = 'id');
        $contextid = $contextData->contextids[0];
        $files = $fs->get_area_files($contextid, 'assignsubmission_file', 'submission_files', $submission->id, 'timemodified', false);
        if (empty($files)) {
            $result['warning'] = array(
                'item' => 'fetch_assignment_file_list',
                'itemid' => $submission->id,
                'warningcode' => 'noFiles',
                'message' => 'Submission of submissionid:' . $submission->id . ' for Assignment of assignmentid:' . $assignmentid . ' does not have any submission files');
        } else {
            $fileurls = array();
            foreach ($files as $file) {
                $pathname = $assignmentid . '/' . $submission->id . $file->get_filepath() . $file->get_filename();
                $fileurl = file_encode_url($CFG->wwwroot . '/webservice/pluginfile.php/'
                        . $contextid . '/assignsubmission_file/submission_files/'
                        . $pathname);
                $fileurls[] = array(
                    'filepath' => '/' . $assignmentid . '/s/' . $file->get_filepath() . $file->get_filename(),
                    'fileurl' => $fileurl
                );
            }
            // make submission zip?
            $result['files'] = $fileurls;
            $result['zipfile'] = array(
                'filepath' => $assignmentid . '/sz/' . $USER->username . '.zip',
                'fileurl' => file_encode_url($CFG->wwwroot . '/webservice/pluginfile.php/'
                        . $contextid . '/assignsubmission_file/submission_files/'
                        . $assignmentid . '/' . $USER->username . '.zip')
            );
        }
        return $result;
    }

    /**
     * Describes the create checkpointn return value
     * @return external_value
     */
    public static function fetch_submission_file_list_returns() {
        return self::fetch_assignment_file_list_returns();
    }

//    /**
//     * Describes the parameters for transfer assignment files
//     * @return \external_function_parameters
//     */
//    public static function transfer_assignment_files_parameters() {
//        return new external_function_parameters(
//                array('assignmentid' => new external_value(PARAM_FILE, 'Assignment identifier')
//                )
//        );
//    }
//
//    /**
//     * 
//     * @return boolean
//     */
//    public static function transfer_assignment_files() {
//        return false;
//    }
//
//    /**
//     * Describes the create checkpointn return value
//     * @return external_value
//     */
//    public static function transfer_assignment_files_returns() {
//        return new external_value(PARAM_BOOL, 'if the upload was successful');
//    }
    ///////////////////////////////////////////////////////////////////////////
    //// Testing services
    ///////////////////////////////////////////////////////////////////////////    

    /**
     * Describes the parameters for test submission
     * @return \external_function_parameters
     */
    public static function set_and_test_submission_parameters() {
        return new external_function_parameters(
                array('submissioninfo' => new external_value(PARAM_TEXT, 'json containing assignmentid, submit and test fields', VALUE_REQUIRED))
        );
    }

    /**
     * 
     * @return boolean
     */
    public static function set_and_test_submission($submissioninfo) {
        $info = json_decode($submissioninfo);        
        $assignmentid = $info->assignmentid;
        $draftid = $info->draftid;
        $submit = $info->submit;
        $test = $info->test;
        //return " assignmentid $assignmentid draftid $draftid submit $submit";
        //$out = local_codehandin_webservice::get_testing_info($assignmentid, $submit, true);
        $out = local_codehandin_webservice::set_and_submit_submission($assignmentid, $draftid, false, $submit);
//        if ($submit) {
//            $aout = local_codehandin_webservice::test_submission(true);
//            if ($test) {
//                return $aout;
//            }
//        } else if ($test) { // test but not submit
//            $out = local_codehandin_webservice::test_submission(false);
//        }
        return $out;
    }

    /**
     * Describes the test submission return value
     * @return external_value
     */
    public static function set_and_test_submission_returns() {
        return new external_single_structure(
                array(
            'warnings' => new external_multiple_structure(
                    new external_single_structure(
                    array(
                'item' => new external_value(PARAM_TEXT, 'item', VALUE_OPTIONAL),
                'itemid' => new external_value(PARAM_INT, 'itemid', VALUE_OPTIONAL),
                'warningcode' => new external_value(PARAM_ALPHANUM, 'number or warning code'),
                'message' => new external_value(PARAM_TEXT, 'untranslated english message to explain the warning')
                    )
                    ), 'list of warnings', VALUE_OPTIONAL),
            'out' => new external_value(PARAM_TEXT, 'item', VALUE_OPTIONAL),
            'success' => new external_value(PARAM_BOOL, 'item', VALUE_OPTIONAL))
        );
    }

    ///////////////////////////////////////////////////////////////////////////
    //// Creation/Update services
    ///////////////////////////////////////////////////////////////////////////    

    /**
     * Describes the parameters for update codehandin
     * @return \external_function_parameters
     */
    public static function update_codehandin_parameters() {
        return new external_function_parameters(array(
            'codehandin' => new external_value(PARAM_RAW, "The test as a String, JSON Object")
        ));
    }

    /**
     * update a codehandin assignment from a jsonstring and provided files 
     * if the id is supplied will try to update
     * @global user_Object $USER the user object storing the users details (validate capability)
     * @global db_Object $DB the database object that enables access to the underlying database
     * @param sting $description a descriptioniption of the checkpoint
     * @return json_Object a json object containing any errors or the id of the new checkpoint
     */
    public static function update_codehandin($codehandin) {
        return json_encode(local_codehandin_webservice::update_codehandin(json_decode($codehandin)));
    }

    /**
     * Describes the update codehandin return value
     * @return external_value
     */
    public static function update_codehandin_returns() { // should just be update
        return new external_single_structure(
                array(
            'warnings' => new external_multiple_structure(
                    new external_single_structure(
                    array(
                'item' => new external_value(PARAM_TEXT, 'item', VALUE_OPTIONAL),
                'itemid' => new external_value(PARAM_INT, 'itemid', VALUE_OPTIONAL),
                'warningcode' => new external_value(PARAM_ALPHANUM, 'number or warning code'),
                'message' => new external_value(PARAM_TEXT, 'untranslated english message to explain the warning')
                    )
                    ), 'list of warnings', VALUE_OPTIONAL),
            'succeeded' => new external_value(PARAM_BOOL, ' if the update succceed', VALUE_OPTIONAL)
        ));
    }

}
