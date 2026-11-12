<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

if (current_user() !== null) {
    redirect('dashboard.php');
}

$error = '';

$old = [
    'full_name' => '',
    'employee_number' => '',
    'email' => '',
    'college' => '',
    'department' => '',
    'designation' => '',
];

// PROCESS INSTRUCTOR REGISTRATION

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

    verify_csrf();

    foreach (array_keys($old) as $field) {
        $input = $_POST[$field] ?? '';

        $old[$field] = is_string($input)
            ? trim($input)
            : '';
    }

    $old['employee_number'] = strtoupper(
        $old['employee_number']
    );

    $old['email'] = strtolower($old['email']);

    $password = is_string($_POST['password'] ?? null)
        ? $_POST['password']
        : '';

    $confirmation = is_string(
        $_POST['confirm_password'] ?? null
    )
        ? $_POST['confirm_password']
        : '';

    try {

        // VALIDATE FULL NAME

        $name = $old['full_name'];

        $nameLength = function_exists('mb_strlen')
            ? mb_strlen($name, 'UTF-8')
            : strlen($name);

        if (
            $nameLength < 3 ||
            $nameLength > 120 ||
            !preg_match(
                '/^[\p{L}][\p{L}\p{M} .,\x{2019}\'-]*$/u',
                $name
            )
        ) {
            throw new RuntimeException(
                'Enter a valid faculty full name.'
            );
        }

        // VALIDATE EMPLOYEE NUMBER

        if (!preg_match(
            '/^[A-Z0-9][A-Z0-9-]{2,39}$/',
            $old['employee_number']
        )) {
            throw new RuntimeException(
                'Employee Number must contain 3–40 letters, numbers, or hyphens.'
            );
        }

        // VALIDATE EMAIL

        if (
            strlen($old['email']) > 190 ||
            !filter_var(
                $old['email'],
                FILTER_VALIDATE_EMAIL
            ) ||
            !preg_match(
                '/@plm\.edu\.ph$/i',
                $old['email']
            )
        ) {
            throw new RuntimeException(
                'Use a valid @plm.edu.ph faculty email.'
            );
        }

        // VALIDATE FACULTY INFORMATION

        foreach ([
            'college' => 150,
            'department' => 150,
            'designation' => 100,
        ] as $field => $maximum) {

            $value = $old[$field];

            $length = function_exists('mb_strlen')
                ? mb_strlen($value, 'UTF-8')
                : strlen($value);

            if (
                $length < 2 ||
                $length > $maximum ||
                preg_match(
                    '/[\x00-\x1F\x7F]/',
                    $value
                )
            ) {
                throw new RuntimeException(
                    ucfirst($field) .
                    ' must contain 2–' .
                    $maximum .
                    ' valid characters.'
                );
            }
        }

        // PASSWORD REQUIREMENTS

        if (
            strlen($password) < 10 ||
            !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password)
        ) {
            throw new RuntimeException(
                'Password needs at least 10 characters, uppercase, lowercase, and a number.'
            );
        }

        if ($password !== $confirmation) {
            throw new RuntimeException(
                'Passwords do not match.'
            );
        }

        // DIRECT INSTRUCTOR REGISTRATION

        $pdo = db();

        $pdo->beginTransaction();

        // CHECK DUPLICATE EMAIL

        $check = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE email = ?
             LIMIT 1'
        );

        $check->execute([
            $old['email']
        ]);

        if ($check->fetch()) {
            throw new RuntimeException(
                'This email address is already registered.'
            );
        }

        // CHECK DUPLICATE EMPLOYEE NUMBER

        $check = $pdo->prepare(
            'SELECT id
             FROM instructors
             WHERE employee_number = ?
             LIMIT 1'
        );

        $check->execute([
            $old['employee_number']
        ]);

        if ($check->fetch()) {
            throw new RuntimeException(
                'This Employee Number is already registered.'
            );
        }

        // CREATE USER ACCOUNT

        $insertUser = $pdo->prepare(
            "INSERT INTO users (
                full_name,
                email,
                password_hash,
                role,
                is_active
            )
            VALUES (?, ?, ?, 'instructor', 1)"
        );

        $insertUser->execute([
            $name,
            $old['email'],
            password_hash(
                $password,
                PASSWORD_DEFAULT
            ),
        ]);

        $userId = (int) $pdo->lastInsertId();

        // CREATE INSTRUCTOR PROFILE

        $insertInstructor = $pdo->prepare(
            'INSERT INTO instructors (
                user_id,
                employee_number,
                college,
                department,
                designation
            )
            VALUES (?, ?, ?, ?, ?)'
        );

        $insertInstructor->execute([
            $userId,
            $old['employee_number'],
            $old['college'],
            $old['department'],
            $old['designation'],
        ]);

        $pdo->commit();

        // REGISTRATION SUCCESS

        flash(
            'success',
            'Instructor account created. Sign in with your Employee Number and password.'
        );

        redirect('instructor-login.php');

    } catch (RuntimeException $exception) {

        if (
            isset($pdo) &&
            $pdo instanceof PDO &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        $error = $exception->getMessage();

    } catch (PDOException $exception) {

        if (
            isset($pdo) &&
            $pdo instanceof PDO &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        if ($exception->getCode() === '23000') {

            $error =
                'The faculty email or Employee Number is already registered.';

        } else {

            error_log(
                'Faculty registration: ' .
                $exception->getMessage()
            );

            $error =
                'Unable to register instructor. Check the database setup.';
        }
    }
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta name="theme-color" content="#10264a">

    <title>
        Instructor Registration | <?= e(APP_NAME) ?>
    </title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/faculty.css"
    >

    <style>
        .faculty-register-card {
            width: 100%;
            max-width: 660px;
        }

        .faculty-register-grid {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .faculty-show-passwords {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            margin-bottom: 16px;
            cursor: pointer;
        }

        .faculty-show-passwords input {
            width: auto;
            margin: 0;
        }

        @media (max-width: 620px) {
            .faculty-register-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }
    </style>

</head>

<body>

<main class="auth-shell">

    <!-- LEFT PANEL -->

    <aside class="auth-hero">

        <a
            class="brand brand-inverse"
            href="index.php"
        >

            <span class="brand-icon">C</span>

            <span>
                <strong>CampusTrack</strong>
                <small>UNIVERSITY PORTAL</small>
            </span>

        </a>

        <div class="hero-copy">

            <span class="eyebrow">
                FACULTY REGISTRATION
            </span>

            <h1>
                Lead your classes.
                <br>
                <span>Track every session.</span>
            </h1>

            <p>
                Create a faculty account for class
                management, student enrollment,
                and attendance monitoring.
            </p>

        </div>

        <span class="hero-foot">
            CAMPUS TRACK · FACULTY PORTAL
        </span>

    </aside>

    <!-- INSTRUCTOR REGISTRATION FORM -->

    <section class="auth-content">

        <div class="auth-card faculty-register-card">

            <span class="eyebrow dark">
                INSTRUCTOR ACCOUNT
            </span>

            <h2>Register as Instructor</h2>

            <p class="subtle">
                Enter your faculty details to create
                your CampusTrack account.
            </p>

            <?php if ($error !== ''): ?>

                <div
                    class="notice notice-error"
                    role="alert"
                >
                    <?= e($error) ?>
                </div>

            <?php endif; ?>

            <form
                method="post"
                action="faculty-register.php"
                id="facultyRegistrationForm"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <!-- FULL NAME -->

                <div class="field">

                    <label for="full_name">
                        Faculty full name
                    </label>

                    <input
                        id="full_name"
                        name="full_name"
                        type="text"
                        maxlength="120"
                        autocomplete="name"
                        placeholder="Adrian Reyes"
                        value="<?= e($old['full_name']) ?>"
                        required
                    >

                </div>

                <!-- EMPLOYEE NUMBER -->

                <div class="faculty-register-grid">

                    <div class="field">

                        <label for="employee_number">
                            Employee / Faculty Number
                        </label>

                        <input
                            id="employee_number"
                            name="employee_number"
                            type="text"
                            maxlength="40"
                            autocapitalize="characters"
                            autocomplete="off"
                            placeholder="FAC-2026-001"
                            value="<?= e($old['employee_number']) ?>"
                            required
                        >

                    </div>

                    <!-- FACULTY EMAIL -->

                    <div class="field">

                        <label for="email">
                            PLM faculty email
                        </label>

                        <input
                            id="email"
                            name="email"
                            type="email"
                            maxlength="190"
                            autocomplete="email"
                            placeholder="faculty@plm.edu.ph"
                            value="<?= e($old['email']) ?>"
                            required
                        >

                    </div>

                </div>

                <!-- COLLEGE -->

                <div class="field">

                    <label for="college">
                        College
                    </label>

                    <input
                        id="college"
                        name="college"
                        type="text"
                        maxlength="150"
                        placeholder="College affiliation"
                        value="<?= e($old['college']) ?>"
                        required
                    >

                </div>

                <!-- DEPARTMENT AND DESIGNATION -->

                <div class="faculty-register-grid">

                    <div class="field">

                        <label for="department">
                            Department
                        </label>

                        <input
                            id="department"
                            name="department"
                            type="text"
                            maxlength="150"
                            placeholder="Department of Computer Science"
                            value="<?= e($old['department']) ?>"
                            required
                        >

                    </div>

                    <div class="field">

                        <label for="designation">
                            Designation
                        </label>

                        <input
                            id="designation"
                            name="designation"
                            type="text"
                            maxlength="100"
                            placeholder="Instructor"
                            value="<?= e($old['designation']) ?>"
                            required
                        >

                    </div>

                </div>

                <!-- PASSWORDS -->

                <div class="faculty-register-grid">

                    <div class="field">

                        <label for="password">
                            Password
                        </label>

                        <input
                            id="password"
                            name="password"
                            type="password"
                            minlength="10"
                            autocomplete="new-password"
                            required
                        >

                    </div>

                    <div class="field">

                        <label for="confirm_password">
                            Confirm password
                        </label>

                        <input
                            id="confirm_password"
                            name="confirm_password"
                            type="password"
                            minlength="10"
                            autocomplete="new-password"
                            required
                        >

                    </div>

                </div>

                <!-- SHOW PASSWORDS -->

                <label
                    class="faculty-show-passwords"
                    for="show_passwords"
                >

                    <input
                        id="show_passwords"
                        type="checkbox"
                    >

                    Show passwords

                </label>

                <p
                    class="subtle"
                    style="font-size:12px;"
                >
                    Password needs at least 10 characters,
                    uppercase and lowercase letters,
                    and a number.
                </p>

                <!-- REGISTER BUTTON -->

                <button
                    class="button button-primary button-full"
                    type="submit"
                >
                    Create Instructor Account
                </button>

            </form>

            <p class="auth-switch">
                Already registered?
                <a href="instructor-login.php">
                    Instructor Sign In
                </a>
            </p>

        </div>

    </section>

</main>

<!-- SHOW / HIDE PASSWORDS -->

<script>
(() => {
    'use strict';

    const checkbox = document.getElementById(
        'show_passwords'
    );

    const password = document.getElementById(
        'password'
    );

    const confirmation = document.getElementById(
        'confirm_password'
    );

    if (!checkbox || !password || !confirmation) {
        return;
    }

    checkbox.addEventListener('change', () => {

        const type = checkbox.checked
            ? 'text'
            : 'password';

        password.type = type;
        confirmation.type = type;

    });

})();
</script>

</body>
</html>
