<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/report_helpers.php';

$user = require_user('student');

// GET STUDENT PROFILE

$stmt = db()->prepare(
    'SELECT
        id,
        student_number,
        program
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
        'Your student profile could not be found.'
    );
}

$studentId = (int) $student['id'];

// VALIDATE FILTERS

$error = '';
$canView = true;

try {

    $filters = attendance_report_filters($_GET);

} catch (RuntimeException $ex) {

    $error = $ex->getMessage();

    $filters = [
        'class_id' => null,
        'from' => null,
        'to' => null
    ];

    $canView = false;
}

// LOAD STUDENT'S ENROLLED CLASSES

$stmt = db()->prepare(
    'SELECT
        c.id,
        c.class_code,
        c.class_name,
        c.section

     FROM enrollments en

     JOIN classes c
        ON c.id = en.class_id

     WHERE en.student_id = ?

     ORDER BY
        c.class_code,
        c.section'
);

$stmt->execute([$studentId]);

$classes = $stmt->fetchAll();

// VALIDATE SELECTED CLASS

if (
    $canView &&
    $filters['class_id'] !== null
) {

    $found = false;

    foreach ($classes as $class) {

        if (
            (int) $class['id'] ===
            $filters['class_id']
        ) {

            $found = true;
            break;
        }
    }

    if (!$found) {

        $error =
            'You are not enrolled in that class.';

        $canView = false;
    }
}

// INITIALIZE ATTENDANCE DATA

$classRows = [];
$attendanceRows = [];

$totals = [
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'recorded' => 0
];

if ($canView) {

    // PER-CLASS ATTENDANCE STATISTICS

    $sql = "
        SELECT
            c.id,
            c.class_code,
            c.class_name,
            c.section,

            COUNT(DISTINCT cs.id)
                AS session_count,

            COUNT(a.id)
                AS recorded_count,

            COALESCE(
                SUM(
                    CASE WHEN a.status = 'Present'
                    THEN 1 ELSE 0 END
                ),
                0
            ) AS present_count,

            COALESCE(
                SUM(
                    CASE WHEN a.status = 'Late'
                    THEN 1 ELSE 0 END
                ),
                0
            ) AS late_count,

            COALESCE(
                SUM(
                    CASE WHEN a.status = 'Absent'
                    THEN 1 ELSE 0 END
                ),
                0
            ) AS absent_count

        FROM enrollments en

        JOIN classes c
            ON c.id = en.class_id

        LEFT JOIN class_sessions cs
            ON cs.class_id = c.id
    ";

    $params = [];

    // DATE FILTER IN LEFT JOIN

    if ($filters['from'] !== null) {

        $sql .= ' AND cs.session_date >= ?';

        $params[] = $filters['from'];
    }

    if ($filters['to'] !== null) {

        $sql .= ' AND cs.session_date <= ?';

        $params[] = $filters['to'];
    }

    $sql .= "
        LEFT JOIN attendance a
            ON a.session_id = cs.id
           AND a.student_id = en.student_id

        WHERE en.student_id = ?
    ";

    $params[] = $studentId;

    if ($filters['class_id'] !== null) {

        $sql .= ' AND c.id = ?';

        $params[] = $filters['class_id'];
    }

    $sql .= '
        GROUP BY
            c.id,
            c.class_code,
            c.class_name,
            c.section

        ORDER BY
            c.class_code,
            c.section
    ';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $classRows = $stmt->fetchAll();

    // CALCULATE TOTALS

    foreach ($classRows as $row) {

        $totals['present'] +=
            (int) $row['present_count'];

        $totals['late'] +=
            (int) $row['late_count'];

        $totals['absent'] +=
            (int) $row['absent_count'];

        $totals['recorded'] +=
            (int) $row['recorded_count'];
    }

    // FULL RECORDED ATTENDANCE HISTORY

    $sql = '
        SELECT
            cs.session_date,
            cs.start_time,
            cs.topic,

            c.class_code,
            c.class_name,
            c.section,

            a.status

        FROM attendance a

        JOIN class_sessions cs
            ON cs.id = a.session_id

        JOIN classes c
            ON c.id = cs.class_id

        JOIN enrollments en
            ON en.class_id = c.id
           AND en.student_id = a.student_id

        WHERE a.student_id = ?
    ';

    $params = [$studentId];

    if ($filters['class_id'] !== null) {

        $sql .= ' AND c.id = ?';

        $params[] = $filters['class_id'];
    }

    if ($filters['from'] !== null) {

        $sql .= ' AND cs.session_date >= ?';

        $params[] = $filters['from'];
    }

    if ($filters['to'] !== null) {

        $sql .= ' AND cs.session_date <= ?';

        $params[] = $filters['to'];
    }

    $sql .= '
        ORDER BY
            cs.session_date DESC,
            cs.start_time DESC,
            cs.id DESC
    ';

    $stmt = db()->prepare($sql);

    $stmt->execute($params);

    $attendanceRows = $stmt->fetchAll();
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
        My Attendance | <?= e(APP_NAME) ?>
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
        href="assets/css/reports.css"
    >

</head>

<body class="dashboard-body">

<?php
require __DIR__ . '/includes/portal_header.php';
?>

<main class="dashboard-container">

    <!-- PAGE HEADER -->

    <div class="page-intro">

        <div>

            <span class="eyebrow dark">
                STUDENT / MY ATTENDANCE
            </span>

            <h1>My Attendance</h1>

            <p class="subtle">
                View your recorded marks and
                attendance rate for each class.
            </p>

        </div>

    </div>

    <?php if ($error !== ''): ?>

        <div
            class="notice notice-error"
            role="alert"
        >
            <?= e($error) ?>
        </div>

    <?php endif; ?>

    <!-- FILTER FORM -->

    <section class="surface report-panel">

        <h2>Filter your history</h2>

        <form
            class="report-filters"
            method="get"
            action="my-attendance.php"
        >

            <div class="field">

                <label for="class_id">
                    Class
                </label>

                <select
                    id="class_id"
                    name="class_id"
                >

                    <option value="">
                        All enrolled classes
                    </option>

                    <?php foreach ($classes as $class): ?>

                        <option
                            value="<?= (int) $class['id'] ?>"
                            <?= $filters['class_id'] ===
                                (int) $class['id']
                                ? 'selected'
                                : '' ?>
                        >

                            <?= e(
                                $class['class_code'] .
                                ' — ' .
                                $class['section']
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="field">

                <label for="from">
                    From date
                </label>

                <input
                    type="date"
                    name="from"
                    id="from"
                    value="<?= e($filters['from'] ?? '') ?>"
                >

            </div>

            <div class="field">

                <label for="to">
                    To date
                </label>

                <input
                    type="date"
                    name="to"
                    id="to"
                    value="<?= e($filters['to'] ?? '') ?>"
                >

            </div>

            <button
                class="button button-primary"
                type="submit"
            >
                Apply filters
            </button>

            <a
                class="button button-outline"
                href="my-attendance.php"
            >
                Reset
            </a>

        </form>

    </section>

    <?php if ($canView): ?>

        <!-- STUDENT ATTENDANCE TOTALS -->

        <section
            class="report-metrics"
            aria-label="Attendance totals"
        >

            <article class="surface report-metric">

                <span>Present</span>

                <strong>
                    <?= $totals['present'] ?>
                </strong>

            </article>

            <article class="surface report-metric">

                <span>Late</span>

                <strong>
                    <?= $totals['late'] ?>
                </strong>

            </article>

            <article class="surface report-metric">

                <span>Absent</span>

                <strong>
                    <?= $totals['absent'] ?>
                </strong>

            </article>

            <article class="surface report-metric">

                <span>Recorded marks</span>

                <strong>
                    <?= $totals['recorded'] ?>
                </strong>

            </article>

            <article class="surface report-metric">

                <span>Attendance rate</span>

                <strong>
                    <?= e(
                        attendance_report_rate(
                            $totals['present'],
                            $totals['late'],
                            $totals['recorded']
                        )
                    ) ?>
                </strong>

            </article>

        </section>

        <!-- ATTENDANCE BY CLASS -->

        <section class="surface report-panel">

            <h2>Attendance by class</h2>

            <p class="subtle">
                Present and Late count as attended.
                Sessions without a saved status
                are not treated as Absent.
            </p>

            <div class="report-table-wrap">

                <table class="report-table">

                    <thead>

                        <tr>
                            <th>Class</th>
                            <th>Sessions</th>
                            <th>Marked</th>
                            <th>Present</th>
                            <th>Late</th>
                            <th>Absent</th>
                            <th>Rate</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($classRows as $row): ?>

                        <tr>

                            <td>

                                <strong>
                                    <?= e($row['class_code']) ?>
                                </strong>

                                <small>
                                    <?= e(
                                        $row['class_name'] .
                                        ' · ' .
                                        $row['section']
                                    ) ?>
                                </small>

                            </td>

                            <td>
                                <?= (int) $row['session_count'] ?>
                            </td>

                            <td>
                                <?= (int) $row['recorded_count'] ?>
                            </td>

                            <td>
                                <?= (int) $row['present_count'] ?>
                            </td>

                            <td>
                                <?= (int) $row['late_count'] ?>
                            </td>

                            <td>
                                <?= (int) $row['absent_count'] ?>
                            </td>

                            <td>

                                <strong>
                                    <?= e(
                                        attendance_report_rate(
                                            (int) $row['present_count'],
                                            (int) $row['late_count'],
                                            (int) $row['recorded_count']
                                        )
                                    ) ?>
                                </strong>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (!$classRows): ?>

                        <tr>

                            <td colspan="7">
                                No enrolled classes found
                                for this account.
                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

        <!-- FULL ATTENDANCE HISTORY -->

        <section class="surface report-panel">

            <h2>
                Full recorded attendance history
            </h2>

            <?php if (!$attendanceRows): ?>

                <p class="subtle">
                    There are no saved attendance
                    records for these filters.
                </p>

            <?php else: ?>

                <div class="report-table-wrap">

                    <table class="report-table">

                        <thead>

                            <tr>
                                <th>Date</th>
                                <th>Class</th>
                                <th>Session topic</th>
                                <th>Status</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($attendanceRows as $row): ?>

                            <?php

                            $statusClass = match (
                                $row['status']
                            ) {
                                'Present' => 'present',
                                'Late' => 'late',
                                'Absent' => 'absent',
                                default => 'unknown',
                            };

                            ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= e(
                                            $row['session_date']
                                        ) ?>
                                    </strong>

                                    <small>
                                        <?= e(
                                            substr(
                                                (string) $row['start_time'],
                                                0,
                                                5
                                            )
                                        ) ?>
                                    </small>

                                </td>

                                <td>

                                    <strong>
                                        <?= e($row['class_code']) ?>
                                    </strong>

                                    <small>
                                        <?= e(
                                            $row['class_name'] .
                                            ' · ' .
                                            $row['section']
                                        ) ?>
                                    </small>

                                </td>

                                <td>
                                    <?= e(
                                        $row['topic'] ?:
                                        'Class meeting'
                                    ) ?>
                                </td>

                                <td>

                                    <span
                                        class="report-status report-status-<?= $statusClass ?>"
                                    >
                                        <?= e($row['status']) ?>
                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>

    <footer class="page-footer">
        CampusTrack · Student Attendance History
    </footer>

</main>

</body>
</html>
