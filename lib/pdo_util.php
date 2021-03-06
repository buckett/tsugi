<?php

function pdo_row_die($pdo, $sql, $arr=FALSE, $error_log=TRUE) {
    $stmt = pdo_query_die($pdo, $sql, $arr, $error_log);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row;
}

function pdo_all_rows_die($pdo, $sql, $arr=FALSE, $error_log=TRUE) {
    $stmt = pdo_query_die($pdo, $sql, $arr, $error_log);
    $rows = array();
    while ( $row = $stmt->fetch(PDO::FETCH_ASSOC) ) {
        array_push($rows, $row);
    }
    return $rows;
}

function pdo_query_die($pdo, $sql, $arr=FALSE, $error_log=TRUE) {
    global $CFG;
    $stmt = pdo_query($pdo, $sql, $arr, $error_log);
    if ( ! $stmt->success ) {
        error_log("Sql Failure:".$stmt->errorImplode." ".$sql);
        if ( isset($CFG) && isset($CFG->dirroot) && isset($CFG->DEVELOPER) && $CFG->DEVELOPER) {
            $sanity = $CFG->dirroot."/sanity-db.php";
            if ( file_exists($sanity) ) {
                include_once($sanity);
            }
        }
        die($stmt->errorImplode); // with error_log
    }
    return $stmt;
}

// Run a PDO Query with lots of error checking
function pdo_query($pdo, $sql, $arr=FALSE, $error_log=TRUE) {
    $errormode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
    if ( $errormode != PDO::ERRMODE_EXCEPTION) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    $q = FALSE;
    $success = FALSE;
    $message = '';
    if ( $arr !== FALSE && ! is_array($arr) ) $arr = Array($arr);
    $start = microtime(true);
    // debug_log($sql, $arr);
    try {
        $q = $pdo->prepare($sql);
        if ( $arr === FALSE ) {
            $success = $q->execute();
        } else {
            $success = $q->execute($arr);
        }
    } catch(Exception $e) {
        $success = FALSE;
        $message = $e->getMessage();
        if ( $error_log ) error_log($message);
    }
    if ( ! is_object($q) ) $q = stdClass();
    if ( isset( $q->success ) ) {
        error_log("PDO::Statement should not have success member");
        die("PDO::Statement should not have success member"); // with error_log
    }
    $q->success = $success;
    if ( isset( $q->ellapsed_time ) ) {
        error_log("PDO::Statement should not have ellapsed_time member");
        die("PDO::Statement should not have ellapsed_time member"); // with error_log
    }
    $q->ellapsed_time = microtime(true)-$start;
    // In case we build this...
    if ( !isset($q->errorCode) ) $q->errorCode = '42000';
    if ( !isset($q->errorInfo) ) $q->errorInfo = Array('42000', '42000', $message);
    if ( !isset($q->errorImplode) ) $q->errorImplode = implode(':',$q->errorInfo);
    // Restore ERRMODE if we changed it
    if ( $errormode != PDO::ERRMODE_EXCEPTION) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $errormode);
    }
    return $q;
}

function pdo_metadata($pdo, $tablename) {
    $sql = "SHOW COLUMNS FROM ".$tablename;
    $q = pdo_query($pdo, $sql);
    if ( $q->success ) {
        return $q->fetchAll();
    } else {
        return false;
    }
}

