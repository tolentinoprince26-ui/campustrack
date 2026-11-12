<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/report_helpers.php';

$user = require_user('instructor');
$instructorId = (int) $user['id'];

$error = '';
$canView = true;

// VALIDATE REPORT FILTERS

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

// GET INSTRUCTOR CLASSES

$classesStmt = db()->prepare(
    'SELECT
        id,
        class_code,
        class_name,
        section
     FROM classes
     WHERE instructor_id = ?
     ORDER BY class_code, section'
);

$classesStmt->execute([$instructorId]);

$classes = $classesStmt->fetchAll();

// VERIFY CLASS OWNERSHIP

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

        $canView = false;

        $error =
            'That class is not assigned to your instructor account.';
    }
}

// REPORT DATA

$classRows = [];
$monthlyRows = [];
$dailyRows = [];
$studentRows = [];

$totals = [
    'sessions' => 0,
    'recorded' => 0,
    'present' => 0,
    'late' => 0,
    'absent' => 0
];

if ($canView) {

    // DATE FILTERS FOR CLASS SUMMARY

    $sessionJoin =
        'LEFT JOIN class_sessions cs
         ON cs.class_id = c.id';

    $sessionParams = [];

    if ($filters['from'] !== null) {

        $sessionJoin .=
            ' AND cs.session_date >= ?';

        $sessionParams[] = $filters['from'];
    }

    if ($filters['to'] !== null) {

        $sessionJoin .=
            ' AND cs.session_date <= ?';

        $sessionParams[] = $filters['to'];
    }

    // PER-CLASS ATTENDANCE

    $classSql = "
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
                    CASE
                        WHEN a.status = 'Present'
                        THEN 1 ELSE 0
                    END
                ),
                0
            ) AS present_count,

            COALESCE(
                SUM(
                    CASE
                        WHEN a.status = 'Late'
                        THEN 1 ELSE 0
                    END
                ),
                0
            ) AS late_count,

            COALESCE(
                SUM(
                    CASE
                        WHEN a.status = 'Absent'
                        THEN 1 ELSE 0
                    END
                ),
                0
            ) AS absent_count

        FROM classes c

        $sessionJoin

        LEFT JOIN attendance a
            ON a.session_id = cs.id

        WHERE c.instructor_id = ?
    ";

    $classParams = [
        ...$sessionParams,
        $instructorId
    ];

    if ($filters['class_id'] !== null) {

        $classSql .= ' AND c.id = ?';

        $classParams[] = $filters['class_id'];
    }

    $classSql .= '
        GROUP BY
            c.id,
            c.class_code,
            c.class_name,
            c.section

        ORDER BY
            c.class_code,
            c.section
    ';

    $stmt = db()->prepare($classSql);
    $stmt->execute($classParams);

    $classRows = $stmt->fetchAll();

    // OVERALL TOTALS

    foreach ($classRows as $row) {

        $totals['sessions'] +=
            (int) $row['session_count'];

        $totals['recorded'] +=
            (int) $row['recorded_count'];

        $totals['present'] +=
            (int) $row['present_count'];

        $totals['late'] +=
            (int) $row['late_count'];

        $totals['absent'] +=
            (int) $row['absent_count'];
    }

    // SHARED DAILY / MONTHLY QUERY

    $periodFrom = '
        FROM class_sessions cs

        JOIN classes c
            ON c.id = cs.class_id

        LEFT JOIN attendance a
            ON a.session_id = cs.id

        WHERE c.instructor_id = ?
    ';

    $periodParams = [$instructorId];

    if ($filters['class_id'] !== null) {

        $periodFrom .= ' AND c.id = ?';

        $periodParams[] = $filters['class_id'];
    }

    if ($filters['from'] !== null) {

        $periodFrom .=
            ' AND cs.session_date >= ?';

        $periodParams[] = $filters['from'];
    }

    if ($filters['to'] !== null) {

        $periodFrom .=
            ' AND cs.session_date <= ?';

        $periodParams[] = $filters['to'];
    }

    $counts = "
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
    ";

    // MONTHLY SUMMARY

    $monthSql = "
        SELECT
            DATE_FORMAT(
                cs.session_date,
                '%Y-%m'
            ) AS period,

            $counts
    ";

    $monthSql .= $periodFrom;

    $monthSql .= "
        GROUP BY
            DATE_FORMAT(
                cs.session_date,
                '%Y-%m'
            )

        ORDER BY period DESC
    ";

    $stmt = db()->prepare($monthSql);
    $stmt->execute($periodParams);

    $monthlyRows = $stmt->fetchAll();

    // RECENT DAILY SUMMARY

    $daySql = "
        SELECT
            cs.session_date AS period,
            $counts
    ";

    $daySql .= $periodFrom;

    $daySql .= '
        GROUP BY cs.session_date

        ORDER BY
            cs.session_date DESC

        LIMIT 15
    ';

    $stmt = db()->prepare($daySql);
    $stmt->execute($periodParams);

    $dailyRows = $stmt->fetchAll();

    // INDIVIDUAL STUDENT REPORT
    // SHOW ONLY FOR A SELECTED CLASS

    if ($filters['class_id'] !== null) {

        $studentsSql = "
            SELECT
                s.student_number,
                u.full_name,

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

            JOIN students s
                ON s.id = en.student_id

            JOIN users u
                ON u.id = s.user_id

            LEFT JOIN class_sessions cs
                ON cs.class_id = en.class_id
        ";

        $studentParams = [];

        if ($filters['from'] !== null) {

            $studentsSql .=
                ' AND cs.session_date >= ?';

            $studentParams[] = $filters['from'];
        }

        if ($filters['to'] !== null) {

            $studentsSql .=
                ' AND cs.session_date <= ?';

            $studentParams[] = $filters['to'];
        }

        $studentsSql .= "
            LEFT JOIN attendance a
                ON a.session_id = cs.id
               AND a.student_id = s.id

            WHERE en.class_id = ?

            GROUP BY
                s.id,
                s.student_number,
                u.full_name

            ORDER BY
                u.full_name,
                s.student_number
        ";

        $studentParams[] = $filters['class_id'];

        $stmt = db()->prepare($studentsSql);

        $stmt->execute($studentParams);

        $studentRows = $stmt->fetchAll();
    }
}

// EXPORT LINK

$exportUrl = 'reports-export.php?' .
    http_build_query([
        'class_id' => $filters['class_id'] ?? '',
        'from' => $filters['from'] ?? '',
        'to' => $filters['to'] ?? ''
    ]);
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
        Attendance Reports | <?= e(APP_NAME) ?>
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

    <!-- PAGE HEADING -->

    <div class="page-intro">

        <div>

            <span class="eyebrow dark">
                INSTRUCTOR / REPORTS
            </span>

            <h1>Attendance Reports</h1>

            <p class="subtle">
                Review your class attendance by student,
                day, and month.
            </p>

        </div>

    </div>

    <!-- VALIDATION ERROR -->

    <?php if ($error !== ''): ?>

        <div
            class="notice notice-error"
            role="alert"
        >
            <?= e($error) ?>
        </div>

    <?php endif; ?>

    <!-- FILTER CONTROLS -->

    <section class="surface report-panel">

        <h2>Filter reports</h2>

        <form
            class="report-filters"
            method="get"
            action="reports.php"
        >

            <div class="field">

                <label for="class_id">
                    Class
                </label>

                <select
                    name="class_id"
                    id="class_id"
                >

                    <option value="">
                        All my classes
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
                    id="from"
                    name="from"
                    value="<?= e($filters['from'] ?? '') ?>"
                >

            </div>

            <div class="field">

                <label for="to">
                    To date
                </label>

                <input
                    type="date"
                    id="to"
                    name="to"
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
                href="reports.php"
            >
                Reset
            </a>

        </form>

        <!-- CSV EXPORT -->

        <?php if ($canView): ?>

            <div class="report-export">

                <a
                    class="button button-outline"
                    href="<?= e($exportUrl) ?>"
                >
                    Export filtered records (CSV)
                </a>

                <small>
                    Export includes saved attendance
                    marks only; unmarked students
                    are excluded.
                </small>

            </div>

        <?php endif; ?>

    </section>

    <?php if ($canView): ?>

        <!-- REPORT TOTALS -->

        <section
            class="report-metrics"
            aria-label="Report totals"
        >

            <article class="surface report-metric">

                <span>Class sessions</span>

                <strong>
                    <?= $totals['sessions'] ?>
                </strong>

            </article>

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

        <!-- CLASS REPORT TABLE -->

        <section class="surface report-panel">

            <h2>Per-class summary</h2>

            <p class="subtle">
                Rate = (Present + Late) divided by
                recorded attendance entries.

                Unmarked entries are not counted as
                absences.
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
                            <th></th>
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

                            <td>

                                <a
                                    href="reports.php?<?= e(
                                        http_build_query([
                                            'class_id' =>
                                                (int) $row['id'],

                                            'from' =>
                                                $filters['from'] ?? '',

                                            'to' =>
                                                $filters['to'] ?? ''
                                        ])
                                    ) ?>"
                                >
                                    Students
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (!$classRows): ?>

                        <tr>

                            <td colspan="8">

                                No classes to report yet.

                                <a href="classes.php">
                                    Create a class
                                </a>.

                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

        <!-- INDIVIDUAL STUDENT REPORT -->

        <?php if ($filters['class_id'] !== null): ?>

            <section class="surface report-panel">

                <h2>
                    Student attendance breakdown
                </h2>

                <p class="subtle">
                    Shows enrolled students for the
                    selected class, including students
                    without recorded attendance.
                </p>

                <div class="report-table-wrap">

                    <table class="report-table">

                        <thead>

                            <tr>
                                <th>Student</th>
                                <th>Sessions</th>
                                <th>Marked</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th>Rate</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($studentRows as $row): ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= e($row['full_name']) ?>
                                    </strong>

                                    <small>
                                        <?= e(
                                            $row['student_number']
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

                        <?php if (!$studentRows): ?>

                            <tr>

                                <td colspan="7">
                                    No students enrolled
                                    in this class.
                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        <?php else: ?>

            <p class="report-hint">

                Select a class above, or click
                <strong>Students</strong> in its
                summary row, to see individual
                attendance percentages.

            </p>

        <?php endif; ?>

        <!-- MONTHLY AND DAILY REPORTS -->

        <div class="report-two-col">

            <section class="surface report-panel">

                <h2>Monthly summary</h2>

                <div class="report-table-wrap">

                    <table class="report-table">

                        <thead>

                            <tr>
                                <th>Month</th>
                                <th>Sessions</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th>Rate</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($monthlyRows as $row): ?>

                            <tr>

                                <td>
                                    <?= e($row['period']) ?>
                                </td>

                                <td>
                                    <?= (int) $row['session_count'] ?>
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
                                    <?= e(
                                        attendance_report_rate(
                                            (int) $row['present_count'],
                                            (int) $row['late_count'],
                                            (int) $row['recorded_count']
                                        )
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        <?php if (!$monthlyRows): ?>

                            <tr>

                                <td colspan="6">
                                    No sessions for the
                                    selected filters.
                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

            <section class="surface report-panel">

                <h2>Recent daily summary</h2>

                <p class="subtle">
                    Latest 15 session dates matching
                    your filters.
                </p>

                <div class="report-table-wrap">

                    <table class="report-table">

                        <thead>

                            <tr>
                                <th>Date</th>
                                <th>Sessions</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th>Rate</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($dailyRows as $row): ?>

                            <tr>

                                <td>
                                    <?= e($row['period']) ?>
                                </td>

                                <td>
                                    <?= (int) $row['session_count'] ?>
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
                                    <?= e(
                                        attendance_report_rate(
                                            (int) $row['present_count'],
                                            (int) $row['late_count'],
                                            (int) $row['recorded_count']
                                        )
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        <?php if (!$dailyRows): ?>

                            <tr>

                                <td colspan="6">
                                    No sessions for the
                                    selected filters.
                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        </div>

    <?php endif; ?>

    <footer class="page-footer">
        CampusTrack · Instructor Attendance Reports
    </footer>

</main>

</body>
</html>
