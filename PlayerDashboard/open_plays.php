<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Player') {
    header("Location: ../login.php");
    exit();
}

require_once '../includes/db.php';

$user_id     = (int)$_SESSION['user_id'];
$active_page = 'open_plays';
$today       = date("Y-m-d");
$now_time    = date("H:i:s");

// Fetch player avatar and skill level
$display_avatar = "default_avatar.png";
$player_skill   = "Beginner";
$stmt_prof = sqlsrv_query($conn, "SELECT avatar_url, skill_level FROM dbo.users WHERE user_id = ?", [$user_id]);
if ($stmt_prof && sqlsrv_has_rows($stmt_prof)) {
    $prof = sqlsrv_fetch_array($stmt_prof, SQLSRV_FETCH_ASSOC);
    if (!empty($prof['avatar_url'])) {
        $display_avatar = $prof['avatar_url'];
    }
    if (!empty($prof['skill_level'])) {
        $player_skill = $prof['skill_level'];
    }
}

// Read GET filters
$search_q     = trim($_GET['q'] ?? '');
$filter_city  = trim($_GET['city'] ?? '');
$filter_skill = trim($_GET['skill'] ?? '');
$filter_date  = trim($_GET['date'] ?? '');

// Fetch distinct cities from venues hosting open plays
$cities = [];
$res_cities = sqlsrv_query($conn, "SELECT DISTINCT city FROM dbo.venues WHERE city IS NOT NULL AND city <> '' ORDER BY city ASC");
if ($res_cities) {
    while ($c_row = sqlsrv_fetch_array($res_cities, SQLSRV_FETCH_ASSOC)) {
        $cities[] = $c_row['city'];
    }
}

// Fetch upcoming Open Play sessions with live confirmed slot counts & current user's joined slots
$sql_plays = "
    SELECT
        o.play_id,
        o.title,
        o.skill_level_req,
        o.play_date,
        o.start_time,
        o.end_time,
        o.fee,
        o.max_slots,
        c.court_name,
        c.court_type,
        v.venue_name,
        v.address,
        v.city,
        (
            SELECT COUNT(*)
            FROM dbo.open_play_roster AS r
            INNER JOIN dbo.bookings AS rb ON r.booking_id = rb.booking_id
            WHERE r.play_id = o.play_id
              AND rb.status = 'Confirmed'
        ) AS booked_slots,
        (
            SELECT COUNT(*)
            FROM dbo.open_play_roster AS r2
            INNER JOIN dbo.bookings AS rb2 ON r2.booking_id = rb2.booking_id
            WHERE r2.play_id = o.play_id
              AND r2.user_id = ?
              AND rb2.status = 'Confirmed'
        ) AS my_slots
    FROM dbo.open_plays AS o
    INNER JOIN dbo.courts AS c ON o.court_id = c.court_id
    INNER JOIN dbo.venues AS v ON c.venue_id = v.venue_id
    WHERE c.is_active = 1
      AND (o.play_date > ? OR (o.play_date = ? AND o.end_time > ?))
";
$params = [$user_id, $today, $today, $now_time];

if ($search_q !== '') {
    $sql_plays .= " AND (o.title LIKE ? OR v.venue_name LIKE ? OR c.court_name LIKE ?)";
    $like = '%' . $search_q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($filter_city !== '') {
    $sql_plays .= " AND v.city = ?";
    $params[] = $filter_city;
}
if ($filter_skill !== '') {
    $sql_plays .= " AND o.skill_level_req = ?";
    $params[] = $filter_skill;
}
if ($filter_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $sql_plays .= " AND o.play_date = ?";
    $params[] = $filter_date;
}

$sql_plays .= " ORDER BY o.play_date ASC, o.start_time ASC";

$stmt_plays = sqlsrv_query($conn, $sql_plays, $params);
$open_plays = [];
if ($stmt_plays) {
    while ($row = sqlsrv_fetch_array($stmt_plays, SQLSRV_FETCH_ASSOC)) {
        $open_plays[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Open Plays - RALLIQ</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <link rel="stylesheet" href="../ralliq-theme.css?v=4">
</head>
<body>

    <?php require_once '../includes/player_nav.php'; ?>

    <header class="page-header-strip">
        <div class="container">
            <div class="row align-items-center gy-2">
                <div class="col-md-8">
                    <span class="badge rounded-pill badge-soft-orange px-3 py-1 mb-2">DROP-IN COMMUNITY SESSIONS</span>
                    <h1 class="h3 fw-bold mb-1">Upcoming Open Plays</h1>
                    <p class="small text-stone mb-0">
                        Your current skill tier: <strong class="text-dark"><?php echo htmlspecialchars($player_skill); ?></strong> &bull; Claim a spot for yourself or bring guests.
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <span class="small fw-semibold text-stone"><?php echo count($open_plays); ?> Session<?php echo count($open_plays) === 1 ? '' : 's'; ?> Scheduled</span>
                </div>
            </div>
        </div>
    </header>

    <main class="container pb-5">

        <!-- Filter Bar -->
        <section class="card card-custom p-4 mb-4">
            <form action="open_plays.php" method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="q" class="form-label-custom small">Search Session or Venue</label>
                    <input type="text" id="q" name="q" class="form-control form-control-custom" placeholder="e.g., Sunset Social, Imus..." value="<?php echo htmlspecialchars($search_q); ?>">
                </div>

                <div class="col-6 col-md-2">
                    <label for="city" class="form-label-custom small">City</label>
                    <select id="city" name="city" class="form-select form-control-custom">
                        <option value="">All Cities</option>
                        <?php foreach ($cities as $city_opt): ?>
                            <option value="<?php echo htmlspecialchars($city_opt); ?>" <?php echo $filter_city === $city_opt ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($city_opt); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <label for="skill" class="form-label-custom small">Skill Level</label>
                    <select id="skill" name="skill" class="form-select form-control-custom">
                        <option value="">All Tiers</option>
                        <?php foreach (['All Levels', 'Beginner', 'Intermediate', 'Advanced'] as $tier): ?>
                            <option value="<?php echo $tier; ?>" <?php echo $filter_skill === $tier ? 'selected' : ''; ?>>
                                <?php echo $tier; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label for="date" class="form-label-custom small">Session Date</label>
                    <input type="date" id="date" name="date" class="form-control form-control-custom" min="<?php echo $today; ?>" value="<?php echo htmlspecialchars($filter_date); ?>">
                </div>

                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                    <?php if ($search_q !== '' || $filter_city !== '' || $filter_skill !== '' || $filter_date !== ''): ?>
                        <a href="open_plays.php" class="btn btn-outline-secondary rounded-pill px-3" title="Reset filters">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Open Plays Grid -->
        <?php if (count($open_plays) > 0): ?>
            <section class="row g-4">
                <?php foreach ($open_plays as $play): ?>
                    <?php
                        $play_id    = (int)$play['play_id'];
                        $booked     = (int)$play['booked_slots'];
                        $max        = (int)$play['max_slots'];
                        $remaining  = max(0, $max - $booked);
                        $my_slots   = (int)$play['my_slots'];
                        $is_full    = ($remaining <= 0);
                        $fill_pct   = $max > 0 ? min(100, round(($booked / $max) * 100)) : 0;

                        $date_str   = $play['play_date']->format('D, M j, Y');
                        $time_str   = $play['start_time']->format('g:i A') . ' - ' . $play['end_time']->format('g:i A');
                        $fee_val    = (float)$play['fee'];
                        $fee_label  = $fee_val > 0 ? '&#8369;' . number_format($fee_val, 2) . ' / player' : 'Free Entry';
                        $loc_line   = $play['venue_name'] . (!empty($play['city']) ? ' (' . $play['city'] . ')' : '');
                    ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card booking-card p-4 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                                <span class="badge rounded-pill badge-soft-orange px-3 py-1">
                                    <?php echo htmlspecialchars($play['skill_level_req']); ?>
                                </span>
                                <?php if ($my_slots > 0): ?>
                                    <span class="badge rounded-pill bg-success text-white px-3 py-1">
                                        Joined (<?php echo $my_slots; ?>)
                                    </span>
                                <?php elseif ($is_full): ?>
                                    <span class="badge rounded-pill bg-secondary text-white px-3 py-1">Full</span>
                                <?php else: ?>
                                    <span class="badge rounded-pill badge-slate px-3 py-1">
                                        <?php echo $remaining; ?> Spot<?php echo $remaining === 1 ? '' : 's'; ?> Left
                                    </span>
                                <?php endif; ?>
                            </div>

                            <h2 class="h5 fw-bold mb-1"><?php echo htmlspecialchars($play['title']); ?></h2>
                            <p class="small fw-semibold text-orange mb-2"><?php echo htmlspecialchars($date_str); ?> &bull; <?php echo htmlspecialchars($time_str); ?></p>

                            <p class="small fw-semibold mb-0"><?php echo htmlspecialchars($loc_line); ?></p>
                            <p class="small text-stone mb-3">
                                <?php echo htmlspecialchars($play['court_name']); ?> (<?php echo htmlspecialchars($play['court_type']); ?>)
                            </p>

                            <!-- Slot Capacity Bar -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="text-stone">Roster Filled</span>
                                    <span class="fw-semibold"><?php echo $booked . ' / ' . $max; ?> players</span>
                                </div>
                                <div class="progress" style="height: 7px; border-radius: 999px; background-color: var(--color-bg);">
                                    <div class="progress-bar" role="progressbar" style="width: <?php echo $fill_pct; ?>%; background-color: var(--color-orange);" aria-valuenow="<?php echo $booked; ?>" aria-valuemin="0" aria-valuemax="<?php echo $max; ?>"></div>
                                </div>
                            </div>

                            <div class="mt-auto pt-3 border-top d-flex align-items-center justify-content-between gap-2">
                                <span class="fw-bold small"><?php echo $fee_label; ?></span>
                                <a href="open_play_details.php?play_id=<?php echo $play_id; ?>" class="btn <?php echo ($is_full && $my_slots === 0) ? 'btn-outline-secondary' : 'btn-primary'; ?> btn-sm px-3">
                                    <?php
                                        if ($my_slots > 0) {
                                            echo 'Manage Spot';
                                        } elseif ($is_full) {
                                            echo 'View Roster';
                                        } else {
                                            echo 'Details &amp; Join';
                                        }
                                    ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="card card-custom p-5 text-center">
                <img src="../assets/Open Play Session Icon.png" alt="No Open Plays" class="mx-auto mb-3" style="width: 72px; height: 72px; object-fit: contain;">
                <h2 class="h5 fw-bold mb-1">No Open Play Sessions Found</h2>
                <p class="small text-stone mb-3">No drop-in sessions match your filters right now. Check back soon or reset your filters.</p>
                <div>
                    <a href="open_plays.php" class="btn btn-primary btn-sm px-4">Reset Filters</a>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>