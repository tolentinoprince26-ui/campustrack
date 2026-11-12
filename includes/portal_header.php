<?php

// Shared CampusTrack navigation.
// Used by both Student and Instructor pages.

$user = require_user();

$currentPage = basename(
    $_SERVER['SCRIPT_NAME'] ?? ''
);

$isInstructorNav = $user['role'] === 'instructor';
?>

<header class="site-header">

    <div class="header-inner">

        <!-- CAMPUS TRACK BRAND -->

        <a
            class="brand"
            href="dashboard.php"
        >

            <span class="brand-icon">C</span>

            <span>
                <strong>CampusTrack</strong>
                <small>UNIVERSITY PORTAL</small>
            </span>

        </a>

        <!-- ACCOUNT CONTROLS -->

        <div class="header-actions">

            <span class="role-pill">
                <?= $isInstructorNav
                    ? 'INSTRUCTOR'
                    : 'STUDENT' ?>
            </span>

            <span class="header-name">
                <?= e($user['full_name']) ?>
            </span>

            <!-- GLOBAL DARK MODE TOGGLE -->

            <button
                type="button"
                id="campustrack-theme-toggle"
                class="theme-toggle"
                aria-label="Switch to dark mode"
                aria-pressed="false"
            >
                Dark mode
            </button>

            <!-- LOG OUT -->

            <form
                method="post"
                action="logout.php"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <button
                    class="button button-outline button-small"
                    type="submit"
                >
                    Log out
                </button>

            </form>

        </div>

    </div>

    <!-- PORTAL NAVIGATION -->

    <nav
        class="portal-nav"
        aria-label="Main navigation"
    >

        <div class="portal-nav-inner">

            <!-- COMMON DASHBOARD -->

            <a
                href="dashboard.php"
                <?= $currentPage === 'dashboard.php'
                    ? 'aria-current="page"'
                    : '' ?>
            >
                Dashboard
            </a>

            <?php if ($isInstructorNav): ?>

                <!-- INSTRUCTOR: CLASSES -->

                <a
                    href="classes.php"
                    <?= $currentPage === 'classes.php'
                        ? 'aria-current="page"'
                        : '' ?>
                >
                    Classes
                </a>

                <!-- INSTRUCTOR: ENROLLMENT -->

                <a
                    href="enrollments.php"
                    <?= $currentPage === 'enrollments.php'
                        ? 'aria-current="page"'
                        : '' ?>
                >
                    Enrollment
                </a>

                <!-- INSTRUCTOR: ATTENDANCE -->

                <a
                    href="attendance.php"
                    <?= in_array(
                        $currentPage,
                        [
                            'attendance.php',
                            'take-attendance.php'
                        ],
                        true
                    )
                        ? 'aria-current="page"'
                        : '' ?>
                >
                    Attendance
                </a>

                <!-- INSTRUCTOR: HISTORY -->

                <a
                    href="attendance-history.php"
                    <?= $currentPage === 'attendance-history.php'
                        ? 'aria-current="page"'
                        : '' ?>
                >
                    History
                </a>

                <!-- INSTRUCTOR: REPORTS -->

                <a
                    href="reports.php"
                    <?= $currentPage === 'reports.php'
                        ? 'aria-current="page"'
                        : '' ?>
                >
                    Reports
                </a>

            <?php else: ?>

                <!-- STUDENT: ATTENDANCE HISTORY -->

                <a
                    href="my-attendance.php"
                    <?= $currentPage === 'my-attendance.php'
                        ? 'aria-current="page"'
                        : '' ?>
                >
                    My Attendance
                </a>

            <?php endif; ?>

        </div>

    </nav>

</header>

<!-- LOAD GLOBAL THEME ON EVERY PORTAL PAGE -->

<script
    src="assets/js/theme.js"
    defer
></script>
