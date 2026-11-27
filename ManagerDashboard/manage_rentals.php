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

// Read & sanitize active GET filters
$filter_court_id = isset($_GET['court_id']) ? (int)$_GET['court_id'] : 0;
$filter_date     = isset($_GET['filter_date']) ? trim($_GET['filter_date']) : '';
$filter_status   = isset($_GET['status']) ? trim($_GET['status']) : 'ALL';

$allowed_statuses = ['ALL', 'Confirmed', 'Completed', 'Cancelled'];
if (!in_array($filter_status, $allowed_statuses, true)) {
    $filter_status = 'ALL';
}
if ($filter_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $filter_date = '';
}

// Feedback Alerts (Supports Post/Redirect/Get without colliding with ?status= filter)
$success_msg = "";
$error_msg = "";

if (isset($_GET['alert'])) {
    if ($_GET['alert'] === 'completed') {
        $success_msg = "Private court reservation marked as Completed.";
    } elseif ($_GET['alert'] === 'cancelled') {
        $success_msg = "Private court reservation has been cancelled and the slot reopened.";
    }
}

// 4. Handle POST Actions (Mark Completed / Cancel Rental)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'], $_POST['booking_id'])) {
    $action     = $_POST['action'];
    $booking_id = (int)$_POST['booking_id'];

    // Preserve active filters on redirect
    $redirect_params = [];
    if (!empty($_POST['return_court_id'])) {
        $redirect_params['court_id'] = (int)$_POST['return_court_id'];
    }
    if (!empty($_POST['return_filter_date'])) {
        $redirect_params['filter_date'] = trim($_POST['return_filter_date']);
    }
    if (!empty($_POST['return_status']) && $_POST['return_status'] !== 'ALL') {
        $redirect_params['status'] = trim($_POST['return_status']);
    }

    if ($booking_id > 0 && in_array($action, ['mark_completed', 'cancel_rental'], true)) {
        // Verify ownership, booking type, and ensure booking is currently Confirmed (and not a $0.00 manager block)
        $sql_verify = "
            SELECT b.booking_id
            FROM bookings AS b
            INNER JOIN courts AS c ON b.court_id = c.court_id
            INNER JOIN venues AS v ON c.venue_id = v.venue_id
            WHERE b.booking_id = ?
              AND v.manager_id = ?
              AND b.booking_type = 'Private_Rental'
              AND b.status = 'Confirmed'
              AND NOT (b.status = 'Cancelled' AND b.total_amount = 0.00)
        ";
        $stmt_verify = sqlsrv_query($conn, $sql_verify, [$booking_id, $user_id]);

        if (!$stmt_verify || !sqlsrv_has_rows($stmt_verify)) {
            $error_msg = "Reservation not found, already finalized, or unauthorized.";
        } else {
            $new_status = ($action === 'mark_completed') ? 'Completed' : 'Cancelled';
            $alert_flag = ($action === 'mark_completed') ? 'completed' : 'cancelled';

            $sql_update = "UPDATE bookings SET status = ? WHERE booking_id = ?";
            if (sqlsrv_query($conn, $sql_update, [$new_status, $booking_id]) !== false) {
                $redirect_params['alert'] = $alert_flag;
                header("Location: manage_rentals.php?" . http_build_query($redirect_params));
                exit();
            } else {
                $error_msg = "Database error while updating the reservation status. Please try again.";
            }
        }
    }
}

// 5. Fetch All Courts Owned by This Manager (for Filter Dropdown)
$sql_courts = "
    SELECT c.court_id, c.court_name, v.venue_name
    FROM courts AS c
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE v.manager_id = ?
    ORDER BY v.venue_name ASC, c.court_name ASC
";
$res_courts = sqlsrv_query($conn, $sql_courts, [$user_id]);
$manager_courts = [];
if ($res_courts && sqlsrv_has_rows($res_courts)) {
    while ($c_row = sqlsrv_fetch_array($res_courts, SQLSRV_FETCH_ASSOC)) {
        $manager_courts[] = $c_row;
    }
}

// 6. Fetch Facility-Wide Private Rental Summary Metrics (Excludes $0.00 Manager-Blocked Hours)
$sql_metrics = "
    SELECT
        SUM(CASE WHEN b.status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
        SUM(CASE WHEN b.status = 'Confirmed' AND b.booking_date = ? THEN 1 ELSE 0 END) AS today_count,
        COALESCE(SUM(CASE WHEN b.status IN ('Confirmed', 'Completed') THEN b.total_amount ELSE 0 END), 0) AS total_revenue
    FROM bookings AS b
    INNER JOIN courts AS c ON b.court_id = c.court_id
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE v.manager_id = ?
      AND b.booking_type = 'Private_Rental'
      AND NOT (b.status = 'Cancelled' AND b.total_amount = 0.00)
";
$res_metrics = sqlsrv_query($conn, $sql_metrics, [$today, $user_id]);

$confirmed_count = 0;
$today_count     = 0;
$total_revenue   = 0.00;

if ($res_metrics && sqlsrv_has_rows($res_metrics)) {
    $m_row = sqlsrv_fetch_array($res_metrics, SQLSRV_FETCH_ASSOC);
    $confirmed_count = (int)($m_row['confirmed_count'] ?? 0);
    $today_count     = (int)($m_row['today_count'] ?? 0);
    $total_revenue   = (float)($m_row['total_revenue'] ?? 0.00);
}

// 7. Fetch Filtered Private Court Rentals (Strictly Excludes $0.00 Manager Blocks)
$sql_rentals = "
    SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, b.total_amount, b.status,
           c.court_name, v.venue_name,
           u.first_name, u.last_name, u.email, u.mobile_number, u.avatar_url
    FROM bookings AS b
    INNER JOIN courts AS c ON b.court_id = c.court_id
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    INNER JOIN users AS u ON b.user_id = u.user_id
    WHERE v.manager_id = ?
      AND b.booking_type = 'Private_Rental'
      AND NOT (b.status = 'Cancelled' AND b.total_amount = 0.00)
";
$query_params = [$user_id];

if ($filter_court_id > 0) {
    $sql_rentals .= " AND b.court_id = ?";
    $query_params[] = $filter_court_id;
}
if ($filter_date !== '') {
    $sql_rentals .= " AND b.booking_date = ?";
    $query_params[] = $filter_date;
}
if ($filter_status !== 'ALL') {
    $sql_rentals .= " AND b.status = ?";
    $query_params[] = $filter_status;
}

$sql_rentals .= " ORDER BY b.booking_date DESC, b.start_time ASC";

$res_rentals = sqlsrv_query($conn, $sql_rentals, $query_params);
$rentals_list = [];
if ($res_rentals && sqlsrv_has_rows($res_rentals)) {
    while ($row = sqlsrv_fetch_array($res_rentals, SQLSRV_FETCH_ASSOC)) {
        $rentals_list[] = $row;
    }
}
$rentals_count = count($rentals_list);
$has_active_filters = ($filter_court_id > 0 || $filter_date !== '' || $filter_status !== 'ALL');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Private Rentals - RALLIQ</title>

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
                        <a class="nav-link active" aria-current="page" href="manage_rentals.php">Private Rentals</a>
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
                    <h1 class="h2 fw-bold mb-1">Private Court Rentals</h1>
                    <p class="text-stone mb-0">
                        Review player court bookings, verify schedules, and finalize completed sessions &bull; <?php echo htmlspecialchars($manager_city); ?>
                    </p>
                </div>
                <div class="col-12 col-lg-5 d-flex flex-wrap align-items-center gap-2 justify-content-lg-end">
                    <span class="badge rounded-pill badge-soft-orange px-3 py-2">
                        <?php echo $confirmed_count; ?> Confirmed
                    </span>
                    <span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2">
                        ₱<?php echo number_format($total_revenue, 2); ?> Revenue
                    </span>
                    <a href="facility_scheduling.php" class="btn btn-sm btn-outline-primary ms-1">Block Hours</a>
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

        <!-- Summary KPI Strip -->
        <section class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="card card-custom p-4 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="icon-badge-soft">
                            <img src="../assets/Rental Calendar Icon.png" alt="Confirmed Rentals" width="32" height="32">
                        </div>
                        <div>
                            <p class="text-stone small fw-semibold text-uppercase mb-0">Active Reservations</p>
                            <h2 class="h3 fw-bold mb-0"><?php echo $confirmed_count; ?></h2>
                            <span class="small text-stone">Confirmed upcoming &amp; live</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card card-custom p-4 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="icon-badge-soft">
                            <img src="../assets/Court Icon.png" alt="Today's Schedule" width="32" height="32">
                        </div>
                        <div>
                            <p class="text-stone small fw-semibold text-uppercase mb-0">Today's Rentals</p>
                            <h2 class="h3 fw-bold mb-0"><?php echo $today_count; ?></h2>
                            <span class="small text-stone">Scheduled for <?php echo date("M j, Y"); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card card-custom p-4 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="icon-badge-soft fw-bold fs-4 text-primary">
                            ₱
                        </div>
                        <div>
                            <p class="text-stone small fw-semibold text-uppercase mb-0">Rental Revenue</p>
                            <h2 class="h3 fw-bold mb-0">₱<?php echo number_format($total_revenue, 2); ?></h2>
                            <span class="small text-stone">Confirmed &amp; completed</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Filter Bar -->
        <section class="card card-custom p-4 mb-4">
            <form method="GET" action="manage_rentals.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="court_id" class="form-label form-label-custom">Filter by Court</label>
                    <select id="court_id" name="court_id" class="form-select form-control-custom">
                        <option value="0">All Registered Courts</option>
                        <?php foreach ($manager_courts as $court): ?>
                            <option value="<?php echo (int)$court['court_id']; ?>" <?php echo ($filter_court_id === (int)$court['court_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($court['venue_name'] . " — " . $court['court_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-sm-6 col-md-3">
                    <label for="filter_date" class="form-label form-label-custom">Reservation Date</label>
                    <input type="date" id="filter_date" name="filter_date" class="form-control form-control-custom" value="<?php echo htmlspecialchars($filter_date); ?>">
                </div>

                <div class="col-12 col-sm-6 col-md-3">
                    <label for="status" class="form-label form-label-custom">Booking Status</label>
                    <select id="status" name="status" class="form-select form-control-custom">
                        <option value="ALL" <?php echo ($filter_status === 'ALL') ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="Confirmed" <?php echo ($filter_status === 'Confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="Completed" <?php echo ($filter_status === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="Cancelled" <?php echo ($filter_status === 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>

                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                    <?php if ($has_active_filters): ?>
                        <a href="manage_rentals.php" class="btn btn-light rounded-pill px-3 fw-semibold border">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Private Court Rentals Table -->
        <section class="card card-custom p-4">
            <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2 pb-3 mb-3 border-bottom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-badge-soft">
                        <img src="../assets/Rental Calendar Icon.png" alt="Private Rentals Schedule" width="32" height="32">
                    </div>
                    <div>
                        <h2 class="h5 fw-bold mb-0">Private Court Reservations</h2>
                        <p class="small text-stone mb-0">
                            Showing <?php echo $rentals_count; ?> reservation<?php echo $rentals_count === 1 ? '' : 's'; ?> across your facilities
                        </p>
                    </div>
                </div>
            </div>

            <?php if ($rentals_count > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr class="text-stone small text-uppercase">
                                <th class="border-0 pb-3">Ref #</th>
                                <th class="border-0 pb-3">Date &amp; Time</th>
                                <th class="border-0 pb-3">Court &amp; Venue</th>
                                <th class="border-0 pb-3">Player</th>
                                <th class="border-0 pb-3">Amount</th>
                                <th class="border-0 pb-3">Status</th>
                                <th class="border-0 pb-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rentals_list as $rental): ?>
                                <?php
                                    $bid        = (int)$rental['booking_id'];
                                    $b_date_raw = ($rental['booking_date'] instanceof DateTime)
                                        ? $rental['booking_date']->format('Y-m-d')
                                        : (string)$rental['booking_date'];
                                    $b_date_str = ($rental['booking_date'] instanceof DateTime)
                                        ? $rental['booking_date']->format('D, M j, Y')
                                        : $b_date_raw;
                                    $start_str  = ($rental['start_time'] instanceof DateTime)
                                        ? $rental['start_time']->format('g:i A')
                                        : (string)$rental['start_time'];
                                    $end_str    = ($rental['end_time'] instanceof DateTime)
                                        ? $rental['end_time']->format('g:i A')
                                        : (string)$rental['end_time'];

                                    $player_name   = trim($rental['first_name'] . ' ' . $rental['last_name']);
                                    $player_avatar = !empty($rental['avatar_url']) ? $rental['avatar_url'] : "default_avatar.png";
                                    $amount_str    = number_format((float)$rental['total_amount'], 2);
                                    $status_val    = $rental['status'];
                                    $is_today      = ($b_date_raw === $today);
                                ?>
                                <tr>
                                    <td class="fw-semibold text-stone small">
                                        #<?php echo $bid; ?>
                                    </td>

                                    <td>
                                        <div class="fw-semibold small">
                                            <?php echo htmlspecialchars($b_date_str); ?>
                                            <?php if ($is_today): ?>
                                                <span class="badge rounded-pill badge-soft-orange ms-1">Today</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-stone">
                                            <?php echo htmlspecialchars($start_str . " - " . $end_str); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="fw-semibold small"><?php echo htmlspecialchars($rental['court_name']); ?></div>
                                        <div class="small text-stone"><?php echo htmlspecialchars($rental['venue_name']); ?></div>
                                    </td>

                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="../avatars/<?php echo htmlspecialchars($player_avatar); ?>" onerror="this.src='../avatars/default_avatar.png';" alt="Player" class="nav-avatar" style="width: 34px; height: 34px;">
                                            <div>
                                                <div class="fw-semibold small"><?php echo htmlspecialchars($player_name); ?></div>
                                                <div class="small text-stone">
                                                    <?php echo htmlspecialchars($rental['mobile_number'] ?: $rental['email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="fw-semibold small">
                                        ₱<?php echo htmlspecialchars($amount_str); ?>
                                    </td>

                                    <td>
                                        <?php if ($status_val === 'Confirmed'): ?>
                                            <span class="badge rounded-pill badge-soft-orange px-3 py-1">Confirmed</span>
                                        <?php elseif ($status_val === 'Completed'): ?>
                                            <span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-1">Completed</span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-1">Cancelled</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-end">
                                        <?php if ($status_val === 'Confirmed'): ?>
                                            <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                                <form method="POST" action="manage_rentals.php" class="m-0">
                                                    <input type="hidden" name="action" value="mark_completed">
                                                    <input type="hidden" name="booking_id" value="<?php echo $bid; ?>">
                                                    <input type="hidden" name="return_court_id" value="<?php echo $filter_court_id; ?>">
                                                    <input type="hidden" name="return_filter_date" value="<?php echo htmlspecialchars($filter_date); ?>">
                                                    <input type="hidden" name="return_status" value="<?php echo htmlspecialchars($filter_status); ?>">
                                                    <button type="submit" class="btn btn-sm btn-soft-orange px-3">
                                                        Complete
                                                    </button>
                                                </form>

                                                <form method="POST" action="manage_rentals.php" class="m-0" onsubmit="return confirm('Cancel this player\'s private court reservation?');">
                                                    <input type="hidden" name="action" value="cancel_rental">
                                                    <input type="hidden" name="booking_id" value="<?php echo $bid; ?>">
                                                    <input type="hidden" name="return_court_id" value="<?php echo $filter_court_id; ?>">
                                                    <input type="hidden" name="return_filter_date" value="<?php echo htmlspecialchars($filter_date); ?>">
                                                    <input type="hidden" name="return_status" value="<?php echo htmlspecialchars($filter_status); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">
                                                        Cancel
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="small text-stone">No actions</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5 px-3 rounded-4 bg-light">
                    <img src="../assets/Rental Calendar Icon.png" alt="No Private Rentals" width="52" height="52" class="mb-3">
                    <h3 class="h6 fw-bold mb-1">No private court rentals found</h3>
                    <p class="small text-stone mb-3">
                        <?php if ($has_active_filters): ?>
                            Try clearing or adjusting your search filters to view other bookings.
                        <?php else: ?>
                            Confirmed player court reservations across your venues will appear here automatically.
                        <?php endif; ?>
                    </p>
                    <?php if ($has_active_filters): ?>
                        <a href="manage_rentals.php" class="btn btn-primary btn-sm px-4">Clear Filters</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

    </main>

    <!-- Bootstrap 5.2.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>