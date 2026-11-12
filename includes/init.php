<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

date_default_timezone_set('Asia/Manila');
ini_set('session.use_strict_mode', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('campustrack_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Cache-Control: private, no-store, max-age=0');

require_once __DIR__ . '/functions.php';
