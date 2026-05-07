<?php
$user = current_user();
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="topbar">
    <div class="topbar-brand">
        <a href="/index.php" class="brand-link">
            <strong>TEKCAN</strong>
            <small>Fiyat Ekranı</small>
        </a>
    </div>
    <nav class="topbar-nav">
        <a href="/index.php" class="nav-link">Fiyat Listesi</a>
        <a href="/arama.php" class="nav-link">Malzeme Arama</a>
        <?php if ($user && $user['rol'] === 'admin'): ?>
        <a href="/admin/" class="nav-link nav-admin">Yönetim</a>
        <?php endif ?>
    </nav>
    <div class="topbar-user">
        <span class="user-name"><?= h($user['ad_soyad']) ?></span>
        <a href="/logout.php" class="btn-logout">Çıkış</a>
    </div>
</header>
<main class="main-content">
