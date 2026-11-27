<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Player') {
    header("Location: ../login.php");
    exit();
}

require_once '../includes/db.php';

$user_id     = (int)$_SESSION['user_id'];
$active_page = 'discover';

// Fetch player avatar for navbar
$display_avatar = "default_avatar.png";
$stmt_prof = sqlsrv_query($conn, "SELECT avatar_url FROM dbo.users WHERE user_id = ?", [$user_id]);
if ($stmt_prof && sqlsrv_has_rows($stmt_prof)) {
    $prof = sqlsrv_fetch_array($stmt_prof, SQLSRV_FETCH_ASSOC);
    if (!empty($prof['avatar_url'])) {
        $display_avatar = $prof['avatar_url'];
    }
}

// Read GET filter inputs
$search_q     = trim($_GET['q'] ?? '');
$filter_city  = trim($_GET['city'] ?? '');
$filter_type  = trim($_GET['court_type'] ?? '');
$filter_surf  = trim($_GET['surface_type'] ?? '');

// Fetch distinct cities for filter dropdown
$cities = [];
$res_cities = sqlsrv_query($conn, "SELECT DISTINCT city FROM dbo.venues WHERE city IS NOT NULL AND city <> '' ORDER BY city ASC");
if ($res_cities) {
    while ($c_row = sqlsrv_fetch_array($res_cities, SQLSRV_FETCH_ASSOC)) {
        $cities[] = $c_row['city'];
    }
}

// Fetch active courts with venue info and cover photo
$sql_courts = "
    SELECT
        c.court_id,
        c.court_name,
        c.court_type,
        c.surface_type,
        v.venue_id,
        v.venue_name,
        v.address,
        v.city,
        (
            SELECT TOP(1) cp.picture_url
            FROM dbo.court_pictures AS cp
            WHERE cp.court_id = c.court_id
            ORDER BY cp.picture_id DESC
        ) AS picture_url
    FROM dbo.courts AS c
    INNER JOIN dbo.venues AS v ON c.venue_id = v.venue_id
    WHERE c.is_active = 1
";
$params = [];

if ($search_q !== '') {
    $sql_courts .= " AND (c.court_name LIKE ? OR v.venue_name LIKE ? OR v.address LIKE ?)";
    $like = '%' . $search_q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($filter_city !== '') {
    $sql_courts .= " AND v.city = ?";
    $params[] = $filter_city;
}
if ($filter_type !== '') {
    $sql_courts .= " AND c.court_type = ?";
    $params[] = $filter_type;
}
if ($filter_surf !== '') {
    $sql_courts .= " AND c.surface_type = ?";
    $params[] = $filter_surf;
}

$sql_courts .= " ORDER BY v.venue_name ASC, c.court_name ASC";

$stmt_courts = sqlsrv_query($conn, $sql_courts, $params);
$courts = [];
if ($stmt_courts) {
    while ($row = sqlsrv_fetch_array($stmt_courts, SQLSRV_FETCH_ASSOC)) {
        $courts[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discover Courts - RALLIQ</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <link rel="stylesheet" href="../ralliq-theme.css?v=4">
</head>
<body>

    <?php require_once '../includes/player_nav.php'; ?>

    <header class="page-header-strip">
        <div class="container">
            <div class="row align-items-center gy-2">
                <div class="col-md-8">
                    <span class="badge rounded-pill badge-soft-orange px-3 py-1 mb-2">PRIVATE RENTALS</span>
                    <h1 class="h3 fw-bold mb-1">Discover Pickleball Courts</h1>
                    <p class="small text-stone mb-0">Browse active indoor and outdoor courts and reserve your preferred time slot.</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <span class="small fw-semibold text-stone"><?php echo count($courts); ?> Active Court<?php echo count($courts) === 1 ? '' : 's'; ?> Available</span>
                </div>
            </div>
        </div>
    </header>

    <main class="container pb-5">

        <!-- Filter Bar -->
        <section class="card card-custom p-4 mb-4">
            <form action="discover.php" method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="q" class="form-label-custom small">Search Venue or Court</label>
                    <input type="text" id="q" name="q" class="form-control form-control-custom" placeholder="e.g., Imus, Championship..." value="<?php echo htmlspecialchars($search_q); ?>">
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
                    <label for="court_type" class="form-label-custom small">Court Type</label>
                    <select id="court_type" name="court_type" class="form-select form-control-custom">
                        <option value="">All Types</option>
                        <option value="Indoor" <?php echo $filter_type === 'Indoor' ? 'selected' : ''; ?>>Indoor</option>
                        <option value="Outdoor" <?php echo $filter_type === 'Outdoor' ? 'selected' : ''; ?>>Outdoor</option>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label for="surface_type" class="form-label-custom small">Surface</label>
                    <select id="surface_type" name="surface_type" class="form-select form-control-custom">
                        <option value="">All Surfaces</option>
                        <?php
                        $surfaces = [
                            'Acrylic Hardcourt',
                            'Cushioned Acrylic',
                            'Polypropylene Modular',
                            'Concrete',
                            'Asphalt',
                            'Wood / Gymnasium'
                        ];
                        foreach ($surfaces as $surf):
                        ?>
                            <option value="<?php echo htmlspecialchars($surf); ?>" <?php echo $filter_surf === $surf ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($surf); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                    <?php if ($search_q !== '' || $filter_city !== '' || $filter_type !== '' || $filter_surf !== ''): ?>
                        <a href="discover.php" class="btn btn-outline-secondary rounded-pill px-3" title="Reset filters">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Courts Grid -->
        <?php if (count($courts) > 0): ?>
            <section class="row g-4">
                <?php foreach ($courts as $court): ?>
                    <?php
                        $img_src = !empty($court['picture_url'])
                            ? '../court_images/' . $court['picture_url']
                            : '../assets/default_court.png';
                        $hourly_rate = ($court['court_type'] === 'Indoor') ? 500 : 350;
                        $location_line = $court['address'] . (!empty($court['city']) ? ', ' . $court['city'] : '');
                    ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card booking-card overflow-hidden d-flex flex-column">
                            <div class="position-relative">
                                <img src="<?php echo htmlspecialchars($img_src); ?>"
                                     onerror="this.src='../assets/default_court.png';"
                                     alt="<?php echo htmlspecialchars($court['court_name']); ?>"
                                     class="w-100 court-thumbnail">
                                <span class="badge rounded-pill badge-slate position-absolute top-0 end-0 m-3 px-3 py-2">
                                    &#8369;<?php echo number_format($hourly_rate, 0); ?> / hr
                                </span>
                            </div>

                            <div class="card-body p-4 d-flex flex-column">
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <span class="badge rounded-pill badge-soft-orange px-3 py-1"><?php echo htmlspecialchars($court['court_type']); ?></span>
                                    <span class="badge rounded-pill bg-light text-dark border px-3 py-1"><?php echo htmlspecialchars($court['surface_type']); ?></span>
                                </div>

                                <h2 class="h5 fw-bold mb-1"><?php echo htmlspecialchars($court['court_name']); ?></h2>
                                <p class="small fw-semibold mb-1"><?php echo htmlspecialchars($court['venue_name']); ?></p>
                                <p class="small text-stone mb-4"><?php echo htmlspecialchars($location_line); ?></p>

                                <div class="mt-auto pt-3 border-top">
                                    <a href="book_court.php?court_id=<?php echo (int)$court['court_id']; ?>" class="btn btn-primary w-100">
                                        Check Slots &amp; Book
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="card card-custom p-5 text-center">
                <img src="../assets/default_court.png" alt="No Courts Found" class="mx-auto mb-3 rounded-3" style="width: 110px; height: 80px; object-fit: cover;">
                <h2 class="h5 fw-bold mb-1">No Matching Courts Found</h2>
                <p class="small text-stone mb-3">Try clearing one or more filters to view all active courts.</p>
                <div>
                    <a href="discover.php" class="btn btn-primary btn-sm px-4">Show All Courts</a>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>