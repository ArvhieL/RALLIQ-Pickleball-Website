<?php
session_start();

// 1. Enforce Role-Based Access Control (RBAC) - Restrict to Managers only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Manager') {
    header("Location: ../login.php");
    exit();
}

date_default_timezone_set('Asia/Manila');
$today = date("Y-m-d");
$now_time = date("H:i:s");
$now_datetime = date("Y-m-d H:i:s");
$formatted_today = date("l, F j, Y");

// 2. Connect to SQL Server
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
$first_name = $_SESSION['first_name'] ?? 'Manager';

// 3. Fetch Manager Profile (Avatar & City)
$sql_profile = "SELECT avatar_url, city FROM users WHERE user_id = ?";
$res_profile = sqlsrv_query($conn, $sql_profile, [$user_id]);
$avatar = "";
$manager_city = "Facility HQ";

if ($res_profile && sqlsrv_has_rows($res_profile)) {
    $profile = sqlsrv_fetch_array($res_profile, SQLSRV_FETCH_ASSOC);
    $avatar = $profile['avatar_url'];
    if (!empty($profile['city'])) {
        $manager_city = $profile['city'];
    }
}
$display_avatar = !empty($avatar) ? $avatar : "default_avatar.png";

// Feedback alert messages (Post/Redirect/Get status or inline validation errors)
$success_msg = "";
$error_msg = "";

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'created') {
        $success_msg = "Open Play session published! Players can now view and join the roster.";
    } elseif ($_GET['status'] === 'cancelled') {
        $success_msg = "Open Play session cancelled and removed. Linked player bookings were marked Cancelled.";
    }
}

// Preserve form inputs if validation fails
$form_values = [
    'court_id'        => '',
    'title'           => '',
    'skill_level_req' => 'All Levels',
    'play_date'       => $today,
    'start_time'      => '08:00',
    'end_time'        => '10:00',
    'fee'             => '150.00',
    'max_slots'       => '8'
];

// 4. Handle POST Actions (Create Open Play / Cancel Open Play)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {

    // ACTION A: Create a New Open Play Session
    if ($_POST['action'] === 'create_open_play') {
        $court_id        = isset($_POST['court_id']) ? (int)$_POST['court_id'] : 0;
        $title           = trim($_POST['title'] ?? '');
        $skill_level_req = trim($_POST['skill_level_req'] ?? 'All Levels');
        $play_date       = trim($_POST['play_date'] ?? '');
        $start_time_raw  = trim($_POST['start_time'] ?? '');
        $end_time_raw    = trim($_POST['end_time'] ?? '');
        $fee             = isset($_POST['fee']) ? (float)$_POST['fee'] : -1;
        $max_slots       = isset($_POST['max_slots']) ? (int)$_POST['max_slots'] : 0;

        $form_values = [
            'court_id'        => $court_id,
            'title'           => $title,
            'skill_level_req' => $skill_level_req,
            'play_date'       => $play_date,
            'start_time'      => $start_time_raw,
            'end_time'        => $end_time_raw,
            'fee'             => $_POST['fee'] ?? '150.00',
            'max_slots'       => $max_slots
        ];

        $allowed_skills = ['All Levels', 'Beginner', 'Intermediate', 'Advanced'];
        $start_time_sql = strlen($start_time_raw) === 5 ? $start_time_raw . ":00" : $start_time_raw;
        $end_time_sql   = strlen($end_time_raw) === 5 ? $end_time_raw . ":00" : $end_time_raw;

        if ($court_id <= 0 || $title === '' || $play_date === '' || $start_time_raw === '' || $end_time_raw === '') {
            $error_msg = "Please complete all required session fields.";
        } elseif (mb_strlen($title) > 50) {
            $error_msg = "Session title must be 50 characters or fewer.";
        } elseif (!in_array($skill_level_req, $allowed_skills, true)) {
            $error_msg = "Please select a valid skill level requirement.";
        } elseif ($play_date < $today) {
            $error_msg = "Open Play date cannot be in the past.";
        } elseif ($end_time_sql <= $start_time_sql) {
            $error_msg = "End time must be later than start time.";
        } elseif ($play_date === $today && $start_time_sql <= $now_time) {
            $error_msg = "For sessions scheduled today, start time must be later than the current time.";
        } elseif ($fee < 0) {
            $error_msg = "Entry fee cannot be negative.";
        } elseif ($max_slots < 2 || $max_slots > 50) {
            $error_msg = "Max player slots must be between 2 and 50.";
        } else {
            // Verify court ownership & operational status
            $sql_verify_court = "
                SELECT c.court_id
                FROM courts AS c
                INNER JOIN venues AS v ON c.venue_id = v.venue_id
                WHERE c.court_id = ?
                  AND v.manager_id = ?
                  AND c.is_active = 1
            ";
            $stmt_verify = sqlsrv_query($conn, $sql_verify_court, [$court_id, $user_id]);

            if (!$stmt_verify || !sqlsrv_has_rows($stmt_verify)) {
                $error_msg = "Selected court is invalid, inactive, or does not belong to your venues.";
            } else {
                // Check for overlapping Open Play sessions on the same court & date
                $sql_conflict_op = "
                    SELECT TOP 1 play_id, title
                    FROM open_plays
                    WHERE court_id = ?
                      AND play_date = ?
                      AND start_time < ?
                      AND end_time > ?
                ";
                $stmt_conflict_op = sqlsrv_query($conn, $sql_conflict_op, [$court_id, $play_date, $end_time_sql, $start_time_sql]);

                // Check for overlapping Private Rentals or Manager-Blocked Maintenance Hours
                $sql_conflict_booking = "
                    SELECT TOP 1 booking_id, status, total_amount
                    FROM bookings
                    WHERE court_id = ?
                      AND booking_date = ?
                      AND start_time < ?
                      AND end_time > ?
                      AND (
                          (booking_type = 'Private_Rental' AND status = 'Confirmed')
                          OR (status = 'Cancelled' AND total_amount = 0.00)
                      )
                ";
                $stmt_conflict_bk = sqlsrv_query($conn, $sql_conflict_booking, [$court_id, $play_date, $end_time_sql, $start_time_sql]);

                if ($stmt_conflict_op && sqlsrv_has_rows($stmt_conflict_op)) {
                    $conflict_row = sqlsrv_fetch_array($stmt_conflict_op, SQLSRV_FETCH_ASSOC);
                    $error_msg = "Schedule conflict: \"" . $conflict_row['title'] . "\" is already scheduled on this court during that time window.";
                } elseif ($stmt_conflict_bk && sqlsrv_has_rows($stmt_conflict_bk)) {
                    $bk_row = sqlsrv_fetch_array($stmt_conflict_bk, SQLSRV_FETCH_ASSOC);
                    if ($bk_row['status'] === 'Cancelled' && (float)$bk_row['total_amount'] == 0.0) {
                        $error_msg = "Schedule conflict: This court has blocked unavailable/maintenance hours during that time window.";
                    } else {
                        $error_msg = "Schedule conflict: A confirmed private court rental already occupies that time slot.";
                    }
                } else {
                    // Insert new Open Play session
                    $sql_insert_op = "
                        INSERT INTO open_plays (court_id, title, skill_level_req, play_date, start_time, end_time, fee, max_slots, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ";
                    $insert_params = [
                        $court_id,
                        $title,
                        $skill_level_req,
                        $play_date,
                        $start_time_sql,
                        $end_time_sql,
                        $fee,
                        $max_slots,
                        $now_datetime
                    ];

                    if (sqlsrv_query($conn, $sql_insert_op, $insert_params) !== false) {
                        header("Location: manage_open_plays.php?status=created");
                        exit();
                    } else {
                        $error_msg = "Database error while creating the Open Play session. Please try again.";
                    }
                }
            }
        }
    }

    // ACTION B: Cancel & Remove an Upcoming Open Play Session
    elseif ($_POST['action'] === 'cancel_play' && isset($_POST['play_id'])) {
        $play_id = (int)$_POST['play_id'];

        $sql_check_owner = "
            SELECT o.play_id
            FROM open_plays AS o
            INNER JOIN courts AS c ON o.court_id = c.court_id
            INNER JOIN venues AS v ON c.venue_id = v.venue_id
            WHERE o.play_id = ?
              AND v.manager_id = ?
              AND (o.play_date > ? OR (o.play_date = ? AND o.end_time > ?))
        ";
        $stmt_owner = sqlsrv_query($conn, $sql_check_owner, [$play_id, $user_id, $today, $today, $now_time]);

        if (!$stmt_owner || !sqlsrv_has_rows($stmt_owner)) {
            $error_msg = "Unable to cancel session. It may have already ended or does not belong to your facility.";
        } else {
            if (sqlsrv_begin_transaction($conn) === false) {
                die(print_r(sqlsrv_errors(), true));
            }

            // 1. Remove active roster slots
            $stmt_del_roster = sqlsrv_query($conn, "DELETE FROM open_play_roster WHERE play_id = ?", [$play_id]);

            // 2. Mark linked Open Play bookings as Cancelled and detach play_id FK so receipt history stays intact
            $sql_cancel_bookings = "
                UPDATE bookings
                SET status = 'Cancelled', play_id = NULL
                WHERE play_id = ?
                  AND booking_type = 'Open_Play'
            ";
            $stmt_upd_bookings = sqlsrv_query($conn, $sql_cancel_bookings, [$play_id]);

            // 3. Delete the Open Play session
            $stmt_del_play = sqlsrv_query($conn, "DELETE FROM open_plays WHERE play_id = ?", [$play_id]);

            if ($stmt_del_roster !== false && $stmt_upd_bookings !== false && $stmt_del_play !== false) {
                sqlsrv_commit($conn);
                header("Location: manage_open_plays.php?status=cancelled");
                exit();
            } else {
                sqlsrv_rollback($conn);
                $error_msg = "Failed to cancel the Open Play session due to a database error.";
            }
        }
    }
}

// 5. Fetch Active Courts Owned by the Logged-In Manager
$sql_courts = "
    SELECT c.court_id, c.court_name, c.court_type, c.surface_type, v.venue_name
    FROM courts AS c
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE v.manager_id = ?
      AND c.is_active = 1
    ORDER BY v.venue_name ASC, c.court_name ASC
";
$res_courts = sqlsrv_query($conn, $sql_courts, [$user_id]);
$manager_courts = [];
if ($res_courts && sqlsrv_has_rows($res_courts)) {
    while ($row = sqlsrv_fetch_array($res_courts, SQLSRV_FETCH_ASSOC)) {
        $manager_courts[] = $row;
    }
}
$active_courts_count = count($manager_courts);

// 6. Fetch Upcoming & Live Open Play Sessions with Confirmed Roster Counts
$sql_upcoming = "
    SELECT o.play_id, o.title, o.skill_level_req, o.play_date, o.start_time, o.end_time, o.fee, o.max_slots,
           c.court_name, v.venue_name,
           (
               SELECT COUNT(*)
               FROM open_play_roster AS r
               INNER JOIN bookings AS rb ON r.booking_id = rb.booking_id
               WHERE r.play_id = o.play_id
                 AND rb.status = 'Confirmed'
           ) AS booked_slots
    FROM open_plays AS o
    INNER JOIN courts AS c ON o.court_id = c.court_id
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE v.manager_id = ?
      AND (o.play_date > ? OR (o.play_date = ? AND o.end_time > ?))
    ORDER BY o.play_date ASC, o.start_time ASC
";
$res_upcoming = sqlsrv_query($conn, $sql_upcoming, [$user_id, $today, $today, $now_time]);
$upcoming_plays = [];
if ($res_upcoming && sqlsrv_has_rows($res_upcoming)) {
    while ($row = sqlsrv_fetch_array($res_upcoming, SQLSRV_FETCH_ASSOC)) {
        $upcoming_plays[] = $row;
    }
}
$upcoming_count = count($upcoming_plays);

// 7. Fetch Enrolled Participants (Primary Players + Guests) for Upcoming Sessions
$sql_rosters = "
    SELECT r.play_id, r.roster_id, r.guest_name, r.registered_at,
           u.first_name, u.last_name, u.skill_level, u.avatar_url
    FROM open_play_roster AS r
    INNER JOIN bookings AS rb ON r.booking_id = rb.booking_id
    INNER JOIN users AS u ON r.user_id = u.user_id
    INNER JOIN open_plays AS o ON r.play_id = o.play_id
    INNER JOIN courts AS c ON o.court_id = c.court_id
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE v.manager_id = ?
      AND rb.status = 'Confirmed'
      AND (o.play_date > ? OR (o.play_date = ? AND o.end_time > ?))
    ORDER BY r.registered_at ASC
";
$res_rosters = sqlsrv_query($conn, $sql_rosters, [$user_id, $today, $today, $now_time]);
$rosters_by_play = [];
if ($res_rosters && sqlsrv_has_rows($res_rosters)) {
    while ($r_row = sqlsrv_fetch_array($res_rosters, SQLSRV_FETCH_ASSOC)) {
        $pid = (int)$r_row['play_id'];
        if (!isset($rosters_by_play[$pid])) {
            $rosters_by_play[$pid] = [];
        }
        $rosters_by_play[$pid][] = $r_row;
    }
}

// 8. Fetch Recent Past Open Play Sessions (Last 10)
$sql_past = "
    SELECT TOP 10 o.play_id, o.title, o.skill_level_req, o.play_date, o.start_time, o.end_time, o.fee, o.max_slots,
           c.court_name, v.venue_name,
           (
               SELECT COUNT(*)
               FROM open_play_roster AS r
               INNER JOIN bookings AS rb ON r.booking_id = rb.booking_id
               WHERE r.play_id = o.play_id
                 AND rb.status IN ('Confirmed', 'Completed')
           ) AS attended_slots
    FROM open_plays AS o
    INNER JOIN courts AS c ON o.court_id = c.court_id
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE v.manager_id = ?
      AND (o.play_date < ? OR (o.play_date = ? AND o.end_time <= ?))
    ORDER BY o.play_date DESC, o.start_time DESC
";
$res_past = sqlsrv_query($conn, $sql_past, [$user_id, $today, $today, $now_time]);
$past_plays = [];
if ($res_past && sqlsrv_has_rows($res_past)) {
    while ($row = sqlsrv_fetch_array($res_past, SQLSRV_FETCH_ASSOC)) {
        $past_plays[] = $row;
    }
}
$past_count = count($past_plays);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Open Plays - RALLIQ</title>

    <!-- Bootstrap 5.2.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">

    <!-- RALLIQ Orange & Slate Theme -->
    <link rel="stylesheet" href="../ralliq-theme.css?v=4">
</head>
<body>

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-ralliq sticky-top py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="manager_dashboard.php">
                <img src="../assets/RalliQ Main Logo.png?v=2" alt="RALLIQ Logo" height="30">
                <span class="badge rounded-pill badge-slate px-2 py-1">ADMIN</span>
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#managerNavbar" aria-controls="managerNavbar" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="managerNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4 gap-lg-3">
                    <li class="nav-item">
                        <a class="nav-link" href="manager_dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_courts.php">Court Management</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="facility_scheduling.php">Facility Scheduling</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_rentals.php">Private Rentals</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" aria-current="page" href="manage_open_plays.php">Open Plays</a>
                    </li>
                </ul>

                <div class="d-flex align-items-center gap-3">
                    <a href="edit_profile.php" class="d-flex align-items-center text-decoration-none gap-2">
                        <img src="../avatars/<?php echo htmlspecialchars($display_avatar); ?>" onerror="this.src='../avatars/default_avatar.png';" alt="Manager Avatar" class="nav-avatar">
                        <span class="fw-semibold small text-dark">My Profile</span>
                    </a>
                    <a href="../logout.php" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Clean White Page Header Strip -->
    <header class="page-header-strip">
        <div class="container">
            <div class="row align-items-center gy-3">
                <div class="col-12 col-lg-7">
                    <h1 class="h2 fw-bold mb-1">Open Play Management</h1>
                    <p class="text-stone mb-0">
                        Host public drop-in sessions, inspect player rosters, and manage capacities &bull; <?php echo htmlspecialchars($manager_city); ?>
                    </p>
                </div>
                <div class="col-12 col-lg-5 d-flex flex-wrap align-items-center gap-2 justify-content-lg-end">
                    <span class="badge rounded-pill badge-soft-orange px-3 py-2">
                        <?php echo $upcoming_count; ?> Active Session<?php echo $upcoming_count === 1 ? '' : 's'; ?>
                    </span>
                    <a href="manage_courts.php" class="btn btn-sm btn-outline-primary ms-1">View Courts</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Workspace -->
    <main class="container pb-5">

        <!-- Feedback Alerts -->
        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success rounded-4 border-0 shadow-sm alert-dismissible fade show mb-4" role="alert">
                <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm alert-dismissible fade show mb-4" role="alert">
                <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- LEFT COLUMN: Host New Open Play Form -->
            <div class="col-12 col-lg-5">
                <div class="card card-custom p-4 h-100">
                    <div class="d-flex align-items-center gap-3 pb-3 mb-4 border-bottom">
                        <div class="icon-badge-soft">
                            <img src="../assets/Open Play Session Icon.png" alt="Host Session" width="32" height="32">
                        </div>
                        <div>
                            <h2 class="h5 fw-bold mb-0">Host New Open Play</h2>
                            <p class="small text-stone mb-0">Publish a public drop-in roster on an active court</p>
                        </div>
                    </div>

                    <?php if ($active_courts_count > 0): ?>
                        <form method="POST" action="manage_open_plays.php">
                            <input type="hidden" name="action" value="create_open_play">

                            <!-- Select Court -->
                            <div class="mb-3">
                                <label for="court_id" class="form-label form-label-custom">Host Court <span class="text-danger">*</span></label>
                                <select id="court_id" name="court_id" class="form-select form-control-custom" required>
                                    <option value="" disabled <?php echo empty($form_values['court_id']) ? 'selected' : ''; ?>>Choose an active court...</option>
                                    <?php foreach ($manager_courts as $court): ?>
                                        <option value="<?php echo (int)$court['court_id']; ?>" <?php echo ((int)$form_values['court_id'] === (int)$court['court_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($court['venue_name'] . " — " . $court['court_name'] . " (" . $court['court_type'] . ")"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Session Title -->
                            <div class="mb-3">
                                <label for="title" class="form-label form-label-custom">Session Title <span class="text-danger">*</span></label>
                                <input type="text" id="title" name="title" class="form-control form-control-custom" maxlength="50" placeholder="e.g., Sunset Social Open Play" value="<?php echo htmlspecialchars($form_values['title']); ?>" required>
                            </div>

                            <!-- Skill Level & Date -->
                            <div class="row g-3 mb-3">
                                <div class="col-12 col-sm-6">
                                    <label for="skill_level_req" class="form-label form-label-custom">Skill Level</label>
                                    <select id="skill_level_req" name="skill_level_req" class="form-select form-control-custom">
                                        <?php foreach (['All Levels', 'Beginner', 'Intermediate', 'Advanced'] as $lvl): ?>
                                            <option value="<?php echo $lvl; ?>" <?php echo ($form_values['skill_level_req'] === $lvl) ? 'selected' : ''; ?>>
                                                <?php echo $lvl; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12 col-sm-6">
                                    <label for="play_date" class="form-label form-label-custom">Session Date <span class="text-danger">*</span></label>
                                    <input type="date" id="play_date" name="play_date" class="form-control form-control-custom" min="<?php echo $today; ?>" value="<?php echo htmlspecialchars($form_values['play_date']); ?>" required>
                                </div>
                            </div>

                            <!-- Start Time & End Time -->
                            <div class="row g-3 mb-3">
                                <div class="col-12 col-sm-6">
                                    <label for="start_time" class="form-label form-label-custom">Start Time <span class="text-danger">*</span></label>
                                    <input type="time" id="start_time" name="start_time" class="form-control form-control-custom" value="<?php echo htmlspecialchars($form_values['start_time']); ?>" required>
                                </div>

                                <div class="col-12 col-sm-6">
                                    <label for="end_time" class="form-label form-label-custom">End Time <span class="text-danger">*</span></label>
                                    <input type="time" id="end_time" name="end_time" class="form-control form-control-custom" value="<?php echo htmlspecialchars($form_values['end_time']); ?>" required>
                                </div>
                            </div>

                            <!-- Entry Fee & Max Player Slots -->
                            <div class="row g-3 mb-4">
                                <div class="col-12 col-sm-6">
                                    <label for="fee" class="form-label form-label-custom">Entry Fee (₱) <span class="text-danger">*</span></label>
                                    <input type="number" id="fee" name="fee" class="form-control form-control-custom" step="0.01" min="0" value="<?php echo htmlspecialchars((string)$form_values['fee']); ?>" required>
                                </div>

                                <div class="col-12 col-sm-6">
                                    <label for="max_slots" class="form-label form-label-custom">Max Player Slots <span class="text-danger">*</span></label>
                                    <input type="number" id="max_slots" name="max_slots" class="form-control form-control-custom" min="2" max="50" value="<?php echo htmlspecialchars((string)$form_values['max_slots']); ?>" required>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                + Publish Open Play Session
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="text-center py-5 px-3 rounded-4 bg-light">
                            <img src="../assets/Court Icon.png" alt="No Active Courts" width="52" height="52" class="mb-3">
                            <h3 class="h6 fw-bold mb-1">No active courts available</h3>
                            <p class="small text-stone mb-3">Register a venue and activate at least one court before hosting an Open Play session.</p>
                            <a href="manage_courts.php" class="btn btn-primary btn-sm px-4">Go to Court Management</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT COLUMN: Scheduled & Past Open Play Rosters -->
            <div class="col-12 col-lg-7">
                <div class="card card-custom p-4 h-100">
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 pb-3 mb-4 border-bottom">
                        <div>
                            <h2 class="h5 fw-bold mb-0">Schedule &amp; Rosters</h2>
                            <p class="small text-stone mb-0">Monitor live sign-ups or manage upcoming sessions</p>
                        </div>

                        <ul class="nav nav-pills ralliq-switcher gap-2" id="openPlayTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="upcoming-tab" data-bs-toggle="pill" data-bs-target="#upcoming-pane" type="button" role="tab" aria-controls="upcoming-pane" aria-selected="true">
                                    Upcoming (<?php echo $upcoming_count; ?>)
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="past-tab" data-bs-toggle="pill" data-bs-target="#past-pane" type="button" role="tab" aria-controls="past-pane" aria-selected="false">
                                    Past (<?php echo $past_count; ?>)
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div class="tab-content" id="openPlayTabsContent">
                        <!-- TAB 1: Upcoming Open Plays -->
                        <div class="tab-pane fade show active" id="upcoming-pane" role="tabpanel" aria-labelledby="upcoming-tab">
                            <?php if ($upcoming_count > 0): ?>
                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($upcoming_plays as $play): ?>
                                        <?php
                                            $pid = (int)$play['play_id'];
                                            $play_date_str = $play['play_date']->format('M j, Y');
                                            $start_str = $play['start_time']->format('g:i A');
                                            $end_str = $play['end_time']->format('g:i A');
                                            $fee_formatted = number_format((float)$play['fee'], 2);
                                            $booked = (int)$play['booked_slots'];
                                            $max = (int)$play['max_slots'];
                                            $is_full = ($booked >= $max);
                                            $session_roster = $rosters_by_play[$pid] ?? [];
                                        ?>
                                        <div class="roster-item-card">
                                            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                                                <div class="d-flex align-items-start gap-3">
                                                    <div class="icon-badge-soft mt-1">
                                                        <img src="../assets/Open Play Session Icon.png" alt="Open Play" width="30" height="30">
                                                    </div>
                                                    <div>
                                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                            <h3 class="h6 fw-bold mb-0"><?php echo htmlspecialchars($play['title']); ?></h3>
                                                            <span class="badge rounded-pill badge-soft-orange px-2 py-1">
                                                                <?php echo htmlspecialchars($play['skill_level_req']); ?>
                                                            </span>
                                                        </div>
                                                        <p class="small fw-medium mb-1">
                                                            <?php echo htmlspecialchars($play['venue_name'] . " • " . $play['court_name']); ?>
                                                        </p>
                                                        <p class="small text-stone mb-0">
                                                            <?php echo htmlspecialchars($play_date_str . " (" . $start_str . " - " . $end_str . ")"); ?> &bull; ₱<?php echo htmlspecialchars($fee_formatted); ?> / slot
                                                        </p>
                                                    </div>
                                                </div>

                                                <div class="d-flex flex-wrap align-items-center justify-content-between justify-content-md-end gap-2">
                                                    <span class="badge rounded-pill px-3 py-2 <?php echo $is_full ? 'bg-success text-white' : 'badge-slate'; ?>">
                                                        <?php echo $booked . " / " . $max; ?> Slots<?php echo $is_full ? ' (Full)' : ''; ?>
                                                    </span>

                                                    <button type="button" class="btn btn-sm btn-soft-orange px-3" data-bs-toggle="modal" data-bs-target="#rosterModal<?php echo $pid; ?>">
                                                        Roster
                                                    </button>

                                                    <form method="POST" action="manage_open_plays.php" class="m-0" onsubmit="return confirm('Cancel this Open Play session? Enrolled players will be removed from the roster and their bookings marked Cancelled.');">
                                                        <input type="hidden" name="action" value="cancel_play">
                                                        <input type="hidden" name="play_id" value="<?php echo $pid; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">
                                                            Cancel
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Roster Inspection Modal -->
                                        <div class="modal fade" id="rosterModal<?php echo $pid; ?>" tabindex="-1" aria-labelledby="rosterModalLabel<?php echo $pid; ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                                                <div class="modal-content border-0 rounded-4 shadow">
                                                    <div class="modal-header border-bottom px-4 py-3">
                                                        <div>
                                                            <h5 class="modal-title fw-bold" id="rosterModalLabel<?php echo $pid; ?>">
                                                                <?php echo htmlspecialchars($play['title']); ?>
                                                            </h5>
                                                            <p class="small text-stone mb-0">
                                                                <?php echo htmlspecialchars($play['court_name'] . " • " . $play_date_str . " (" . $start_str . " - " . $end_str . ")"); ?>
                                                            </p>
                                                        </div>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>

                                                    <div class="modal-body px-4 py-3">
                                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                                            <span class="small fw-semibold text-uppercase text-stone">Confirmed Roster</span>
                                                            <span class="badge rounded-pill badge-soft-orange px-3 py-1">
                                                                <?php echo $booked . " / " . $max; ?> Filled
                                                            </span>
                                                        </div>

                                                        <?php if (count($session_roster) > 0): ?>
                                                            <div class="d-flex flex-column gap-2">
                                                                <?php foreach ($session_roster as $player): ?>
                                                                    <?php
                                                                        $p_avatar = !empty($player['avatar_url']) ? $player['avatar_url'] : 'default_avatar.png';
                                                                        $primary_name = trim($player['first_name'] . ' ' . $player['last_name']);
                                                                        $is_guest = !empty($player['guest_name']);
                                                                        $reg_time = ($player['registered_at'] instanceof DateTime)
                                                                            ? $player['registered_at']->format('M j, g:i A')
                                                                            : '';
                                                                    ?>
                                                                    <div class="d-flex align-items-center justify-content-between p-2 rounded-3 bg-light border">
                                                                        <div class="d-flex align-items-center gap-2">
                                                                            <img src="../avatars/<?php echo htmlspecialchars($p_avatar); ?>" onerror="this.src='../avatars/default_avatar.png';" alt="Player" class="nav-avatar" style="width: 34px; height: 34px;">
                                                                            <div>
                                                                                <?php if ($is_guest): ?>
                                                                                    <div class="small fw-bold">
                                                                                        <?php echo htmlspecialchars($player['guest_name']); ?>
                                                                                        <span class="badge bg-secondary ms-1">Guest</span>
                                                                                    </div>
                                                                                    <div class="small text-stone">Booked by <?php echo htmlspecialchars($primary_name); ?></div>
                                                                                <?php else: ?>
                                                                                    <div class="small fw-bold"><?php echo htmlspecialchars($primary_name); ?></div>
                                                                                    <div class="small text-stone">
                                                                                        <?php echo htmlspecialchars($player['skill_level'] ?? 'Beginner'); ?>
                                                                                        <?php echo $reg_time !== '' ? ' &bull; Joined ' . htmlspecialchars($reg_time) : ''; ?>
                                                                                    </div>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </div>
                                                                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1">
                                                                            Confirmed
                                                                        </span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="text-center py-4 rounded-3 bg-light">
                                                                <p class="small text-stone mb-0">No players have joined this roster yet.</p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>

                                                    <div class="modal-footer border-top px-4 py-2">
                                                        <button type="button" class="btn btn-sm btn-light rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 px-3 rounded-4 bg-light">
                                    <img src="../assets/Open Play Session Icon.png" alt="No Upcoming Open Plays" width="52" height="52" class="mb-3">
                                    <h3 class="h6 fw-bold mb-1">No upcoming Open Plays</h3>
                                    <p class="small text-stone mb-0">Use the form on the left to publish your next public drop-in session.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- TAB 2: Recent Past Open Plays -->
                        <div class="tab-pane fade" id="past-pane" role="tabpanel" aria-labelledby="past-tab">
                            <?php if ($past_count > 0): ?>
                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($past_plays as $past): ?>
                                        <?php
                                            $past_date_str = $past['play_date']->format('M j, Y');
                                            $past_start = $past['start_time']->format('g:i A');
                                            $past_end = $past['end_time']->format('g:i A');
                                            $attended = (int)$past['attended_slots'];
                                            $past_max = (int)$past['max_slots'];
                                            $past_fee = number_format((float)$past['fee'], 2);
                                        ?>
                                        <div class="roster-item-card opacity-75">
                                            <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="icon-badge-soft">
                                                        <img src="../assets/Open Play Session Icon.png" alt="Past Open Play" width="30" height="30">
                                                    </div>
                                                    <div>
                                                        <h3 class="h6 fw-bold mb-1"><?php echo htmlspecialchars($past['title']); ?></h3>
                                                        <p class="small text-stone mb-0">
                                                            <?php echo htmlspecialchars($past['venue_name'] . " • " . $past['court_name']); ?> &bull;
                                                            <?php echo htmlspecialchars($past_date_str . " (" . $past_start . " - " . $past_end . ")"); ?> &bull;
                                                            ₱<?php echo htmlspecialchars($past_fee); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                                <span class="badge rounded-pill badge-soft-orange px-3 py-2 align-self-start align-self-sm-center">
                                                    <?php echo $attended . " / " . $past_max; ?> Attended
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 px-3 rounded-4 bg-light">
                                    <img src="../assets/Open Play Session Icon.png" alt="No Past Open Plays" width="52" height="52" class="mb-3">
                                    <h3 class="h6 fw-bold mb-1">No completed sessions yet</h3>
                                    <p class="small text-stone mb-0">Past Open Play sessions will automatically appear here once their end time passes.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <!-- Bootstrap 5.2.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>