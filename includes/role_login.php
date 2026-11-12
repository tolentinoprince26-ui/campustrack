<?php
declare(strict_types=1);

require_once __DIR__ . '/init.php';

/**
 * Student Email + Password authentication.
 * Instructor authentication is handled separately.
 */
function handle_role_login(string $expectedRole): array
{
    if ($expectedRole !== 'student') {
        throw new InvalidArgumentException(
            'Faculty sign-in must use instructor_auth.php.'
        );
    }

    if (current_user() !== null) {
        redirect('dashboard.php');
    }

    $email = '';
    $error = '';
    $notice = take_flash();

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
    ) {
        return compact('email', 'error', 'notice');
    }

    verify_csrf();

    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';

    $email = is_string($emailInput)
        ? strtolower(trim($emailInput))
        : '';

    $password = is_string($passwordInput)
        ? $passwordInput
        : '';

    $lockedUntil = (int) (
        $_SESSION['login_locked_until'] ?? 0
    );

    if ($lockedUntil > time()) {

        $error =
            'Too many attempts. Please try again later.';

    } elseif (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        ) ||
        $password === ''
    ) {

        $error =
            'Enter a valid email address and password.';

    } else {

        try {

            $stmt = db()->prepare(
                "SELECT
                    id,
                    password_hash
                 FROM users
                 WHERE email = ?
                   AND role = 'student'
                   AND is_active = 1
                 LIMIT 1"
            );

            $stmt->execute([$email]);

            $account = $stmt->fetch();

            if (
                $account &&
                password_verify(
                    $password,
                    $account['password_hash']
                )
            ) {

                if (password_needs_rehash(
                    $account['password_hash'],
                    PASSWORD_DEFAULT
                )) {

                    $update = db()->prepare(
                        'UPDATE users
                         SET password_hash = ?
                         WHERE id = ?'
                    );

                    $update->execute([
                        password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        ),
                        (int) $account['id']
                    ]);
                }

                unset(
                    $_SESSION['login_failures'],
                    $_SESSION['login_locked_until']
                );

                login_user((int) $account['id']);

                redirect('dashboard.php');
            }

            $failures = (int) (
                $_SESSION['login_failures'] ?? 0
            ) + 1;

            if ($failures >= 5) {

                $_SESSION['login_failures'] = 0;

                $_SESSION['login_locked_until'] =
                    time() + 60;

            } else {

                $_SESSION['login_failures'] =
                    $failures;
            }

            $error =
                'Invalid student email or password.';

        } catch (PDOException $exception) {

            error_log(
                'Student sign-in: ' .
                $exception->getMessage()
            );

            $error =
                'Student sign-in is temporarily unavailable.';
        }
    }

    return compact('email', 'error', 'notice');
}
