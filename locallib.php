<?php

require_once("$CFG->libdir/enrollib.php");
require_once("$CFG->libdir/filestorage/zip_packer.php");

// use zip for better compatability perhaps
//define('ASSIGNSUBMISSION_CHI_MAXFILES', 1);
//define('ASSIGNSUBMISSION_CHI_MAXSUMMARYFILES', 1);
// do not add underscores ... are removed by moodles string clearer
define('CODEHANDIN_TEMP_FILEAREA', 'codehandintempzips'); // for temp zips of test & assignment files (paritial zips) ... if using core upload functions equiv to user files
define('CODEHANDIN_FILEAREA', 'codehandin_files'); // for assignment files
define('CODEHANDIN_ZIP_FILEAREA', 'codehandin_zipfiles'); // for zips of assignment files
define('ASSIGNSUBMISSION_FILEAREA_TEMP', 'codehandin_temp_submission_files'); // not used

define('COMPONENT', 'assignsubmission_codehandin'); //
define('SHORT_SERIVCE_NAME', 'codehandin'); // must be the same as 'shortname' => 'codehandin' in services.php

class local_codehandin_webservice {
    /*
     * useful functions not part of any section
     * 
     * returns  'id', 'category', 'sortorder', 'shortname', 'fullname', 'idnumber',
     *          'startdate', 'visible', 'groupmode', 'groupmodeforce', 'cacherev'
     * $courses = enrol_get_my_courses();
     */

    public static function getFileAreas() {
        $name = get_string('pluginname', 'assignsubmission_codehandin');
        return array(CODEHANDIN_ZIP_FILEAREA => $name,
            CODEHANDIN_TEMP_FILEAREA => $name,
            CODEHANDIN_FILEAREA => $name,
            CODEHANDIN_ZIP_FILEAREA => $name,
            ASSIGNSUBMISSION_FILEAREA_TEMP => $name);
    }

    const add_contents_to_test_table = false;

///////////////////////////////////////////////////////////////////////////
//// helper functions
///////////////////////////////////////////////////////////////////////////

    public static function getAccessableAssignmentids() {
        global $DB;
//$placeholders = array();
        list($inorequalsql, $placeholders) = $DB->get_in_or_equal(array_keys(enrol_get_my_courses()), SQL_PARAMS_NAMED);
        return array_keys($DB->get_records_sql("SELECT {codehandin}.id FROM {assign}, {codehandin} "
                        . "WHERE {assign}.id = {codehandin}.id "
                        . "AND {assign}.course " . $inorequalsql, $placeholders)); // =2"
    }

    /**
     * get the ids of the courses the current user is enrolled in 
     * @return string an array of course ids
     */
    private static function getCoursesString() {
        return "(" . implode(",", array_keys(enrol_get_my_courses())) . ") ";
    }

    /**
     *     * uses the file system to quickly get a file by id
     * @param $fileid the id of the file to be found
     * @return file or false if file could not be found
     */
    private static function get_file_byID($fileid) {
        $fs = get_file_storage();
        return $fs->get_file_by_id($fileid);
    }

///////////////////////////////////////////////////////////////////////////
//// get Codehandin details
///////////////////////////////////////////////////////////////////////////    

    /**
     * fetch the assignments for the current user
     * (uses a single DB call with data arranged in program)
     * @param array|int $assignmentids the ids of the assignments
     * @param array $contextids assignmentids and contextids
     * @param bool $basic true - minimal details, false - all details 
     * (includes cps and tests)    
     * @global db_Object $DB the database object that enables access to the underlying database
     * @return jsonObject of the assignments the user has
     * @todo maybe cache the assignment details rather than creating the assignment every time
     */
    public static function fetch_assignments_raw($assignmentids, $contextids, $basic = true) {
        global $DB;
// return 'in locallib::fetch_assignments '
//$placeholders = array();
        list($inorequalsql, $placeholders) = $DB->get_in_or_equal($assignmentids, SQL_PARAMS_NAMED);
        $info = new stdClass();
// get course, codehandin, checkpoint, test for assignment ids
        $sql = "SELECT @rn:=@rn+1 AS rank, " . ($basic ? "" : "t.id AS tid, cp.id AS cpid,") . " c.id AS cid, co.id AS coid, co.shortname, 
            a.name AS assignname, a.intro, a.duedate,
            cpl.id AS cplid, cpl.name AS ccpproglang_name, 
            c.mustattemptcompile, c.studentfile,
            c.spectest, c.spectestonly, c.funcpercent"
                . ($basic ? "" : ",   
            cp.name AS cpname, cp.description AS cpdescription, 
            cp.runtimeargs AS cpruntimeargs, cp.ordering AS cpordering, 
            cp.marks AS cpmarks, t.checkpointid,
            t.description AS tdescription, t.gradeonly, 
            t.runtimeargs AS truntimeargs, t.ioastext, 
            t.input, t.output, t.outputerr, t.retval, t.ordering AS tordering, 
            t.marks AS tmarks")
                . " FROM (({course} AS co 
            INNER JOIN (({assign} AS a INNER JOIN {codehandin} AS c ON a.id = c.id) 
            INNER JOIN {codehandin_proglang} AS cpl ON cpl.id = c.proglangid)ON a.course = co.id) "
                . ($basic ? "" : "LEFT JOIN ({codehandin_checkpoint} AS cp LEFT JOIN {codehandin_test} AS t ON t.checkpointid = cp.id) ON c.id = cp.assignmentid")
                . ") JOIN (SELECT @rn := 0 FROM DUAL) AS sub WHERE c.id " . $inorequalsql
                . " ORDER BY co.id ASC, c.id ASC" . ($basic ? "" : ", cp.id ASC, t.id ASC");
        $recs = array_values($DB->get_records_sql($sql, $placeholders));
        if (empty($recs)) {
            $warnings[] = array(
                'item' => 'fetch_assignments',
                'itemid' => '0',
                'warningcode' => 'noIDsInDB',
                'message' => ' There are currently no codehandin assignments in the database');
            $info->warnings = $warnings;
            return $info;
        } else {
            $lcoid = $recs[0]->coid;
            $courses = array();
            $codehandins = array();
            $course = array('id' => $recs[0]->coid,
                'shortname' => $recs[0]->shortname);
            if (!$basic) {
                $lcid = $recs[0]->cid;
                $checkpoints = array();
                $tests = array();
                $codehandin = array('id' => $recs[0]->cid,
                    'contextid' => $contextids[$recs[0]->cid],
                    'assignname' => $recs[0]->assignname,
                    'intro' => $recs[0]->intro,
                    'duedate' => $recs[0]->duedate,
                    'proglangid' => $recs[0]->cplid,
                    'proglang' => $recs[0]->ccpproglang_name,
                    'mustattemptcompile' => $recs[0]->mustattemptcompile,
                    'studentfile' => $recs[0]->studentfile,
                    'spectestonly' => $recs[0]->spectestonly,
                    'funcpercent' => $recs[0]->funcpercent);
                $checkpoint = null;
                $lcpid = -1;
                if (isset($recs[0]->cpid)) {
                    $checkpoint = array('id' => $recs[0]->cpid,
                        'name' => $recs[0]->cpname,
                        'description' => $recs[0]->cpdescription,
                        'runtimeargs' => $recs[0]->cpruntimeargs,
                        'ordering' => $recs[0]->cpordering,
                        'marks' => $recs[0]->cpmarks);
                    $lcpid = $recs[0]->cpid;
                }
                foreach ($recs as $cr) {//each is a test change
                    if (isset($cr->cpid) ? ($lcpid != $cr->cpid) : true) {// checkpoint change
                        if (!empty($tests)) {
                            $checkpoint['tests'] = $tests; // if tests add to the last checkpoint
                        }
                        if ($checkpoint) {
                            $checkpoints[] = $checkpoint; //$checkpoint->ordering
                        }
                        if ($cr->cpid) {
                            $checkpoint = array('id' => $cr->cpid,
                                'name' => $cr->cpname,
                                'description' => $cr->cpdescription,
                                'runtimeargs' => $cr->cpruntimeargs,
                                'ordering' => $cr->cpordering,
                                'marks' => $cr->cpmarks);
                            $lcpid = $cr->cpid;
                        } else {
                            $checkpoint = null;
                            $lcpid = -1;
                        }
                        $tests = array();
                        if ($lcid != $cr->cid) {// codehandin change
                            $codehandin['checkpoints'] = $checkpoints; // if checkpoints add to the last codehandin
                            $codehandins[] = $codehandin;
                            $codehandin = array('id' => $cr->cid,
                                'contextid' => $contextids[$cr->cid],
                                'assignname' => $cr->assignname,
                                'intro' => $cr->intro,
                                'duedate' => $cr->duedate,
                                'proglangid' => $cr->cplid,
                                'proglang' => $cr->ccpproglang_name,
                                'mustattemptcompile' => $cr->mustattemptcompile,
                                'studentfile' => $cr->studentfile,
                                'spectestonly' => $cr->spectestonly,
                                'funcpercent' => $cr->funcpercent);
                            $lcid = $cr->cid;
                            $checkpoints = array();
                            if ($lcoid != $cr->coid) {//course change
                                $course['codehandins'] = $codehandins; // if codehandins add to the last course 
                                $courses[] = $course; // ignore courses that do not have any codehandins!
                                $codehandins = array();
// higher level here
                                $course = array('id' => $cr->coid,
                                    'shortname' => $cr->shortname);
                                $lcoid = $cr->coid;
                            }
                        }
                    }
                    if ($cr->tid) {
                        $tests[$cr->tordering] = array('id' => $cr->tid,
                            'description' => $cr->tdescription,
                            'status' => $cr->status,
                            'gradeonly' => $cr->gradeonly,
                            'runtimeargs' => $cr->truntimeargs,
                            'ioastext' => $cr->ioastext,
                            'input' => $cr->input,
                            'output' => $cr->output,
                            'outputerr' => $cr->outputerr,
                            'retval' => $cr->retval,
                            'ordering' => $cr->tordering,
                            'marks' => $cr->tmarks);
                    }
                } // end of the tests
                if ($checkpoint) { // if the last recognised checkpoint was a checkpoint?
                    if (!empty($tests)) {
                        $checkpoint['tests'] = $tests; // add test to the last checkpoint        
                    }
                    $checkpoints[] = $checkpoint; //$checkpoint->ordering
                    $codehandin['checkpoints'] = $checkpoints; // add checkpoints to the last codehandin      
                }
                $codehandins[] = $codehandin;
            } else {
                foreach ($recs as $cr) { //codehandin change
                    if ($lcoid != $cr->coid) {//course change
                        $course['codehandins'] = $codehandins; // if codehandins add to the last course 
                        $courses[] = $course; // courses must have codehandins
                        $codehandins = array();
                        $course = array('id' => $cr->coid,
                            'shortname' => $cr->shortname);
                    }
                    $codehandins[] = array('id' => $cr->cid,
                        'contextid' => $contextids[$cr->cid],
                        'assignname' => $cr->assignname,
                        'intro' => $cr->intro,
                        'duedate' => $cr->duedate,
                        'proglangid' => $cr->cplid,
                        'proglang' => $cr->ccpproglang_name,
                        'mustattemptcompile' => $cr->mustattemptcompile,
                        'studentfile' => $cr->studentfile,
                        'spectestonly' => $cr->spectestonly,
                        'funcpercent' => $cr->funcpercent);
                }
            }
            $course['codehandins'] = $codehandins; // add codehandins to the last course
            $courses[] = $course;
            $info->courses = $courses;
        }
        return $info;
    }

///////////////////////////////////////////////////////////////////////////
//// manipulate Codehandin checkpoints and tests
///////////////////////////////////////////////////////////////////////////   

    private static function get_test_update_info($assignmentid) {
        global $DB;
        $sql = "SELECT t.id AS tid, t.gradeonly, t.input, t.output, t.outputerr"
                . " FROM ({codehandin} AS c LEFT JOIN {codehandin_checkpoint} AS cp ON c.id = cp.assignmentid) "
                . " LEFT JOIN {codehandin_test} AS t ON t.checkpointid = cp.id "
                . " WHERE c.id = $assignmentid ";
        return $DB->get_records_sql($sql);
    }

    /**
     * inserts or updates test (those with ids are updated and those without are inserted)
     * if a tests has a draftinputid then the file is saved from the file system 
     * @todo could save tests and do in a batch?
     * @return type
     */
    private static function insert_or_update_tests($assignmentid, $checkpointid, $tests, $etinfo, $delids, $delfilepaths) {
        global $DB;

        $tids = array();
        if (!empty($tests)) {
            foreach ($tests as $test) {
                if (isset($test->d) || isset($test->delete)) {
                    if ($test->id[0] != n) { // just in case they are telling me to delete a new test
                        $delids[] = $test->id;
                        $delfilepaths[] = $etinfo[$test->id]->gradeonly ? "g/" : "t/"
                                . "$assignmentid/$checkpointid/$test->id";
                    }
                    continue;
                }

                if ($test->id[0] == "n") {
                    if (!isset($test->status)) {
                        if (isset($test->ioastext)) {
                            if ($test->ioastext) {
                                $ie = isset($test->input);
                                $oe = isset($test->output);
                                $ee = isset($test->outputerr);
                            } else {
                                $test->status = self::get_status_fileIOE($test);
                            }
                        } else {
                            $test->status = self::get_status_fileIOE($test);
                        }
                    }

                    if (!isset($test->input)) {
                        $test->input = 0;
                    }
                    if (!isset($test->output)) {
                        $test->output = 0;
                    }
                    if (!isset($test->outputerr)) {
                        $test->outputerr = 0;
                    }
                    $test->checkpointid = $checkpointid;
                    $tids[$test->id] = $DB->insert_record("codehandin_test", $test, true, true);
                } else {// not new
                    if (isset($test->ioastext)) {
                        if ($test->ioastext) {
                            $ie = isset($test->input);
                            $oe = isset($test->output);
                            $ee = isset($test->outputerr);
                        } else {
                            $test->status = self::get_status_fileIOE($test
                                            , !is_int($etinfo[$test->id]->input)
                                            , !is_int($etinfo[$test->id]->output)
                                            , !is_int($etinfo[$test->id]->outputerr)
                                            , $delfilepaths
                                            , ($etinfo[$test->id]->gradeonly ? "g/" : "t/")
                                            . "$assignmentid/$checkpointid/");
                        }
                    } else {
                        $test->status = self::get_status_fileIOE($test
                                        , !is_int($etinfo[$test->id]->input)
                                        , !is_int($etinfo[$test->id]->output)
                                        , !is_int($etinfo[$test->id]->outputerr)
                                        , $delfilepaths
                                        , ($etinfo[$test->id]->gradeonly ? "g/" : "t/")
                                        . "$assignmentid/$checkpointid/");
                    }

                    $tids[$test->id] = $test->id;
//                    if(!isset($test->id))
//                    return json_encode($tids).json_encode($test);
                    $DB->update_record("codehandin_test", $test, true);
                }
            }
        }
        return $tids;
    }

    /**
     * get the status of the submission while adding deleted files to the delfilespaths
     * @param Object $test the test object to check the status of
     * @param bool $ie if the input already exists on the file system
     * @param bool $oe if the output already exists on the file system
     * @param bool $ee if the outputerr already exists on the file system
     * @param array $delfilepaths
     * @param bool|stirng $prefix
     * @return int 0 input, output and or outputerr does not exist (not runable)
     *             1 input and output exist, (runable)
     *             2 input and outputerr exist and (runable)
     *             3 input, output and outputerr exist. (runable)
     */
    private static function get_status_fileIOE($test, $ie = false, $oe = false, $ee = false, $delfilepaths = false, $prefix = false) {

        if (isset($test->input)) {
            if ($delfilepaths) {
                $delfilepaths[] = "$prefix$test->id/i";
            }
            $ie = !is_int($test->input);
        }
        if (isset($test->output)) {
            if ($delfilepaths) {
                $delfilepaths[] = "$prefix$test->id/o";
            }
            $oe = !is_int($test->output);
        }
        if (isset($test->outputerr)) {
            if ($delfilepaths) {
                $delfilepaths[] = "$prefix$test->id/e";
            }
            $ee = is_int($test->outputerr);
        }
// statuses 0 i|o|e|oe    1 io  2 ie 3 ioe   
        if (!$ie) { // no ie
            $test->status = 0;
        } else if ($oe) {
            if ($ee) { //ie+oe+ee
                $test->status = 3;
            } else { //ie+oe
                $test->status = 1;
            }
        } else if ($ee) { // ie+ee
            $test->status = 2;
        } else { // only ie
            $test->status = 0;
        }
        return status;
    }

    /**
     * inserts checkpoints into the db and returns an array of checkpoints which 
     */
    private static function insert_or_update_checkpoints($assignmentid, $checkpoints) {
        global $DB;
        $etinfo = self::get_test_update_info($assignmentid);
        $delcpids = array();
        $deltids = array();
        $delfilepaths = array();
        $cpids = array();
        foreach ($checkpoints as $cp) {
            if (isset($cp->d) || isset($cp->delete)) {
                if ($cp->id[0] != n) { // just in case they are telling me to delete a new cp
                    $delcpids[] = $cp->id;
                    $delfilepaths[] = "g/$assignmentid/$cp->id";
                    $delfilepaths[] = "t/$assignmentid/$cp->id";
                }
                continue;
            }
            $cp->assignmentid = $assignmentid;
            $cpid = array();
            $t = false;
            $tests = array();
            if (isset($cp->tests)) {
                $t = true;
                $tests = $cp->tests;
            }
            $ocpid = $cp->id;
            if ($cp->id[0] == "n") {
                unset($cp->id);
                $cpid['cpid'] = $DB->insert_record("codehandin_checkpoint", $cp, true);
            } else {
                $cpid['cpid'] = $cp->id;
                $DB->update_record("codehandin_checkpoint", $cp, true);
            }
            if ($t) { // has tests
                $cpid['tids'] = self::insert_or_update_tests($assignmentid, $cpid['cpid'], $tests, $etinfo, $deltids, $delfilepaths);
            }
            $cpids[$ocpid] = $cpid;
        }
        $cpids['delfilepaths'] = $delfilepaths; // export the deleted file paths with the cp ids        
//$placeholderscp = array();
        if (!empty($delcpids)) {
            list($inorequalsql, $placeholderscp) = $DB->get_in_or_equal($delcpids, SQL_PARAMS_NAMED);
            $DB->delete_records_select("codehandin_checkpoint", "id $inorequalsql", $placeholderscp);
        }
//$placeholderst = array();
        if (!empty($deltids)) {
            list($inorequalsql2, $placeholderst) = $DB->get_in_or_equal($deltids, SQL_PARAMS_NAMED);
            $DB->delete_records_select("codehandin_test", "id $inorequalsql2", $placeholderst);
        }

        return $cpids;
    }

// remember the submission module submits this as codehandin
    /**
     * updates an exisitng codehandin in or creates one
     * @global type $DB the database object
     * @param  stdClass $codehandin a codehandin object that contains only cps and tests to be updated or inserted
     * @return boolean
     * @throws type
     */
    public static function update_codehandin(stdClass $codehandin) {
        global $DB;
        $info = new stdClass();

        $contextid = $codehandin->contextid;
        unset($codehandin->contextid);

        if (isset($codehandin->clean)) { //($assignmentid, $contextid, $legacy)
            self::clean_assignment($codehandin->id, $contextid, true);
            $info->succeeded = true;
            return $info;
        }

        $fs = get_file_storage();
//        $legacy = $codehandin->legacy;
//        unset($codehandin->legacy);
//remove assign info & update
        $assign = array();
        $a = false;
        if (isset($codehandin->assignname)) {
            $assign['name'] = $codehandin->assignname;
            $a = true;
        }
        if (isset($codehandin->intro)) {
            $assign['intro'] = $codehandin->intro;
            unset($codehandin->intro);
            $a = true;
        }
        if (isset($codehandin->duedate)) {
            $assign['duedate'] = $codehandin->duedate;
            unset($codehandin->duedate);
            $a = true;
        }
        if ($a) { // update the assign info
            $assign['id'] = $codehandin->id;
            $DB->update_record("assign", $assign);
        }

// remove the checkpoints before adding inserting/updating the codehandin
        if (isset($codehandin->checkpoints)) {
            if (!empty($codehandin->checkpoints)) {
                $cpids = self::insert_or_update_checkpoints($codehandin->id, $codehandin->checkpoints); // also inserts its checkpoints
//                return json_encode($cpids);
            }
//            if(!empty($cpids['delfilepaths'])){
//                
//            }
            foreach (self::get_filerecords_by_filepath_prefix($contextid, $codehandin->id, $cpids['delfilepaths']) as $fr) {
                $file = $fs->get_file_instance($fr);
                $file->delete();
            }
            unset($cpids['delfilepaths']);
            unset($codehandin->checkpoints);
        }



//remove new file info & wait for tests to be added        
        if (isset($codehandin->hasfiles)) {
            self::my_extract_to_storage($contextid, COMPONENT, CODEHANDIN_FILEAREA, $codehandin->id, $cpids);
            unset($codehandin->filechanges);
// add files to the filearea
            self::make_assign_zip($contextid, $codehandin->id);
        }

// not sure what to do with these yet
        unset($codehandin->spectestfiles);

        if (isset($codehandin->changed)) {
            if ($codehandin->changed) {
                unset($codehandin->changed);
                if ($DB->record_exists("codehandin", array('id' => $codehandin->id))) {
                    $DB->update_record("codehandin", $codehandin);
                } else {
//                    // must be raw as last var allows inserting the id as well 
//                    $DB->insert_record_raw("codehandin", $codehandin, false, false, true);
                }
            }
        }
        $info->succeeded = true;
//currently no warnings are generated
// $info->warnings = $warnings;
// send to external compile service
//self::send_CHI_Files($codehandin, $contextid);
//$file->add_to_curl_request(&$curlrequest, $key) 
        return $info;
    }

    public static function insert_codehandin(stdClass $codehandin) {
        global $DB;
        if (!$DB->record_exists("codehandin", array('id' => $codehandin->id))) {
            $DB->insert_record_raw("codehandin", $codehandin, false, false, true);
        }
    }

///////////////////////////////////////////////////////////////////////////
//// other functions
///////////////////////////////////////////////////////////////////////////
    public static function fetch_codehandin_service_details() {
        global $DB;
        return $DB->get_record('external_services', array('shortname' => SHORT_SERIVCE_NAME));
    }

///////////////////////////////////////////////////////////////////////////
//// private file file handling methods
///////////////////////////////////////////////////////////////////////////

    /**
     * 
     * @global type $COURSE
     * @return type
     */
    private static function get_file_options() {
        global $COURSE;
//$course = self::assignment->get_course();
        $fileoptions = array(
            'subdirs' => 0,
            'maxbytes' => $COURSE->maxbytes,
            'maxfiles' => 1,
            'accepted_types' => '*',
            'return_types' => FILE_INTERNAL);
        return $fileoptions;
    }

    /**
     * gets all the filespaths that begin with one of the specified paths
     * @global db_Object $DB
     * @param type $contextid
     * @param type $assignmentid
     * @param type $paths
     * @return type
     */
    private static function get_filerecords_by_filepath_prefix($contextid, $assignmentid, $paths) {
        global $DB;
// get the file statement
        $pathOr = "";
        if (!empty($paths)) {
            $npath = count($paths);
            $pathOr = " AND (f.filepath LIKE %$paths[0]";
            for ($i = 1; $i < $npath; $i++) {
                $pathOr . " OR f.filepath LIKE %$paths[i]";
            }
            $pathOr . ")";
        }
        $sql = "SELECT " . self::instance_sql_fields('f', 'r') .
                " FROM {files} f LEFT JOIN {files_reference} r ON f.referencefileid = r.id " .
                " WHERE f.contextid = $contextid" .
                " AND f.component = 'assignsubmission_codehandin'" .
                " AND f.filearea = '" . CODEHANDIN_FILEAREA . "' "
                . $pathOr .
                " AND itemid = $assignmentid ";
        return $DB->get_records_sql($sql);
    }

    /**
     * fetches all test files (I/O/Err) for a particular assignment and creates two zips, one for student testing (all non-grading only tests) and one for grading (all tests)
     * 
     * https://stackoverflow.com/questions/4085333/modifying-a-single-text-file-in-a-zip-file-in-php
     * 
     * @global db_Object $DB the database object
     * @param int $contextid the assignments context 
     * @param int $assignmentid the id of the assignment
     */
    public static function make_assign_zip($contextid, $assignmentid) {
//global $CFG;
// get the files

        $files = self::get_test_grade_area_files($contextid, $assignmentid);

// Zip files.
        $fp = get_file_packer('application/zip');
// zips files and returns the archive
        $a = $fp->archive_to_storage($files->testfiles, $contextid, COMPONENT, CODEHANDIN_ZIP_FILEAREA, $assignmentid, '/', "t$assignmentid.zip");
        $b = $fp->archive_to_storage($files->gradefiles, $contextid, COMPONENT, CODEHANDIN_ZIP_FILEAREA, $assignmentid, '/', "g$assignmentid.zip");
//        $a = $fp->archive_to_pathname($files->testfiles, "$CFG->dataroot/codehandin/t$assignmentid.zip");
//        $b = $fp->archive_to_pathname($files->gradefiles, "$CFG->dataroot/codehandin/g$assignmentid.zip");
        return $a && $b;
    }

    /**
     * Returns files (test and grade) from all codehandin file areas for a specfic assignment
     *
     * @param int $contextid the assignments context 
     * @param int $assignmentid the id of the assignment
     * @return \stdclass containing gradefiles and testfiles attributes which are arrays of grade and test (non grade only files) files
     */
    public static function get_test_grade_area_files($contextid, $assignmentid) {
        global $DB;
        $fs = get_file_storage();

        $sql = "SELECT " . self::instance_sql_fields('f', 'r') .
                " FROM {files} f LEFT JOIN {files_reference} r ON f.referencefileid = r.id " .
                " WHERE f.contextid = " . $contextid .
                " AND f.component = '" . COMPONENT . "'" .
                " AND f.filearea = '" . CODEHANDIN_FILEAREA . "'" .
                " AND f.itemid = $assignmentid";
        $gradefiles = array();
        $testfiles = array();
        $filerecords = $DB->get_records_sql($sql);
        foreach ($filerecords as $filerecord) {
            if ($filerecord->filename === '.') {
                continue;
            }
            $fpath = $filerecord->filepath . $filerecord->filename;
            $file = $fs->get_file_instance($filerecord);
            $gradefiles[$fpath] = $file;
// /g/cpid/testid/o/file.ftype
// /t/cpid/testid/o/file.ftype
            if ($filerecord->filepath[1] == 't') { //substr($filerecord->filepath,2)
                $testfiles[$fpath] = $file;
            }
        }
        $files = new stdclass();
        $files->gradefiles = $gradefiles;
        $files->testfiles = $testfiles;
        return $files;
    }

    /**
     * Get the sql formated fields for a file instance to be created from a
     * {files} and {files_refernece} join.
     * [copied verbatum from file_storage.php where the method is private]
     *
     * @param string $filesprefix the table prefix for the {files} table
     * @param string $filesreferenceprefix the table prefix for the {files_reference} table
     * @return string the sql to go after a SELECT
     */
    private static function instance_sql_fields($filesprefix, $filesreferenceprefix) {
// Note, these fieldnames MUST NOT overlap between the two tables,
// else problems like MDL-33172 occur.
        $filefields = array('contenthash', 'pathnamehash', 'contextid', 'component', 'filearea',
            'itemid', 'filepath', 'filename', 'userid', 'filesize', 'mimetype', 'status', 'source',
            'author', 'license', 'timecreated', 'timemodified', 'sortorder', 'referencefileid');

        $referencefields = array('repositoryid' => 'repositoryid',
            'reference' => 'reference',
            'lastsync' => 'referencelastsync');

// id is specifically named to prevent overlaping between the two tables.
        $fields = array();
        $fields[] = $filesprefix . '.id AS id';
        foreach ($filefields as $field) {
            $fields[] = "{$filesprefix}.{$field}";
        }
        foreach ($referencefields as $field => $alias) {
            $fields[] = "{$filesreferenceprefix}.{$field} AS {$alias}";
        }
        return implode(', ', $fields);
    }

    /**
     * cannot call the inbuild extractor as new cpids and testids need to be replaced with their true ids to build the appropriate file structure
     * 
     */
    public function my_extract_to_storage($contextid, $statusonent, $filearea, $itemid, $cpids) {
        $processed = array();
        global $CFG, $USER; //
        $usercontextid = context_user::instance($USER->id)->id;

        $fs = get_file_storage();
        $file = $fs->get_file($usercontextid, 'user', CODEHANDIN_TEMP_FILEAREA, $itemid, '/', "$itemid.zip");
        if ($file) {
            $contenthash = $file->get_contenthash();
            $userid = null;
            $progress = null;

//return 'appleszz0';
            $l1 = $contenthash[0] . $contenthash[1];
            $l2 = $contenthash[2] . $contenthash[3];
            $archivefile = "$CFG->dataroot/filedir/$l1/$l2/$contenthash";

            check_dir_exists($CFG->tempdir . '/zip');
            $pathbase = '/';
            $ziparch = new zip_archive();
            if (!$ziparch->open($archivefile, file_archive::OPEN)) {
                return false;
            }

// Get the number of files (approx).
            if ($progress) {
                $approxmax = $ziparch->estimated_count();
                $done = 0;
            }
            foreach ($ziparch as $info) {
// Notify progress.
                if ($progress) {
                    $progress->progress($done, $approxmax);
                    $done++;
                }

                $size = $info->size;
                $name = $info->pathname;

                if ($name === '' or array_key_exists($name, $processed)) {
//probably filename collisions caused by filename cleaning/conversion
                    continue;
                }

                if ($info->is_directory) {
                    $newfilepath = $pathbase . $name . '/';
                    $fs->create_directory($contextid, $statusonent, $filearea, $itemid, $newfilepath, $userid);
                    $processed[$name] = true;
                    continue;
                }

                $parts = explode('/', trim($name, '/'));
                $filename = array_pop($parts);

                if (!array_key_exists($parts[1], $cpids)) {
                    $processed[$name] = 'Can not read file from zip archive';
                    break;
                }
                if (!array_key_exists($parts[2], $cpids[$parts[1]]['tids'])) {
                    $processed[$name] = 'Can not read file from zip archive';
                    break;
                }
//            // 0  1  2 3  4    
//            // t/n0/n0/i/i.txt => ["t","n0","n0","i","i.txt"]            
                $parts[2] = $cpids[$parts[1]]['tids'][$parts[2]];
                $parts[1] = $cpids[$parts[1]]['cpid'];
                $filepath = '/' . implode('/', $parts) . '/';
                $name2 = $filepath;

                if ($size < 2097151) {
// Small file.
                    if (!$fz = $ziparch->get_stream($info->index)) {
                        $processed[$name2] = 'Can not read file from zip archive'; // TODO: localise
                        continue;
                    }
                    $content = '';
                    while (!feof($fz)) {
                        $content .= fread($fz, 262143);
                    }
                    fclose($fz);
                    if (strlen($content) !== $size) {
                        $processed[$name2] = 'Unknown error during zip extraction'; // TODO: localise
// something went wrong :-(
                        unset($content);
                        continue;
                    }
                    $file = $fs->get_file($contextid, $statusonent, $filearea, $itemid, $filepath, $filename);
                    if ($file) {
                        if (!$file->delete()) {
                            $processed[$name2] = 'Can not delete existing file'; // TODO: localise
                            continue;
                        }
                    }
                    $file_record = new stdClass();
                    $file_record->contextid = $contextid;
                    $file_record->component = $statusonent;
                    $file_record->filearea = $filearea;
                    $file_record->itemid = $itemid;
                    $file_record->filepath = $filepath;
                    $file_record->filename = $filename;
                    $file_record->userid = $userid;
                    if ($fs->create_file_from_string($file_record, $content)) {
                        $processed[$name2] = true;
                    } else {
                        $processed[$name2] = 'Unknown error during zip extraction'; // TODO: localise
                    }
                    unset($content);
                    continue;
                } else {
// large file, would not fit into memory :-(
                    $tmpfile = tempnam($CFG->tempdir . '/zip', 'unzip');
                    if (!$fp = fopen($tmpfile, 'wb')) {
                        @unlink($tmpfile);
                        $processed[$name2] = 'Can not write temp file'; // TODO: localise
                        continue;
                    }
                    if (!$fz = $ziparch->get_stream($info->index)) {
                        @unlink($tmpfile);
                        $processed[$name2] = 'Can not read file from zip archive'; // TODO: localise
                        continue;
                    }
                    while (!feof($fz)) {
                        $content = fread($fz, 262143);
                        fwrite($fp, $content);
                    }
                    fclose($fz);
                    fclose($fp);
                    if (filesize($tmpfile) !== $size) {
                        $processed[$name2] = 'Unknown error during zip extraction'; // TODO: localise
// something went wrong :-(
                        @unlink($tmpfile);
                        continue;
                    }
                    $file = $fs->get_file($contextid, $statusonent, $filearea, $itemid, $filepath, $filename);
                    if ($file) {
                        if (!$file->delete()) {
                            @unlink($tmpfile);
                            $processed[$name2] = 'Can not delete existing file'; // TODO: localise
                            continue;
                        }
                    }
                    $file_record = new stdClass();
                    $file_record->contextid = $contextid;
                    $file_record->component = $statusonent;
                    $file_record->filearea = $filearea;
                    $file_record->itemid = $itemid;
                    $file_record->filepath = $filepath;
                    $file_record->filename = $filename;
                    $file_record->userid = $userid;
                    if ($fs->create_file_from_pathname($file_record, $tmpfile)) {
                        $processed[$name2] = true;
                    } else {
                        $processed[$name2] = 'Unknown error during zip extraction'; // TODO: localise
                    }
                    @unlink($tmpfile);
                    continue;
                }
            }
            $ziparch->close();

// delete the zip file
            $file = $fs->get_file($usercontextid, 'user', CODEHANDIN_TEMP_FILEAREA, $itemid, '/', "$itemid.zip");
            if ($file) {
                $file->delete();
            }
        }
        return $processed;
    }

    /**
     * remove all the checkpoints and tests of an assignment including all their files
     * 
     * @global moodle_database $DB
     * @param type $assignmentid
     * @param type $contextid
     * @param type $legacy
     */
    private static function clean_assignment($assignmentid, $contextid, $legacy) {
        /* @var $DB moodle_database */
        global $DB;
        $cpids = array_keys($DB->get_records('codehandin_checkpoint', array('assignmentid' => $assignmentid), null, 'id'));
        $DB->delete_records_list("codehandin_test", "checkpointid", $cpids);
        $DB->delete_records("codehandin_checkpoint", array('assignmentid' => $assignmentid));
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, COMPONENT, CODEHANDIN_FILEAREA, $assignmentid);  //
        foreach ($files as $file) {
            $file->delete();
        }
        $file = $fs->get_file(5, $legacy ? 'user' : COMPONENT, CODEHANDIN_TEMP_FILEAREA, $assignmentid, '/', "$assignmentid.zip");
        if ($file) {
            $file->delete();
        }
        $folder = $fs->get_file(5, $legacy ? 'user' : COMPONENT, CODEHANDIN_TEMP_FILEAREA, $assignmentid, '/', ".");
        if ($folder) {
            $folder->delete();
        }
//return "assignmentid=$assignmentid, contextid=$contextid, COMPONENT=" . COMPONENT . ", CODEHANDIN_FILEAREA=" . CODEHANDIN_FILEAREA;
    }

    /**
     * moves the file uploaded into the submission draft area, 
     * saves the submission and submits it for grading if required
     * @param int $assignmentid the id of the assignment
     * @param int $draftid  the draft id of the uploaded file (the itemid 
     *                      returned to the user in the file uploaded reply)
     * @param bool $submit true if submission is required
     * return int the
     */
    public static function set_and_submit_submission($assignmentid, $draftid, $test, $submit) {
        global $USER, $DB;
        
        $output = array();
        $userid = $USER->id;

        $cm = get_coursemodule_from_instance('assign', $assignmentid, 0, false, MUST_EXIST);
        $ctx = context_module::instance($cm->id);
        $assign = new assign($ctx, null, null);
        //$output['contextid'] = $assign->get_context()->id;
        // check can still submit
        if (!$assign->submissions_open($userid)) {
            $output['out'] = get_string('duedatereached', 'assign') . ' or ' . get_string('submissionsclosed', 'assign');
            return $output;
        }

        // get the submission (creates it if one dosen't exist
        if ($assign->get_instance()->teamsubmission) {
            $submission = $assign->get_group_submission($userid, 0, false);
        } else {
            $submission = $assign->get_user_submission($userid, false);
        }
        //return json_encode($submission); // returns somthing like {\"id\":\"4\",\"assignment\":\"12\",\"userid\":\"2\",\"timecreated\":\"1426155594\",\"timemodified\":\"1426941027\",\"status\":\"submitted\",\"groupid\":\"0\",\"attemptnumber\":\"0\"}

        $fileplugin = $assign->get_submission_plugin_by_type('file');
        $data = new stdClass();
        $fileplugin->get_form_elements($submission, new MoodleQuickForm(), $data);
        $draftitemid = $data->files_filemanager;            //$draftitemid = file_get_submitted_draft_itemid('files_filemanager');
        //return json_encode($draftitemid);        

        $output['draftid'] = json_encode($draftitemid);

        // get the id of the uploaded file?
        $rec = $DB->get_record_sql("SELECT id, contextid FROM {files} WHERE userid = $userid AND itemid = $draftid AND filename<>'.' ");
        if (!$rec) { // no file to uploaded submission file to submit?
            $output['out'] = 'there is no file of that draftid';
            return $output;
        }

        if ($draftitemid == 0) {
            $draftitemid = $draftid;
        } else { // files may exist ... delete them?
            $fs = get_file_storage();
            $fs->delete_area_files($rec->contextid, 'user', 'draft', $draftitemid);
            // change the upload's itemid (draftid) to match the existing itemid (draftid)
            $filerecchangedata = new stdClass();
            $filerecchangedata->id = $rec->id;
            $filerecchangedata->itemid = $draftitemid;
            $DB->update_record('files', $filerecchangedata);
        }

        // make some upload data
        $plugindata = array();
        $plugindata['files_filemanager'] = $draftitemid;
        //use mod_assign_external::save_submission_parameters() and $assign->get_submission_plugins()
        $ote_data = array();
        $ote_data['text'] = null;
        $ote_data['format'] = null;
        $ote_data['itemid'] = null;
        $plugindata['onlinetext_editor'] = $ote_data; // ? have to specifiy this even if it isnt on?
        // save the submission data
        if ($assign->get_instance()->requiresubmissionstatement) {
            $data->submissionstatement = 'y'; // just in case tell them we accept the submission statement (submissionstatement just shouldn't be empty)
        }
        $notices = array(); // for output messages from submit for grading
        $data->codehandin_test=$test; // pass it to the codehandin plugin to do the testing
        if ($submit) {
            $assign->save_submission($data, $notices);
        } else {
            $assign->submit_for_grading($data, $notices);
        }
        //mod_assign_external::save_submission($assignmentid, $plugindata); //        $assign->save_submission($data, $output);
//        if ($submit) {             
//            //mod_assign_external::submit_for_grading($assignmentid, true);
//        } 

        if ($test) {
            $output['out'] = $assign->get_submission_plugin_by_type('codehandin')->get_grade_info($submission->id);
        }
        $output['passed'] = true;
        return json_encode($output);
    }

    public static function get_testing_info($assignmentid, $grade, $all) {
        global $DB;
        $sql = "SELECT @rn:=@rn+1 AS rank, t.checkpointid, t.id AS tid,             
            t.description AS tdescription, t.gradeonly, t.status,
            t.runtimeargs AS truntimeargs, t.ioastext, 
            t.input, t.output, t.outputerr, t.retval, t.ordering AS tordering, 
            t.marks AS tmarks FROM (({codehandin} AS c 
            INNER JOIN {codehandin_checkpoint} AS cp ON c.id = cp.assignmentid) 
            INNER JOIN {codehandin_test} AS t ON t.checkpointid = cp.id)
            INNER JOIN (SELECT @rn := 0 FROM DUAL) AS sub WHERE c.id = $assignmentid "
                . ($grade ? "" : " AND t.gradeonly = 1 ")
                . ($all ? "" : " AND t.status > 0 ")
                . " ORDER BY t.id ASC";
        $recs = array_values($DB->get_records_sql($sql));
        $cpTypes = array();
        $checkpoints = array();
        $itcheckpoints = array();
        foreach ($recs as $test) {
            $status = $test->status;
            if ($status == 0 || $status == null) {
                if (!array_key_exists($test->checkpointid, $itcheckpoints)) {
                    $itcheckpoints[$test->checkpointid] = array();
                }
                $itcheckpoints[$test->checkpointid][] = $test->tid;
                continue;
            }
            if (!array_key_exists($test->checkpointid, $checkpoints)) {
                $checkpoints[$test->checkpointid] = array();
                $checkpoints[$test->checkpointid][$test->tid] = array();
            } else if (!array_key_exists($test->tid, $checkpoints[$test->checkpointid])) {
                $checkpoints[$test->checkpointid][$test->tid] = array();
            }
            $type = ($test->gradeonly == 1) ? "t" : "g";
            $checkpoints[$test->checkpointid][$test->tid]['input'] = "$type/$test->checkpointid/$test->tid/$test->input";
            if ($status == 1 || $status == 3) {
                $checkpoints[$test->checkpointid][$test->tid]['output'] = "$type/$test->checkpointid/$test->tid/$test->output";
            }
            if ($status == 2 || $status == 3) {
                $checkpoints[$test->checkpointid][$test->tid]['outputerr'] = "$type/$test->checkpointid/$test->tid/$test->outputerr";
            }
        }
        $cpTypes['cpGood'] = $checkpoints;
        $cpTypes['cpbad'] = $itcheckpoints;
        return json_encode($cpTypes);
    }

    public static function test_submission($grade) {
        return $grade;
    }

//    /**
//     * run a file
//     * @param string $lang the language to run using (PHP, Java, C etc.)
//     * @param string $userTempDir the users tempoary directory containing their files
//     * @param string $runFile the name of the file to run
//     * @param string $runtime_args any runtime argugments that need to be included
//     * @return bool
//     */
//    private static function cmdRun($lang, $userTempDir, $runFile, $runtime_args) {
//        // Should detect windows/cygwin/linux
//        $runscript = '';
//        if (strtoupper(substr(PHP_OS, 0, 3)) === "WIN") {
//            // Look for cygwin
//            // Should probably use the registry
//            // will fail if cygwin isn't installed on windows
//            $runscript = "C:\\cygwin\\bin\\timeout.exe 10";
//            $ext = ".exe";
//        } else {
//            // We're running something *nix
//            // we can use ulimit to limit memory use, not sure  about windows
//            // limit to 64MB of virtual memory
//            // 5s of runtime, could possibly change this limit for each test
//            // may want to use ulimit to limit the cpu time
//            // Okay, use a specific user, if it doesn't exist, return an error telling
//            // the user to set it up
//            /*  $retval = 0;
//              exec("id moodle_sandbox",[],$retval);
//              if($retval !== 0) {
//              return ['error'=>"Sandbox user does not exist. Please contact administrator"];
//              }
//              ulimit -v 65536; / */
//            $runscript = '/usr/bin/timeout 10'; //'iptables -A OUTPUT -m owner --cmd-owner $runFile -j DROP 
//            $ext = "";
//        }
//        // Check the runscript exists
//        /*
//          if(!file_exists($runscript)) {
//          return ['error'=>"Sandbox utility does not exist. Please contact system administrator"];
//          }
//         */
//        // In future, update to:  return this::prepareCommand($DB->get_record("codehandin_languages",["id"=>$lang])["runCommand"]);
//
//        switch ($lang) {
//            case "java":
//                return "$runscript java -cp {$userTempDir} {$runFile} {$runtime_args}";  //-Djava.security.policy=./runpolicy.java.policy -Djava.security.manager";
//            case "c":
//            case "c++":
//                return "$runscript {$userTempDir}{$runFile}{$ext} {$runtime_args}";
//            case "octave":
//                return "$runscript  octave --no-window-system -q {$runFile} {$runtime_args}";
//            case "matlab":
//                $runFile = explode('.', $runFile)[0];
//                // May want to chomp off the copyright message here
//                return "$runscript matlab -nodisplay -nojvm -nosplash -r \"{$runFile} {$runtime_args}; exit\"";
//            case "python3":
//                return "$runscript python3 {$runFile} {$runtime_args}";
//            case "python2":
//                return "$runscript python2.7 {$runFile} {$runtime_args}";
//            case "javascript":
//                return "$runscript rhino {$runFile} {$runtime_args}";
//            case "prolog":
//                return "$runscript swipl {$runFile} {$runtime_args}";
//            # case "R": // Need to look into how to run R
//            #     return "$runscript R -q -f {$runfile} {$runtime_args}";
//            default:
//                return false;
//        }
//    }
//
//    /**
//     * test a file
//     * @global config_Object $CFG the moodle configuration file
//     * @global user_Object $USER the user object storing the users details
//     * @global db_Object $DB the database object that enables access to the underlying database
//     * @param int $assignmentid the assignment id
//     * @param type $file the file object returned by self::getFile
//     * @param bool $continue continue with more tests when a test fails
//     * @param bool $assessment if this is for assessment purposes
//     * @return an array of elements 
//     */
//    private static function test($assignmentid, $file, $continue, $assessment) {
//        global $CFG, $USER, $DB;
//        //$context = get_context_instance(CONTEXT_MODULE, $USER->id);
//        $context = context_user::instance($USER->id);
//        self::validate_context($context);
//        // We should check if this student is even in a topic with this codehandin. if they are being assessed, check if they aren't
//        // Submitting again when they shouldn't
//        $userTempDir = $CFG->dataroot . '/temp/' . $USER->id . '/';
//        // Make sure the user has a dir in the temp dir
//        if (!file_exists($userTempDir)) {
//            mkdir($userTempDir, 0777, true);
//        }
//
//        if (!$file) {
//            return ['type' => 'internal', 'error' => "Could not retrieve file. Make sure you own the file"];
//        }
//        $testFile = $userTempDir . $file->get_filename();
//        $file->copy_content_to($testFile);
//        // If the mimetype is a zip file, unzip
//        // Should get the language of the assignment and 
//        // use the appropriate compiler/runtime
//
//        $lang = $DB->get_record('codehandin', array('id' => $assignmentid))->language;
//        // If the file is a zip, we should probably unzip it
//        if ($file->get_mimetype() === "application/zip") {
//            $zip = new ZipArchive;
//            $res = $zip->open($testFile);
//            if ($res === TRUE) {
//                $zip->extractTo($userTempDir);
//                $zip->close();
//                $fromZip = true;
//            } else {
//                return ['error' => "Could not unzip file {$file->get_filename()}", 'type' => 'internal'];
//            }
//        } elseif ($file->get_mimetype() === "text/plain") {
//            $fromZip = false;
//        } else {
//            return ['error' => "Could not determine the filetype"]; // prevent the user from trying to compile non plaintext files
//        }
//        $cmd = self::cmdCompile($lang, $userTempDir);
//        if ($cmd === false) {
//            return ['error' => "Langauge $lang doesn't exist. Please contact the teacher"];
//        }
//        putenv("PATH=/usr/local/bin:/usr/bin:/bin");
//        $retval = 0;
//        $output = [];
//        exec($cmd . ' 2>&1', $output, $retval);
//
//        //If return value from compiler isn't 0, we stop        
//        if ($retval != 0) {
//            // Clean up generated files in the user's dir
//            cleanup($USER->id);
//            return ['type' => 'compiler',
//                'error' => "Your code did not compile or the compiler returned a non-zero value",
//                'cmd' => $cmd,
//                'path' => getenv('PATH'),
//                'retval' => $retval,
//                'output' => implode("\n", $output)
//            ];
//        }
//        $sql = "SELECT {codehandin_test}.id as tid,
//                      {codehandin_checkpoint}.id AS cid, 
//                      {codehandin_test}.input, 
//                      {codehandin_test}.output, 
//                      {codehandin_test}.runtime_args,
//                      {codehandin_test}.outputerr,
//                      {codehandin_test}.retval
//                 FROM {codehandin_checkpoint}, 
//                      {codehandin_test}, 
//                      {codehandin}
//                WHERE {codehandin}.id = ?
//                  AND {codehandin_checkpoint}.assignmentid = {codehandin}.id 
//                  AND {codehandin_test}.checkpointid = {codehandin_checkpoint}.id";
//        // If a teacher tests this it should use all tests, teachers can't submit though
//        if (has_capability('mod/codehandin:addinstance', $context)) {
//            
//        } elseif (!$assessment) {
//            $sql .= " AND {codehandin_test}.assessment = 0";
//        }
//        $sql .= " ORDER BY {codehandin_checkpoint}.ordering ASC, tid, cid";
//        $inputs = $DB->get_records_sql($sql, [$assignmentid]);
//        $results = [];
//
//        foreach ($inputs as $k => $i) {
//            // Use the policy file to prevent running malicious code
//            // perhaps we could have an argument to specify the main class?
//            // This would need to be stored when running an assessment
//            $runClass = $fromZip ? "Main" : explode('.', $file->get_filename())[0];
//            if ($lang == "matlab") {
//                $runClass = $file->get_filename();
//            }
//            $cmd = self::cmdRun($lang, $userTempDir, $runClass, $i->runtime_args);
//            // Some kind of error occurred when making the run cmd
//            if (is_array($cmd)) {
//                return $cmd;
//            }
//            // Time should be in the db and specified per test
//            $desc = array(
//                0 => array('pipe', 'r'),
//                1 => array('pipe', 'w'),
//                2 => array('pipe', 'r')
//            );
//            $proc = proc_open($cmd, $desc, $pipes);
//            if (is_resource($proc)) {
//                fwrite($pipes[0], $i->input);
//                fclose($pipes[0]);
//                // This buffer may become large
//                // so we may want to chunk it
//                // and periodically check if it exceeds the expected
//                // output length
//                $output = stream_get_contents($pipes[1]);
//                fclose($pipes[1]);
//                $outputerr = stream_get_contents($pipes[2]);
//                fclose($pipes[2]);
//                $retval = proc_close($proc);
//            } else {
//                return ['error' => "Could not run cmd: {$cmd}"];
//            }
//            // timeout returns 124 on timeout
//            if ($retval == "124") {
//                return ["type" => "runtime", "error" => "Time limit exceeded"];
//            }
//            // need to ensure that the test data is split with \n
//            $output = self::prepareOutput($output);
//            $required_output = self::prepareOutput($i->output);
//            $outputerr = self::prepareOutput($outputerr);
//            $required_outputerr = self::prepareOutput($i->outputerr);
//            // Might need to organise newlines/trailing whitespace depending on OS
//            $pass = $output == $required_output && $outputerr == $required_outputerr && $retval == $i->retval;
//            $test_result = ['checkpointid' => $i->cid, 'test_id' => $i->tid, 'pass' => $pass];
//            // When under assessment, the student is not shown the required input/outputs
//            if (!$pass && !$assessment) {
//                $test_result['cmd'] = $cmd;
//                $test_result['input'] = $i->input;
//                $test_result['given_output'] = $output;
//                $test_result['required_output'] = $required_output;
//                $test_result['required_outputerr'] = $required_outputerr;
//                $test_result['outputerr'] = $outputerr;
//                $test_result['retval'] = $retval;
//                $test_result['required_retval'] = $i->retval;
//                if (!$continue) {
//                    $results[] = $test_result;
//                    break;
//                }
//            }
//            $results[] = $test_result;
//            // If we fail a test during assessment we don't continue
//            if (!$pass && $assessment) {
//                break;
//            }
//        }
//        cleanup($USER->id);
//
//        $cps = [];
//        foreach ($inputs as $i) {
//            $cps[$i->cid] = 0;
//        }
//        $cp_total = count($cps);
//        // Map of checkpointid -> pass
//        $checkpoints = [];
//        foreach ($results as $r) {
//            if (!array_key_exists($r['checkpointid'], $checkpoints)) {
//                $checkpoints[$r['checkpointid']] = $r['pass'];
//            } else {
//                $checkpoints[$r['checkpointid']] &= $r['pass'];
//            }
//        }
//        $checkpoints_passed = count(array_filter($checkpoints));
//        return ['assessment' => $assessment, 'checkpoints' => $checkpoints, 'test_results' => $results, 'checkpoints_passed' => $checkpoints_passed, 'checkpoints_total' => $cp_total, 'grade' => $checkpoints_passed / $cp_total * 100];
//    }
//
//    /**
//     * returns the results of running the tests for a specific assignment 
//     * @global user_Object $USER the user object storing the users details
//     * @param int $assignmentid the id of the assignment
//     * @param int $file_id the id fo the file to test
//     * @param bool $continue continue if the test fails
//     * @return json_Object containing the test results
//     */
//    public static function test_assignment($assignmentid, $file_id, $continue) {
//        global $USER; //, $DB, $CFG;
//        //Parameter validation
//        //REQUIRED
//        //$params = self::validate_parameters(self::test_assignment_parameters(), array('assignmentid' => $assignmentid, 'file_id' => $file_id, 'continue' => $continue));
//        //Context validation
//        //OPTIONAL but in most web service it should present
//        //$context = get_context_instance(CONTEXT_MODULE, $USER->id);
//        $context = context_user::instance($USER->id);
//        self::validate_context($context);
//
//        if (!has_capability('mod/codehandin:submit', $context) || has_capability('mod/codehandin:addinstance', $context)) {
//            return json_encode(['error' => "You do not have permissions to test this."]);
//        }
//        $file = self::getFile($file_id);
//        $result = self::test($assignmentid, $file, $continue, false);
//        $file->delete();
//        return json_encode($result);
//    }
}

class Warning {

    public $item;
    public $itemid;
    public $warningcode;
    public $message;

    function __construct($item, $itemid, $warningcode, $message) {
        $this->item = $item;
        $this->itemid = $itemid;
        $this->warningcode = $warningcode;
        $this->message = $message;
    }

}
