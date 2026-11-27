<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Player') {
    header("Location: ../login.php");
    exit();
}

require_once '../includes/db.php';

$user_id     = (int)$_SESSION['user_id'];
$active_page = 'play_history';
$today       = date("Y-m-d");
$now_time    = date("H:i:s");

// Handle self-service cancellation for upcoming Private Court Rentals
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['cancel_booking_id'])) {
    $cancel_id = (int)$_POST['cancel_booking_id'];

    if ($cancel_id > 0) {
        $sql_verify = "
            SELECT booking_id
            FROM dbo.bookings
            WHERE booking_id = ?
              AND user_id = ?
              AND booking_type = 'Private_Rental'
              AND status = 'Confirmed'
              AND (booking_date > ? OR (booking_date = ? AND start_time > ?))
        ";
        $stmt_verify = sqlsrv_query($conn, $sql_verify, [$cancel_id, $user_id, $today, $today, $now_time]);

        if ($stmt_verify && sqlsrv_has_rows($stmt_verify)) {
            $sql_cancel = "
                UPDATE dbo.bookings
                SET status = 'Cancelled'
                WHERE booking_id = ?
                  AND user_id = ?
                  AND booking_type = 'Private_Rental'
                  AND status = 'Confirmed'
            ";
            $stmt_cancel = sqlsrv_query($conn, $sql_cancel, [$cancel_id, $user_id]);

            if ($stmt_cancel !== false) {
                header("Location: play_history.php?status=cancelled");
                exit();
            }
        }
    }

    header("Location: play_history.php?error=cannot_cancel");
    exit();
}

// Fetch player avatar for shared navigation bar
$display_avatar = "default_avatar.png";
$stmt_prof = sqlsrv_query($conn, "SELECT avatar_url FROM dbo.users WHERE user_id = ?", [$user_id]);
if ($stmt_prof && sqlsrv_has_rows($stmt_prof)) {
    $prof = sqlsrv_fetch_array($stmt_prof, SQLSRV_FETCH_ASSOC);
    if (!empty($prof['avatar_url'])) {
        $display_avatar = $prof['avatar_url'];
    }
}

// Optional type filter (?type=Private_Rental or ?type=Open_Play)
$filter_type = trim($_GET['type'] ?? '');
if (!in_array($filter_type, ['Private_Rental', 'Open_Play'], true)) {
    $filter_type = '';
}

// Fetch all player bookings (LEFT JOIN open_plays so history survives if play_id becomes NULL)
$sql_history = "
    SELECT
        b.booking_id,
        b.court_id,
        b.play_id,
        b.booking_type,
        b.booking_date,
        b.start_time,
        b.end_time,
        b.total_amount,
        b.status,
        b.created_at,
        c.court_name,
        c.court_type,
        v.venue_name,
        v.address,
        v.city,
        o.title AS play_title,
        o.skill_level_req,
        (
            SELECT COUNT(*)
            FROM dbo.open_play_roster AS r
            WHERE r.booking_id = b.booking_id
        ) AS roster_slots
    FROM dbo.bookings AS b
    INNER JOIN dbo.courts AS c ON b.court_id = c.court_id
    INNER JOIN dbo.venues AS v ON c.venue_id = v.venue_id
    LEFT JOIN dbo.open_plays AS o ON b.play_id = o.play_id
    WHERE b.user_id = ?
";
$params = [$user_id];

if ($filter_type !== '') {
    $sql_history .= " AND b.booking_type = ?";
    $params[] = $filter_type;
}

$sql_history .= " ORDER BY b.booking_date DESC, b.start_time DESC, b.booking_id DESC";

$stmt_history = sqlsrv_query($conn, $sql_history, $params);

$all_records       = [];
$upcoming_records  = [];
$completed_records = [];
$cancelled_records = [];
$total_spent       = 0.0;

if ($stmt_history) {
    while ($row = sqlsrv_fetch_array($stmt_history, SQLSRV_FETCH_ASSOC)) {
        $b_date_str = $row['booking_date']->format('Y-m-d');
        $end_str    = $row['end_time']->format('H:i:s');
        $is_past    = ($b_date_str < $today) || ($b_date_str === $today && $end_str <= $now_time);

        if ($row['status'] === 'Cancelled') {
            $row['bucket'] = 'cancelled';
            $cancelled_records[] = $row;
        } elseif ($is_past || $row['status'] === 'Completed') {
            $row['bucket'] = 'completed';
            $completed_records[] = $row;
            $total_spent += (float)$row['total_amount'];
        } else {
            $row['bucket'] = 'upcoming';
            $upcoming_records[] = $row;
            $total_spent += (float)$row['total_amount'];
        }

        $all_records[] = $row;
    }
}

$flash_booking_msg = $_SESSION['booking_success'] ?? '';
unset($_SESSION['booking_success']);

function renderHistoryCard(array $item, string $today, string $now_time): void {
    $booking_id   = (int)$item['booking_id'];
    $receipt_code = '#BK-' . str_pad((string)$booking_id, 5, '0', STR_PAD_LEFT);
    $is_open_play = ($item['booking_type'] === 'Open_Play');
    $bucket       = $item['bucket'];

    $date_label   = $item['booking_date']->format('D, M j, Y');
    $time_label   = $item['start_time']->format('g:i A') . ' - ' . $item['end_time']->format('g:i A');
    $booked_on    = $item['created_at'] ? $item['created_at']->format('M j, Y g:i A') : '';
    $amount_val   = (float)$item['total_amount'];
    $amount_label = $amount_val > 0 ? '&#8369;' . number_format($amount_val, 2) : 'Free';

    $b_date_str   = $item['booking_date']->format('Y-m-d');
    $start_str    = $item['start_time']->format('H:i:s');
    $has_started  = ($b_date_str < $today) || ($b_date_str === $today && $start_str <= $now_time);

    if ($is_open_play) {
        $headline = !empty($item['play_title'])
            ? $item['play_title']
            : 'Open Play Session (Cancelled by Venue)';
        $slots = (int)$item['roster_slots'];
        $sub_meta = $item['court_name'] . ' (' . $item['court_type'] . ')';
        if ($slots > 0) {
            $sub_meta .= ' &bull; ' . $slots . ' Spot' . ($slots > 1 ? 's' : '');
        }
    } else {
        $headline = $item['court_name'] . ' (' . $item['court_type'] . ')';
        $sub_meta = 'Private Full-Court Reservation';
    }

    $loc_line = $item['venue_name'] . (!empty($item['city']) ? ' &bull; ' . $item['city'] : '');
    ?>
    <div class="card card-custom p-4">
        <div class="row align-items-center gy-3">
            <div class="col-12 col-lg-7">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="small fw-bold text-stone"><?php echo $receipt_code; ?></span>
                    <?php if ($is_open_play): ?>
                        <span class="badge rounded-pill px-3 py-1 fw-semibold" style="background-color: rgba(167, 184, 159, 0.35); color: var(--color-forest);">Open Play</span>
                    <?php else: ?>
                        <span class="badge rounded-pill px-3 py-1 fw-semibold" style="background-color: var(--color-forest); color: var(--color-lime);">Private Rental</span>
                    <?php endif; ?>

                    <?php if ($bucket === 'upcoming'): ?>
                        <span class="badge rounded-pill bg-success text-white px-3 py-1">Confirmed</span>
                    <?php elseif ($bucket === 'completed'): ?>
                        <span class="badge rounded-pill bg-secondary bg-opacity-10 text-dark border px-3 py-1">Completed</span>
                    <?php else: ?>
                        <span class="badge rounded-pill bg-danger bg-opacity-10 text-danger px-3 py-1">Cancelled</span>
                    <?php endif; ?>
                </div>

                <h2 class="h5 fw-bold mb-1"><?php echo htmlspecialchars($headline); ?></h2>
                <p class="small fw-semibold mb-1" style="color: var(--color-forest);"><?php echo htmlspecialchars($date_label); ?> &bull; <?php echo htmlspecialchars($time_label); ?></p>
                <p class="small fw-semibold mb-0"><?php echo htmlspecialchars($loc_line); ?></p>
                <p class="small text-stone mb-0"><?php echo $sub_meta; ?></p>
                <?php if ($booked_on !== ''): ?>
                    <p class="small text-stone mt-1 mb-0" style="font-size: 0.78rem;">Booked on <?php echo htmlspecialchars($booked_on); ?></p>
                <?php endif; ?>
            </div>

            <div class="col-12 col-lg-5 d-flex flex-column align-items-lg-end justify-content-between gap-2">
                <div class="text-lg-end">
                    <span class="small text-stone d-block">Total Paid</span>
                    <span class="h5 fw-bold <?php echo $bucket === 'cancelled' ? 'text-decoration-line-through text-stone' : ''; ?> mb-0" style="<?php echo $bucket !== 'cancelled' ? 'color: var(--color-forest);' : ''; ?>">
                        <?php echo $amount_label; ?>
                    </span>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                    <?php if ($bucket === 'upcoming' && !$is_open_play && !$has_started): ?>
                        <form action="play_history.php" method="POST" class="m-0" onsubmit="return confirm('Cancel your private court reservation <?php echo $receipt_code; ?> on <?php echo htmlspecialchars($date_label); ?>?');">
                            <input type="hidden" name="cancel_booking_id" value="<?php echo $booking_id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center justify-content-center">
                                Cancel Reservation
                            </button>
                        </form>
                    <?php elseif ($bucket === 'upcoming' && $is_open_play && !empty($item['play_id'])): ?>
                        <a href="open_play_details.php?play_id=<?php echo (int)$item['play_id']; ?>" class="btn btn-primary-custom btn-sm px-3 py-2 d-inline-flex align-items-center justify-content-center">
                            Manage / Leave Spot
                        </a>
                    <?php elseif (!$is_open_play): ?>
                        <a href="book_court.php?court_id=<?php echo (int)$item['court_id']; ?>" class="btn btn-sm rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center justify-content-center" style="background-color: var(--color-white); color: var(--color-forest); border: 1px solid var(--color-stone);">
                            Book Court Again
                        </a>
                    <?php elseif (!empty($item['play_id'])): ?>
                        <a href="open_play_details.php?play_id=<?php echo (int)$item['play_id']; ?>" class="btn btn-sm rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center justify-content-center" style="background-color: var(--color-white); color: var(--color-forest); border: 1px solid var(--color-stone);">
                            View Session
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Play History &amp; Receipts - RALLIQ</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <link rel="stylesheet" href="../ralliq-theme.css?v=4">
</head>
<body>

    <?php require_once '../includes/player_nav.php'; ?>

    <header class="py-4 mb-4 bg-white border-bottom">
        <div class="container">
            <div class="row align-items-center gy-3">
                <div class="col-md-8">
                    <span class="badge rounded-pill px-3 py-1 mb-2 fw-semibold" style="background-color: var(--color-forest); color: var(--color-lime); font-size: 0.72rem; letter-spacing: 0.5px;">ACTIVITY &amp; RECEIPT LEDGER</span>
                    <h1 class="h3 fw-bold mb-1">Play History</h1>
                    <p class="small text-stone mb-0">
                        Track your court rentals, Open Play sign-ups, receipts, and cancellations.
                    </p>
                </div>
                <div class="col-md-4 d-flex flex-wrap align-items-center justify-content-md-end gap-2">
                    <a href="discover.php" class="btn btn-primary-custom btn-sm px-3 py-2 d-inline-flex align-items-center justify-content-center">
                        Book a Court
                    </a>
                    <a href="open_plays.php" class="btn btn-sm rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center justify-content-center" style="background-color: var(--color-white); color: var(--color-forest); border: 1px solid var(--color-stone);">
                        Join Open Play
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="container pb-5">

        <!-- Status & Flash Banners -->
        <?php if ((isset($_GET['status']) && ($_GET['status'] === 'booked' || $_GET['status'] === 'success')) || isset($_GET['booking']) || $flash_booking_msg !== ''): ?>
            <div class="alert alert-success rounded-4 border-0 shadow-sm mb-4" role="alert">
                <strong>Reservation Confirmed!</strong>
                <?php echo $flash_booking_msg !== '' ? htmlspecialchars($flash_booking_msg) : 'Your court booking has been added to your schedule and receipt ledger.'; ?>
            </div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'cancelled'): ?>
            <div class="alert alert-success rounded-4 border-0 shadow-sm mb-4" role="alert">
                Your private court reservation has been cancelled and the time slot has been released.
            </div>
        <?php elseif (isset($_GET['error']) && $_GET['error'] === 'cannot_cancel'): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4" role="alert">
                Unable to cancel that reservation. It may have already started or was previously cancelled.
            </div>
        <?php endif; ?>

        <!-- Summary KPI Strip -->
        <section class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card card-custom p-3">
                    <span class="small text-stone fw-semibold">Upcoming</span>
                    <span class="h4 fw-bold mb-0" style="color: var(--color-forest);"><?php echo count($upcoming_records); ?></span>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-custom p-3">
                    <span class="small text-stone fw-semibold">Completed</span>
                    <span class="h4 fw-bold mb-0" style="color: var(--color-forest);"><?php echo count($completed_records); ?></span>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-custom p-3">
                    <span class="small text-stone fw-semibold">Cancelled</span>
                    <span class="h4 fw-bold mb-0" style="color: var(--color-forest);"><?php echo count($cancelled_records); ?></span>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-custom p-3">
                    <span class="small text-stone fw-semibold">Active Spend</span>
                    <span class="h4 fw-bold mb-0" style="color: var(--color-forest);">&#8369;<?php echo number_format($total_spent, 2); ?></span>
                </div>
            </div>
        </section>

        <!-- Navigation Tabs + Booking Type Filter -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <ul class="nav nav-pills ralliq-switcher gap-2" id="historyTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="all-tab" data-bs-toggle="pill" data-bs-target="#all-pane" type="button" role="tab" aria-controls="all-pane" aria-selected="true">
                        All (<?php echo count($all_records); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="upcoming-tab" data-bs-toggle="pill" data-bs-target="#upcoming-pane" type="button" role="tab" aria-controls="upcoming-pane" aria-selected="false">
                        Upcoming (<?php echo count($upcoming_records); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="completed-tab" data-bs-toggle="pill" data-bs-target="#completed-pane" type="button" role="tab" aria-controls="completed-pane" aria-selected="false">
                        Completed (<?php echo count($completed_records); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="cancelled-tab" data-bs-toggle="pill" data-bs-target="#cancelled-pane" type="button" role="tab" aria-controls="cancelled-pane" aria-selected="false">
                        Cancelled (<?php echo count($cancelled_records); ?>)
                    </button>
                </li>
            </ul>

            <form action="play_history.php" method="GET" class="d-flex align-items-center gap-2">
                <label for="typeFilter" class="small fw-semibold text-stone mb-0">Type:</label>
                <select id="typeFilter" name="type" class="form-select form-select-sm form-control-custom" onchange="this.form.submit()" style="min-width: 170px;">
                    <option value="" <?php echo $filter_type === '' ? 'selected' : ''; ?>>All Bookings</option>
                    <option value="Private_Rental" <?php echo $filter_type === 'Private_Rental' ? 'selected' : ''; ?>>Private Rentals Only</option>
                    <option value="Open_Play" <?php echo $filter_type === 'Open_Play' ? 'selected' : ''; ?>>Open Plays Only</option>
                </select>
            </form>
        </div>

        <!-- Tab Panes -->
        <div class="tab-content" id="historyTabsContent">

            <!-- TAB 1: All Activity -->
            <div class="tab-pane fade show active" id="all-pane" role="tabpanel" aria-labelledby="all-tab">
                <?php if (count($all_records) > 0): ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($all_records as $item): ?>
                            <?php renderHistoryCard($item, $today, $now_time); ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card card-custom p-5 text-center">
                        <img src="../assets/Rental Calendar Icon.png" alt="No History" class="mx-auto mb-3" style="width: 72px; height: 72px; object-fit: contain;">
                        <h2 class="h5 fw-bold mb-1">No Bookings Yet</h2>
                        <p class="small text-stone mb-3">You haven't booked a private court or joined an Open Play session yet.</p>
                        <div class="d-flex flex-wrap align-items-center justify-content-center gap-2">
                            <a href="discover.php" class="btn btn-primary-custom btn-sm px-4 py-2 d-inline-flex align-items-center justify-content-center">
                                Discover Courts
                            </a>
                            <a href="open_plays.php" class="btn btn-sm rounded-pill px-4 py-2 fw-semibold d-inline-flex align-items-center justify-content-center" style="background-color: var(--color-white); color: var(--color-forest); border: 1px solid var(--color-stone);">
                                Browse Open Plays
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 2: Upcoming -->
            <div class="tab-pane fade" id="upcoming-pane" role="tabpanel" aria-labelledby="upcoming-tab">
                <?php if (count($upcoming_records) > 0): ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($upcoming_records as $item): ?>
                            <?php renderHistoryCard($item, $today, $now_time); ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card card-custom p-5 text-center">
                        <p class="small text-stone mb-0">No upcoming reservations or Open Play sessions scheduled.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 3: Completed -->
            <div class="tab-pane fade" id="completed-pane" role="tabpanel" aria-labelledby="completed-tab">
                <?php if (count($completed_records) > 0): ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($completed_records as $item): ?>
                            <?php renderHistoryCard($item, $today, $now_time); ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card card-custom p-5 text-center">
                        <p class="small text-stone mb-0">No completed sessions in your history yet.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 4: Cancelled -->
            <div class="tab-pane fade" id="cancelled-pane" role="tabpanel" aria-labelledby="cancelled-tab">
                <?php if (count($cancelled_records) > 0): ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($cancelled_records as $item): ?>
                            <?php renderHistoryCard($item, $today, $now_time); ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card card-custom p-5 text-center">
                        <p class="small text-stone mb-0">You have no cancelled bookings.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>