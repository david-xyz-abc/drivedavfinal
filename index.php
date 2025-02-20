<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Self Hosted Google Drive - Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">

  <style>
    /* [Existing styles remain unchanged] */
    .toggle-link {
      color: #4a90e2;
      cursor: pointer;
      text-decoration: underline;
      margin-top: 15px;
      display: inline-block;
    }
    .hidden {
      display: none;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="logo"></div>
    <div class="project-name">Self Hosted Google Drive</div>

    <?php 
      if (isset($_SESSION['error'])) {
          echo '<div class="error">' . htmlspecialchars($_SESSION['error']) . '</div>';
          unset($_SESSION['error']);
      }
      if (isset($_SESSION['message'])) {
          echo '<div style="color: #4a90e2; margin-bottom: 15px;">' . htmlspecialchars($_SESSION['message']) . '</div>';
          unset($_SESSION['message']);
      }
    ?>

    <!-- Login Form -->
    <form action="authenticate.php" method="post" id="loginForm">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>

      <button type="submit" class="button">Sign In</button>
    </form>

    <span class="toggle-link" onclick="toggleForms()">Need an account? Register here</span>

    <!-- Registration Form -->
    <form action="register.php" method="post" id="registerForm" class="hidden">
      <div class="form-group">
        <label for="reg_username">Username</label>
        <input type="text" id="reg_username" name="username" required>
      </div>

      <div class="form-group">
        <label for="reg_password">Password</label>
        <input type="password" id="reg_password" name="password" required>
      </div>

      <button type="submit" class="button">Register</button>
    </form>

    <span class="toggle-link hidden" onclick="toggleForms()" id="loginLink">Already have an account? Sign in</span>
  </div>

  <script>
    function toggleForms() {
      const loginForm = document.getElementById('loginForm');
      const registerForm = document.getElementById('registerForm');
      const loginLink = document.getElementById('loginLink');
      loginForm.classList.toggle('hidden');
      registerForm.classList.toggle('hidden');
      loginLink.classList.toggle('hidden');
      document.querySelectorAll('.toggle-link')[0].classList.toggle('hidden');
    }
  </script>
</body>
</html>
