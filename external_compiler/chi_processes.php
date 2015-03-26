<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * keeps track of all codehandin (chi) processes
 *
 * @author SuperNova
 */
class CHI_processes {

    static public function add($userid, $server, $chiid, $adminticket) {
        global $DB;
        $info = new stdClass();
        $info->userid = $userid;
        $info->server = $server;
        $info->chiid = $chiid;
        $info->start_time = time();
        $info->adminticket = $adminticket;
        vpl_truncate_RUNNING_PROCESSES($info);
        return $DB->insert_record('codehandin_processes', $info);
    }

    /*
     * get a process, a user can only run one process at a time
     */

    static public function get($userid) {
        global $DB;
        return $DB->get_record('codehandin_processes', array('userid' => $userid));
    }

    static public function remove($userid) {
        global $DB;
        $DB->delete_records('codehandin_processes', array('userid' => $userid));
    }

}
