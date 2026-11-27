<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Player') {
    header("Location: ../login.php");
    exit();
}

require_once '../includes/db.php';

$user_id         = (int)$_SESSION['user_id'];
$first_name      = $_SESSION['first_name'];
$today           = date("Y-m-d");
$now_time        = date("H:i:s");
$formatted_today = date("l, F j, Y");
$active_page     = 'dashboard';

// Fetch player profile info for the header and navbar
$sql_profile = "SELECT avatar_url, skill_level, city FROM users WHERE user_id = ?";
$stmt_profile = sqlsrv_query($conn, $sql_profile, [$user_id]);

$display_avatar = "default_avatar.png";
$skill_level    = "Unrated";
$player_city    = "";

if ($stmt_profile && sqlsrv_has_rows($stmt_profile)) {
    $profile = sqlsrv_fetch_array($stmt_profile, SQLSRV_FETCH_ASSOC);
    if (!empty($profile['avatar_url'])) {
        $display_avatar = $profile['avatar_url'];
    }
    if (!empty($profile['skill_level'])) {
        $skill_level = $profile['skill_level'];
    }
    if (!empty($profile['city'])) {
        $player_city = $profile['city'];
    }
}

// Fetch confirmed upcoming private court rentals
$sql_rentals = "
    SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, b.total_amount,
           c.court_name, c.court_type, v.venue_name, v.address
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

$upcoming_rentals = [];
if ($stmt_rentals && sqlsrv_has_rows($stmt_rentals)) {
    while ($row = sqlsrv_fetch_array($stmt_rentals, SQLSRV_FETCH_ASSOC)) {
        $upcoming_rentals[] = $row;
    }
}

// Fetch upcoming Open Play sessions joined by the player (grouped per session so guest slots don't duplicate cards)
$sql_open_plays = "
    SELECT o.play_id, o.title, o.play_date, o.start_time, o.end_time, o.skill_level_req,
           c.court_name, v.venue_name, COUNT(r.roster_id) AS reserved_spots
    FROM open_play_roster AS r
    INNER JOIN bookings AS rb ON r.booking_id = rb.booking_id
    INNER JOIN open_plays AS o ON r.play_id = o.play_id
    INNER JOIN courts AS c ON o.court_id = c.court_id
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    WHERE r.user_id = ?
      AND rb.status = 'Confirmed'
      AND (o.play_date > ? OR (o.play_date = ? AND o.end_time > ?))
    GROUP BY o.play_id, o.title, o.play_date, o.start_time, o.end_time, o.skill_requirement, c.court_name, v.venue_name
    ORDER BY o.play_date ASC, o.start_time ASC
";
$stmt_plays = sqlsrv_query($conn, $sql_open_plays, [$user_id, $today, $today, $now_time]);

$upcoming_plays = [];
if ($stmt_plays && sqlsrv_has_rows($stmt_plays)) {
    while ($row = sqlsrv_fetch_array($stmt_plays, SQLSRV_FETCH_ASSOC)) {
        $upcoming_plays[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Player Dashboard - RALLIQ</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <link rel="stylesheet" href="../ralliq-theme.css?v=4">
</head>
<body>

    <?php require_once '../includes/player_nav.php'; ?>

    <!-- Player Welcome Header -->
    <header class="page-header-strip">
        <div class="container">
            <div class="row align-items-center gy-3">
                <div class="col-12 col-lg-7 d-flex align-items-center gap-3">
                    <img src="../avatars/<?php echo htmlspecialchars($display_avatar); ?>"
                         onerror="this.src='../avatars/default_avatar.png';"
                         alt="Profile Avatar"
                         class="avatar-circle flex-shrink-0">
                    <div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="badge rounded-pill badge-slate px-3 py-1">Skill: <?php echo htmlspecialchars($skill_level); ?></span>
                            <?php if ($player_city !== ''): ?>
                                <span class="small text-stone">&bull; <?php echo htmlspecialchars($player_city); ?></span>
                            <?php endif; ?>
                        </div>
                        <h1 class="h3 fw-bold mb-1">Welcome back, <?php echo htmlspecialchars($first_name); ?>!</h1>
                        <p class="small text-stone mb-0"><?php echo htmlspecialchars($formatted_today); ?></p>
                    </div>
                </div>

                <div class="col-12 col-lg-5 d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a href="discover.php" class="btn btn-primary px-4">+ Book a Court</a>
                    <a href="open_plays.php" class="btn btn-outline-primary px-4">Join Open Play</a>
                </div>
            </div>
        </div>
    </header>

    <main class="container pb-5">

        <!-- Quick Summary Row -->
        <section class="row g-4 mb-5">
            <div class="col-12 col-md-4">
                <div class="card card-custom h-100 p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="icon-badge-soft">
                            <img src="../assets/Rental Calendar Icon.png" alt="Private Rentals" width="30" height="30" style="object-fit: contain;">
                        </div>
                        <div>
                            <p class="small text-stone text-uppercase fw-semibold mb-0">Upcoming Rentals</p>
                            <h2 class="h3 fw-bold mb-0"><?php echo count($upcoming_rentals); ?></h2>
                        </div>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between align-items-center">
                        <span class="small text-stone">Confirmed court slots</span>
                        <a href="discover.php" class="small fw-semibold text-orange text-decoration-none">Reserve &rarr;</a>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card card-custom h-100 p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="icon-badge-soft">
                            <img src="../assets/Open Play Session Icon.png" alt="Open Plays" width="30" height="30" style="object-fit: contain;">
                        </div>
                        <div>
                            <p class="small text-stone text-uppercase fw-semibold mb-0">Joined Open Plays</p>
                            <h2 class="h3 fw-bold mb-0"><?php echo count($upcoming_plays); ?></h2>
                        </div>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between align-items-center">
                        <span class="small text-stone">Active drop-in games</span>
                        <a href="open_plays.php" class="small fw-semibold text-orange text-decoration-none">Browse &rarr;</a>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card card-custom h-100 p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="icon-badge-soft">
                            <img src="../assets/Court Icon.png" alt="Match History" width="30" height="30" style="object-fit: contain;">
                        </div>
                        <div>
                            <p class="small text-stone text-uppercase fw-semibold mb-0">Activity &amp; Receipts</p>
                            <h2 class="h5 fw-bold mb-0">Booking Ledger</h2>
                        </div>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between align-items-center">
                        <span class="small text-stone">Past &amp; cancelled games</span>
                        <a href="play_history.php" class="small fw-semibold text-orange text-decoration-none">View History &rarr;</a>
                    </div>
                </div>
            </div>
        </section>

        <!-- View 1: Upcoming Private Court Rentals -->
        <section class="mb-5">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="h4 fw-bold mb-0">My Upcoming Private Rentals</h2>
                    <p class="small text-stone mb-0">Confirmed private court reservations</p>
                </div>
                <a href="discover.php" class="btn btn-sm btn-soft-orange px-3">Find Courts</a>
            </div>

            <?php if (count($upcoming_rentals) > 0): ?>
                <div class="row g-4">
                    <?php foreach ($upcoming_rentals as $rental): ?>
                        <?php
                            $r_date  = $rental['booking_date']->format('M j, Y (D)');
                            $r_start = $rental['start_time']->format('g:i A');
                            $r_end   = $rental['end_time']->format('g:i A');
                        ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="card booking-card p-4">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <span class="badge rounded-pill badge-soft-orange px-3 py-1"><?php echo htmlspecialchars($r_date); ?></span>
                                    <span class="fw-bold small text-orange">&#8369;<?php echo number_format((float)$rental['total_amount'], 2); ?></span>
                                </div>
                                <h3 class="h5 fw-bold mb-1"><?php echo htmlspecialchars($rental['court_name']); ?></h3>
                                <p class="small fw-medium mb-2"><?php echo htmlspecialchars($rental['venue_name']); ?></p>
                                <p class="small text-stone mb-3">
                                    <?php echo htmlspecialchars($r_start . ' - ' . $r_end); ?> &bull; <?php echo htmlspecialchars($rental['court_type']); ?>
                                </p>
                                <div class="mt-auto pt-3 border-top d-flex justify-content-between align-items-center">
                                    <span class="small text-stone text-truncate me-2"><?php echo htmlspecialchars($rental['venue_address']); ?></span>
                                    <span class="badge bg-success bg-opacity-10 text-success rounded-pill">Confirmed</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card card-custom p-5 text-center">
                    <img src="../assets/Rental Calendar Icon.png" alt="No Rentals" width="52" height="52" class="mx-auto mb-3" style="object-fit: contain;">
                    <h3 class="h5 fw-bold mb-1">No Upcoming Private Rentals</h3>
                    <p class="small text-stone mb-3">Reserve a court for solo practice or a private match with friends.</p>
                    <div>
                        <a href="discover.php" class="btn btn-primary btn-sm px-4">Discover Courts</a>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <!-- View 2: Upcoming Joined Open Plays -->
        <section>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="h4 fw-bold mb-0">My Upcoming Open Plays</h2>
                    <p class="small text-stone mb-0">Drop-in sessions you are registered to play</p>
                </div>
                <a href="open_plays.php" class="btn btn-sm btn-soft-orange px-3">Browse Sessions</a>
            </div>

            <?php if (count($upcoming_plays) > 0): ?>
                <div class="card card-custom overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle table-ralliq mb-0">
                            <thead>
                                <tr>
                                    <th>Session</th>
                                    <th>Venue &amp; Court</th>
                                    <th>Date &amp; Time</th>
                                    <th>Skill Tier</th>
                                    <th>Spots</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcoming_plays as $play): ?>
                                    <?php
                                        $p_date  = $play['play_date']->format('M j, Y');
                                        $p_time  = $play['start_time']->format('g:i A') . ' - ' . $play['end_time']->format('g:i A');
                                        $spots   = (int)$play['reserved_spots'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <img src="../assets/Open Play Session Icon.png" alt="Open Play" width="38" height="38" style="object-fit: contain;">
                                                <span class="fw-bold"><?php echo htmlspecialchars($play['title']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-medium"><?php echo htmlspecialchars($play['venue_name']); ?></div>
                                            <div class="small text-stone"><?php echo htmlspecialchars($play['court_name']); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-medium"><?php echo htmlspecialchars($p_date); ?></div>
                                            <div class="small text-stone"><?php echo htmlspecialchars($p_time); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge rounded-pill badge-slate px-3 py-1"><?php echo htmlspecialchars($play['skill_requirement']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge rounded-pill badge-soft-orange px-3 py-1">
                                                <?php echo $spots . ($spots === 1 ? ' Player' : ' Players'); ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <a href="open_play_details.php?play_id=<?php echo (int)$play['play_id']; ?>" class="btn btn-sm btn-outline-primary px-3">
                                                View Roster
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="card card-custom p-5 text-center">
                    <img src="../assets/Open Play Session Icon.png" alt="No Open Plays" width="52" height="52" class="mx-auto mb-3" style="object-fit: contain;">
                    <h3 class="h5 fw-bold mb-1">No Joined Open Play Sessions</h3>
                    <p class="small text-stone mb-3">Join a scheduled Open Play session to match up with local players.</p>
                    <div>
                        <a href="open_plays.php" class="btn btn-primary btn-sm px-4">Find Open Plays</a>
                    </div>
                </div>
            <?php endif; ?>
        </section>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>