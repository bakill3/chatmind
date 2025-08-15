<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() == PHP_SESSION_NONE) session_start();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>ChatMind</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Inter + Bootstrap + Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="assets/js/main.js"></script>

  <style>
    body {
      font-family: 'Inter', Arial, sans-serif !important;
      background: none !important;
      position: relative;
      min-height: 100vh;
    }
    body::before {
      content: "";
      position: fixed; z-index: -1; inset: 0;
      background: linear-gradient(120deg,#e3f0ff 0%,#e7d3fe 80%,#f8e5f7 100%);
      background-image: url('data:image/svg+xml;utf8,<svg width="100%" height="100%" viewBox="0 0 1440 1080" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M0 300C360 400 1080 180 1440 320V1080H0V300Z" fill="%23dab6fc" fill-opacity="0.19"/><path d="M0 600C400 700 1000 460 1440 640V1080H0V600Z" fill="%23a0e9ff" fill-opacity="0.17"/></svg>');
      background-size: cover; background-repeat: no-repeat; background-position: center top;
      opacity: 1;
    }
  </style>
</head>
<body>

<!-- Preloader (original “CM”) -->
<div id="preloader">
  <div class="loader"></div>
</div>
