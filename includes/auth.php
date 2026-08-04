<?php

declare(strict_types=1);

function requireAuth(bool $jsonResponse = false): void
{
    $wantsJson = $jsonResponse || (function_exists('wants_json_response') && wants_json_response());
    $isLoggedIn = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;

    if ($isLoggedIn) {
        if (!isAuthSessionAlive() || !isSessionVersionValid()) {
            clearAuthSession();

            if ($wantsJson) {
                jsonResponse([
                    'success' => false,
                    'error' => 'Session expired',
                ], 401);
            }

            set_flash('error', 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง');
            redirect('/login.php');
        }

        $_SESSION['last_activity_at'] = time();
        return;
    }

    if ($wantsJson) {
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
        if (!isAuthSessionAlive() || !isSessionVersionValid()) {
            clearAuthSession();
            return;
        }

        redirect('/dashboard.php');
    }
}

function isAuthSessionAlive(): bool
{
    $now = time();
    $startedAt = isset($_SESSION['auth_started_at']) ? (int)$_SESSION['auth_started_at'] : $now;
    $lastActivityAt = isset($_SESSION['last_activity_at']) ? (int)$_SESSION['last_activity_at'] : $now;

    if (!isset($_SESSION['auth_started_at'])) {
        $_SESSION['auth_started_at'] = $startedAt;
    }

    if (!isset($_SESSION['last_activity_at'])) {
        $_SESSION['last_activity_at'] = $lastActivityAt;
    }

    $idleTimeout = max(0, (int)SESSION_IDLE_TIMEOUT_SECONDS);
    $absoluteTimeout = max(0, (int)SESSION_ABSOLUTE_TIMEOUT_SECONDS);

    $idleExpired = $idleTimeout > 0 && ($now - $lastActivityAt) > $idleTimeout;
    $absoluteExpired = $absoluteTimeout > 0 && ($now - $startedAt) > $absoluteTimeout;

    return !$idleExpired && !$absoluteExpired;
}

function isSessionVersionValid(): bool
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    if (!function_exists('db')) {
        return true;
    }

    $sessionVersion = max(1, (int)($_SESSION['session_version'] ?? 1));

    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT session_version FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return false;
        }

        $currentVersion = max(1, (int)($row['session_version'] ?? 1));
        return $currentVersion === $sessionVersion;
    } catch (PDOException $exception) {
        // ข้อยกเว้นเดียวที่ยอมให้ผ่าน: schema เก่าที่ยังไม่มีคอลัมน์ session_version
        // (ไม่งั้นอัปเกรดแล้วผู้ใช้ทุกคนถูกเตะออกพร้อมกัน)
        $isMissingColumn = (string)$exception->getCode() === '42S22'
            && str_contains(strtolower($exception->getMessage()), 'session_version');

        if ($isMissingColumn) {
            return true;
        }

        // นอกนั้นต้อง fail-closed — เดิมคืน true ทุกกรณี แปลว่า DB สะดุดชั่วขณะ
        // ทำให้ session ที่ถูกยกเลิกไปแล้ว (รีเซ็ต/เปลี่ยนรหัสผ่าน) กลับมาใช้ได้
        error_log('[auth] session version check failed: ' . $exception->getMessage());
        return false;
    } catch (Throwable $exception) {
        error_log('[auth] session version check failed: ' . $exception->getMessage());
        return false;
    }
}

function clearAuthSession(): void
{
    unset(
        $_SESSION['user_id'],
        $_SESSION['email'],
        $_SESSION['display_name'],
        $_SESSION['session_version'],
        $_SESSION['auth_started_at'],
        $_SESSION['last_activity_at'],
        $_SESSION['current_shop_id'],
        $_SESSION['current_shop_name'],
        $_SESSION['csrf_token']
    );

    session_regenerate_id(true);
}
