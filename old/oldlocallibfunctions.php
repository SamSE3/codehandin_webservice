<?php


define('ASSIGNSUBMISSION_CHI_SPECTEST_FILEAREA', 'submission_chi_spectest');
define('ASSIGNSUBMISSION_CHI_SPECGRADE_FILEAREA', 'submission_chi_specgrade');
define('ASSIGNSUBMISSION_CHI_INPUT_FILEAREA', 'submission_chi_input');
define('ASSIGNSUBMISSION_CHI_OUTPUT_FILEAREA', 'submission_chi_output');
define('ASSIGNSUBMISSION_CHI_OUTPUTERR_FILEAREA', 'submission_chi_outputerr');

/**
 * holds functions that have been written but are not currently used by the locallib 
 */
class unusedfunctions {

    
    /**
     * 
     * @return \external_function_parameters
     */
    public static function fetch_file_list_parameters() {
        return new external_function_parameters(
                array('assignmentid' => new external_value(PARAM_INT, 'Assignment identifier')
                )
        );
    }

    /**
     * list the files belonging to a user
     * @global user_Object $USER
     * @return type
     */
    public static function fetch_file_list($assignmentid) {
        global $USER; //, $DB;
////        $params = self::validate_parameters(self::get_grades_parameters(), ['assignmentids' => [$assignmentid]]);
////Parameter validation
////REQUIRED
////$params = self::validate_parameters(self::list_files_parameters(), array());
////Context validation
////OPTIONAL but in most web service it should present
////$context = get_context_instance(CONTEXT_MODULE, $USER->id);
////        $context = context_user::instance($USER->id);
////        self::validate_context($context);
////        $fs = get_file_storage();
////        $files = $fs->get_area_files('22', 'user', 'private', false, "itemid, filepath, filename", true);
////        ob_start();
////        var_dump($files);
////        $result = ob_get_clean();
////        return $result;
//        return json_encode(['error' => "this function is not supported yet"]);
    }

    /**
     * Returns the list of files belonging to the user
     * @return external_descriptioniption
     */
    public static function fetch_file_list_returns() {
        return new external_single_structure(
                array('status' => new external_value(PARAM_INT, 'status value', VALUE_REQUIRED),
            'warnings' => new external_single_structure(
                    array(
                'item' => new external_value(PARAM_TEXT, 'item', VALUE_OPTIONAL),
                'itemid' => new external_value(PARAM_INT, 'itemid', VALUE_OPTIONAL),
                'warningcode' => new external_value(PARAM_ALPHANUM, 'number or warning code'),
                'message' => new external_value(PARAM_TEXT, 'untranslated english message to explain the warning')
                    ), 'list of warnings', VALUE_OPTIONAL)
                )
        );
    }
    
    /**
     * check if the current user can access the assignment
     * @global user_Object $USER the user object storing the users details
     * @param int $assignmentid the id of the assignment 
     * @return \stdClass|boolean true if can access the assignment or an error if cannot
     */
    private static function canAccessAssignment($assignmentid = false) {
        global $DB;
        $placeholders = array();
        list($inorequalsql, $placeholders) = $DB->get_in_or_equal(enrol_get_my_courses(), SQL_PARAMS_NAMED);
        if (!$DB->record_exists_sql(
                        "SELECT {codehandin}.id FROM {assign}, {codehandin} "
                        . "WHERE {assign}.id = {codehandin}.id "
                        . (is_number($assignmentid) ? "AND {assign}.id = $assignmentid " : "")
                        . "AND {assign}.course " . $inorequalsql)) {
            $out = array();
            $error = array();
            $error['item'] = 'fetch_assignment_files';
            $error['assignmentid'] = $assignmentid;
            $error['warningcode'] = 'noCHIorAccess';
            $error['message'] = 'the id does not exist or you do not have permission to access this assignment';
            $out['error'] = $error;
            return $out;
        }
        return true;
    }    
    
    /**
     * fetches all test files (I/O/Err) for a particular assignment and creates two zips, one for student testing (all non-grading only tests) and one for grading (all tests)
     * @global db_Object $DB the database object
     * @param int $contextid the assignments context 
     * @param int $assignmentid the id of the assignment
     */
    public static function make_assign_zip($contextid, $assignmentid) {
        global $CFG;
// get the files
        $files = self::get_multiple_area_files($contextid, $assignmentid);

// Zip files.
        $fp = get_file_packer('application/zip');

// zips files and returns the archive
        $a = $fp->archive_to_pathname($files->testfiles, "$CFG->dataroot\\codehandin\\t$assignmentid.zip");
        $b = $fp->archive_to_pathname($files->gradefiles, "$CFG->dataroot\\codehandin\\g$assignmentid.zip");
        return $a && $b;
    }

    /**
     * 
     * @global db_Object $DB
     * @param type $assignmentid
     * @return \stdClass
     */
    private static function get_test_ids($assignmentid) {
        global $DB;
        $alltestids = array();
        $gradeonlytestids = array();
        $sql = "SELECT {codehandin_test}.id as id, {codehandin_test}.gradeonly "
                . "FROM {codehandin},{codehandin_checkpoint},{codehandin_test} "
                . "WHERE {codehandin}.id = $assignmentid "
                . "AND {codehandin_checkpoint}.assignmentid = {codehandin}.id "
                . "AND {codehandin_checkpoint}.id = {codehandin_test}.checkpointid ";
        $tests = $DB->get_records_sql($sql);
        foreach ($tests as $t) {
            $alltestids[] = $t->id;
            if ($t->gradeonly) {
                $gradeonlytestids[] = $t->id;
            }
        }
        $testids = new stdClass();
        $testids->alltestids = $alltestids;
        $testids->gradeonlytestids = $gradeonlytestids;
        return $testids;
    }

    /**
     * Unzip 
     * [modified version of zip_packer's extract_to_storage function]
     * 
     * @todo MDL-31048 localise messages
     * @param int $contextid the assignments context 
     * @param int $assignmentid the id of the assignment
     * @return array|bool list of processed files; false if error
     */
    public static function extract_assign_zip_to_storage($contextid, $assignmentid) {
        global $CFG;

        $chi_fileareas = get_file_areas();
        check_dir_exists($CFG->tempdir . '/zip');
        $fs = get_file_storage();
        $processed = array();

        $ziparch = new zip_archive();
        if (!$ziparch->open("$CFG->tempdir\\codehandin\\$assignmentid.zip", file_archive::OPEN)) {
            return false;
        }

        foreach ($ziparch as $info) {
            $size = $info->size;
            $name = $info->pathname;
            if ($name === '' or array_key_exists($name, $processed) or $info->is_directory) {
//probably filename collisions caused by filename cleaning/conversion
// and drop directories
                continue;
            }

            $parts = explode('/', trim($name, '/'));
            $filename = array_pop($parts);
            $parts = explode('_', $parts[0]); // i_0  = > {i,0}
            if ($parts == 'i') {
                $ft = 0;
            } else if ($parts == 'o') {
                $ft = 1;
            } else {
                $ft = 2;
            }
            $testid = $parts[1];

            if ($size < 2097151) {
// Small file.
                if (!$fz = $ziparch->get_stream($info->index)) {
                    $processed[$name] = 'Can not read file from zip archive'; // TODO: localise
                    continue;
                }
                $content = '';
                while (!feof($fz)) {
                    $content .= fread($fz, 262143);
                }
                fclose($fz);
                if (strlen($content) !== $size) {
                    $processed[$name] = 'Unknown error during zip extraction'; // TODO: localise
// something went wrong :-(
                    unset($content);
                    continue;
                }
                self::create_files($fs, $contextid, $chi_fileareas[$ft], $testid, $filename, $processed, true, $content);
                unset($content);
            } else {
// large file, would not fit into memory :-(
                $tmpfile = tempnam($CFG->tempdir . '/zip', 'unzip');
                if (!$fp = fopen($tmpfile, 'wb')) {
                    @unlink($tmpfile);
                    $processed[$name] = 'Can not write temp file'; // TODO: localise
                    continue;
                }
                if (!$fz = $ziparch->get_stream($info->index)) {
                    @unlink($tmpfile);
                    $processed[$name] = 'Can not read file from zip archive'; // TODO: localise
                    continue;
                }
                while (!feof($fz)) {
                    $content = fread($fz, 262143);
                    fwrite($fp, $content);
                }
                fclose($fz);
                fclose($fp);
                if (filesize($tmpfile) !== $size) {
                    $processed[$name] = 'Unknown error during zip extraction'; // TODO: localise
// something went wrong :-(
                    @unlink($tmpfile);
                    continue;
                }
                self::create_files($fs, $contextid, $chi_fileareas[$ft], $testid, $filename, $processed, false, $tmpfile);
                @unlink($tmpfile);
            }
        }
        $ziparch->close();
        return $processed;
    }

    /**
     * Create a 
     *
     * @todo MDL-31048 localise messages
     * @param file_storage $fs the file storage object used to create/lookup files
     * @param int $contextid context ID
     * @param string $filearea file area
     * @param int $testid the id of the test the file belongs to
     * @param string $filename the name of the file
     * @param array $processed a list of the files processed
     * @param bool $smallfile true if a small file and reading content as a 
     * string in mem or false if a large file and reading from the file 
     * temporally extracted to the disk
     * @return int 0 on success and 1 on failure
     */
    private function create_files($fs, $contextid, $filearea, $testid, $filename, $processed, $smallfile, $tmpfileorcontent) {
        global $USER;
        if ($file = $fs->get_file($contextid, 'assignsubmission_codehandin', $filearea, $testid, '/', $filename)) {
            if (!$file->delete()) {
                $processed[$name] = 'Can not delete existing file'; // TODO: localise
                return 1;
            }
        }
        $file_record = new stdClass();
        $file_record->contextid = $contextid;
        $file_record->component = 'assignsubmission_codehandin';
        $file_record->filearea = $filearea;
        $file_record->itemid = $testid;
        $file_record->filepath = '/';
        $file_record->filename = $filename;
        $file_record->userid = $USER->id;

        if ($smallfile ? $fs->create_file_from_string($file_record, $tmpfileorcontent) :
                        $fs->create_file_from_pathname($file_record, $tmpfileorcontent)) {
            $processed[$name] = true;
        } else {
            $processed[$name] = 'Unknown error during zip extraction'; // TODO: localise
        }
// add a directory file if it does not alread exist
        if (!$fs->file_exists($contextid, 'assignsubmission_codehandin', $filearea, $testid, '/', '.')) {
            create_directory($contextid, 'assignsubmission_codehandin', $filearea, $testid, '/');
            if ($fs->create_file_from_string($file_record, $tmpfileorcontent)) {
                $processed[$name] = true;
            } else {
                $processed[$name] = 'Unknown error creating dir root file'; // TODO: localise
            }
        }
        return 0;
    }

    /**
     * Returns files from all codehandin file areas for a specfic assignment
     *
     * @param int $contextid the assignments context 
     * @param int $assignmentid the id of the assignment
     * @return \stdclass containing gradefiles and testfiles attributes which are arrays of grade and test (non grade only files) files
     */
    public static function get_multiple_area_files($contextid, $assignmentid) {
        global $DB;
        $fs = get_file_storage();
        // get the test ids
        $testids = self::get_test_ids($assignmentid);

        $placeholders = array();
        list($inorequalsql, $placeholders) = $DB->get_in_or_equal($testids->alltestids, SQL_PARAMS_NAMED);
        $sql = "SELECT " . self::instance_sql_fields('f', 'r') .
                " FROM {files} f LEFT JOIN {files_reference} r ON f.referencefileid = r.id " .
                " WHERE f.contextid = " . $contextid .
                " AND f.component = 'assignsubmission_codehandin'" .
                " AND f.filearea = :filearea" .
                " AND f.itemid " . $inorequalsql;

        $gradefiles = array();
        $testfiles = array();
        $ft = 0;
        $fts = array('i_', 'o_', 'e_');
        foreach (self::get_file_areas() as $fa) {
            $placeholders['filearea'] = $fa;
            $filerecords = $DB->get_records_sql($sql, $placeholders);
            foreach ($filerecords as $filerecord) {
                if ($filerecord->filename === '.') {
                    continue;
                }
                $dirPath = '/' . $fts[$ft] . $filerecord->itemid . '/' . $filerecord->filename;
                $file = $fs->get_file_instance($filerecord);
                $gradefiles[$dirPath] = $file;
                if (!in_array($filerecord->itemid, $testids->gradeonlytestids)) {
                    $testfiles[$dirPath] = $file;
                }
            }
            $ft++;
        }
        $files = new stdclass();
        $files->gradefiles = $gradefiles;
        $files->testfiles = $testfiles;
        return $files;
    }
       
    /**
     * 
     * @global type $DB
     * @param type $itemid the ids of the files
     * @param type $filearea the file area
     * @param type $contextid the context of the assignment 
     * @return int|boolean the id of the records otherwise false
     */
    private static function get_first_area_file_id($itemid, $filearea, $contextid) {
        global $DB;

        $rec = $DB->get_field('files', 'id', array('contextid' => $contextid,
            'component' => 'assignsubmission_codehandin', 'filearea' => $filearea,
            'itemid' => $itemid), IGNORE_MULTIPLE);
        if (!$rec) {
            return false;
        }
        return (int) $rec;
    }    
    
    //    private static function make_file($contextid, $filename, $filepath, $assignmentid) {
//        $fs = get_file_storage();
//        if (!$file = $fs->get_file($contextid, SHORT_SERIVCE_NAME, ASSIGNSUBMISSION_CHI_ASSIGNZIP_FILEAREA, $assignmentid, $filepath, $filename)) {
//            $file = $fs->create_file_from_pathname(
//                    array('contextid' => $contextid, 'component' => SHORT_SERIVCE_NAME,
//                'filearea' => ASSIGNSUBMISSION_CHI_ASSIGNZIP_FILEAREA,
//                'itemid' => $assignmentid, 'filepath' => $filepath,
//                'filename' => $filename), $this->testfile);
//        }
//    }

    /**
     * get all the files in a particular codehandin
     * @param stdclass $codehandin
     * @return an array of files
     */
    private static function get_CHI_files($codehandin, $contextid) {
        $fs = get_file_storage();
        $files = array();
        self::addFile(self::create_CHI_Info_File($codehandin, $contextid)->id, $files, $fs);
        self::addFile($codehandin->studentfile, $files, $fs, "s0");
        self::addFile($codehandin->assessmentfile, $files, $fs, "a0");
        foreach ($codehandin->checkpoints as $cp) {
            foreach ($cp->tests as $test) {
                if (!$cp->ioastext) {
                    addFile($test->input, $files, $fs, "i" . $test->ordering);
                    addFile($test->output, $files, $fs, "o" . $test->ordering);
                    if (isset($test->outputerr)) {
                        addFile($test->outputerr, $files, $fs, "e" . $test->ordering);
                    }
                }
            }
        }
        return $files;
    }

    /**
     * 
     * @param type $codehandin
     * @param type $contextid
     */
    private static function create_CHI_Info_File($codehandin, $contextid) {
        $fs = get_file_storage();
        $filerecord = new stdClass();
        $filerecord->contextid = $contextid;
        $filerecord->component = 'assignsubmission_codehandin';
        $filerecord->itemid = $codehandin->id;
        $filerecord->filearea = ASSIGNSUBMISSION_CHI_FILEAREA;
        $filerecord->filepath = '/';
        $filerecord->filename = 'chiinf.txt';
        $fs->create_file_from_string($filerecord, $codehandin);
    }

    /**
     * 
     * @param type $codehandin
     * @param type $contextid
     */
    private static function send_CHI_Files($codehandin, $contextid) {
        $files = self::get_CHI_Files($codehandin, $contextid);
        $zipfile = self::pack_files($files, $codehandin->id);
        self::sendZipFiles($zipfile);
    }

    /**
     * Generate zip file from array of given files.
     *
     * @param array $files - array of files to pass into archive_to_pathname.
     *                       This array is indexed by the final file name and each
     *                       element in the array is an instance of a stored_file object.
     * @return path of temp file - note this returned file does
     *         not have a .zip extension - it is a temp file.
     */
    private static function pack_files($files, $id) {
        global $CFG;
        // Create path for new zip file.
        $tempzip = tempnam($CFG->tempdir . '/codehandin/', $id);
        // Zip files.
        $zipper = new zip_packer();
        if ($zipper->archive_to_pathname($files, $tempzip)) {
            return $tempzip;
        }
        return false;
    }

    /**
     * 
     * @param type $zipfile
     */
    private static function send_Zip_Files($zipfile) {
        // use the CURL (Client URL PHP libary)
        // http://hayageek.com/php-curl-post-get/
    }

    /**
     * 
     */
    private static function make_User_Dir() {
        global $CFG, $USER;
        $userTempDir = $CFG->dataroot . 'CHI/temp' . $USER->id . '/';
        // Make sure the user has a dir in the temp dir
        if (!file_exists($userTempDir)) {
            mkdir($userTempDir, 0777, true);
        }
    }

    //    /**
//     * 
//     * @return \external_function_parameters
//     */
//    public static function submit_assignment_parameters() {
//        return new external_function_parameters(
//                array('assignmentid' => new external_value(PARAM_INT, 'Assignment identifier'),
//            'file_id' => new external_value(PARAM_INT, 'ID of the file we want to test'),
//            'reallysubmit' => new external_value(PARAM_BOOL, 'Submit the file even when it doesn\'t compile')
//                )
//        );
//    }
//
//    /**
//     * submit an assignment to be graded
//     * @global user_Object $USER the user object storing the users details
//     * @global db_Object $DB the database object that enables access to the underlying database
//     * @param int $assignmentid the id of the assignment to submit the assignment to
//     * @param int $file_id the id of the file to be submitted
//     * @param bool $reallysubmit can submit non compiling assignments
//     * @return json_Object returns a json object containing the result
//     */
//    public static function submit_assignment($assignmentid, $file_id, $test = true, $reallysubmit = false) {
//        $mae = new mod_assign_external();
//        $mae->save_submission($assignmentid);
//        submit_for_grading($assignmentid, true);
//    }
//
//    /**
//     * Returns descriptioniption of method result value
//     * @return external_descriptioniption
//     */
//    public static function submit_assignment_returns() {
//        return new external_value(PARAM_RAW, 'The results of the public and private tests, mapping from testId to result (true|false)');
//    }
//    
//    removed from update codehandin ... files to be implemented later
    ////        if ($codehandin->spectestonly) {
//            // save the specific test files
//            if (isset($codehandin->draftspectestid)) {
//                file_save_draft_area_files($codehandin->draftspectestid, $contextid, 'assignsubmission_codehandin', ASSIGNSUBMISSION_CHI_SPECTEST_FILEAREA, $codehandin->id, $fileoptions);
////                $spectestid = self::get_first_area_file_id($codehandin->id, ASSIGNSUBMISSION_CHI_SPECTEST_FILEAREA, $contextid);
////                if ($spectestid) {
////                    $codehandin->spectest = $spectestid;
////                }
//            }
//            if (isset($codehandin->draftspectestassessmentid)) {
//                file_save_draft_area_files($codehandin->draftspectestassessmentid, $contextid, 'assignsubmission_codehandin', ASSIGNSUBMISSION_CHI_SPECTESTASSESSMENT_FILEAREA, $codehandin->id, $fileoptions);
////                $spectestassessmentid = self::get_first_area_file_id($codehandin->id, ASSIGNSUBMISSION_CHI_SPECTESTASSESSMENT_FILEAREA, $contextid);
////                if ($spectestassessmentid) {
////                    $codehandin->spectestassessment = $spectestassessmentid;
////                }
//            }
//        //}
}
//        $conditions = array('contextid' => $usercontextid, 'component' => 'user', 'filearea' => CODEHANDIN_TEMP_FILEAREA, 'itemid' => $itemid, 'filepath' => '/', 'filename' => "$itemid.zip");
//        $contenthash = $DB->get_record("files", $conditions, $fields = 'contenthash', $strictness = IGNORE_MISSING)->contenthash;