<?php
session_start();

// 1. Redirect already-authenticated users directly to their role dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'Manager') {
        header("Location: ManagerDashboard/manager_dashboard.php");
        exit();
    } elseif ($_SESSION['role'] === 'Player') {
        header("Location: PlayerDashboard/player_dashboard.php");
        exit();
    }
}

// 2. SQL Server Connection
$serverName = "LAPTOP-QIJJ9RAT\\SQLEXPRESS";
$connectionOptions = [
    "Database" => "FinalProject",
    "Uid"      => "",
    "PWD"      => ""
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die(print_r(sqlsrv_errors(), true));
}

// 3. Handle Login Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if ($email === '' || $password === '') {
        header("Location: login.php?error=empty_fields");
        exit();
    }

    // Parameterized query to verify credentials against dbo.users
    $sql  = "SELECT user_id, first_name, role FROM users WHERE email = ? AND password = ?";
    $stmt = sqlsrv_query($conn, $sql, [$email, $password]);

    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        if ($row) {
            session_regenerate_id(true);

            $_SESSION['user_id']    = $row['user_id'];
            $_SESSION['first_name'] = $row['first_name'];
            $_SESSION['role']       = $row['role'];

            if ($_SESSION['role'] === 'Manager') {
                header("Location: ManagerDashboard/manager_dashboard.php");
                exit();
            } else {
                header("Location: PlayerDashboard/player_dashboard.php");
                exit();
            }
        }
    }

    // Authentication failed
    header("Location: login.php?error=invalid_credentials");
    exit();
}

// 4. Resolve Status & Error Alert Messages
$alert_message = '';
$alert_type    = '';

if (isset($_GET['status']) && $_GET['status'] === 'logged_out') {
    $alert_message = 'You have been logged out safely.';
    $alert_type    = 'success';
} elseif (isset($_GET['error'])) {
    if ($_GET['error'] === 'invalid_credentials') {
        $alert_message = 'Invalid email or password. Please try again.';
        $alert_type    = 'danger';
    } elseif ($_GET['error'] === 'empty_fields') {
        $alert_message = 'Please enter both your email address and password.';
        $alert_type    = 'warning';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - RALLIQ</title>

    <!-- Bootstrap 5.2.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">

    <!-- RALLIQ Orange & Slate Theme -->
    <link rel="stylesheet" href="ralliq-theme.css?v=4">

    <style>
        .login-hero-panel {
            background-image:
                linear-gradient(135deg, rgba(30, 36, 43, 0.84) 0%, rgba(30, 36, 43, 0.58) 60%, rgba(255, 107, 43, 0.32) 100%),
                url('assets/RalliQ Background Splash.png');
            background-size: cover;
            background-position: center;
            min-height: 100vh;
            border-right: 4px solid var(--color-orange);
        }
    </style>
</head>
<body class="min-vh-100 p-0">

    <div class="container-fluid p-0">
        <div class="row g-0 min-vh-100">

            <!-- Left Column: Full-Height Court Splash Hero (Desktop) -->
            <div class="d-none d-lg-flex col-lg-7 login-hero-panel align-items-center p-5">
                <div class="w-100 px-xl-5">
                    <h1 class="display-2 fw-bold text-white mb-3">READY<br>TO<br>RALLY?</h1>
                    <p class="fs-4 text-white opacity-75 mb-0" style="max-width: 480px;">
                        Book courts, join open play sessions, and manage your venue in one place.
                    </p>
                </div>
            </div>

            <!-- Right Column: Simplified Login Form Card -->
            <div class="col-12 col-lg-5 d-flex flex-column justify-content-center p-4 p-md-5">
                <div class="w-100 mx-auto" style="max-width: 440px;">

                    <div class="card card-custom p-4 p-md-5">
                        <div class="card-body p-0">

                            <!-- Brand Logo & Subheading -->
                            <div class="text-center border-bottom pb-4 mb-4">
                                <img src="assets/RalliQ Main Logo.png" alt="RALLIQ Logo" class="img-fluid mb-3" style="max-width: 220px;">
                                <h2 class="h5 fw-bold mb-1">Welcome Back</h2>
                                <p class="small text-stone mb-0">Sign in to access your player or manager portal</p>
                            </div>

                            <!-- Status & Validation Feedback -->
                            <?php if ($alert_message !== ''): ?>
                                <div class="alert alert-<?php echo htmlspecialchars($alert_type); ?> text-center small fw-medium rounded-3 py-2 mb-4" role="alert">
                                    <?php echo htmlspecialchars($alert_message); ?>
                                </div>
                            <?php endif; ?>

                            <!-- Login Form -->
                            <form action="login.php" method="POST">
                                <div class="mb-3">
                                    <label for="email" class="form-label form-label-custom">Email Address</label>
                                    <input
                                        type="email"
                                        class="form-control form-control-custom"
                                        id="email"
                                        name="email"
                                        placeholder="player@example.com"
                                        maxlength="50"
                                        required
                                    >
                                </div>

                                <div class="mb-4">
                                    <label for="password" class="form-label form-label-custom">Password</label>
                                    <input
                                        type="password"
                                        class="form-control form-control-custom"
                                        id="password"
                                        name="password"
                                        placeholder="Enter your password"
                                        maxlength="50"
                                        required
                                    >
                                </div>

                                <button type="submit" class="btn btn-primary w-100 py-2 fs-6">
                                    Log In
                                </button>
                            </form>

                            <!-- Sign Up Footer Link -->
                            <div class="text-center border-top pt-4 mt-4">
                                <p class="small text-stone mb-1">Don't have an account yet?</p>
                                <a href="signup.php" class="text-orange fw-semibold text-decoration-none">
                                    Create an Account
                                </a>
                            </div>

                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>

    <!-- Bootstrap 5.2.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>