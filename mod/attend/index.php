<?php
require_once "../../config.php";
require_once $CFG->dirroot."/pdo.php";
require_once $CFG->dirroot."/lib/lms_lib.php";

$LTI = lti_require_data(array('user_id', 'link_id', 'role','context_id'));

// Model 
$p = $CFG->dbprefix;
$stmt = $pdo->prepare("SELECT code FROM {$p}attend_code WHERE link_id = :ID");
$stmt->execute(array(":ID" => $LINK->id));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$old_code = "";
if ( $row !== false ) $old_code = $row['code'];

if ( isset($_POST['code']) && $USER->instructor ) {
    $sql = "INSERT INTO {$p}attend_code 
            (link_id, code) VALUES ( :ID, :CO ) 
            ON DUPLICATE KEY UPDATE code = :CO";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':CO' => $_POST['code'],
        ':ID' => $LINK->id));
    $_SESSION['success'] = 'Code updated';
    header( 'Location: '.sessionize('index.php') ) ;
    return;
} else if ( isset($_POST['code']) ) { // Student
    if ( $old_code == $_POST['code'] ) {
        $sql = "INSERT INTO {$p}attend 
            (link_id, user_id, ipaddr, attend, updated_at) 
            VALUES ( :LI, :UI, :IP, NOW(), NOW() ) 
            ON DUPLICATE KEY UPDATE updated_at = NOW()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(
            ':LI' => $LINK->id,
            ':UI' => $USER->id,
            ':IP' => $_SERVER["REMOTE_ADDR"]));
        $_SESSION['success'] = 'Attendance Recorded...';
    } else {
        $_SESSION['error'] = 'Code incorrect';
    }
    header( 'Location: '.sessionize('index.php') ) ;
    return;
}

// View 
?>
<html><head><title>Attendance tool</title>
</head>
<body style="background-color:orange;">
<?php
if ( isset($_SESSION['error']) ) {
    echo '<p style="color:red">'.$_SESSION['error']."</p>\n";
    unset($_SESSION['error']);
}
if ( isset($_SESSION['success']) ) {
    echo '<p style="color:green">'.$_SESSION['success']."</p>\n";
    unset($_SESSION['success']);
}

// A nice welcome...
echo("<p>Welcome");
if ( isset($USER->displayname) ) {
    echo(" ");
    echo(htmlent_utf8($USER->displayname));
}
if ( isset($CONTEXT->title) ) {
    echo(" from ");
    echo(htmlent_utf8($CONTEXT->title));
}

if ( $USER->instructor ) {
    echo(" (Instructor)");
}
echo("</p>\n");

echo('<form method="post">');
echo("Enter code:\n");
if ( $USER->instructor ) {
echo('<input type="text" name="code" value="'.htmlent_utf8($old_code).'"> ');
echo('<input type="submit" name="send" value="Update code"><br/>');
} else {
echo('<input type="text" name="code" value=""> ');
echo('<input type="submit" name="send" value="Record attendance"><br/>');
}
echo("\n</form>\n");

if ( $USER->instructor ) {
    $stmt = $pdo->prepare("SELECT user_id,attend,ipaddr FROM {$p}attend 
            WHERE link_id = :LI ORDER BY attend DESC, user_id");
    $stmt->execute(array(':LI' => $LINK->id));
    echo('<table border="1">'."\n");
    echo("<tr><th>User</th><th>Attendance</th><th>IP Address</th></tr>\n");
    while ( $row = $stmt->fetch(PDO::FETCH_ASSOC) ) {
        echo "<tr><td>";
        echo($row['user_id']);
        echo("</td><td>");
        echo($row['attend']);
        echo("</td><td>");
        echo(htmlentities($row['ipaddr']));
        echo("</td></tr>\n");
    }
    echo("</table>\n");
}

$OUTPUT->footer();
