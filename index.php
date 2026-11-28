<?php
session_start();
require_once 'includes/db.php';

$is_logged_in  = isset($_SESSION['user_id']) && isset($_SESSION['role']);
$user_role     = $_SESSION['role'] ?? '';
$dashboard_url = ($user_role === 'Manager')
    ? 'ManagerDashboard/manager_dashboard.php'
    : 'PlayerDashboard/player_dashboard.php';

// 1. Live Platform Metrics from SQL Server Tables
$stats = [
    'venues'   => 0,
    'courts'   => 0,
    'players'  => 0,
    'sessions' => 0,
];

if (isset($conn) && $conn) {
    $sql_stats = "
        SELECT
            (SELECT COUNT(*) FROM dbo.venues) AS total_venues,
            (SELECT COUNT(*) FROM dbo.courts WHERE is_active = 1) AS total_courts,
            (SELECT COUNT(*) FROM dbo.users WHERE role = 'Player') AS total_players,
            (SELECT COUNT(*) FROM dbo.open_plays) AS total_sessions
    ";
    $stmt_stats = sqlsrv_query($conn, $sql_stats);
    if ($stmt_stats && ($row = sqlsrv_fetch_array($stmt_stats, SQLSRV_FETCH_ASSOC))) {
        $stats['venues']   = (int)$row['total_venues'];
        $stats['courts']   = (int)$row['total_courts'];
        $stats['players']  = (int)$row['total_players'];
        $stats['sessions'] = (int)$row['total_sessions'];
    }
}

// 2. Location Search & Quick City Filter Pills (dbo.venues)
$search_location  = trim($_GET['location'] ?? '');
$available_cities = [];
$searched_venues  = [];

if (isset($conn) && $conn) {
    $sql_cities = "
        SELECT DISTINCT LTRIM(RTRIM(city)) AS city_name
        FROM dbo.venues
        WHERE city IS NOT NULL AND LTRIM(RTRIM(city)) <> ''
        ORDER BY city_name ASC
    ";
    $stmt_cities = sqlsrv_query($conn, $sql_cities);
    if ($stmt_cities) {
        while ($c_row = sqlsrv_fetch_array($stmt_cities, SQLSRV_FETCH_ASSOC)) {
            $available_cities[] = $c_row['city_name'];
        }
    }

    if ($search_location !== '') {
        $like_term = '%' . $search_location . '%';
        $sql_venues = "
            SELECT
                v.venue_id,
                v.venue_name,
                v.address,
                v.city,
                v.venue_photo,
                (
                    SELECT COUNT(*)
                    FROM dbo.courts AS c
                    WHERE c.venue_id = v.venue_id AND c.is_active = 1
                ) AS active_courts
            FROM dbo.venues AS v
            WHERE v.city LIKE ?
               OR v.address LIKE ?
               OR v.venue_name LIKE ?
            ORDER BY v.venue_name ASC
        ";
        $stmt_venues = sqlsrv_query($conn, $sql_venues, [$like_term, $like_term, $like_term]);
    } else {
        $sql_venues = "
            SELECT TOP 6
                v.venue_id,
                v.venue_name,
                v.address,
                v.city,
                v.venue_photo,
                (
                    SELECT COUNT(*)
                    FROM dbo.courts AS c
                    WHERE c.venue_id = v.venue_id AND c.is_active = 1
                ) AS active_courts
            FROM dbo.venues AS v
            ORDER BY v.date_created DESC, v.venue_id DESC
        ";
        $stmt_venues = sqlsrv_query($conn, $sql_venues);
    }

    if ($stmt_venues) {
        while ($v_row = sqlsrv_fetch_array($stmt_venues, SQLSRV_FETCH_ASSOC)) {
            $searched_venues[] = $v_row;
        }
    }
}

// 3. Upcoming Open Play Sessions Preview (dbo.open_plays)
$upcoming_plays = [];
if (isset($conn) && $conn) {
    $today = date('Y-m-d');
    $sql_plays = "
        SELECT TOP 3
            o.play_id,
            o.title,
            o.skill_level_req,
            o.play_date,
            o.start_time,
            o.end_time,
            o.max_slots,
            c.court_name,
            v.venue_name,
            v.city,
            (
                SELECT COUNT(*)
                FROM dbo.open_play_roster AS r
                WHERE r.play_id = o.play_id
            ) AS booked_slots
        FROM dbo.open_plays AS o
        INNER JOIN dbo.courts AS c ON o.court_id = c.court_id
        INNER JOIN dbo.venues AS v ON c.venue_id = v.venue_id
        WHERE c.is_active = 1
          AND o.play_date >= ?
        ORDER BY o.play_date ASC, o.start_time ASC
    ";
    $stmt_plays = sqlsrv_query($conn, $sql_plays, [$today]);
    if ($stmt_plays) {
        while ($p_row = sqlsrv_fetch_array($stmt_plays, SQLSRV_FETCH_ASSOC)) {
            $upcoming_plays[] = $p_row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RALLIQ — Philippine Pickleball Court Booking &amp; Open Play Portal</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <link rel="stylesheet" href="ralliq-theme.css?v=5">
</head>
<body>

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-ralliq sticky-top py-3">
        <div class="container container-landing">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <img src="assets/RalliQ Main Logo.png" alt="RALLIQ Logo" height="32">
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#landingNav" aria-controls="landingNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="landingNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4 gap-lg-2">
                    <li class="nav-item"><a class="nav-link" href="#venue-search">Search Venues</a></li>
                    <li class="nav-item"><a class="nav-link" href="#features">Platform</a></li>
                    <li class="nav-item"><a class="nav-link" href="#open-plays">Open Plays</a></li>
                    <li class="nav-item"><a class="nav-link" href="#roles">Players &amp; Managers</a></li>
                    <li class="nav-item"><a class="nav-link" href="#faq">FAQ</a></li>
                </ul>

                <div class="d-flex align-items-center gap-2">
                    <?php if ($is_logged_in): ?>
                        <a href="<?php echo htmlspecialchars($dashboard_url); ?>" class="btn btn-primary btn-sm px-4">My Dashboard</a>
                        <a href="logout.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">Logout</a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-outline-primary btn-sm px-4">Log In</a>
                        <a href="signup.php" class="btn btn-primary btn-sm px-4">Sign Up</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Centered Editorial Hero & Live Server Metrics -->
    <header class="py-5 bg-white baseline-x text-center">
        <div class="container container-landing py-2">
            <span class="badge rounded-pill badge-soft-orange px-3 py-2 mb-3">
                PHILIPPINES PICKLEBALL COURT &amp; OPEN PLAY NETWORK
            </span>

            <h1 class="display-4 fw-bold mb-3">
                BUILT FOR EVERY RALLY.
            </h1>

            <p class="lead text-stone mx-auto mb-4 hero-subtitle">
                One unified booking portal for Philippine pickleball players and court operators. Reserve conflict-free private court slots, join skill-matched Open Plays, and track every match receipt in one place.
            </p>

            <div class="d-flex flex-wrap justify-content-center gap-3 mb-5">
                <?php if ($is_logged_in): ?>
                    <a href="<?php echo htmlspecialchars($dashboard_url); ?>" class="btn btn-primary px-4 py-3">Open My Dashboard</a>
                    <a href="#venue-search" class="btn btn-outline-primary px-4 py-3">Search Venues</a>
                <?php else: ?>
                    <a href="signup.php" class="btn btn-primary px-4 py-3">Create Free Account</a>
                    <a href="#venue-search" class="btn btn-outline-primary px-4 py-3">Find a Venue</a>
                <?php endif; ?>
            </div>

            <!-- Panoramic Court Banner -->
            <div class="mx-auto mb-4 overflow-hidden">
                <img src="assets/RalliQ Background Splash 2.png"
                     onerror="this.src='assets/RalliQ Background Splash.png';"
                     alt="RALLIQ Pickleball Court"
                     class="w-100 hero-panoramic">
            </div>

            <!-- Live Server Table Metrics Strip -->
            <div class="row g-0 metrics-bar py-3 text-center text-md-start shadow-sm">
                <div class="col-6 col-md-3 px-4 py-2 border-end">
                    <span class="h4 fw-bold d-block mb-0 brand-heading">
                        <?php echo number_format($stats['venues']); ?>
                    </span>
                    <span class="small text-stone">Registered Venues</span>
                </div>
                <div class="col-6 col-md-3 px-4 py-2 border-md-end">
                    <span class="h4 fw-bold d-block mb-0 brand-heading">
                        <?php echo number_format($stats['courts']); ?>
                    </span>
                    <span class="small text-stone">Active Courts</span>
                </div>
                <div class="col-6 col-md-3 px-4 py-2 border-end border-top border-md-top-0">
                    <span class="h4 fw-bold d-block mb-0 brand-heading">
                        <?php echo number_format($stats['players']); ?>
                    </span>
                    <span class="small text-stone">Registered Players</span>
                </div>
                <div class="col-6 col-md-3 px-4 py-2 border-top border-md-top-0">
                    <span class="h4 fw-bold d-block mb-0 brand-heading">
                        <?php echo number_format($stats['sessions']); ?>
                    </span>
                    <span class="small text-stone">Open Play Sessions</span>
                </div>
            </div>
        </div>
    </header>

    <!-- Search Venues by Location Section -->
    <section id="venue-search" class="py-5 baseline-x">
        <div class="container container-landing py-2">
            <div class="text-center mb-4">
                <span class="badge rounded-pill badge-soft-orange px-3 py-1 mb-2">LOCATION DIRECTORY</span>
                <h2 class="h3 fw-bold mb-2">Search Venues by Location</h2>
                <p class="text-stone mb-0">Enter a city, municipality, or street address across the Philippines to inspect available pickleball facilities.</p>
            </div>

            <!-- Location Search Card -->
            <div class="card card-custom p-3 p-md-4 mb-4">
                <form method="GET" action="index.php#venue-search" class="row g-2 align-items-center">
                    <div class="col-12 col-md-8">
                        <label for="locationInput" class="visually-hidden">Location</label>
                        <input type="text"
                               class="form-control form-control-custom"
                               id="locationInput"
                               name="location"
                               maxlength="50"
                               value="<?php echo htmlspecialchars($search_location); ?>"
                               placeholder="Search by city, municipality, or address (e.g., Imus, Bacoor, Cavite)...">
                    </div>
                    <div class="col-12 col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">Search Location</button>
                        <?php if ($search_location !== ''): ?>
                            <a href="index.php#venue-search" class="btn btn-outline-secondary rounded-pill px-3 d-flex align-items-center">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if (count($available_cities) > 0): ?>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-3 pt-3 border-top">
                        <span class="small text-stone fw-semibold me-1">Active Locations:</span>
                        <?php foreach ($available_cities as $city_item): ?>
                            <?php $is_selected = (strcasecmp($search_location, $city_item) === 0); ?>
                            <a href="index.php?location=<?php echo urlencode($city_item); ?>#venue-search"
                               class="btn btn-sm rounded-pill px-3 <?php echo $is_selected ? 'btn-primary' : 'btn-soft-orange'; ?>">
                                <?php echo htmlspecialchars($city_item); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Search Status Heading -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <p class="small text-stone mb-0">
                    <?php if ($search_location !== ''): ?>
                        Showing <strong><?php echo count($searched_venues); ?></strong> venue<?php echo count($searched_venues) === 1 ? '' : 's'; ?> matching
                        "<strong><?php echo htmlspecialchars($search_location); ?></strong>"
                    <?php else: ?>
                        Showing registered venues on RALLIQ (search a city or address above to filter)
                    <?php endif; ?>
                </p>
            </div>

            <!-- Venue Results Grid -->
            <?php if (count($searched_venues) > 0): ?>
                <div class="row g-4">
                    <?php foreach ($searched_venues as $venue): ?>
                        <?php
                            $v_photo = !empty($venue['venue_photo'])
                                ? 'venue_images/' . $venue['venue_photo']
                                : 'assets/default_court.png';
                            $court_count = (int)$venue['active_courts'];
                            $discover_link = ($is_logged_in && $user_role === 'Player')
                                ? 'PlayerDashboard/discover.php?search=' . urlencode($venue['venue_name'])
                                : 'login.php';
                        ?>
                        <div class="col-12 col-md-4">
                            <div class="card booking-card overflow-hidden d-flex flex-column">
                                <div class="position-relative">
                                    <img src="<?php echo htmlspecialchars($v_photo); ?>"
                                         onerror="this.src='assets/default_court.png';"
                                         alt="<?php echo htmlspecialchars($venue['venue_name']); ?>"
                                         class="w-100 venue-cover">
                                    <span class="badge rounded-pill badge-slate position-absolute top-0 end-0 m-3 px-3 py-2">
                                        <?php echo $court_count; ?> Active <?php echo $court_count === 1 ? 'Court' : 'Courts'; ?>
                                    </span>
                                </div>

                                <div class="card-body p-4 d-flex flex-column">
                                    <?php if (!empty($venue['city'])): ?>
                                        <span class="badge rounded-pill badge-soft-orange align-self-start px-3 py-1 mb-2">
                                            <?php echo htmlspecialchars($venue['city']); ?>
                                        </span>
                                    <?php endif; ?>

                                    <h3 class="h5 fw-bold mb-1"><?php echo htmlspecialchars($venue['venue_name']); ?></h3>
                                    <p class="small text-stone mb-4"><?php echo htmlspecialchars($venue['address']); ?></p>

                                    <div class="mt-auto pt-3 border-top">
                                        <a href="<?php echo htmlspecialchars($discover_link); ?>" class="btn btn-primary btn-sm w-100">
                                            View Courts &amp; Schedules
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card card-custom p-5 text-center">
                    <img src="assets/Venue Icon.png" alt="No Venues Found" class="mx-auto mb-3 empty-state-icon">
                    <h3 class="h5 fw-bold mb-1">
                        <?php echo $search_location !== '' ? 'No Venues Found in "' . htmlspecialchars($search_location) . '"' : 'No Venues Registered Yet'; ?>
                    </h3>
                    <p class="small text-stone mb-3">
                        <?php echo $search_location !== ''
                            ? 'Try searching another municipality, city, or street address, or clear your search filter.'
                            : 'Facility managers can register their sports complex right after creating a Manager account.'; ?>
                    </p>
                    <div>
                        <?php if ($search_location !== ''): ?>
                            <a href="index.php#venue-search" class="btn btn-outline-primary btn-sm px-4">Reset Search</a>
                        <?php else: ?>
                            <a href="signup.php" class="btn btn-primary btn-sm px-4">Register a Venue</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Numbered Core Platform Pillars (01 - 04) -->
    <section id="features" class="py-5 bg-white baseline-x">
        <div class="container container-landing py-2">
            <div class="text-center mb-5">
                <span class="badge rounded-pill badge-soft-orange px-3 py-1 mb-2">THE PLATFORM</span>
                <h2 class="h3 fw-bold mb-2">Everything Around Your Rally</h2>
                <p class="text-stone mb-0">Structured tools built for both everyday players and facility managers.</p>
            </div>

            <div class="row g-4">
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card card-custom h-100 p-4">
                        <span class="pillar-number mb-3">01</span>
                        <h3 class="h5 fw-bold mb-2">Private Court Rentals</h3>
                        <p class="small text-stone mb-0">
                            Filter indoor and outdoor courts by surface type and reserve open time slots with automatic overlap protection.
                        </p>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card card-custom h-100 p-4">
                        <span class="pillar-number mb-3">02</span>
                        <h3 class="h5 fw-bold mb-2">Open Play Sessions</h3>
                        <p class="small text-stone mb-0">
                            Browse community drop-in sessions by skill tier, inspect live player rosters, and reserve spots for yourself plus guests.
                        </p>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card card-custom h-100 p-4">
                        <span class="pillar-number mb-3">03</span>
                        <h3 class="h5 fw-bold mb-2">Facility Scheduling</h3>
                        <p class="small text-stone mb-0">
                            Managers organize multiple venues, toggle court availability, and block maintenance or holiday hours in seconds.
                        </p>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card card-custom h-100 p-4">
                        <span class="pillar-number mb-3">04</span>
                        <h3 class="h5 fw-bold mb-2">Unified Play History</h3>
                        <p class="small text-stone mb-0">
                            Every confirmed booking, guest registration, and cancellation is logged with clear reference numbers and status tracking.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Live Upcoming Open Play Sessions -->
    <section id="open-plays" class="py-5 baseline-x">
        <div class="container container-landing py-2">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
                <div>
                    <span class="badge rounded-pill badge-soft-orange px-3 py-1 mb-2">COMMUNITY DROP-INS</span>
                    <h2 class="h3 fw-bold mb-1">Upcoming Open Play Sessions</h2>
                    <p class="small text-stone mb-0">Live drop-in games currently open for player registration.</p>
                </div>
                <a href="<?php echo ($is_logged_in && $user_role === 'Player') ? 'PlayerDashboard/open_plays.php' : 'login.php'; ?>"
                   class="btn btn-outline-primary btn-sm px-4">
                    View All Sessions
                </a>
            </div>

            <?php if (count($upcoming_plays) > 0): ?>
                <div class="row g-4">
                    <?php foreach ($upcoming_plays as $play): ?>
                        <?php
                            $booked = (int)$play['booked_slots'];
                            $max    = (int)$play['max_slots'];
                            $left   = max(0, $max - $booked);
                            $play_link = ($is_logged_in && $user_role === 'Player')
                                ? 'PlayerDashboard/open_play_details.php?play_id=' . (int)$play['play_id']
                                : 'login.php';
                        ?>
                        <div class="col-12 col-md-4">
                            <div class="card booking-card p-4 d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="badge rounded-pill badge-soft-orange px-3 py-1">
                                        <?php echo htmlspecialchars($play['skill_level_req']); ?>
                                    </span>
                                    <?php if (!empty($play['city'])): ?>
                                        <span class="small text-stone fw-semibold"><?php echo htmlspecialchars($play['city']); ?></span>
                                    <?php endif; ?>
                                </div>

                                <h3 class="h5 fw-bold mb-1"><?php echo htmlspecialchars($play['title']); ?></h3>
                                <p class="small fw-semibold mb-1 text-slate">
                                    <?php echo $play['play_date']->format('D, M j, Y'); ?> &bull;
                                    <?php echo $play['start_time']->format('g:i A') . ' - ' . $play['end_time']->format('g:i A'); ?>
                                </p>
                                <p class="small text-stone mb-4">
                                    <?php echo htmlspecialchars($play['venue_name'] . ' — ' . $play['court_name']); ?>
                                </p>

                                <div class="mt-auto pt-3 border-top d-flex justify-content-between align-items-center">
                                    <span class="small fw-semibold <?php echo $left > 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $left > 0 ? $left . ' of ' . $max . ' slots left' : 'Session Full'; ?>
                                    </span>
                                    <a href="<?php echo htmlspecialchars($play_link); ?>" class="btn btn-primary btn-sm px-3">
                                        View Roster
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card card-custom p-5 text-center">
                    <img src="assets/Open Play Session Icon.png" alt="No Sessions" class="mx-auto mb-3 empty-state-icon">
                    <h3 class="h5 fw-bold mb-1">No Upcoming Open Plays Scheduled</h3>
                    <p class="small text-stone mb-0">Facility managers can publish new Open Play sessions directly from their dashboard.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- 50/50 Split Storytelling: Players vs. Facility Managers -->
    <section id="roles" class="py-5 bg-white baseline-x">
        <div class="container container-landing py-2">
            <div class="text-center mb-4">
                <span class="badge rounded-pill badge-soft-orange px-3 py-1 mb-2">CHOOSE YOUR SIDE</span>
                <h2 class="h3 fw-bold mb-1">Two Portals. One Seamless System.</h2>
                <p class="small text-stone mb-0">Role-based access tailored to how you use the court.</p>
            </div>

            <div class="row g-0 rounded-4 border shadow-sm overflow-hidden bg-white">
                <!-- Left Split: Players -->
                <div class="col-12 col-lg-6 px-4 px-md-5 py-5 boundary-y bg-warm-alabaster">
                    <span class="badge rounded-pill badge-soft-orange px-3 py-1 mb-3">FOR PLAYERS</span>
                    <h3 class="h4 fw-bold mb-3">Step Onto the Baseline.</h3>
                    <p class="text-stone small mb-4">
                        Search registered venues by location, lock down conflict-free private court slots, bring guests to Open Plays, and manage your activity ledger.
                    </p>
                    <ul class="list-unstyled d-flex flex-column gap-2 small text-stone mb-4">
                        <li><span class="text-orange fw-bold me-2">&bull;</span><strong>Location &amp; Surface Filters:</strong> Browse Indoor and Outdoor courts across venues.</li>
                        <li><span class="text-orange fw-bold me-2">&bull;</span><strong>Real-Time Slot Checks:</strong> Occupied rentals, Open Plays, and blocked hours are automatically hidden.</li>
                        <li><span class="text-orange fw-bold me-2">&bull;</span><strong>Open Play + Guest Registration:</strong> Reserve your spot and add guest names in one step.</li>
                        <li><span class="text-orange fw-bold me-2">&bull;</span><strong>Self-Service Play History:</strong> Review past receipts or cancel upcoming reservations anytime.</li>
                    </ul>
                    <a href="signup.php" class="btn btn-primary btn-sm px-4">Register as a Player</a>
                </div>

                <!-- Right Split: Facility Managers -->
                <div class="col-12 col-lg-6 px-4 px-md-5 py-5 bg-white">
                    <span class="badge rounded-pill badge-slate px-3 py-1 mb-3">FOR FACILITY MANAGERS</span>
                    <h3 class="h4 fw-bold mb-3">Run Operations with Grid Control.</h3>
                    <p class="text-stone small mb-4">
                        Manage your sports facilities from a single dashboard—list courts, block maintenance schedules, host Open Plays, and monitor incoming bookings.
                    </p>
                    <ul class="list-unstyled d-flex flex-column gap-2 small text-stone mb-4">
                        <li><span class="text-orange fw-bold me-2">&bull;</span><strong>Venue &amp; Court Directory:</strong> Add multiple facilities, court photos, and playing surfaces.</li>
                        <li><span class="text-orange fw-bold me-2">&bull;</span><strong>Maintenance &amp; Holiday Blocks:</strong> Block specific dates and hours so players cannot double-book.</li>
                        <li><span class="text-orange fw-bold me-2">&bull;</span><strong>Open Play Management:</strong> Set skill level requirements, slot caps, and inspect live attendee rosters.</li>
                        <li><span class="text-orange fw-bold me-2">&bull;</span><strong>Rental Ledger Oversight:</strong> Track confirmed player rentals and cancel reservations when needed.</li>
                    </ul>
                    <a href="signup.php" class="btn btn-outline-primary btn-sm px-4">Register as a Manager</a>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section (Cancellation Rules & Platform Policies) -->
    <section id="faq" class="py-5 baseline-x">
        <div class="container container-faq py-2">
            <div class="text-center mb-4">
                <span class="badge rounded-pill badge-soft-orange px-3 py-1 mb-2">FREQUENTLY ASKED QUESTIONS</span>
                <h2 class="h3 fw-bold mb-1">Booking &amp; Cancellation Policies</h2>
                <p class="small text-stone mb-0">Everything you need to know about reservations, Open Play rosters, and cancellations.</p>
            </div>

            <div class="accordion" id="ralliqFaqAccordion">
                <!-- FAQ 1: Private Rental Cancellation -->
                <div class="accordion-item card-custom mb-3 overflow-hidden border">
                    <h3 class="accordion-header" id="faqHeadingOne">
                        <button class="accordion-button fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseOne" aria-expanded="true" aria-controls="faqCollapseOne">
                            How do I cancel a private court rental?
                        </button>
                    </h3>
                    <div id="faqCollapseOne" class="accordion-collapse collapse show" aria-labelledby="faqHeadingOne" data-bs-parent="#ralliqFaqAccordion">
                        <div class="accordion-body small text-stone">
                            You can cancel any upcoming <strong>Confirmed</strong> private court reservation directly from your <strong>Play History</strong> page before the scheduled date. Once cancelled, the time slot is immediately released back to the court’s booking calendar for other players, while your receipt remains saved in your history log with a <strong>Cancelled</strong> status.
                        </div>
                    </div>
                </div>

                <!-- FAQ 2: Leaving an Open Play Session -->
                <div class="accordion-item card-custom mb-3 overflow-hidden border">
                    <h3 class="accordion-header" id="faqHeadingTwo">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseTwo" aria-expanded="false" aria-controls="faqCollapseTwo">
                            What happens if I leave an Open Play session after joining?
                        </button>
                    </h3>
                    <div id="faqCollapseTwo" class="accordion-collapse collapse" aria-labelledby="faqHeadingTwo" data-bs-parent="#ralliqFaqAccordion">
                        <div class="accordion-body small text-stone">
                            Open the session’s <strong>Details &amp; Roster</strong> page prior to the play date and click <strong>Leave Open Play Session</strong>. Leaving automatically removes you along with any guest slots attached to your reservation, from the active participant roster, frees those slots for other players, and updates your booking receipt to <strong>Cancelled</strong>.
                        </div>
                    </div>
                </div>

                <!-- FAQ 3: Manager-Cancelled Sessions -->
                <div class="accordion-item card-custom mb-3 overflow-hidden border">
                    <h3 class="accordion-header" id="faqHeadingThree">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseThree" aria-expanded="false" aria-controls="faqCollapseThree">
                            What if a Facility Manager cancels an Open Play or blocks a court?
                        </button>
                    </h3>
                    <div id="faqCollapseThree" class="accordion-collapse collapse" aria-labelledby="faqHeadingThree" data-bs-parent="#ralliqFaqAccordion">
                        <div class="accordion-body small text-stone">
                            If a Facility Manager cancels an upcoming Open Play session or schedules a maintenance block, affected reservations are marked as <strong>Cancelled</strong> while preserving your original transaction receipt in <strong>Play History</strong> so you always have a complete record of your activity.
                        </div>
                    </div>
                </div>

                <!-- FAQ 4: Bringing Guests -->
                <div class="accordion-item card-custom mb-3 overflow-hidden border">
                    <h3 class="accordion-header" id="faqHeadingFour">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseFour" aria-expanded="false" aria-controls="faqCollapseFour">
                            Can I reserve Open Play slots for friends or guests?
                        </button>
                    </h3>
                    <div id="faqCollapseFour" class="accordion-collapse collapse" aria-labelledby="faqHeadingFour" data-bs-parent="#ralliqFaqAccordion">
                        <div class="accordion-body small text-stone">
                            Yes. When joining an Open Play session, you can reserve multiple slots in a single transaction and enter guest names so every player appears on the session roster, provided enough open slots remain available.
                        </div>
                    </div>
                </div>

                <!-- FAQ 5: Double-Booking Prevention -->
                <div class="accordion-item card-custom overflow-hidden border">
                    <h3 class="accordion-header" id="faqHeadingFive">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseFive" aria-expanded="false" aria-controls="faqCollapseFive">
                            How does RALLIQ prevent double-bookings on the same court?
                        </button>
                    </h3>
                    <div id="faqCollapseFive" class="accordion-collapse collapse" aria-labelledby="faqHeadingFive" data-bs-parent="#ralliqFaqAccordion">
                        <div class="accordion-body small text-stone">
                            Every booking and Open Play schedule is validated against confirmed private rentals, scheduled Open Play sessions, and manager-blocked maintenance windows before confirmation, ensuring no two bookings overlap on the same court.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="py-4 bg-white">
        <div class="container container-landing d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <img src="assets/RalliQ Main Logo.png" alt="RALLIQ" height="24">
                <span class="small text-stone">RALLIQ Philippines. Pickleball Court &amp; Open Play.</span>
            </div>
            <div class="d-flex gap-3 small">
                <a href="#venue-search" class="text-decoration-none text-stone">Search Venues</a>
                <span class="text-muted">|</span>
                <a href="login.php" class="text-decoration-none text-stone">Log In</a>
                <span class="text-muted">|</span>
                <a href="signup.php" class="text-decoration-none text-stone">Sign Up</a>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>