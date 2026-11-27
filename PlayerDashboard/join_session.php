<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Player') {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: open_plays.php");
    exit();
}

require_once '../includes/db.php';

$user_id     = (int)$_SESSION['user_id'];
$first_name  = trim($_SESSION['first_name'] ?? 'Player');
$play_id     = (int)($_POST['play_id'] ?? 0);
$slot_count  = (int)($_POST['slot_count'] ?? 1);
$guest_names = $_POST['guest_names'] ?? [];

$today    = date("Y-m-d");
$now_time = date("H:i:s");

function failJoin($play_id, $message) {
    $_SESSION['open_play_error'] = $message;
    header("Location: open_play_details.php?play_id=" . (int)$play_id);
    exit();
}

if ($play_id <= 0) {
    header("Location: open_plays.php");
    exit();
}

if ($slot_count < 1 || $slot_count > 10) {
    failJoin($play_id, "Please select a valid number of spots.");
}

// Begin transaction so capacity check, booking receipt, and roster inserts stay atomic
if (sqlsrv_begin_transaction($conn) === false) {
    failJoin($play_id, "Unable to start registration transaction. Please try again.");
}

// 1. Fetch Open Play details and verify court is active
$sql_play = "
    SELECT o.play_id, o.court_id, o.title, o.play_date, o.start_time, o.end_time, o.fee, o.max_slots
    FROM dbo.open_plays AS o
    INNER JOIN dbo.courts AS c ON o.court_id = c.court_id
    WHERE o.play_id = ?
      AND c.is_active = 1
";
$stmt_play = sqlsrv_query($conn, $sql_play, [$play_id]);

if (!$stmt_play || !sqlsrv_has_rows($stmt_play)) {
    sqlsrv_rollback($conn);
    header("Location: open_plays.php");
    exit();
}

$play = sqlsrv_fetch_array($stmt_play, SQLSRV_FETCH_ASSOC);
$play_date_str = $play['play_date']->format('Y-m-d');
$start_time_str = $play['start_time']->format('H:i:s');
$end_time_str   = $play['end_time']->format('H:i:s');

// Verify session has not already started
if ($play_date_str < $today || ($play_date_str === $today && $start_time_str <= $now_time)) {
    sqlsrv_rollback($conn);
    failJoin($play_id, "This Open Play session has already started.");
}

// 2. Check live confirmed roster capacity
$sql_count = "
    SELECT COUNT(*) AS booked_slots
    FROM dbo.open_play_roster AS r
    INNER JOIN dbo.bookings AS b ON r.booking_id = b.booking_id
    WHERE r.play_id = ?
      AND b.status = 'Confirmed'
";
$stmt_count = sqlsrv_query($conn, $sql_count, [$play_id]);
$count_row  = $stmt_count ? sqlsrv_fetch_array($stmt_count, SQLSRV_FETCH_ASSOC) : ['booked_slots' => 0];
$booked_slots    = (int)$count_row['booked_slots'];
$remaining_slots = (int)$play['max_slots'] - $booked_slots;

if ($remaining_slots < $slot_count) {
    sqlsrv_rollback($conn);
    $msg = $remaining_slots <= 0
        ? "Sorry, this Open Play session just filled up."
        : "Only " . $remaining_slots . " spot(s) remain for this session.";
    failJoin($play_id, $msg);
}

// 3. Insert parent Open_Play booking record into dbo.bookings
$total_amount = round($slot_count * (float)$play['fee'], 2);
$sql_booking = "
    INSERT INTO dbo.bookings (user_id, court_id, play_id, booking_type, booking_date, start_time, end_time, total_amount, status, created_at)
    OUTPUT INSERTED.booking_id
    VALUES (?, ?, ?, 'Open_Play', ?, ?, ?, ?, 'Confirmed', GETDATE())
";
$booking_params = [
    $user_id,
    (int)$play['court_id'],
    $play_id,
    $play_date_str,
    $start_time_str,
    $end_time_str,
    $total_amount
];
$stmt_booking = sqlsrv_query($conn, $sql_booking, $booking_params);

if (!$stmt_booking || !($bk_row = sqlsrv_fetch_array($stmt_booking, SQLSRV_FETCH_ASSOC))) {
    sqlsrv_rollback($conn);
    failJoin($play_id, "Database error while creating your booking receipt.");
}

$booking_id = (int)$bk_row['booking_id'];

// 4. Insert primary player's own roster row (guest_name = NULL)
$sql_roster = "
    INSERT INTO dbo.open_play_roster (play_id, booking_id, user_id, guest_name, registered_at)
    VALUES (?, ?, ?, ?, GETDATE())
";
$stmt_primary = sqlsrv_query($conn, $sql_roster, [$play_id, $booking_id, $user_id, null]);
if ($stmt_primary === false) {
    sqlsrv_rollback($conn);
    failJoin($play_id, "Database error while registering your spot on the roster.");
}

// 5. Insert any additional guest roster rows
for ($g = 0; $g < ($slot_count - 1); $g++) {
    $raw_guest = is_array($guest_names) && isset($guest_names[$g]) ? trim($guest_names[$g]) : '';
    if (strlen($raw_guest) > 100) {
        $raw_guest = substr($raw_guest, 0, 100);
    }
    $guest_label = ($raw_guest !== '') ? $raw_guest : ('Guest #' . ($g + 1) . ' (' . $first_name . ')');

    $stmt_guest = sqlsrv_query($conn, $sql_roster, [$play_id, $booking_id, $user_id, $guest_label]);
    if ($stmt_guest === false) {
        sqlsrv_rollback($conn);
        failJoin($play_id, "Database error while registering your guest spot(s).");
    }
}

sqlsrv_commit($conn);

header("Location: open_play_details.php?play_id=" . $play_id . "&status=joined");
exit();