<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
    redirect('/dashboard.php');
}

redirect('/login.php');
