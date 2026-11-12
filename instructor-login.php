<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/instructor_auth.php';

$login = handle_instructor_login();

$error = $login['error'];
$notice = $login['notice'];
$employeeNumber = $login['employee_number'];
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
        Instructor Sign In | <?= e(APP_NAME) ?>
    </title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/faculty.css"
    >

</head>

<body>

<main class="auth-shell">

    <!-- FACULTY PORTAL BRANDING -->

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
                FACULTY ATTENDANCE PORTAL
            </span>

            <h1>
                Lead your classes.
                <br>
                Record progress.
                <br>
                <span>Make every session count.</span>
            </h1>

            <p>
                Manage class rosters, student attendance
                and teaching records in one secure
                faculty workspace.
            </p>

        </div>

        <span class="hero-foot">
            CAMPUS TRACK · FACULTY ACCESS
        </span>

    </aside>

    <!-- INSTRUCTOR LOGIN PANEL -->

    <section class="auth-content">

        <div class="auth-card">

            <span class="eyebrow dark">
                FACULTY ACCESS
            </span>

            <h2>Instructor Sign In</h2>

            <p class="subtle">
                Use your assigned Employee Number
                and password.
            </p>

            <!-- LOGIN PORTAL SELECTION -->

            <nav
                class="login-role-switch"
                aria-label="Sign-in portals"
            >

                <a
                    href="student-login.php"
                    class="login-role-option"
                >
                    Student Login
                </a>

                <a
                    href="instructor-login.php"
                    class="login-role-option active"
                    aria-current="page"
                >
                    Instructor Login
                </a>

            </nav>

            <!-- SUCCESS MESSAGE -->

            <?php if ($notice): ?>

                <div
                    class="notice notice-<?= e($notice['type']) ?>"
                    role="status"
                >
                    <?= e($notice['message']) ?>
                </div>

            <?php endif; ?>

            <!-- ERROR MESSAGE -->

            <?php if ($error !== ''): ?>

                <div
                    class="notice notice-error"
                    role="alert"
                >
                    <?= e($error) ?>
                </div>

            <?php endif; ?>

            <!-- LOGIN FORM -->

            <form
                method="post"
                action="instructor-login.php"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <!-- EMPLOYEE NUMBER -->

                <div class="field">

                    <label for="employee_number">
                        Employee / Faculty Number
                    </label>

                    <input
                        id="employee_number"
                        name="employee_number"
                        type="text"
                        maxlength="40"
                        autocomplete="username"
                        autocapitalize="characters"
                        placeholder="Enter your assigned employee number"
                        value="<?= e($employeeNumber) ?>"
                        required
                    >

                </div>

                <!-- PASSWORD -->

                <div class="field">

                    <label for="password">
                        Password
                    </label>

                    <div class="faculty-password-wrap">

                        <input
                            id="password"
                            name="password"
                            type="password"
                            placeholder="Enter your faculty password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            id="togglePassword"
                            class="faculty-password-toggle"
                            type="button"
                            aria-controls="password"
                            aria-pressed="false"
                            aria-label="Show password"
                        >
                            Show
                        </button>

                    </div>

                </div>

                <!-- SIGN IN -->

                <button
                    class="button button-primary button-full"
                    type="submit"
                >
                    Sign in to Faculty Portal
                </button>

            </form>

            <!-- NEW FACULTY REGISTRATION LINK -->

            <p class="auth-switch">
                New faculty member?
                <a href="faculty-register.php">
                    Register as Instructor
                </a>
            </p>

            <p class="faculty-note">
                Applications require identity verification
                and approval before Employee Number
                sign-in is enabled.
            </p>

        </div>

        <p class="legal">
            © <?= date('Y') ?>
            CampusTrack · Academic Project
        </p>

    </section>

</main>

<!-- PASSWORD TOGGLE -->

<script>
(() => {
    'use strict';

    const input = document.getElementById(
        'password'
    );

    const button = document.getElementById(
        'togglePassword'
    );

    if (!input || !button) return;

    button.addEventListener('click', () => {

        const visible =
            input.type === 'password';

        input.type = visible
            ? 'text'
            : 'password';

        button.textContent = visible
            ? 'Hide'
            : 'Show';

        button.setAttribute(
            'aria-pressed',
            visible ? 'true' : 'false'
        );

        button.setAttribute(
            'aria-label',
            visible
                ? 'Hide password'
                : 'Show password'
        );

    });
})();
</script>

</body>
</html>
