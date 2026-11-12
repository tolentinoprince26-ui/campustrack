<?php
declare(strict_types=1);

require_once __DIR__ . '/init.php';

/**
 * Instructor Employee Number + Password authentication.
 */
function handle_instructor_login(): array
{
    if (current_user() !== null) {
        redirect('dashboard.php');
    }

    $employeeNumber = '';
    $error = '';
    $notice = take_flash();

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
    ) {
        return [
            'error' => $error,
            'notice' => $notice,
            'employee_number' => $employeeNumber
        ];
    }

    verify_csrf();

    $employeeInput = $_POST['employee_number'] ?? '';
    $passwordInput = $_POST['password'] ?? '';

    $employeeNumber = is_string($employeeInput)
        ? strtoupper(trim($employeeInput))
        : '';

    $password = is_string($passwordInput)
        ? $passwordInput
        : '';

    // CHECK LOGIN ATTEMPT LIMIT

    $lockedUntil = (int) (
        $_SESSION['login_locked_until'] ?? 0
    );

    if ($lockedUntil > time()) {

        $error =
            'Too many attempts. Please try again later.';

    } elseif (
        !preg_match(
            '/^[A-Z0-9][A-Z0-9-]{2,39}$/',
            $employeeNumber
        ) ||
        $password === ''
    ) {

        $error =
            'Enter your Employee Number and password.';

    } else {

        try {

            // LOOK UP THE EMPLOYEE NUMBER.
            // NEVER AUTHENTICATE A STUDENT AS FACULTY.

            $stmt = db()->prepare(
                "SELECT
                    u.id,
                    u.password_hash
                 FROM instructors i
                 JOIN users u
                    ON u.id = i.user_id
                 WHERE i.employee_number = ?
                   AND u.role = 'instructor'
                   AND u.is_active = 1
                 LIMIT 1"
            );

            $stmt->execute([$employeeNumber]);

            $account = $stmt->fetch();

            // VERIFY PASSWORD

            if (
                $account &&
                password_verify(
                    $password,
                    $account['password_hash']
                )
            ) {

                // REHASH PASSWORD WHEN NECESSARY

                if (password_needs_rehash(
                    $account['password_hash'],
                    PASSWORD_DEFAULT
                )) {

                    $stmt = db()->prepare(
                        'UPDATE users
                         SET password_hash = ?
                         WHERE id = ?'
                    );

                    $stmt->execute([
                        password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        ),
                        (int) $account['id']
                    ]);
                }

                // RESET LOGIN FAILURE COUNT

                unset(
                    $_SESSION['login_failures'],
                    $_SESSION['login_locked_until']
                );

                // CREATE AUTHENTICATED SESSION

                login_user((int) $account['id']);

                redirect('dashboard.php');
            }

            // COUNT FAILED LOGIN ATTEMPTS

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
                'Invalid Employee Number or password.';

        } catch (PDOException $exception) {

            error_log(
                'Instructor sign-in: ' .
                $exception->getMessage()
            );

            $error =
                'Instructor sign-in is temporarily unavailable.';
        }
    }

    return [
        'error' => $error,
        'notice' => $notice,
        'employee_number' => $employeeNumber
    ];
}
