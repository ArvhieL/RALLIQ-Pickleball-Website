<?php
session_start();

// 1. Enforce Role-Based Access Control (RBAC)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Player') {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_POST['play_id'])) {
    header("Location: open_plays.php");
    exit();
}

$serverName = "LAPTOP-QIJJ9RAT\\SQLEXPRESS";
$connectionOptions = [
    "Database" => "FinalProject",
    "Uid" => "",
    "PWD" => ""
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die(print_r(sqlsrv_errors(), true));
}

$user_id = (int)$_SESSION['user_id'];
$play_id = (int)$_POST['play_id'];
$today = date("Y-m-d");

// 2. Verify the session exists, is upcoming, and the player actually has slots reserved
$sql_check = "
    SELECT o.play_date, COUNT(r.roster_id) AS user_slots
    FROM open_plays AS o
    INNER JOIN open_play_roster AS r ON o.play_id = r.play_id
    WHERE o.play_id = ?
      AND r.user_id = ?
      AND o.play_date >= ?
    GROUP BY o.play_date
";
$stmt_check = sqlsrv_query($conn, $sql_check, [$play_id, $user_id, $today]);

if ($stmt_check === false || !sqlsrv_has_rows($stmt_check)) {
    header("Location: open_play_details.php?play_id=" . $play_id . "&error=cannot_leave");
    exit();
}

// 3. Begin transaction so roster removal and booking cancellation succeed or fail together
if (sqlsrv_begin_transaction($conn) === false) {
    die(print_r(sqlsrv_errors(), true));
}

// Step A: Delete the player's primary and guest rows from open_play_roster
$sql_delete_roster = "
    DELETE FROM open_play_roster
    WHERE play_id = ?
      AND user_id = ?
";
$stmt_delete = sqlsrv_query($conn, $sql_delete_roster, [$play_id, $user_id]);

// Step B: Mark their confirmed Open_Play booking record(s) as Cancelled
$sql_cancel_booking = "
    UPDATE bookings
    SET status = 'Cancelled'
    WHERE play_id = ?
      AND user_id = ?
      AND booking_type = 'Open_Play'
      AND status = 'Confirmed'
";
$stmt_update = sqlsrv_query($conn, $sql_cancel_booking, [$play_id, $user_id]);

if ($stmt_delete !== false && $stmt_update !== false) {
    sqlsrv_commit($conn);
    header("Location: open_play_details.php?play_id=" . $play_id . "&status=left_session");
    exit();
} else {
    sqlsrv_rollback($conn);
    header("Location: open_play_details.php?play_id=" . $play_id . "&error=leave_failed");
    exit();
}
?>