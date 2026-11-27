<?php
// signup_success.php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up Successful - RALLIQ</title>
    
    <!-- Bootstrap CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    
    <!-- Architecture-Aligned Brand CSS -->
    <link rel="stylesheet" href="ralliq-theme.css">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3" style="background-color: var(--color-forest); background-image: url('assets/Register Background.png'); background-size: cover; background-position: center;">

    <div class="card card-custom border-0 text-center shadow-lg" style="max-width: 500px; border-radius: var(--radius-card); background-color: var(--color-white); overflow: hidden;">
        <div class="card-body p-5">
            <img src="assets/RalliQ Main Logo.png" alt="RALLIQ Logo" class="img-fluid mb-4" style="max-width: 250px;">
            
            <div class="mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" fill="var(--color-lime)" class="bi bi-check-circle-fill" viewBox="0 0 16 16">
                  <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                </svg>
            </div>
            
            <h2 class="h3 fw-bold mb-3" style="color: var(--color-forest); font-family: var(--font-heading);">Account Created!</h2>
            <p class="text-stone mb-5">Your RALLIQ account has been successfully registered. You can now log in to book courts and join open play sessions.</p>
            
            <!-- Fixed href to point to login -->
            <a href="login.php" class="btn btn-primary-custom w-100 fs-5" style="border-radius: 50px;">Go to Login</a>
        </div>
        
        <!-- Bottom Brand Accent Line -->
        <div style="height: 10px; background-color: var(--color-lime); width: 100%;"></div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>