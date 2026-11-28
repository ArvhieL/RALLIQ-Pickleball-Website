<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Created - RALLIQ</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <link rel="stylesheet" href="ralliq-theme.css?v=4">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3">

    <div class="card card-custom text-center overflow-hidden" style="max-width: 480px; width: 100%;">
        <div style="height: 6px; background-color: var(--color-orange);"></div>

        <div class="card-body p-4 p-md-5">
            <img src="assets/RalliQ Main Logo.png" alt="RALLIQ Logo" class="img-fluid mb-4" style="max-width: 210px;">

            <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle" style="width: 72px; height: 72px; background-color: var(--color-orange-soft); color: var(--color-orange);">
                <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                </svg>
            </div>

            <h1 class="h3 fw-bold mb-2">Account Created!</h1>
            <p class="text-stone mb-4">
                Your RALLIQ profile is ready. Log in to reserve pickleball courts, join Open Play sessions, or manage your facility.
            </p>

            <a href="login.php" class="btn btn-primary w-100 py-2 fs-6">Go to Login</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>