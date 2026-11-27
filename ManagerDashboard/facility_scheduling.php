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

// Active UI Tab & Status Alerts (Supports Post/Redirect/Get)
$active_tab = (isset($_REQUEST['tab']) && $_REQUEST['tab'] === 'maintenance') ? 'maintenance' : 'block';
$success_msg = "";
$error_msg = "";

if (isset($_GET['status'])) {
    $status_messages = [
        'blocked'         => "Court time slot blocked and removed from player availability.",
        'released'        => "Blocked time slot released. The court hours are open for booking again.",
        'maintenance_on'  => "Court placed under Full Maintenance and hidden from player discovery.",
        'maintenance_off' => "Court marked as Operational and reopened for bookings."
    ];
    if (isset($status_messages[$_GET['status']])) {
        $success_msg = $status_messages[$_GET['status']];
    }
}

// Preserve form inputs if validation fails
$form_values = [
    'court_id'   => '',
    'block_date' => $today,
    'start_time' => '',
    'end_time'   => ''
];

// 4. POST Handler 1: Block Specific Court Hours (Single Date/Time Window)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'block_hours') {
    $active_tab = 'block';
    $court_id       = isset($_POST['court_id']) ? (int)$_POST['court_id'] : 0;
    $block_date     = trim($_POST['block_date'] ?? '');
    $start_time_raw = trim($_POST['start_time'] ?? '');
    $end_time_raw   = trim($_POST['end_time'] ?? '');

    $form_values = [
        'court_id'   => $court_id,
        'block_date' => $block_date,
        'start_time' => $start_time_raw,
        'end_time'   => $end_time_raw
    ];

    if ($court_id <= 0 || $block_date === '' || $start_time_raw === '' || $end_time_raw === '') {
        $error_msg = "Please complete all required fields to block court hours.";
    } elseif ($block_date < $today) {
        $error_msg = "You cannot block hours on a past date.";
    } else {
        $start_time = date("H:i:s", strtotime($start_time_raw));
        $end_time   = date("H:i:s", strtotime($end_time_raw));

        if ($start_time >= $end_time) {
            $error_msg = "End time must be later than start time.";
        } elseif ($block_date === $today && $end_time <= $now_time) {
            $error_msg = "The selected end time has already passed for today.";
        } else {
            // Verify the selected court belongs to this Manager
            $sql_verify_court = "
                SELECT c.court_id
                FROM courts AS c
                INNER JOIN venues AS v ON c.venue_id = v.venue_id
                WHERE c.court_id = ? AND v.manager_id = ?
            ";
            $stmt_verify = sqlsrv_query($conn, $sql_verify_court, [$court_id, $user_id]);

            if (!$stmt_verify || !sqlsrv_has_rows($stmt_verify)) {
                $error_msg = "Invalid court selection or unauthorized facility access.";
            } else {
                // Conflict Check A: Overlapping Confirmed Player Bookings
                $sql_conflict_booking = "
                    SELECT TOP(1) booking_id
                    FROM bookings
                    WHERE court_id = ?
                      AND booking_date = ?
                      AND status = 'Confirmed'
                      AND start_time < ?
                      AND end_time > ?
                ";
                $stmt_conflict_booking = sqlsrv_query($conn, $sql_conflict_booking, [$court_id, $block_date, $end_time, $start_time]);

                // Conflict Check B: Overlapping Scheduled Open Play Sessions
                $sql_conflict_openplay = "
                    SELECT TOP(1) play_id
                    FROM open_plays
                    WHERE court_id = ?
                      AND play_date = ?
                      AND start_time < ?
                      AND end_time > ?
                ";
                $stmt_conflict_openplay = sqlsrv_query($conn, $sql_conflict_openplay, [$court_id, $block_date, $end_time, $start_time]);

                // Conflict Check C: Overlapping Existing Manager Block
                $sql_conflict_block = "
                    SELECT TOP(1) booking_id
                    FROM bookings
                    WHERE court_id = ?
                      AND booking_date = ?
                      AND status = 'Cancelled'
                      AND total_amount = 0.00
                      AND start_time < ?
                      AND end_time > ?
                ";
                $stmt_conflict_block = sqlsrv_query($conn, $sql_conflict_block, [$court_id, $block_date, $end_time, $start_time]);

                if ($stmt_conflict_booking && sqlsrv_has_rows($stmt_conflict_booking)) {
                    $error_msg = "Cannot block this window because a player already has a confirmed reservation during these hours.";
                } elseif ($stmt_conflict_openplay && sqlsrv_has_rows($stmt_conflict_openplay)) {
                    $error_msg = "Cannot block this window because an Open Play session is scheduled during these hours.";
                } elseif ($stmt_conflict_block && sqlsrv_has_rows($stmt_conflict_block)) {
                    $error_msg = "This court already has a blocked time slot overlapping the selected hours.";
                } else {
                    $sql_insert_block = "
                        INSERT INTO bookings (user_id, court_id, play_id, booking_type, booking_date, start_time, end_time, slots_reserved, total_amount, status, created_at)
                        VALUES (?, ?, NULL, 'Private_Rental', ?, ?, ?, 1, 0.00, 'Cancelled', ?)
                    ";
                    $params_insert = [$user_id, $court_id, $block_date, $start_time, $end_time, $now_datetime];

                    if (sqlsrv_query($conn, $sql_insert_block, $params_insert) !== false) {
                        header("Location: facility_scheduling.php?status=blocked&tab=block");
                        exit();
                    } else {
                        $error_msg = "Unable to save blocked hours. Please try again.";
                    }
                }
            }
        }
    }
}

// 5. POST Handler 2: Release / Unblock a Blocked Time Slot
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'release_slot') {
    $active_tab = 'block';
    $release_booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;

    if ($release_booking_id > 0) {
        $sql_verify_block = "
            SELECT b.booking_id
            FROM bookings AS b
            INNER JOIN courts AS c ON b.court_id = c.court_id
            INNER JOIN venues AS v ON c.venue_id = v.venue_id
            WHERE b.booking_id = ?
              AND v.manager_id = ?
              AND b.status = 'Cancelled'
              AND b.total_amount = 0.00
        ";
        $stmt_verify_block = sqlsrv_query($conn, $sql_verify_block, [$release_booking_id, $user_id]);

        if ($stmt_verify_block && sqlsrv_has_rows($stmt_verify_block)) {
            if (sqlsrv_query($conn, "DELETE FROM bookings WHERE booking_id = ?", [$release_booking_id]) !== false) {
                header("Location: facility_scheduling.php?status=released&tab=block");
                exit();
            } else {
                $error_msg = "Failed to release the blocked time slot.";
            }
        } else {
            $error_msg = "Blocked slot not found or unauthorized action.";
        }
    }
}

// 6. POST Handler 3: Toggle Full-Court Maintenance Status (courts.is_active)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'toggle_maintenance') {
    $active_tab = 'maintenance';
    $target_court_id = isset($_POST['court_id']) ? (int)$_POST['court_id'] : 0;
    $new_status = (isset($_POST['new_status']) && $_POST['new_status'] === '1') ? 1 : 0;

    if ($target_court_id > 0) {
        $sql_verify_owner = "
            SELECT c.court_id
            FROM courts AS c
            INNER JOIN venues AS v ON c.venue_id = v.venue_id
            WHERE c.court_id = ? AND v.manager_id = ?
        ";
        $stmt_verify_owner = sqlsrv_query($conn, $sql_verify_owner, [$target_court_id, $user_id]);

        if ($stmt_verify_owner && sqlsrv_has_rows($stmt_verify_owner)) {
            if (sqlsrv_query($conn, "UPDATE courts SET is_active = ? WHERE court_id = ?", [$new_status, $target_court_id]) !== false) {
                $status_flag = ($new_status === 1) ? 'maintenance_off' : 'maintenance_on';
                header("Location: facility_scheduling.php?status={$status_flag}&tab=maintenance");
                exit();
            } else {
                $error_msg = "Failed to update court operational status.";
            }
        } else {
            $error_msg = "Invalid court or unauthorized action.";
        }
    }
}

// 7. Fetch All Courts Owned by This Manager
$sql_courts = "
    SELECT c.court_id, c.court_name, c.surface_type, c.court_type, c.is_active, v.venue_name
    FROM courts AS c
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE v.manager_id = ?
    ORDER BY v.venue_name ASC, c.court_name ASC
";
$res_courts = sqlsrv_query($conn, $sql_courts, [$user_id]);

$manager_courts = [];
$maintenance_count = 0;
if ($res_courts && sqlsrv_has_rows($res_courts)) {
    while ($c_row = sqlsrv_fetch_array($res_courts, SQLSRV_FETCH_ASSOC)) {
        $manager_courts[] = $c_row;
        if ((int)$c_row['is_active'] === 0) {
            $maintenance_count++;
        }
    }
}

// 8. Fetch Active & Upcoming Blocked Time Slots (Excludes Expired Same-Day Blocks)
$sql_blocked_slots = "
    SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, c.court_name, v.venue_name
    FROM bookings AS b
    INNER JOIN courts AS c ON b.court_id = c.court_id
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE v.manager_id = ?
      AND b.booking_type = 'Private_Rental'
      AND b.status = 'Cancelled'
      AND b.total_amount = 0.00
      AND (b.booking_date > ? OR (b.booking_date = ? AND b.end_time > ?))
    ORDER BY b.booking_date ASC, b.start_time ASC
";
$res_blocked = sqlsrv_query($conn, $sql_blocked_slots, [$user_id, $today, $today, $now_time]);

$blocked_slots = [];
if ($res_blocked && sqlsrv_has_rows($res_blocked)) {
    while ($b_row = sqlsrv_fetch_array($res_blocked, SQLSRV_FETCH_ASSOC)) {
        $blocked_slots[] = $b_row;
    }
}
$blocked_slots_count = count($blocked_slots);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facility Scheduling - RALLIQ</title>

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
                        <a class="nav-link active" aria-current="page" href="facility_scheduling.php">Facility Scheduling</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_rentals.php">Private Rentals</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_open_plays.php">Open Plays</a>
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
                    <h1 class="h2 fw-bold mb-1">Facility Scheduling</h1>
                    <p class="text-stone mb-0">
                        Block specific court hours for holidays or events, or toggle full-court maintenance &bull; <?php echo htmlspecialchars($manager_city); ?>
                    </p>
                </div>
                <div class="col-12 col-lg-5 d-flex flex-wrap align-items-center gap-2 justify-content-lg-end">
                    <span class="badge rounded-pill badge-soft-orange px-3 py-2">
                        <?php echo $blocked_slots_count; ?> Blocked Slot<?php echo $blocked_slots_count === 1 ? '' : 's'; ?>
                    </span>
                    <span class="badge rounded-pill bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-2">
                        <?php echo $maintenance_count; ?> In Maintenance
                    </span>
                    <a href="manage_courts.php" class="btn btn-sm btn-outline-primary ms-1">Court Catalog</a>
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

        <!-- Pill Tab Switcher -->
        <ul class="nav nav-pills ralliq-switcher gap-2 mb-4" id="schedulingTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo ($active_tab === 'block') ? 'active' : ''; ?>" id="block-tab" data-bs-toggle="pill" data-bs-target="#block-pane" type="button" role="tab" aria-controls="block-pane" aria-selected="<?php echo ($active_tab === 'block') ? 'true' : 'false'; ?>">
                    Block Time Slots (<?php echo $blocked_slots_count; ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo ($active_tab === 'maintenance') ? 'active' : ''; ?>" id="maintenance-tab" data-bs-toggle="pill" data-bs-target="#maintenance-pane" type="button" role="tab" aria-controls="maintenance-pane" aria-selected="<?php echo ($active_tab === 'maintenance') ? 'true' : 'false'; ?>">
                    Court Maintenance (<?php echo count($manager_courts); ?>)
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="schedulingTabContent">

            <!-- TAB 1: Block Specific Court Hours -->
            <div class="tab-pane fade <?php echo ($active_tab === 'block') ? 'show active' : ''; ?>" id="block-pane" role="tabpanel" aria-labelledby="block-tab">
                <div class="row g-4">

                    <!-- Left Column: Block Court Hours Form -->
                    <div class="col-12 col-lg-5">
                        <div class="card card-custom p-4 h-100">
                            <div class="d-flex align-items-center gap-3 pb-3 mb-4 border-bottom">
                                <div class="icon-badge-soft">
                                    <img src="../assets/Blocked Calendar Icon.png" alt="Block Hours" width="32" height="32">
                                </div>
                                <div>
                                    <h2 class="h5 fw-bold mb-0">Block Court Hours</h2>
                                    <p class="small text-stone mb-0">Reserve a court window for repairs or private events</p>
                                </div>
                            </div>

                            <?php if (count($manager_courts) > 0): ?>
                                <form action="facility_scheduling.php?tab=block" method="POST">
                                    <input type="hidden" name="action" value="block_hours">

                                    <div class="mb-3">
                                        <label for="court_id" class="form-label form-label-custom">Select Court <span class="text-danger">*</span></label>
                                        <select id="court_id" name="court_id" class="form-select form-control-custom" required>
                                            <option value="" disabled <?php echo empty($form_values['court_id']) ? 'selected' : ''; ?>>Choose a court...</option>
                                            <?php foreach ($manager_courts as $c_row): ?>
                                                <option value="<?php echo (int)$c_row['court_id']; ?>" <?php echo ((int)$form_values['court_id'] === (int)$c_row['court_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($c_row['venue_name'] . " — " . $c_row['court_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label for="block_date" class="form-label form-label-custom">Closure Date <span class="text-danger">*</span></label>
                                        <input type="date" id="block_date" name="block_date" class="form-control form-control-custom" min="<?php echo htmlspecialchars($today); ?>" value="<?php echo htmlspecialchars($form_values['block_date']); ?>" required>
                                    </div>

                                    <div class="row g-3 mb-4">
                                        <div class="col-12 col-sm-6">
                                            <label for="start_time" class="form-label form-label-custom">Start Time <span class="text-danger">*</span></label>
                                            <input type="time" id="start_time" name="start_time" class="form-control form-control-custom" value="<?php echo htmlspecialchars($form_values['start_time']); ?>" required>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <label for="end_time" class="form-label form-label-custom">End Time <span class="text-danger">*</span></label>
                                            <input type="time" id="end_time" name="end_time" class="form-control form-control-custom" value="<?php echo htmlspecialchars($form_values['end_time']); ?>" required>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100">
                                        Block Court Hours
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="text-center py-5 px-3 rounded-4 bg-light">
                                    <img src="../assets/Court Icon.png" alt="No Courts" width="52" height="52" class="mb-3">
                                    <h3 class="h6 fw-bold mb-1">No courts registered yet</h3>
                                    <p class="small text-stone mb-3">Register a venue and court before blocking schedule hours.</p>
                                    <a href="manage_courts.php" class="btn btn-primary btn-sm px-4">+ Add a Court First</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right Column: Active & Upcoming Blocked Slots -->
                    <div class="col-12 col-lg-7">
                        <div class="card card-custom p-4 h-100">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pb-3 mb-4 border-bottom">
                                <div>
                                    <h2 class="h5 fw-bold mb-0">Active &amp; Upcoming Blocked Slots</h2>
                                    <p class="small text-stone mb-0">Expired time windows clear automatically once their end time passes</p>
                                </div>
                                <span class="badge rounded-pill badge-soft-orange px-3 py-2">
                                    <?php echo $blocked_slots_count; ?> Active
                                </span>
                            </div>

                            <?php if ($blocked_slots_count > 0): ?>
                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($blocked_slots as $slot): ?>
                                        <?php
                                            $slot_date = ($slot['booking_date'] instanceof DateTime)
                                                ? $slot['booking_date']->format('D, M j, Y')
                                                : $slot['booking_date'];
                                            $slot_start = ($slot['start_time'] instanceof DateTime)
                                                ? $slot['start_time']->format('g:i A')
                                                : $slot['start_time'];
                                            $slot_end = ($slot['end_time'] instanceof DateTime)
                                                ? $slot['end_time']->format('g:i A')
                                                : $slot['end_time'];
                                        ?>
                                        <div class="roster-item-card d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="icon-badge-soft">
                                                    <img src="../assets/Blocked Calendar Icon.png" alt="Blocked Slot" width="30" height="30">
                                                </div>
                                                <div>
                                                    <h3 class="h6 fw-bold mb-1"><?php echo htmlspecialchars($slot['court_name']); ?></h3>
                                                    <p class="small text-stone mb-0">
                                                        <?php echo htmlspecialchars($slot['venue_name']); ?> &bull;
                                                        <strong class="text-dark"><?php echo htmlspecialchars($slot_date); ?></strong>
                                                        (<?php echo htmlspecialchars($slot_start . " - " . $slot_end); ?>)
                                                    </p>
                                                </div>
                                            </div>

                                            <form action="facility_scheduling.php?tab=block" method="POST" class="m-0 align-self-end align-self-sm-center">
                                                <input type="hidden" name="action" value="release_slot">
                                                <input type="hidden" name="booking_id" value="<?php echo (int)$slot['booking_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">
                                                    Release Slot
                                                </button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 px-3 rounded-4 bg-light">
                                    <img src="../assets/Blocked Calendar Icon.png" alt="No Blocked Slots" width="52" height="52" class="mb-3">
                                    <h3 class="h6 fw-bold mb-1">No blocked time slots</h3>
                                    <p class="small text-stone mb-0">All operational courts are currently open for player scheduling.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>

            <!-- TAB 2: Full Court Maintenance Toggles -->
            <div class="tab-pane fade <?php echo ($active_tab === 'maintenance') ? 'show active' : ''; ?>" id="maintenance-pane" role="tabpanel" aria-labelledby="maintenance-tab">
                <div class="card card-custom p-4">
                    <div class="d-flex align-items-center gap-3 pb-3 mb-4 border-bottom">
                        <div class="icon-badge-soft">
                            <img src="../assets/Maintenance Calendar Icon.png" alt="Court Maintenance" width="32" height="32">
                        </div>
                        <div>
                            <h2 class="h5 fw-bold mb-0">Full-Court Maintenance Controls</h2>
                            <p class="small text-stone mb-0">Toggle a court between Operational (Active) and Closed for Extended Repairs</p>
                        </div>
                    </div>

                    <?php if (count($manager_courts) > 0): ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($manager_courts as $court): ?>
                                <?php $is_operational = ((int)$court['is_active'] === 1); ?>
                                <div class="roster-item-card d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="icon-badge-soft">
                                            <img src="../assets/Maintenance Calendar Icon.png" alt="Court Status" width="30" height="30">
                                        </div>
                                        <div>
                                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                <h3 class="h6 fw-bold mb-0"><?php echo htmlspecialchars($court['court_name']); ?></h3>
                                                <?php if ($is_operational): ?>
                                                    <span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-1">
                                                        Operational
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge rounded-pill bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-1">
                                                        In Maintenance
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="small text-stone mb-0">
                                                <?php echo htmlspecialchars($court['venue_name'] . " • " . $court['court_type'] . " • " . $court['surface_type']); ?>
                                            </p>
                                        </div>
                                    </div>

                                    <form action="facility_scheduling.php?tab=maintenance" method="POST" class="m-0 align-self-end align-self-sm-center">
                                        <input type="hidden" name="action" value="toggle_maintenance">
                                        <input type="hidden" name="court_id" value="<?php echo (int)$court['court_id']; ?>">
                                        <?php if ($is_operational): ?>
                                            <input type="hidden" name="new_status" value="0">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">
                                                Set Maintenance
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="new_status" value="1">
                                            <button type="submit" class="btn btn-sm btn-primary px-3">
                                                Reopen Court
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 px-3 rounded-4 bg-light">
                            <img src="../assets/Court Icon.png" alt="No Courts" width="52" height="52" class="mb-3">
                            <h3 class="h6 fw-bold mb-1">No courts registered yet</h3>
                            <p class="small text-stone mb-3">Register a venue and court in Court Management to configure availability.</p>
                            <a href="manage_courts.php" class="btn btn-primary btn-sm px-4">+ Add Court</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <!-- Bootstrap 5.2.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>