<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Player') {
    header("Location: ../login.php");
    exit();
}

require_once '../includes/db.php';

$user_id     = (int)$_SESSION['user_id'];
$active_page = 'discover';
$today       = date("Y-m-d");

// Fetch player avatar for navbar
$display_avatar = "default_avatar.png";
$stmt_prof = sqlsrv_query($conn, "SELECT avatar_url FROM dbo.users WHERE user_id = ?", [$user_id]);
if ($stmt_prof && sqlsrv_has_rows($stmt_prof)) {
    $prof = sqlsrv_fetch_array($stmt_prof, SQLSRV_FETCH_ASSOC);
    if (!empty($prof['avatar_url'])) {
        $display_avatar = $prof['avatar_url'];
    }
}

$court_id = (int)($_GET['court_id'] ?? 0);
if ($court_id <= 0) {
    header("Location: discover.php");
    exit();
}

// Fetch selected active court + venue metadata
$sql_court = "
    SELECT c.court_id, c.court_name, c.court_type, c.surface_type, c.is_active,
           v.venue_name, v.address, v.city
    FROM dbo.courts AS c
    INNER JOIN dbo.venues AS v ON c.venue_id = v.venue_id
    WHERE c.court_id = ? AND c.is_active = 1
";
$stmt_court = sqlsrv_query($conn, $sql_court, [$court_id]);
if (!$stmt_court || !sqlsrv_has_rows($stmt_court)) {
    header("Location: discover.php");
    exit();
}
$court = sqlsrv_fetch_array($stmt_court, SQLSRV_FETCH_ASSOC);

// Fetch court pictures
$pictures = [];
$stmt_pics = sqlsrv_query($conn, "SELECT picture_url FROM dbo.court_pictures WHERE court_id = ? ORDER BY picture_id DESC", [$court_id]);
if ($stmt_pics) {
    while ($p = sqlsrv_fetch_array($stmt_pics, SQLSRV_FETCH_ASSOC)) {
        $pictures[] = $p['picture_url'];
    }
}
$hero_img = !empty($pictures) ? '../court_images/' . $pictures[0] : '../assets/default_court.png';

// Selected date for checking busy windows (defaults to today)
$selected_date = trim($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date) || $selected_date < $today) {
    $selected_date = $today;
}

// Fetch busy/unavailable windows on the selected date (Confirmed bookings, Open Plays, and Manager Blocks)
$busy_slots = [];

$sql_busy_bookings = "
    SELECT DISTINCT b.start_time, b.end_time, b.booking_type, b.status, b.total_amount
    FROM dbo.bookings AS b
    WHERE b.court_id = ?
      AND b.booking_date = ?
      AND (
          b.status = 'Confirmed'
          OR (b.status = 'Cancelled' AND b.total_amount = 0.00)
      )
    ORDER BY b.start_time ASC
";
$res_busy = sqlsrv_query($conn, $sql_busy_bookings, [$court_id, $selected_date]);
if ($res_busy) {
    while ($b = sqlsrv_fetch_array($res_busy, SQLSRV_FETCH_ASSOC)) {
        $label = 'Reserved';
        if ($b['status'] === 'Cancelled' && (float)$b['total_amount'] == 0.0) {
            $label = 'Facility Blocked';
        } elseif ($b['booking_type'] === 'Open_Play') {
            $label = 'Open Play Session';
        }
        $busy_slots[] = [
            'start' => $b['start_time']->format('g:i A'),
            'end'   => $b['end_time']->format('g:i A'),
            'label' => $label
        ];
    }
}

// Also include scheduled Open Plays that have 0 sign-ups yet
$sql_busy_op = "
    SELECT o.start_time, o.end_time, o.title
    FROM dbo.open_plays AS o
    WHERE o.court_id = ? AND o.play_date = ?
    ORDER BY o.start_time ASC
";
$res_op = sqlsrv_query($conn, $sql_busy_op, [$court_id, $selected_date]);
if ($res_op) {
    while ($op = sqlsrv_fetch_array($res_op, SQLSRV_FETCH_ASSOC)) {
        $start_str = $op['start_time']->format('g:i A');
        $end_str   = $op['end_time']->format('g:i A');
        $already_listed = false;
        foreach ($busy_slots as $existing) {
            if ($existing['start'] === $start_str && $existing['end'] === $end_str) {
                $already_listed = true;
                break;
            }
        }
        if (!$already_listed) {
            $busy_slots[] = [
                'start' => $start_str,
                'end'   => $end_str,
                'label' => 'Open Play: ' . $op['title']
            ];
        }
    }
}

$hourly_rate = ($court['court_type'] === 'Indoor') ? 500.00 : 350.00;
$flash_error = $_SESSION['booking_error'] ?? '';
unset($_SESSION['booking_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book <?php echo htmlspecialchars($court['court_name']); ?> - RALLIQ</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <link rel="stylesheet" href="../ralliq-theme.css?v=4">
</head>
<body>

    <?php require_once '../includes/player_nav.php'; ?>

    <header class="page-header-strip">
        <div class="container">
            <a href="discover.php" class="small fw-semibold text-orange text-decoration-none d-inline-block mb-2">&larr; Back to Discover Courts</a>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h1 class="h3 fw-bold mb-1"><?php echo htmlspecialchars($court['court_name']); ?></h1>
                    <p class="small text-stone mb-0">
                        <?php echo htmlspecialchars($court['venue_name']); ?> &bull; <?php echo htmlspecialchars($court['address']); ?><?php echo !empty($court['city']) ? ', ' . htmlspecialchars($court['city']) : ''; ?>
                    </p>
                </div>
                <span class="badge rounded-pill badge-slate px-3 py-2 fs-6">
                    &#8369;<?php echo number_format($hourly_rate, 2); ?> / hour
                </span>
            </div>
        </div>
    </header>

    <main class="container pb-5">

        <?php if ($flash_error !== ''): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4" role="alert">
                <strong>Booking Unavailable:</strong> <?php echo htmlspecialchars($flash_error); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- Left Column: Court Info & Daily Availability Preview -->
            <div class="col-12 col-lg-6">
                <div class="card card-custom overflow-hidden mb-4">
                    <img src="<?php echo htmlspecialchars($hero_img); ?>"
                         onerror="this.src='../assets/default_court.png';"
                         alt="<?php echo htmlspecialchars($court['court_name']); ?>"
                         class="w-100"
                         style="height: 240px; object-fit: cover;">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <span class="badge rounded-pill badge-soft-orange px-3 py-1"><?php echo htmlspecialchars($court['court_type']); ?> Court</span>
                            <span class="badge rounded-pill bg-light text-dark border px-3 py-1"><?php echo htmlspecialchars($court['surface_type']); ?></span>
                        </div>
                        <p class="small text-stone mb-0">
                            Private court rentals include exclusive access to the full court for your selected hours. Please arrive 10 minutes before your scheduled start time.
                        </p>
                    </div>
                </div>

                <!-- Daily Busy Schedule Checker -->
                <div class="card card-custom p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <h2 class="h6 fw-bold mb-0">Unavailable Windows</h2>
                            <p class="small text-stone mb-0">For <?php echo date('F j, Y', strtotime($selected_date)); ?></p>
                        </div>

                        <form action="book_court.php" method="GET" class="d-flex gap-2">
                            <input type="hidden" name="court_id" value="<?php echo $court_id; ?>">
                            <input type="date" name="date" class="form-control form-control-sm form-control-custom" min="<?php echo $today; ?>" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="this.form.submit()">
                        </form>
                    </div>

                    <?php if (count($busy_slots) > 0): ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($busy_slots as $slot): ?>
                                <div class="roster-item-card d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="small fw-semibold"><?php echo htmlspecialchars($slot['start'] . ' - ' . $slot['end']); ?></span>
                                    <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill"><?php echo htmlspecialchars($slot['label']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-3 rounded-4 text-center" style="background-color: var(--color-bg);">
                            <p class="small fw-semibold text-success mb-0">All operating hours are currently open on this date!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Reservation Form -->
            <div class="col-12 col-lg-6">
                <div class="card card-custom p-4 p-md-5">
                    <h2 class="h4 fw-bold mb-1">Reserve This Court</h2>
                    <p class="small text-stone mb-4">Select your date and hourly time window below.</p>

                    <form action="process_booking.php" method="POST" id="bookingForm">
                        <input type="hidden" name="court_id" value="<?php echo $court_id; ?>">

                        <div class="mb-3">
                            <label for="booking_date" class="form-label-custom">Play Date *</label>
                            <input type="date" id="booking_date" name="booking_date" class="form-control form-control-custom" min="<?php echo $today; ?>" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <label for="start_time" class="form-label-custom">Start Time *</label>
                                <select id="start_time" name="start_time" class="form-select form-control-custom" required>
                                    <?php for ($h = 6; $h <= 21; $h++): ?>
                                        <?php
                                            $val = sprintf('%02d:00', $h);
                                            $lbl = date('g:i A', strtotime($val));
                                        ?>
                                        <option value="<?php echo $val; ?>" <?php echo $h === 8 ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="col-6">
                                <label for="end_time" class="form-label-custom">End Time *</label>
                                <select id="end_time" name="end_time" class="form-select form-control-custom" required>
                                    <?php for ($h = 7; $h <= 22; $h++): ?>
                                        <?php
                                            $val = sprintf('%02d:00', $h);
                                            $lbl = date('g:i A', strtotime($val));
                                        ?>
                                        <option value="<?php echo $val; ?>" <?php echo $h === 10 ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Live Fee Summary Box -->
                        <div class="p-4 rounded-4 mb-4" style="background-color: var(--color-bg); border: 1px solid var(--color-border);">
                            <div class="d-flex justify-content-between small mb-2">
                                <span class="text-stone">Court Rate (<?php echo htmlspecialchars($court['court_type']); ?>)</span>
                                <span class="fw-semibold">&#8369;<?php echo number_format($hourly_rate, 2); ?> / hr</span>
                            </div>
                            <div class="d-flex justify-content-between small mb-2">
                                <span class="text-stone">Duration</span>
                                <span class="fw-semibold" id="durationLabel">2 hrs</span>
                            </div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold">Estimated Total</span>
                                <span class="h4 fw-bold text-orange mb-0" id="totalAmountLabel">&#8369;<?php echo number_format($hourly_rate * 2, 2); ?></span>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-3 fs-6" id="submitBookingBtn">
                            Confirm Court Reservation
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </main>

    <script>
        const hourlyRate = <?php echo json_encode($hourly_rate); ?>;
        const startSelect = document.getElementById('start_time');
        const endSelect = document.getElementById('end_time');
        const durationLabel = document.getElementById('durationLabel');
        const totalLabel = document.getElementById('totalAmountLabel');
        const submitBtn = document.getElementById('submitBookingBtn');

        function updateTotal() {
            const startHour = parseInt(startSelect.value.split(':')[0], 10);
            const endHour = parseInt(endSelect.value.split(':')[0], 10);
            const hours = endHour - startHour;

            if (hours <= 0) {
                durationLabel.textContent = 'Invalid time range';
                totalLabel.textContent = '₱0.00';
                submitBtn.disabled = true;
            } else {
                durationLabel.textContent = hours + (hours === 1 ? ' hr' : ' hrs');
                const total = hours * hourlyRate;
                totalLabel.textContent = '₱' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                submitBtn.disabled = false;
            }
        }

        startSelect.addEventListener('change', updateTotal);
        endSelect.addEventListener('change', updateTotal);
        updateTotal();
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>