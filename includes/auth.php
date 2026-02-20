<?php

declare(strict_types=1);

function requireAuth(bool $jsonResponse = false): void
{
    $isLoggedIn = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;

    if ($isLoggedIn) {
        return;
    }

    if ($jsonResponse || is_api_request()) {
        jsonResponse([
            'success' => false,
            'error' => 'Unauthorized',
        ], 401);
    }

    set_flash('error', 'กรุณาเข้าสู่ระบบก่อนใช้งาน');
    redirect('/login.php');
}

function requireGuest(): void
{
    $isLoggedIn = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;

    if ($isLoggedIn) {
        redirect('/dashboard.php');
    }
}
