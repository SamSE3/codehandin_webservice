<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of TempFunctions
 *
 * @author SuperNova
 */
class TempFunctions {

    //$file->extract_to_storage($packer, $contextid, COMPONENT, CODEHANDIN_FILEAREA, $assignmentid, '/');
// stored_file.php

    public function extract_to_storage(file_packer $packer, $contextid, $component, $filearea, $itemid, $pathbase, $userid = null, file_progress $progress = null) {
        $archivefile = $this->get_content_file_location();
        return $packer->extract_to_storage($archivefile, $contextid, $component, $filearea, $itemid, $pathbase, $userid, $progress);
    }

    protected function get_content_file_location() {
        $this->sync_external_file();
        return $this->get_pathname_by_contenthash();
    }

// protected ... so call parts individually


    public function sync_external_file() {
        if (!empty($this->repository)) {
            $this->repository->sync_reference($this);
        }
    }

    protected function get_pathname_by_contenthash() {
        // Detect is local file or not.
        $contenthash = $this->file_record->contenthash;
        $l1 = $contenthash[0] . $contenthash[1];
        $l2 = $contenthash[2] . $contenthash[3];
        return "$this->filedir/$l1/$l2/$contenthash";
    }

// again protected so call parts locally


    public function get_contenthash() {
        $this->sync_external_file();
        return $this->file_record->contenthash;
    }

    // replaced the need for a seperate sync_external_file() call
// so write a new function 

    
}
