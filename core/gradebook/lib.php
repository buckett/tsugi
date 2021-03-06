<?php

require_once "classes.php";

function load_grades($pdo) {
    global $CFG, $USER, $LINK;
    $LTI = lti_require_data(array('link_id', 'role'));
    if ( ! $USER->instructor ) die("Requires instructor role");
    $p = $CFG->dbprefix;

    // Get basic grade data
    $stmt = pdo_query_die($pdo,
        "SELECT R.result_id AS result_id, R.user_id AS user_id,
            grade, note, R.json AS json, R.updated_at AS updated_at, displayname, email
        FROM {$p}lti_result AS R
        JOIN {$p}lti_user AS U ON R.user_id = U.user_id
        WHERE R.link_id = :LID
        ORDER BY updated_at DESC",
        array(":LID" => $LINK->id)
    );
    return $stmt;
}

// $detail is either false or a class with methods
function show_grades($stmt, $detail = false) {
    echo('<table border="1">');
    echo("\n<tr><th>Name<th>Email</th><th>Grade</th><th>Date</th></tr>\n");

    while ( $row = $stmt->fetch(PDO::FETCH_ASSOC) ) {
        echo('<tr><td>');
        if ( $detail ) {
            $detail->link($row);
        } else {
            echo(htmlent_utf8($row['displayname']));
        }
        echo("</td>\n<td>".htmlent_utf8($row['email'])."</td>
            <td>".htmlent_utf8($row['grade'])."</td>
            <td>".htmlent_utf8($row['updated_at'])."</td>
        </tr>\n");
    }
    echo("</table>\n");
}

// Not cached
function load_grade($pdo, $user_id=false) {
    global $CFG, $USER, $LINK;
    $LTI = lti_require_data(array('user_id', 'link_id', 'role'));
    if ( ! $USER->instructor && $user_id !== false ) die("Requires instructor role");
    if ( $user_id == false ) $user_id = $USER->id;
    $p = $CFG->dbprefix;

    // Get basic grade data
    $stmt = pdo_query_die($pdo,
        "SELECT R.result_id AS result_id, R.user_id AS user_id,
            grade, note, R.json AS json, R.updated_at AS updated_at, displayname, email
        FROM {$p}lti_result AS R
        JOIN {$p}lti_user AS U ON R.user_id = U.user_id
        WHERE R.link_id = :LID AND R.user_id = :UID
        GROUP BY U.email",
        array(":LID" => $LINK->id, ":UID" => $user_id)
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row;
}

function show_grade_info($row) {
    echo('<p><a href="grades.php">Back to All Grades</a>'."</p><p>\n");
    echo("User Name: ".htmlent_utf8($row['displayname'])."<br/>\n");
    echo("User Email: ".htmlent_utf8($row['email'])."<br/>\n");
    echo("Last Submision: ".htmlent_utf8($row['updated_at'])."<br/>\n");
    echo("Score: ".htmlent_utf8($row['grade'])."<br/>\n");
    echo("</p>\n");
}

// newdata can be a string or array (preferred)
function update_grade_json($pdo, $newdata=false) {
    global $CFG;
    if ( $newdata == false ) return;
    if ( is_string($newdata) ) $newdata = json_decode($newdata, true);
    $LTI = lti_require_data(array('result_id'));
    $row = load_grade($pdo);
    $data = array();
    if ( $row !== false && isset($row['json'])) {
        $data = json_decode($row['json'], true);
    }

    $changed = false;
    foreach ($newdata as $k => $v ) {
        if ( (!isset($data[$k])) || $data[$k] != $v ) {
            $data[$k] = $v;
            $changed = true;
        }
    }

    if ( $changed === false ) return;

    $jstr = json_encode($data);

    $stmt = pdo_query_die($pdo,
        "UPDATE {$CFG->dbprefix}lti_result SET json = :json, updated_at = NOW() 
            WHERE result_id = :RID",
        array(
            ':json' => $jstr,
            ':RID' => $LTI['result_id'])
    );
}

function get_grade($pdo, $result_id, $sourcedid, $service) {
    global $CFG;
    $grade = get_grade_web_service($sourcedid, $service);
    if ( is_string($grade) ) return $grade;
  
    // UPDATE the retrieved grade
    $stmt = pdo_query_die($pdo,
        "UPDATE {$CFG->dbprefix}lti_result SET server_grade = :server_grade, 
            retrieved_at = NOW() WHERE result_id = :RID",
        array( ':server_grade' => $grade, ":RID" => $result_id)
    );
    return $grade;
}

function get_grade_web_service($sourcedid, $service) {
    global $CFG;
    global $LastPOXGradeResponse;
    global $LastPOXGradeParse;
    global $LastPOXGradeError;
    $LastPOXGradeResponse = false;
    $LastPOXGradeParse = false;
    $LastPOXGradeError = false;
    $lti = $_SESSION['lti'];
    if ( ! ( isset($lti['key_key']) && isset($lti['secret']) ) ) {
        error_log('Session is missing required data');
        $debug = safe_var_dump($lti);
        error_log($debug);
        return "Missing required session data";
    }

    $content_type = "application/xml";
    $sourcedid = htmlspecialchars($sourcedid);
    
    $operation = 'readResultRequest';
    $postBody = str_replace(
        array('SOURCEDID', 'OPERATION','MESSAGE'),
        array($sourcedid, $operation, uniqid()),
        getPOXRequest());

    $response = sendOAuthBodyPOST($service, $lti['key_key'], $lti['secret'], 
        $content_type, $postBody);
    $LastPOXGradeResponse = $response;

    $status = "Failure to retrieve grade";
    if ( strpos($response, '<?xml') !== 0 ) {
        error_log("Fatal XML Grade Read: ".session_id().' '.$response);
        return "Unable to read XML from web service.";
    }

    $grade = false;
    try {
        $retval = parseResponse($response);
        $LastPOXGradeParse = $retval;
        if ( is_array($retval) ) {
            if ( isset($retval['imsx_codeMajor']) && $retval['imsx_codeMajor'] == 'success') {
                if ( isset($retval['textString'])) $grade = $retval['textString']+0.0;
            } else if ( isset($retval['imsx_description']) ) {
                $LastPOXGradeError = $retval['imsx_description'];
                error_log("Grade read failure: "+$LastPOXGradeError);
                return $LastPOXGradeError;
            }
        }
    } catch(Exception $e) {
        $LastPOXGradeError = $e->getMessage();
        error_log("Grade read failure: "+$LastPOXGradeError);
        return $LastPOXGradeError;
    }
    return $grade;
}

function send_grade($grade, $verbose=true, $pdo=false, $result=false) {
    if ( ! isset($_SESSION['lti']) || ! isset($_SESSION['lti']['sourcedid']) ) {
        return "Session not set up for grade return";
    }
    $debug_log = array();
    $retval = false;
    try {
        if ( $result === false ) $result = $_SESSION['lti'];
        $retval = send_grade_internal($grade, $debug_log, $pdo, $result);
    } catch(Exception $e) {
        $retval = "Grade Exception: ".$e->getMessage();
        error_log($retval);
        $debug_log[] = $retval;
    } 
    if ( $verbose ) dumpGradeDebug($debug_log);
    return $retval;
}

function dumpGradeDebug($debug_log) {
    if ( ! is_array($debug_log) ) return;

    foreach ( $debug_log as $k => $v ) {
        if ( count($v) > 1 ) {
            $OUTPUT->toggle_pre($v[0], $v[1]);
        } else { 
            line_out($v[0]);
        }
    }
}

function send_grade_detail($grade, &$debug_log, $pdo=false, $result=false) {
    if ( ! isset($_SESSION['lti']) || ! isset($_SESSION['lti']['sourcedid']) ) {
        return "Session not set up for grade return";
    }
    $retval = false;
    try {
        if ( $result === false ) $result = $_SESSION['lti'];
        $retval = send_grade_internal($grade, $debug_log, $pdo, $result);
    } catch(Exception $e) {
        $retval = "Grade Exception: ".$e->getMessage();
        $debug_log[] = $retval;
        error_log($retval);
    }
    return $retval;
}

function send_grade_internal($grade, &$debug_log, $pdo,  $result) {
    global $CFG;
    global $LastPOXGradeResponse;
    $LastPOXGradeResponse = false;;
    $lti = $_SESSION['lti'];
    if ( ! ( isset($lti['service']) && isset($lti['sourcedid']) &&
        isset($lti['key_key']) && isset($lti['secret']) && 
        array_key_exists('grade', $lti) ) ) {
        error_log('Session is missing required data');
        $debug = safe_var_dump($lti);
        error_log($debug);
        return "Missing required session data";
    }

    if ( ! ( isset($result['sourcedid']) && isset($result['result_id']) &&
        array_key_exists('grade', $result) ) ) {
        error_log('Result is missing required data');
        $debug = safe_var_dump($result);
        error_log($debug);
        return "Missing required result data";
    }

    $sourcedid = $result['sourcedid'];
    // TODO: Should this be result?
    $service = $lti['service'];
    $status = send_grade_web_service($grade, $sourcedid, $service, $debug_log);

    $detail = $status;
    if ( $detail === true ) {
        $detail = 'Success';
        $msg = 'Grade sent '.$grade.' to '.$sourcedid.' by '.$lti['user_id'].' '.$detail;
        error_log($msg);
        if ( is_array($debug_log) )  $debug_log[] = array($msg);
    } else {
        $msg = 'Grade failure '.$grade.' to '.$sourcedid.' by '.$lti['user_id'].' '.$detail;
        error_log($msg);
        return $status;
    }

    // Update result in the database and in the LTI session area
    $_SESSION['lti']['grade'] = $grade;
    if ( $pdo !== false ) {
        $stmt = pdo_query($pdo,
            "UPDATE {$CFG->dbprefix}lti_result SET grade = :grade, 
                updated_at = NOW() WHERE result_id = :RID",
            array(
                ':grade' => $grade,
                ':RID' => $result['result_id'])
        );
        if ( $stmt->success ) {
            $msg = "Grade updated result_id=".$result['result_id']." grade=$grade";
        } else {
            $msg = "Grade NOT updated result_id=".$result['result_id']." grade=$grade";
        }
        error_log($msg);
        if ( is_array($debug_log) )  $debug_log[] = array($msg);
    }
    return $status;
}

function send_grade_web_service($grade, $sourcedid, $service, &$debug_log=false) {
    global $CFG;
    global $LastPOXGradeResponse;
    $LastPOXGradeResponse = false;;
    $lti = $_SESSION['lti'];
    if ( !isset($lti['key_key']) || !isset($lti['secret']) ) {
        error_log('Session is missing required data');
        $debug = safe_var_dump($lti);
        error_log($debug);
        return "Missing required session data";
    }

    $content_type = "application/xml";
    $sourcedid = htmlspecialchars($sourcedid);
    
    $operation = 'replaceResultRequest';
    $postBody = str_replace(
        array('SOURCEDID', 'GRADE', 'OPERATION','MESSAGE'),
        array($sourcedid, $grade.'', 'replaceResultRequest', uniqid()),
        getPOXGradeRequest());
    
    if ( is_array($debug_log) ) $debug_log[] = array('Sending '.$grade.' to '.$lti['service'].' sourcedid='.$sourcedid);

    if ( is_array($debug_log) )  $debug_log[] = array('Grade API Request (debug)',$postBody);

    $response = sendOAuthBodyPOST($lti['service'], $lti['key_key'], $lti['secret'], 
        $content_type, $postBody);
    global $LastOAuthBodyBaseString;
    $lbs = $LastOAuthBodyBaseString;
    if ( is_array($debug_log) )  $debug_log[] = array("Grade API Response (debug)",$response);
    $LastPOXGradeResponse = $response;
    $status = "Failure to store grade";
    if ( strpos($response, '<?xml') !== 0 ) {
        error_log("Fatal XML Grade Update: ".session_id().' '.$response);
        return $status;
    }
    try {
        $retval = parseResponse($response);
        if ( isset($retval['imsx_codeMajor']) && $retval['imsx_codeMajor'] == 'success') {
            $status = true;
        } else if ( isset($retval['imsx_description']) ) {
            $status = $retval['imsx_description'];
        }
    } catch(Exception $e) {
        $status = $e->getMessage();
        if ( is_array($debug_log) )  $debug_log[] = array("Exception: ".$status);
    }
    return $status;
}
