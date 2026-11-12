<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT
        . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        error_log('CampusTrack database error: ' . $exception->getMessage());
        http_response_code(500);
        exit('Database connection failed. Check MySQL and config.php.');
    }

    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $page): never
{
    header('Location: ' . $page);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $stored = $_SESSION['csrf_token'] ?? null;
    $sent = $_POST['csrf_token'] ?? null;
    if (!is_string($stored) || !is_string($sent) || !hash_equals($stored, $sent)) {
        http_response_code(419);
        exit('Form expired or invalid. Refresh the page and try again.');
    }
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, full_name, email, role FROM users
         WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function require_user(?string $role = null): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }
    if ($role !== null && $user['role'] !== $role) {
        http_response_code(403);
        exit('Access denied for your account role.');
    }
    return $user;
}

function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['csrf_token'], $_SESSION['login_failures'], $_SESSION['login_locked_until']);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($value) ? $value : null;
}
