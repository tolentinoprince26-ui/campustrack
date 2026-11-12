<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

if (current_user() !== null) {
    redirect('dashboard.php');
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
        Choose Your Portal | <?= e(APP_NAME) ?>
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
        .portal-choice-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin: 26px 0 18px;
        }

        .portal-choice {
            display: block;
            padding: 24px 16px;
            border: 1px solid #dce5f1;
            border-radius: 14px;
            color: #142c50;
            text-decoration: none;
        }

        .portal-choice strong {
            display: block;
            margin-bottom: 8px;
            font-size: 17px;
        }

        .portal-choice small {
            color: #6b7b91;
            line-height: 1.5;
        }

        .portal-choice:hover,
        .portal-choice:focus-visible {
            background: #eaf1ff;
            border-color: #2563eb;
            text-decoration: none;
        }

        @media (max-width: 560px) {

            .portal-choice-grid {
                grid-template-columns: 1fr;
            }

        }
    </style>

    <!-- CAMPUS TRACK PROGRESSIVE WEB APP -->

    <link
        rel="manifest"
        href="manifest.webmanifest"
    >

    <link
        rel="apple-touch-icon"
        href="assets/icons/icon-192.png"
    >

    <script
        src="assets/js/pwa.js"
        defer
    ></script>

</head>

<body>

<main class="auth-shell">

    <!-- CAMPUS TRACK BRANDING -->

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
                One university portal, with dedicated
                workspaces for students and faculty.
            </p>

        </div>

        <span class="hero-foot">
            MODERN UNIVERSITY PORTAL
        </span>

    </aside>

    <!-- PORTAL SELECTION -->

    <section class="auth-content">

        <div class="auth-card">

            <span class="eyebrow dark">
                WELCOME TO CAMPUS TRACK
            </span>

            <h2>Choose your portal</h2>

            <p class="subtle">
                Select the account type assigned to you.
            </p>

            <nav
                class="portal-choice-grid"
                aria-label="Choose your sign-in portal"
            >

                <!-- STUDENT LOGIN -->

                <a
                    href="student-login.php"
                    class="portal-choice"
                >

                    <strong>
                        Student Login
                    </strong>

                    <small>
                        Sign in with your PLM email
                        and password.
                    </small>

                </a>

                <!-- INSTRUCTOR LOGIN -->

                <a
                    href="instructor-login.php"
                    class="portal-choice"
                >

                    <strong>
                        Instructor Login
                    </strong>

                    <small>
                        Sign in with your Employee Number
                        and password.
                    </small>

                </a>

            </nav>

            <!-- STUDENT REGISTRATION -->

            <p class="auth-switch">
                New student?
                <a href="register.php">
                    Create an account
                </a>
            </p>

            <!-- FACULTY REGISTRATION -->

            <p class="auth-switch">
                New faculty member?
                <a href="faculty-register.php">
                    Register as Instructor
                </a>
            </p>

        </div>

    </section>

</main>

</body>
</html>
