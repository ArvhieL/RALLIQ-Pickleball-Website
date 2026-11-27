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

// 4. Stat 1: Count Truly Active Courts (Operational AND not currently in a blocked time slot)
$sql_courts = "
    SELECT COUNT(*) AS total_courts
    FROM courts AS c
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE v.manager_id = ?
      AND c.is_active = 1
      AND c.court_id NOT IN (
          SELECT b.court_id
          FROM bookings AS b
          WHERE b.booking_date = ?
            AND b.status = 'Cancelled'
            AND b.total_amount = 0.00
            AND b.start_time <= ?
            AND b.end_time > ?
      )
";
$res_courts = sqlsrv_query($conn, $sql_courts, [$user_id, $today, $now_time, $now_time]);
$total_courts = 0;
if ($res_courts && sqlsrv_has_rows($res_courts)) {
    $row = sqlsrv_fetch_array($res_courts, SQLSRV_FETCH_ASSOC);
    $total_courts = (int)$row['total_courts'];
}

// 5. Stat 2 & Table Data: Fetch Today's Confirmed Private Rentals (Excludes $0 Manager Blocks)
$sql_rentals = "
    SELECT b.booking_id, b.start_time, b.end_time, c.court_name, u.first_name, u.last_name
    FROM bookings AS b
    INNER JOIN courts AS c ON b.court_id = c.court_id
    INNER JOIN users AS u ON b.user_id = u.user_id
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE v.manager_id = ?
      AND b.booking_type = 'Private_Rental'
      AND b.status = 'Confirmed'
      AND b.booking_date = ?
    ORDER BY b.start_time ASC
";
$res_rentals = sqlsrv_query($conn, $sql_rentals, [$user_id, $today]);

$todays_rentals = [];
if ($res_rentals && sqlsrv_has_rows($res_rentals)) {
    while ($row = sqlsrv_fetch_array($res_rentals, SQLSRV_FETCH_ASSOC)) {
        $todays_rentals[] = $row;
    }
}
$todays_rentals_count = count($todays_rentals);

// 6. Stat 3 & List Data: Fetch Active & Upcoming Open Plays with Confirmed Roster Counts
$sql_open_plays = "
    SELECT o.play_id, o.title, o.play_date, o.start_time, o.end_time, o.max_slots, c.court_name,
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
$res_open_plays = sqlsrv_query($conn, $sql_open_plays, [$user_id, $today, $today, $now_time]);

$upcoming_plays = [];
if ($res_open_plays && sqlsrv_has_rows($res_open_plays)) {
    while ($row = sqlsrv_fetch_array($res_open_plays, SQLSRV_FETCH_ASSOC)) {
        $upcoming_plays[] = $row;
    }
}
$upcoming_plays_count = count($upcoming_plays);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - RALLIQ</title>

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
                        <a class="nav-link active" aria-current="page" href="manager_dashboard.php">Dashboard</a>
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
                    <h1 class="h2 fw-bold mb-1">Manager Dashboard</h1>
                    <p class="text-stone mb-0">
                        Welcome back, <strong><?php echo htmlspecialchars($first_name); ?></strong> &bull;
                        <?php echo htmlspecialchars($formatted_today); ?> &bull;
                        <?php echo htmlspecialchars($manager_city); ?>
                    </p>
                </div>
                <div class="col-12 col-lg-5 d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a href="facility_scheduling.php" class="btn btn-outline-primary">Block Court Hours</a>
                    <a href="manage_open_plays.php" class="btn btn-primary">+ New Open Play</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Dashboard Content -->
    <main class="container pb-5">

        <!-- Row 1: 3 Summary Metric Cards -->
        <section class="row g-4 mb-4">
            <!-- Card 1: Active Courts -->
            <div class="col-12 col-md-4">
                <div class="card card-custom h-100">
                    <div class="card-body p-4 d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-stone small fw-semibold text-uppercase mb-1">Active Courts</p>
                            <h2 class="display-6 fw-bold mb-1"><?php echo $total_courts; ?></h2>
                            <span class="small text-stone">Available right now</span>
                        </div>
                        <div class="icon-badge-soft">
                            <img src="../assets/Court Icon.png" alt="Active Courts" width="32" height="32">
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-top px-4 py-3 d-flex justify-content-between align-items-center">
                        <span class="small text-stone">Venue catalog</span>
                        <a href="manage_courts.php" class="small fw-semibold text-orange text-decoration-none">Manage Courts &rarr;</a>
                    </div>
                </div>
            </div>

            <!-- Card 2: Today's Private Rentals -->
            <div class="col-12 col-md-4">
                <div class="card card-custom h-100">
                    <div class="card-body p-4 d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-stone small fw-semibold text-uppercase mb-1">Today's Rentals</p>
                            <h2 class="display-6 fw-bold mb-1"><?php echo $todays_rentals_count; ?></h2>
                            <span class="small text-stone">Scheduled for <?php echo date("M j"); ?></span>
                        </div>
                        <div class="icon-badge-soft">
                            <img src="../assets/Rental Calendar Icon.png" alt="Today's Rentals" width="32" height="32">
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-top px-4 py-3 d-flex justify-content-between align-items-center">
                        <span class="small text-stone">Confirmed bookings</span>
                        <a href="manage_rentals.php" class="small fw-semibold text-orange text-decoration-none">View Schedule &rarr;</a>
                    </div>
                </div>
            </div>

            <!-- Card 3: Upcoming Open Plays -->
            <div class="col-12 col-md-4">
                <div class="card card-custom h-100">
                    <div class="card-body p-4 d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-stone small fw-semibold text-uppercase mb-1">Open Play Sessions</p>
                            <h2 class="display-6 fw-bold mb-1"><?php echo $upcoming_plays_count; ?></h2>
                            <span class="small text-stone">Active &amp; upcoming</span>
                        </div>
                        <div class="icon-badge-soft">
                            <img src="../assets/Open Play Session Icon.png" alt="Open Play Sessions" width="32" height="32">
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-top px-4 py-3 d-flex justify-content-between align-items-center">
                        <span class="small text-stone">Drop-in rosters</span>
                        <a href="manage_open_plays.php" class="small fw-semibold text-orange text-decoration-none">Manage Plays &rarr;</a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Row 2: Two-Column Operational Schedules -->
        <section class="row g-4">
            <!-- Left Column: Today's Private Court Rentals -->
            <div class="col-12 col-lg-7">
                <div class="card card-custom h-100 p-4">
                    <div class="d-flex align-items-center justify-content-between pb-3 mb-3 border-bottom">
                        <div>
                            <h3 class="h5 fw-bold mb-0">Private Court Rentals</h3>
                            <p class="small text-stone mb-0">Confirmed player reservations for today</p>
                        </div>
                        <a href="manage_rentals.php" class="btn btn-sm btn-soft-orange px-3">View All</a>
                    </div>

                    <?php if ($todays_rentals_count > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-ralliq align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Court</th>
                                        <th>Renter</th>
                                        <th class="text-end">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($todays_rentals as $rental): ?>
                                        <?php
                                            $start = $rental['start_time']->format('g:i A');
                                            $end = $rental['end_time']->format('g:i A');
                                            $renter = $rental['first_name'] . ' ' . $rental['last_name'];
                                        ?>
                                        <tr>
                                            <td class="fw-semibold small"><?php echo htmlspecialchars($start . ' - ' . $end); ?></td>
                                            <td class="small"><?php echo htmlspecialchars($rental['court_name']); ?></td>
                                            <td class="small"><?php echo htmlspecialchars($renter); ?></td>
                                            <td class="text-end">
                                                <span class="badge rounded-pill badge-soft-orange px-3 py-1">Confirmed</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 px-3 rounded-4 bg-light">
                            <img src="../assets/Rental Calendar Icon.png" alt="No Rentals" width="48" height="48" class="mb-3">
                            <h4 class="h6 fw-bold mb-1">No private rentals today</h4>
                            <p class="small text-stone mb-3">All court slots are currently open or reserved for Open Play.</p>
                            <a href="facility_scheduling.php" class="btn btn-sm btn-primary">Manage Court Hours</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Upcoming Open Plays -->
            <div class="col-12 col-lg-5">
                <div class="card card-custom h-100 p-4">
                    <div class="d-flex align-items-center justify-content-between pb-3 mb-3 border-bottom">
                        <div>
                            <h3 class="h5 fw-bold mb-0">Upcoming Open Plays</h3>
                            <p class="small text-stone mb-0">Active public drop-in rosters</p>
                        </div>
                        <a href="manage_open_plays.php" class="btn btn-sm btn-soft-orange px-3">Manage All</a>
                    </div>

                    <?php if ($upcoming_plays_count > 0): ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($upcoming_plays as $play): ?>
                                <?php
                                    $play_date = $play['play_date']->format('M j, Y');
                                    $start = $play['start_time']->format('g:i A');
                                    $end = $play['end_time']->format('g:i A');
                                    $booked = (int)$play['booked_slots'];
                                    $max = (int)$play['max_slots'];
                                ?>
                                <div class="roster-item-card d-flex align-items-center justify-content-between gap-3">
                                    <div>
                                        <h4 class="h6 fw-bold mb-1"><?php echo htmlspecialchars($play['title']); ?></h4>
                                        <p class="small text-stone mb-0">
                                            <?php echo htmlspecialchars($play_date); ?> &bull; <?php echo htmlspecialchars($start . ' - ' . $end); ?>
                                        </p>
                                        <span class="small fw-medium text-orange"><?php echo htmlspecialchars($play['court_name']); ?></span>
                                    </div>
                                    <span class="badge rounded-pill badge-soft-orange px-3 py-2">
                                        <?php echo $booked . '/' . $max; ?> spots
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 px-3 rounded-4 bg-light">
                            <img src="../assets/Open Play Session Icon.png" alt="No Open Plays" width="48" height="48" class="mb-3">
                            <h4 class="h6 fw-bold mb-1">No upcoming Open Plays</h4>
                            <p class="small text-stone mb-3">Publish a drop-in session so players can join.</p>
                            <a href="manage_open_plays.php" class="btn btn-sm btn-primary">+ Create Open Play</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

    </main>

    <!-- Bootstrap 5.2.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>