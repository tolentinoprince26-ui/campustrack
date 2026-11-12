<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/role_login.php';

$login = handle_role_login('student');

$error = $login['error'];
$email = $login['email'];
$notice = $login['notice'];
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
        Student Sign In | <?= e(APP_NAME) ?>
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

    <!-- STUDENT HERO -->

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
                STUDENT ATTENDANCE MONITORING
            </span>

            <h1>
                Every class.
                <br>
                Every student.
                <br>
                <span>Accounted for.</span>
            </h1>

            <p>
                View your enrolled classes, attendance
                records, and progress in one secure portal.
            </p>

        </div>

        <span class="hero-foot">
            MODERN UNIVERSITY PORTAL
        </span>

    </aside>

    <!-- STUDENT LOGIN FORM -->

    <section class="auth-content">

        <div class="auth-card">

            <span class="eyebrow dark">
                STUDENT ACCESS
            </span>

            <h2>Student Sign In</h2>

            <p class="subtle">
                Use your PLM student email address
                and password.
            </p>

            <nav
                class="login-role-switch"
                aria-label="Sign-in portals"
            >

                <a
                    href="student-login.php"
                    class="login-role-option active"
                    aria-current="page"
                >
                    Student Login
                </a>

                <a
                    href="instructor-login.php"
                    class="login-role-option"
                >
                    Instructor Login
                </a>

            </nav>

            <?php if ($notice): ?>

                <div
                    class="notice notice-<?= e($notice['type']) ?>"
                    role="status"
                >
                    <?= e($notice['message']) ?>
                </div>

            <?php endif; ?>

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
                action="student-login.php"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <!-- PLM EMAIL -->

                <div class="field">

                    <label for="email">
                        PLM student email
                    </label>

                    <input
                        id="email"
                        name="email"
                        type="email"
                        maxlength="190"
                        placeholder="student@plm.edu.ph"
                        autocomplete="username"
                        value="<?= e($email) ?>"
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
                            placeholder="Enter your password"
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

                <button
                    class="button button-primary button-full"
                    type="submit"
                >
                    Sign in to Student Portal
                </button>

            </form>

            <p class="auth-switch">
                New student?
                <a href="register.php">
                    Create an account
                </a>
            </p>

        </div>

        <p class="legal">
            © <?= date('Y') ?>
            CampusTrack · Academic Project
        </p>

    </section>

</main>

<script>
(() => {
    'use strict';

    const input = document.getElementById('password');

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
