<?php
session_start();

// Enforce Role-Based Access Control (RBAC)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Player') {
    header("Location: ../login.php");
    exit();
}

date_default_timezone_set('Asia/Manila');
$today = date("Y-m-d");
$now_time = date("H:i:s");

$user_id = (int)$_SESSION['user_id'];
$first_name = $_SESSION['first_name'];

// Database Connection
$serverName = "LAPTOP-QIJJ9RAT\SQLEXPRESS";
$connectionOptions = [
    "Database" => "FinalProject",
    "Uid" => "",
    "PWD" => ""
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die(print_r(sqlsrv_errors(), true));
}

// 1. Fetch Player Avatar & Profile Info (Parameterized)
$avatar_filename = "default_avatar.png";
$skill_level = "";
$sql_user = "SELECT avatar_url, last_name, skill_level FROM users WHERE user_id = ?";
$stmt_user = sqlsrv_query($conn, $sql_user, [$user_id]);
if ($stmt_user && sqlsrv_has_rows($stmt_user)) {
    $row = sqlsrv_fetch_array($stmt_user, SQLSRV_FETCH_ASSOC);
    if (!empty($row['avatar_url'])) {
        $avatar_filename = $row['avatar_url'];
    }
    $skill_level = $row['skill_level'];
}

// 2. Fetch Upcoming Confirmed Private Rentals (Excludes Cancelled / Past End Times)
$sql_rentals = "
    SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, c.court_name, v.venue_name,
           (SELECT TOP(1) cp.picture_url FROM court_pictures AS cp WHERE cp.court_id = c.court_id ORDER BY cp.picture_id ASC) AS court_photo
    FROM bookings AS b
    INNER JOIN courts AS c ON b.court_id = c.court_id
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE b.user_id = ?
      AND b.booking_type = 'Private_Rental'
      AND b.status = 'Confirmed'
      AND (b.booking_date > ? OR (b.booking_date = ? AND b.end_time > ?))
    ORDER BY b.booking_date ASC, b.start_time ASC
";
$stmt_rentals = sqlsrv_query($conn, $sql_rentals, [$user_id, $today, $today, $now_time]);

// 3. Fetch Upcoming Enrolled Open Plays (Active & Future End Times Only)
$sql_open_plays = "
    SELECT op.play_id, op.title, op.play_date, op.start_time, op.end_time, c.court_name, v.venue_name,
           COUNT(opr.roster_id) AS reserved_spots
    FROM open_play_roster AS opr
    INNER JOIN open_plays AS op ON opr.play_id = op.play_id
    INNER JOIN courts AS c ON op.court_id = c.court_id
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    INNER JOIN bookings AS b ON opr.booking_id = b.booking_id
    WHERE opr.user_id = ?
      AND b.status = 'Confirmed'
      AND (op.play_date > ? OR (op.play_date = ? AND op.end_time > ?))
    GROUP BY op.play_id, op.title, op.play_date, op.start_time, op.end_time, c.court_name, v.venue_name
    ORDER BY op.play_date ASC, op.start_time ASC
";
$stmt_open_plays = sqlsrv_query($conn, $sql_open_plays, [$user_id, $today, $today, $now_time]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Player Dashboard - RALLIQ</title>

    <!-- Bootstrap 5.2.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">

    <!-- RALLIQ Organic Sport Theme -->
    <link rel="stylesheet" href="../ralliq-theme.css?v=3">
</head>
<body>

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg bg-white sticky-top shadow-sm py-3">
        <div class="container">
            <a class="navbar-brand" href="player_dashboard.php">
                <img src="../assets/RalliQ Main Logo.png" alt="RALLIQ" height="30">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#playerNav" aria-controls="playerNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="playerNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4 gap-lg-2 fw-semibold">
                    <li class="nav-item"><a class="nav-link active" style="color: var(--color-forest);" aria-current="page" href="player_dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link text-stone" href="discover.php">Discover Courts</a></li>
                    <li class="nav-item"><a class="nav-link text-stone" href="open_plays.php">Open Play</a></li>
                    <li class="nav-item"><a class="nav-link text-stone" href="play_history.php">Play History</a></li>
                </ul>

                <div class="d-flex align-items-center gap-3">
                    <a href="edit_profile.php" class="text-decoration-none text-stone fw-semibold d-flex align-items-center">
                        <img src="../avatars/<?php echo htmlspecialchars($avatar_filename); ?>" onerror="this.src='../avatars/default_avatar.png';" alt="Profile" class="nav-avatar me-2">
                        My Profile
                    </a>
                    <a href="../logout.php" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container py-5">

        <!-- Welcome Banner -->
        <div class="dashboard-banner d-flex flex-column flex-sm-row align-items-start align-items-sm-center gap-4" style="background-image: url('../assets/Player Welcome Banner.png');">
            <img src="../avatars/<?php echo htmlspecialchars($avatar_filename); ?>" onerror="this.src='../avatars/default_avatar.png';" alt="Profile Avatar" class="avatar-circle shadow-sm">
            <div>
                <h1 class="h2 fw-bold mb-1">Welcome back, <?php echo htmlspecialchars($first_name); ?>!</h1>
                <p class="mb-0 fw-semibold text-stone">
                    Skill Level: <span style="color: var(--color-forest);"><?php echo htmlspecialchars(!empty($skill_level) ? $skill_level : 'Not Specified'); ?></span>
                </p>
            </div>
        </div>

        <div class="row g-5">

            <!-- Left Column: Upcoming Private Rentals -->
            <div class="col-12 col-lg-6">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="h4 fw-bold mb-0">Upcoming Court Rentals</h2>
                    <a href="discover.php" class="btn btn-sm btn-primary-custom">Book a Court</a>
                </div>

                <div class="row g-4">
                    <?php if ($stmt_rentals && sqlsrv_has_rows($stmt_rentals)): ?>
                        <?php while ($rental = sqlsrv_fetch_array($stmt_rentals, SQLSRV_FETCH_ASSOC)): ?>
                            <?php
                                $dateObj = $rental['booking_date'];
                                $displayDate = ($dateObj instanceof DateTime) ? $dateObj->format('D, M j, Y') : $dateObj;
                                $displayStart = ($rental['start_time'] instanceof DateTime) ? $rental['start_time']->format('g:i A') : $rental['start_time'];
                                $displayEnd = ($rental['end_time'] instanceof DateTime) ? $rental['end_time']->format('g:i A') : $rental['end_time'];
                                $courtPhoto = $rental['court_photo'];
                            ?>
                            <div class="col-12">
                                <div class="card booking-card">
                                    <?php if (!empty($courtPhoto)): ?>
                                        <img src="../court_images/<?php echo htmlspecialchars($courtPhoto); ?>" alt="<?php echo htmlspecialchars($rental['court_name']); ?>" class="court-thumbnail w-100">
                                    <?php else: ?>
                                        <div class="court-thumbnail w-100 d-flex align-items-center justify-content-center">
                                            <span class="text-white opacity-75 fw-semibold">Court Image Available Soon</span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="card-body p-4">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h5 class="fw-bold mb-0"><?php echo htmlspecialchars($rental['court_name']); ?></h5>
                                            <span class="badge rounded-pill px-3 py-2 fw-semibold" style="background-color: rgba(214, 255, 79, 0.35); color: var(--color-forest);">Confirmed</span>
                                        </div>
                                        <p class="text-stone mb-3"><small><?php echo htmlspecialchars($rental['venue_name']); ?></small></p>

                                        <div class="p-3 bg-light rounded-3">
                                            <div class="d-flex align-items-center mb-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="var(--color-forest)" class="bi bi-calendar-event me-2" viewBox="0 0 16 16"><path d="M11 6.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5v-1z"/><path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/></svg>
                                                <span class="fw-semibold text-dark"><?php echo htmlspecialchars($displayDate); ?></span>
                                            </div>
                                            <div class="d-flex align-items-center">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="var(--color-forest)" class="bi bi-clock me-2" viewBox="0 0 16 16"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/></svg>
                                                <span class="text-dark"><?php echo htmlspecialchars($displayStart . " - " . $displayEnd); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="p-5 text-center bg-white rounded-4 shadow-sm">
                                <p class="text-stone mb-3">You have no upcoming private rentals.</p>
                                <a href="discover.php" class="btn btn-sm btn-primary-custom">Browse Available Courts</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Enrolled Open Plays -->
            <div class="col-12 col-lg-6">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="h4 fw-bold mb-0">Enrolled Open Plays</h2>
                    <a href="open_plays.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold" style="color: var(--color-forest); border-color: var(--color-forest);">Find Games</a>
                </div>

                <div class="row g-4">
                    <?php if ($stmt_open_plays && sqlsrv_has_rows($stmt_open_plays)): ?>
                        <?php while ($play = sqlsrv_fetch_array($stmt_open_plays, SQLSRV_FETCH_ASSOC)): ?>
                            <?php
                                $dateObj = $play['play_date'];
                                $displayDate = ($dateObj instanceof DateTime) ? $dateObj->format('D, M j, Y') : $dateObj;
                                $displayStart = ($play['start_time'] instanceof DateTime) ? $play['start_time']->format('g:i A') : $play['start_time'];
                                $displayEnd = ($play['end_time'] instanceof DateTime) ? $play['end_time']->format('g:i A') : $play['end_time'];
                                $spots = (int)$play['reserved_spots'];
                            ?>
                            <div class="col-12">
                                <div class="card booking-card bg-white p-4">
                                    <div class="d-flex gap-4 align-items-center">
                                        <div class="flex-shrink-0 text-center">
                                            <img src="../assets/Open Play Session Icon.png" alt="Open Play" class="open-play-icon mb-2">
                                            <span class="d-block badge rounded-pill" style="background-color: var(--color-lime); color: var(--color-forest);">
                                                <?php echo $spots . ($spots === 1 ? " Spot" : " Spots"); ?>
                                            </span>
                                        </div>

                                        <div class="flex-grow-1">
                                            <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($play['title']); ?></h5>
                                            <p class="text-stone mb-2 small"><?php echo htmlspecialchars($play['venue_name'] . " - " . $play['court_name']); ?></p>

                                            <div class="d-flex flex-wrap gap-3">
                                                <div class="d-flex align-items-center">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-calendar-event text-secondary me-1" viewBox="0 0 16 16"><path d="M11 6.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5v-1z"/><path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/></svg>
                                                    <span class="small fw-semibold"><?php echo htmlspecialchars($displayDate); ?></span>
                                                </div>
                                                <div class="d-flex align-items-center">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-clock text-secondary me-1" viewBox="0 0 16 16"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/></svg>
                                                    <span class="small"><?php echo htmlspecialchars($displayStart . " - " . $displayEnd); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="p-5 text-center bg-white rounded-4 shadow-sm">
                                <p class="text-stone mb-3">You haven't joined any upcoming Open Play sessions.</p>
                                <a href="open_plays.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold" style="color: var(--color-forest); border-color: var(--color-forest);">Explore Open Plays</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- Bootstrap 5.2.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>