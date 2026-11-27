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

// Fetch player avatar for navbar
$display_avatar = "default_avatar.png";
$stmt_prof = sqlsrv_query($conn, "SELECT avatar_url FROM dbo.users WHERE user_id = ?", [$user_id]);
if ($stmt_prof && sqlsrv_has_rows($stmt_prof)) {
    $prof = sqlsrv_fetch_array($stmt_prof, SQLSRV_FETCH_ASSOC);
    if (!empty($prof['avatar_url'])) {
        $display_avatar = $prof['avatar_url'];
    }
}

$play_id = (int)($_GET['play_id'] ?? 0);
if ($play_id <= 0) {
    header("Location: open_plays.php");
    exit();
}

// Fetch Open Play session + court + venue metadata
$sql_play = "
    SELECT
        o.play_id,
        o.court_id,
        o.title,
        o.skill_level_req,
        o.play_date,
        o.start_time,
        o.end_time,
        o.fee,
        o.max_slots,
        c.court_name,
        c.court_type,
        c.surface_type,
        v.venue_name,
        v.address,
        v.city,
        (
            SELECT TOP(1) cp.picture_url
            FROM dbo.court_pictures AS cp
            WHERE cp.court_id = c.court_id
            ORDER BY cp.picture_id DESC
        ) AS picture_url
    FROM dbo.open_plays AS o
    INNER JOIN dbo.courts AS c ON o.court_id = c.court_id
    INNER JOIN dbo.venues AS v ON c.venue_id = v.venue_id
    WHERE o.play_id = ?
";
$stmt_play = sqlsrv_query($conn, $sql_play, [$play_id]);
if (!$stmt_play || !sqlsrv_has_rows($stmt_play)) {
    header("Location: open_plays.php");
    exit();
}
$play = sqlsrv_fetch_array($stmt_play, SQLSRV_FETCH_ASSOC);

// Fetch confirmed roster entries (primary players + guests)
$sql_roster = "
    SELECT
        r.roster_id,
        r.user_id,
        r.guest_name,
        r.registered_at,
        u.first_name,
        u.last_name,
        u.skill_level,
        u.avatar_url
    FROM dbo.open_play_roster AS r
    INNER JOIN dbo.users AS u ON r.user_id = u.user_id
    INNER JOIN dbo.bookings AS b ON r.booking_id = b.booking_id
    WHERE r.play_id = ?
      AND b.status = 'Confirmed'
    ORDER BY r.registered_at ASC, r.roster_id ASC
";
$stmt_roster = sqlsrv_query($conn, $sql_roster, [$play_id]);

$roster_list = [];
$my_slots    = 0;
if ($stmt_roster) {
    while ($r_row = sqlsrv_fetch_array($stmt_roster, SQLSRV_FETCH_ASSOC)) {
        $roster_list[] = $r_row;
        if ((int)$r_row['user_id'] === $user_id) {
            $my_slots++;
        }
    }
}

$booked_slots    = count($roster_list);
$max_slots       = (int)$play['max_slots'];
$remaining_slots = max(0, $max_slots - $booked_slots);
$fee_per_slot    = (float)$play['fee'];

$play_date_raw = $play['play_date']->format('Y-m-d');
$start_raw     = $play['start_time']->format('H:i:s');
$has_started   = ($play_date_raw < $today) || ($play_date_raw === $today && $start_raw <= $now_time);

$court_img = !empty($play['picture_url'])
    ? '../court_images/' . $play['picture_url']
    : '../assets/default_court.png';

$flash_error = $_SESSION['open_play_error'] ?? '';
unset($_SESSION['open_play_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($play['title']); ?> - Open Play | RALLIQ</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <link rel="stylesheet" href="../ralliq-theme.css?v=4">
</head>
<body>

    <?php require_once '../includes/player_nav.php'; ?>

    <header class="page-header-strip">
        <div class="container">
            <a href="open_plays.php" class="small fw-semibold text-orange text-decoration-none d-inline-block mb-2">&larr; Back to Open Plays</a>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge rounded-pill badge-soft-orange px-3 py-1"><?php echo htmlspecialchars($play['skill_level_req']); ?></span>
                        <span class="badge rounded-pill bg-light text-dark border px-3 py-1"><?php echo htmlspecialchars($play['court_type']); ?></span>
                    </div>
                    <h1 class="h3 fw-bold mb-1"><?php echo htmlspecialchars($play['title']); ?></h1>
                    <p class="small text-stone mb-0">
                        <?php echo htmlspecialchars($play['venue_name']); ?> &bull; <?php echo htmlspecialchars($play['address']); ?><?php echo !empty($play['city']) ? ', ' . htmlspecialchars($play['city']) : ''; ?>
                    </p>
                </div>
                <span class="badge rounded-pill badge-slate px-3 py-2 fs-6">
                    <?php echo $fee_per_slot > 0 ? '&#8369;' . number_format($fee_per_slot, 2) . ' / player' : 'Free Entry'; ?>
                </span>
            </div>
        </div>
    </header>

    <main class="container pb-5">

        <!-- Status & Error Alert Banners -->
        <?php if (isset($_GET['status']) && $_GET['status'] === 'joined'): ?>
            <div class="alert alert-success rounded-4 border-0 shadow-sm mb-4" role="alert">
                <strong>You're on the roster!</strong> Your Open Play slot(s) have been confirmed.
            </div>
        <?php elseif (isset($_GET['status']) && $_GET['status'] === 'left_session'): ?>
            <div class="alert alert-success rounded-4 border-0 shadow-sm mb-4" role="alert">
                You have successfully left this Open Play session. Your reserved slot(s) have been released.
            </div>
        <?php elseif (isset($_GET['error']) && ($_GET['error'] === 'cannot_leave' || $_GET['error'] === 'leave_failed')): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4" role="alert">
                Unable to process your leave request. The session may have already started or you are no longer registered.
            </div>
        <?php elseif ($flash_error !== ''): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4" role="alert">
                <strong>Unable to Join:</strong> <?php echo htmlspecialchars($flash_error); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- Left Column: Session Details & Confirmed Roster -->
            <div class="col-12 col-lg-7">
                <div class="card card-custom overflow-hidden mb-4">
                    <img src="<?php echo htmlspecialchars($court_img); ?>"
                         onerror="this.src='../assets/default_court.png';"
                         alt="<?php echo htmlspecialchars($play['court_name']); ?>"
                         class="w-100"
                         style="height: 220px; object-fit: cover;">
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-6 col-sm-4">
                                <p class="small text-stone text-uppercase fw-semibold mb-1">Date</p>
                                <p class="fw-bold mb-0"><?php echo $play['play_date']->format('M j, Y'); ?></p>
                            </div>
                            <div class="col-6 col-sm-4">
                                <p class="small text-stone text-uppercase fw-semibold mb-1">Time</p>
                                <p class="fw-bold mb-0">
                                    <?php echo $play['start_time']->format('g:i A') . ' - ' . $play['end_time']->format('g:i A'); ?>
                                </p>
                            </div>
                            <div class="col-12 col-sm-4">
                                <p class="small text-stone text-uppercase fw-semibold mb-1">Court</p>
                                <p class="fw-bold mb-0"><?php echo htmlspecialchars($play['court_name']); ?></p>
                                <p class="small text-stone mb-0"><?php echo htmlspecialchars($play['surface_type']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Confirmed Player Roster -->
                <div class="card card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                        <div>
                            <h2 class="h5 fw-bold mb-0">Confirmed Roster</h2>
                            <p class="small text-stone mb-0">Players and guests registered for this session</p>
                        </div>
                        <span class="badge rounded-pill badge-slate px-3 py-2">
                            <?php echo $booked_slots . ' / ' . $max_slots; ?> Slots
                        </span>
                    </div>

                    <?php if ($booked_slots > 0): ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($roster_list as $idx => $entry): ?>
                                <?php
                                    $is_guest   = !empty($entry['guest_name']);
                                    $host_name  = $entry['first_name'] . ' ' . $entry['last_name'];
                                    $entry_name = $is_guest ? $entry['guest_name'] : $host_name;
                                    $p_avatar   = !empty($entry['avatar_url']) ? $entry['avatar_url'] : 'default_avatar.png';
                                    $is_me      = ((int)$entry['user_id'] === $user_id);
                                ?>
                                <div class="roster-item-card d-flex align-items-center justify-content-between gap-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="small fw-bold text-stone" style="width: 22px;">#<?php echo $idx + 1; ?></span>
                                        <img src="../avatars/<?php echo htmlspecialchars($p_avatar); ?>"
                                             onerror="this.src='../avatars/default_avatar.png';"
                                             alt="Player Avatar"
                                             class="nav-avatar">
                                        <div>
                                            <p class="fw-bold small mb-0">
                                                <?php echo htmlspecialchars($entry_name); ?>
                                                <?php if ($is_me && !$is_guest): ?>
                                                    <span class="badge rounded-pill badge-soft-orange ms-1">You</span>
                                                <?php endif; ?>
                                            </p>
                                            <p class="small text-stone mb-0">
                                                <?php if ($is_guest): ?>
                                                    Guest of <?php echo htmlspecialchars($host_name); ?>
                                                <?php else: ?>
                                                    Skill: <?php echo htmlspecialchars($entry['skill_level'] ?? 'Beginner'); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <span class="badge rounded-pill bg-success bg-opacity-10 text-success px-3 py-1">Confirmed</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 px-3 rounded-4" style="background-color: var(--color-bg);">
                            <p class="small text-stone mb-0">No players have joined yet. Be the first to claim a spot!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Join Form & Leave Session Controls -->
            <div class="col-12 col-lg-5">
                <div class="card card-custom p-4">
                    <h2 class="h5 fw-bold mb-1">Session Registration</h2>
                    <p class="small text-stone mb-3">
                        <?php echo $remaining_slots; ?> of <?php echo $max_slots; ?> slots still available.
                    </p>

                    <?php if ($has_started): ?>
                        <div class="p-3 rounded-4 text-center" style="background-color: var(--color-bg);">
                            <p class="small fw-semibold text-stone mb-0">This Open Play session has already started or ended.</p>
                        </div>
                    <?php elseif ($remaining_slots > 0): ?>
                        <form action="join_session.php" method="POST" id="joinSessionForm">
                            <input type="hidden" name="play_id" value="<?php echo $play_id; ?>">

                            <div class="mb-3">
                                <label for="slot_count" class="form-label-custom">How many spots do you need? *</label>
                                <select id="slot_count" name="slot_count" class="form-select form-control-custom" required>
                                    <?php $max_selectable = min(4, $remaining_slots); ?>
                                    <?php for ($s = 1; $s <= $max_selectable; $s++): ?>
                                        <option value="<?php echo $s; ?>">
                                            <?php echo $s === 1 ? '1 Spot (Just Me)' : $s . ' Spots (Me + ' . ($s - 1) . ' Guest' . ($s - 1 > 1 ? 's' : '') . ')'; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <!-- Dynamic Guest Name Inputs -->
                            <div id="guestFieldsContainer" class="mb-3"></div>

                            <!-- Fee Breakdown -->
                            <div class="p-3 rounded-4 mb-4" style="background-color: var(--color-bg); border: 1px solid var(--color-border);">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="text-stone">Entry Fee</span>
                                    <span class="fw-semibold">&#8369;<?php echo number_format($fee_per_slot, 2); ?> &times; <span id="slotCountLabel">1</span></span>
                                </div>
                                <hr class="my-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold">Total Due</span>
                                    <span class="h5 fw-bold text-orange mb-0" id="totalFeeLabel">&#8369;<?php echo number_format($fee_per_slot, 2); ?></span>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-3">
                                <?php echo $my_slots > 0 ? 'Add More Spots' : 'Join Open Play'; ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="p-3 rounded-4 text-center" style="background-color: var(--color-bg);">
                            <p class="small fw-semibold text-stone mb-0">All <?php echo $max_slots; ?> slots for this session are currently filled.</p>
                        </div>
                    <?php endif; ?>

                    <!-- Active Registration & Leave Session Box (Wired to PlayerDashboard/leave_session.php) -->
                    <?php if ($my_slots > 0): ?>
                        <div class="p-3 rounded-4 mt-4" style="background-color: var(--color-bg); border: 1px solid var(--color-border);">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="fw-bold small">Your Active Registration</span>
                                <span class="badge rounded-pill badge-slate px-3 py-1">
                                    <?php echo $my_slots; ?> Slot<?php echo $my_slots > 1 ? 's' : ''; ?> Reserved
                                </span>
                            </div>
                            <p class="small text-stone mb-3">
                                Can't make it anymore? Leave this session to release your spot(s) back to the community.
                            </p>
                            <?php if (!$has_started): ?>
                                <form action="leave_session.php" method="POST" onsubmit="return confirm('Are you sure you want to leave this Open Play session and release your <?php echo $my_slots; ?> slot(s)?');">
                                    <input type="hidden" name="play_id" value="<?php echo $play_id; ?>">
                                    <button type="submit" class="btn btn-outline-danger w-100 rounded-pill fw-semibold">
                                        Leave Open Play Session
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <script>
        const feePerSlot = <?php echo json_encode($fee_per_slot); ?>;
        const slotSelect = document.getElementById('slot_count');
        const guestContainer = document.getElementById('guestFieldsContainer');
        const slotCountLabel = document.getElementById('slotCountLabel');
        const totalFeeLabel = document.getElementById('totalFeeLabel');

        if (slotSelect) {
            slotSelect.addEventListener('change', function () {
                const count = parseInt(this.value, 10) || 1;
                slotCountLabel.textContent = count;
                const total = count * feePerSlot;
                totalFeeLabel.textContent = '₱' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                guestContainer.innerHTML = '';
                for (let i = 1; i < count; i++) {
                    const div = document.createElement('div');
                    div.className = 'mb-2';
                    div.innerHTML = `
                        <label class="form-label-custom small">Guest #${i} Name (Optional)</label>
                        <input type="text" name="guest_names[]" maxlength="100" class="form-control form-control-custom" placeholder="e.g., Alex Reyes">
                    `;
                    guestContainer.appendChild(div);
                }
            });
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>