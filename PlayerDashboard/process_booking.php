<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Player') {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: discover.php");
    exit();
}

require_once '../includes/db.php';

$user_id      = (int)$_SESSION['user_id'];
$court_id     = (int)($_POST['court_id'] ?? 0);
$booking_date = trim($_POST['booking_date'] ?? '');
$start_raw    = trim($_POST['start_time'] ?? '');
$end_raw      = trim($_POST['end_time'] ?? '');

$today    = date("Y-m-d");
$now_time = date("H:i:s");

function failBooking($court_id, $booking_date, $message) {
    $_SESSION['booking_error'] = $message;
    $query = "court_id=" . (int)$court_id;
    if ($booking_date !== '') {
        $query .= "&date=" . urlencode($booking_date);
    }
    header("Location: book_court.php?" . $query);
    exit();
}

if ($court_id <= 0 || $booking_date === '' || $start_raw === '' || $end_raw === '') {
    failBooking($court_id, $booking_date, "Please complete all required reservation fields.");
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking_date) || $booking_date < $today) {
    failBooking($court_id, $today, "You cannot book a court on a past date.");
}

$start_time = date("H:i:s", strtotime($start_raw));
$end_time   = date("H:i:s", strtotime($end_raw));

if ($end_time <= $start_time) {
    failBooking($court_id, $booking_date, "End time must be later than start time.");
}

if ($booking_date === $today && $start_time <= $now_time) {
    failBooking($court_id, $booking_date, "Selected start time has already passed for today.");
}

// Verify court exists and is active
$sql_court = "SELECT court_id, court_name, court_type FROM dbo.courts WHERE court_id = ? AND is_active = 1";
$stmt_court = sqlsrv_query($conn, $sql_court, [$court_id]);
if (!$stmt_court || !sqlsrv_has_rows($stmt_court)) {
    header("Location: discover.php");
    exit();
}
$court = sqlsrv_fetch_array($stmt_court, SQLSRV_FETCH_ASSOC);

// Calculate exact total_amount based on duration and court type
$hours = (strtotime($end_time) - strtotime($start_time)) / 3600;
$hourly_rate  = ($court['court_type'] === 'Indoor') ? 500.00 : 350.00;
$total_amount = round($hours * $hourly_rate, 2);

if ($total_amount <= 0) {
    failBooking($court_id, $booking_date, "Invalid reservation duration.");
}

// Begin transaction to prevent double-booking race conditions
if (sqlsrv_begin_transaction($conn) === false) {
    failBooking($court_id, $booking_date, "Could not start booking transaction. Please try again.");
}

// 1. Check conflict against Confirmed bookings AND Manager-Blocked Hours (Cancelled + total_amount = 0.00)
$sql_conflict_bookings = "
    SELECT TOP(1) b.booking_id
    FROM dbo.bookings AS b
    WHERE b.court_id = ?
      AND b.booking_date = ?
      AND (
          b.status = 'Confirmed'
          OR (b.status = 'Cancelled' AND b.total_amount = 0.00)
      )
      AND b.start_time < ?
      AND b.end_time > ?
";
$stmt_cb = sqlsrv_query($conn, $sql_conflict_bookings, [$court_id, $booking_date, $end_time, $start_time]);

// 2. Check conflict against scheduled Open Plays (even if 0 players have joined yet)
$sql_conflict_op = "
    SELECT TOP(1) o.play_id
    FROM dbo.open_plays AS o
    WHERE o.court_id = ?
      AND o.play_date = ?
      AND o.start_time < ?
      AND o.end_time > ?
";
$stmt_op = sqlsrv_query($conn, $sql_conflict_op, [$court_id, $booking_date, $end_time, $start_time]);

if (($stmt_cb && sqlsrv_has_rows($stmt_cb)) || ($stmt_op && sqlsrv_has_rows($stmt_op))) {
    sqlsrv_rollback($conn);
    failBooking($court_id, $booking_date, "That time slot overlaps with an existing reservation, Open Play, or blocked maintenance window.");
}

// Insert confirmed private court rental
$sql_insert = "
    INSERT INTO dbo.bookings (user_id, court_id, play_id, booking_type, booking_date, start_time, end_time, total_amount, status, created_at)
    VALUES (?, ?, NULL, 'Private_Rental', ?, ?, ?, ?, 'Confirmed', GETDATE())
";
$insert_params = [$user_id, $court_id, $booking_date, $start_time, $end_time, $total_amount];
$stmt_insert   = sqlsrv_query($conn, $sql_insert, $insert_params);

if ($stmt_insert === false) {
    sqlsrv_rollback($conn);
    failBooking($court_id, $booking_date, "Database error while saving your reservation. Please try again.");
}

sqlsrv_commit($conn);

$_SESSION['flash_success'] = "Court reserved! " . $court['court_name'] . " is confirmed for " . date('M j, Y', strtotime($booking_date)) . " (" . date('g:i A', strtotime($start_time)) . " - " . date('g:i A', strtotime($end_time)) . ").";
header("Location: play_history.php");
exit();