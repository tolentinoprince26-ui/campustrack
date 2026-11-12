<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$user = require_user();

$isInstructor = $user['role'] === 'instructor';

if ($isInstructor) {

    $instructorId = (int) $user['id'];

    // COUNT CLASSES

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM classes
         WHERE instructor_id = ?'
    );

    $stmt->execute([$instructorId]);

    $classCount = (int) $stmt->fetchColumn();

    // COUNT DISTINCT ENROLLED STUDENTS

    $stmt = db()->prepare(
        'SELECT COUNT(DISTINCT e.student_id)

         FROM enrollments e

         JOIN classes c
            ON c.id = e.class_id

         WHERE c.instructor_id = ?'
    );

    $stmt->execute([$instructorId]);

    $studentCount = (int) $stmt->fetchColumn();

    // COUNT ATTENDANCE SESSIONS

    $stmt = db()->prepare(
        'SELECT COUNT(*)

         FROM class_sessions cs

         JOIN classes c
            ON c.id = cs.class_id

         WHERE c.instructor_id = ?'
    );

    $stmt->execute([$instructorId]);

    $sessionCount = (int) $stmt->fetchColumn();

    // COUNT RECORDED ATTENDANCE

    $stmt = db()->prepare(
        'SELECT COUNT(*)

         FROM attendance a

         JOIN class_sessions cs
            ON cs.id = a.session_id

         JOIN classes c
            ON c.id = cs.class_id

         WHERE c.instructor_id = ?'
    );

    $stmt->execute([$instructorId]);

    $recordCount = (int) $stmt->fetchColumn();

} else {

    // LOAD STUDENT PROFILE

    $stmt = db()->prepare(
        'SELECT
            id,
            student_number,
            program,
            year_level

         FROM students

         WHERE user_id = ?

         LIMIT 1'
    );

    $stmt->execute([
        (int) $user['id']
    ]);

    $student = $stmt->fetch();

    if (!$student) {

        http_response_code(403);

        exit(
            'Your student profile is missing.'
        );
    }

    $studentId = (int) $student['id'];

    // COUNT ENROLLED CLASSES

    $stmt = db()->prepare(
        'SELECT COUNT(*)

         FROM enrollments

         WHERE student_id = ?'
    );

    $stmt->execute([$studentId]);

    $classCount = (int) $stmt->fetchColumn();

    // STUDENT ATTENDANCE STATISTICS

    $stmt = db()->prepare(
        "SELECT
            COUNT(*) AS total,

            COALESCE(
                SUM(status = 'Present'),
                0
            ) AS present_count,

            COALESCE(
                SUM(status = 'Late'),
                0
            ) AS late_count,

            COALESCE(
                SUM(status = 'Absent'),
                0
            ) AS absent_count

         FROM attendance

         WHERE student_id = ?"
    );

    $stmt->execute([$studentId]);

    $counts = $stmt->fetch();

    $total = (int) $counts['total'];

    $attended =
        (int) $counts['present_count'] +
        (int) $counts['late_count'];

    $percentage = $total > 0
        ? round(
            $attended * 100 / $total,
            1
        )
        : null;

    // RECENT STUDENT ATTENDANCE HISTORY

    $stmt = db()->prepare(
        'SELECT
            c.class_code,
            c.class_name,
            c.section,
            cs.session_date,
            cs.start_time,
            a.status

         FROM attendance a

         JOIN class_sessions cs
            ON cs.id = a.session_id

         JOIN classes c
            ON c.id = cs.class_id

         WHERE a.student_id = ?

         ORDER BY
            cs.session_date DESC,
            cs.start_time DESC,
            a.id DESC

         LIMIT 12'
    );

    $stmt->execute([$studentId]);

    $recentAttendance = $stmt->fetchAll();
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

    <title>
        Dashboard | <?= e(APP_NAME) ?>
    </title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/portal.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/attendance.css"
    >


        <!-- DASHBOARD ANALYTICS STYLING -->

        <link
            rel="stylesheet"
            href="assets/css/dashboard-insights.css"
        >

        <!-- CHART.JS LIBRARY -->

        <script
            src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"
            defer
        ></script>

        <!-- ASYNCHRONOUS CHART HANDLER -->

        <script
            src="assets/js/dashboard-insights.js"
            defer
        ></script>

        <link
            rel="stylesheet"
            href="assets/css/theme.css"
        >

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

<body class="dashboard-body">

<?php
require __DIR__ . '/includes/portal_header.php';
?>

<main class="dashboard-container">

    <!-- PAGE INTRODUCTION -->

    <div class="page-intro">

        <div>

            <span class="eyebrow dark">
                <?= $isInstructor
                    ? 'INSTRUCTOR WORKSPACE'
                    : 'STUDENT WORKSPACE' ?>
            </span>

            <h1>
                Welcome, <?= e($user['full_name']) ?>
            </h1>

            <p class="subtle">
                Your attendance monitoring overview.
            </p>

        </div>

        <span class="date-badge">
            <?= e(date('F j, Y')) ?>
        </span>

    </div>

    <?php if ($isInstructor): ?>

        <!-- INSTRUCTOR STATISTICS -->

        <section
            class="stat-grid"
            aria-label="Instructor statistics"
        >

            <article class="stat-card">

                <span class="stat-symbol">CL</span>

                <p>Classes</p>

                <strong>
                    <?= $classCount ?>
                </strong>

                <small>Assigned classes</small>

            </article>

            <article class="stat-card">

                <span class="stat-symbol">ST</span>

                <p>Students</p>

                <strong>
                    <?= $studentCount ?>
                </strong>

                <small>
                    Distinct enrolled students
                </small>

            </article>

            <article class="stat-card">

                <span class="stat-symbol">SE</span>

                <p>Sessions</p>

                <strong>
                    <?= $sessionCount ?>
                </strong>

                <small>Class meetings</small>

            </article>

            <article class="stat-card">

                <span class="stat-symbol">AT</span>

                <p>Attendance entries</p>

                <strong>
                    <?= $recordCount ?>
                </strong>

                <small>
                    Saved student statuses
                </small>

            </article>

        </section>

        <!-- INSTRUCTOR ACTIONS -->

        <div class="panel-grid">

            <section class="surface dashboard-panel">

                <span class="eyebrow dark">
                    INSTRUCTOR TOOLS
                </span>

                <h2>Manage your classroom</h2>

                <p class="subtle">
                    Create classes, enroll students,
                    and record attendance for each
                    meeting.
                </p>

                <div
                    style="display:flex; flex-wrap:wrap; gap:10px;"
                >

                    <a
                        class="button button-primary"
                        href="attendance.php"
                    >
                        Take attendance
                    </a>

                    <a
                        class="button button-outline"
                        href="classes.php"
                    >
                        Manage classes
                    </a>

                    <a
                        class="button button-outline"
                        href="enrollments.php"
                    >
                        Enroll students
                    </a>

                    <a
                        class="button button-outline"
                        href="attendance-history.php"
                    >
                        Attendance history
                    </a>

                </div>

                <div class="task-list">

                    <div>
                        <b>01</b>
                        <span>
                            Create and edit classes
                        </span>
                        <small>Available</small>
                    </div>

                    <div>
                        <b>02</b>
                        <span>
                            Enroll registered students
                        </span>
                        <small>Available</small>
                    </div>

                    <div>
                        <b>03</b>
                        <span>
                            Create sessions and record attendance
                        </span>
                        <small>Available</small>
                    </div>

                    <div>
                        <b>04</b>
                        <span>
                            Review and correct attendance
                        </span>
                        <small>Available</small>
                    </div>

                </div>

            </section>

            <aside class="surface dashboard-panel">

                <span class="eyebrow dark">
                    SYSTEM STATUS
                </span>

                <h2>Instructor overview</h2>

                <p class="subtle">
                    Statistics are calculated from your
                    own classes, enrollments, sessions,
                    and attendance records.
                </p>

                <div class="info-note">

                    Select Attendance to create a new
                    session. Each enrolled student can
                    be marked Present, Late, or Absent.

                </div>

            </aside>

        </div>

    <?php else: ?>

        <!-- STUDENT STATISTICS -->

        <section
            class="stat-grid"
            aria-label="Student statistics"
        >

            <article class="stat-card">

                <span class="stat-symbol">CL</span>

                <p>Enrolled classes</p>

                <strong>
                    <?= $classCount ?>
                </strong>

                <small>Current classes</small>

            </article>

            <article class="stat-card">

                <span class="stat-symbol">PR</span>

                <p>Present</p>

                <strong>
                    <?= (int) $counts['present_count'] ?>
                </strong>

                <small>Recorded entries</small>

            </article>

            <article class="stat-card">

                <span class="stat-symbol">LT</span>

                <p>Late</p>

                <strong>
                    <?= (int) $counts['late_count'] ?>
                </strong>

                <small>Recorded entries</small>

            </article>

            <article class="stat-card">

                <span class="stat-symbol">AB</span>

                <p>Absent</p>

                <strong>
                    <?= (int) $counts['absent_count'] ?>
                </strong>

                <small>Recorded entries</small>

            </article>

        </section>

        <!-- STUDENT ATTENDANCE OVERVIEW -->

        <div class="panel-grid">

            <section class="surface dashboard-panel">

                <span class="eyebrow dark">
                    ATTENDANCE OVERVIEW
                </span>

                <h2>Your attendance percentage</h2>

                <p class="subtle">
                    Present and Late are counted as
                    attended, divided by total recorded
                    attendance entries.
                </p>

                <div class="percentage-line">

                    <strong>

                        <?= $percentage === null
                            ? 'N/A'
                            : e(
                                number_format(
                                    $percentage,
                                    1
                                ) . '%'
                            ) ?>

                    </strong>

                    <?php if ($percentage === null): ?>

                        <span class="status-pill neutral">
                            No records yet
                        </span>

                    <?php elseif (
                        $percentage < LOW_ATTENDANCE_THRESHOLD
                    ): ?>

                        <span class="status-pill warning">
                            Needs attention
                        </span>

                    <?php else: ?>

                        <span class="status-pill good">
                            On track
                        </span>

                    <?php endif; ?>

                </div>

                <div class="progress-track">

                    <div
                        class="progress-bar"
                        style="width: <?= $percentage ?? 0 ?>%"
                    ></div>

                </div>

                <p class="fineprint">

                    Low-attendance threshold:
                    <?= LOW_ATTENDANCE_THRESHOLD ?>%.

                    Unmarked meetings are not included
                    in this percentage.

                </p>

            </section>

            <!-- STUDENT PROFILE -->

            <aside class="surface dashboard-panel">

                <span class="eyebrow dark">
                    MY PROFILE
                </span>

                <h2>Student information</h2>

                <dl class="details">

                    <div>

                        <dt>Student number</dt>

                        <dd>
                            <?= e(
                                $student['student_number']
                            ) ?>
                        </dd>

                    </div>

                    <div>

                        <dt>Program</dt>

                        <dd>
                            <?= e($student['program']) ?>
                        </dd>

                    </div>

                    <div>

                        <dt>Year level</dt>

                        <dd>
                            Year <?= (int) $student['year_level'] ?>
                        </dd>

                    </div>

                    <div>

                        <dt>Email</dt>

                        <dd>
                            <?= e($user['email']) ?>
                        </dd>

                    </div>

                </dl>

            </aside>

        </div>

        <!-- STUDENT RECENT ATTENDANCE -->

        <section class="surface attendance-panel">

            <span class="eyebrow dark">
                RECENT ATTENDANCE
            </span>

            <h2>Your attendance history</h2>

            <?php if (!$recentAttendance): ?>

                <p class="subtle">
                    No attendance records yet. Your
                    instructor needs to record a class
                    session first.
                </p>

            <?php else: ?>

                <div class="attendance-table-wrap">

                    <table class="attendance-table">

                        <thead>

                            <tr>
                                <th>Date / Time</th>
                                <th>Class</th>
                                <th>Section</th>
                                <th>Status</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach (
                            $recentAttendance as $entry
                        ): ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= e(
                                            $entry['session_date']
                                        ) ?>
                                    </strong>

                                    <small>
                                        <?= e(
                                            substr(
                                                $entry['start_time'],
                                                0,
                                                5
                                            )
                                        ) ?>
                                    </small>

                                </td>

                                <td>

                                    <strong>
                                        <?= e(
                                            $entry['class_code']
                                        ) ?>
                                    </strong>

                                    <small>
                                        <?= e(
                                            $entry['class_name']
                                        ) ?>
                                    </small>

                                </td>

                                <td>
                                    <?= e($entry['section']) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= e($entry['status']) ?>
                                    </strong>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

            <p class="fineprint">
                Showing up to 12 most recent
                recorded entries.
            </p>

        </section>

    <?php endif; ?>

    <!-- SHARED INSTRUCTOR / STUDENT ANALYTICS -->

    <?php
    require __DIR__ . '/includes/dashboard_insights.php';
    ?>

    <footer class="page-footer">

        CampusTrack · Student Attendance
        Monitoring System · Phase 2C

    </footer>

</main>

</body>
</html>
