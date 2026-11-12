<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$user = require_user('instructor');
$instructorId = (int) $user['id'];

// INSTRUCTOR CLASSES

$stmt = db()->prepare(
    'SELECT
        id,
        class_code,
        class_name,
        section
     FROM classes
     WHERE instructor_id = ?
     ORDER BY class_code, section'
);

$stmt->execute([$instructorId]);
$classes = $stmt->fetchAll();

// SELECT CLASS

$classId = filter_var(
    $_GET['class_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$selectedClass = null;

foreach ($classes as $class) {

    if (
        is_int($classId) &&
        (int) $class['id'] === $classId
    ) {

        $selectedClass = $class;
        break;
    }
}

if (!isset($_GET['class_id']) && $classes) {
    $selectedClass = $classes[0];
}

$sessions = [];
$selectedSession = null;
$rows = [];

// LOAD HISTORY FOR SELECTED CLASS

if ($selectedClass) {

    $stmt = db()->prepare(
        "SELECT
            cs.id,
            cs.session_date,
            cs.start_time,
            cs.topic,

            COUNT(a.id) AS recorded,

            COALESCE(
                SUM(a.status = 'Present'),
                0
            ) AS present_count,

            COALESCE(
                SUM(a.status = 'Late'),
                0
            ) AS late_count,

            COALESCE(
                SUM(a.status = 'Absent'),
                0
            ) AS absent_count

         FROM class_sessions cs

         LEFT JOIN attendance a
            ON a.session_id = cs.id

         WHERE cs.class_id = ?

         GROUP BY
            cs.id,
            cs.session_date,
            cs.start_time,
            cs.topic

         ORDER BY
            cs.session_date DESC,
            cs.start_time DESC,
            cs.id DESC"
    );

    $stmt->execute([
        (int) $selectedClass['id']
    ]);

    $sessions = $stmt->fetchAll();

    // SELECT SPECIFIC SESSION

    $wantedSessionId = filter_var(
        $_GET['session_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    foreach ($sessions as $session) {

        if (
            is_int($wantedSessionId) &&
            (int) $session['id'] === $wantedSessionId
        ) {

            $selectedSession = $session;
            break;
        }
    }

    if (!isset($_GET['session_id']) && $sessions) {
        $selectedSession = $sessions[0];
    }

    // LOAD STUDENT ATTENDANCE FOR SESSION

    if ($selectedSession) {

        $stmt = db()->prepare(
            'SELECT
                u.full_name,
                s.student_number,
                a.status,
                a.recorded_at

             FROM enrollments e

             JOIN students s
                ON s.id = e.student_id

             JOIN users u
                ON u.id = s.user_id

             LEFT JOIN attendance a
                ON a.student_id = s.id
               AND a.session_id = ?

             WHERE e.class_id = ?

             ORDER BY
                u.full_name,
                s.student_number'
        );

        $stmt->execute([
            (int) $selectedSession['id'],
            (int) $selectedClass['id']
        ]);

        $rows = $stmt->fetchAll();
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

    <title>
        Attendance History | <?= e(APP_NAME) ?>
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
                INSTRUCTOR / ATTENDANCE
            </span>

            <h1>Attendance History</h1>

            <p class="subtle">
                Review recorded sessions and correct
                records if necessary.
            </p>

        </div>

        <a
            class="button button-outline"
            href="attendance.php"
        >
            Attendance sessions
        </a>

    </div>

    <?php if (!$classes): ?>

        <section class="surface attendance-panel">

            <p>No classes created yet.</p>

            <a href="classes.php">
                Create a class
            </a>

        </section>

    <?php else: ?>

        <!-- CLASS SELECTOR -->

        <section class="surface attendance-panel">

            <form
                method="get"
                action="attendance-history.php"
                class="attendance-class-picker"
            >

                <div class="field">

                    <label for="class_id">
                        Class
                    </label>

                    <select
                        id="class_id"
                        name="class_id"
                        required
                    >

                        <?php foreach ($classes as $class): ?>

                            <option
                                value="<?= (int) $class['id'] ?>"
                                <?= $selectedClass &&
                                    (int) $class['id'] ===
                                    (int) $selectedClass['id']
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

                <button
                    type="submit"
                    class="button button-outline"
                >
                    View history
                </button>

            </form>

        </section>

        <?php if ($selectedClass): ?>

            <!-- LIST OF ATTENDANCE SESSIONS -->

            <section class="surface attendance-panel">

                <h2>
                    Recorded sessions
                    (<?= count($sessions) ?>)
                </h2>

                <?php if (!$sessions): ?>

                    <p>
                        No class sessions recorded yet.
                    </p>

                <?php else: ?>

                    <div class="attendance-table-wrap">

                        <table class="attendance-table">

                            <thead>
                                <tr>
                                    <th>Date / Time</th>
                                    <th>Topic</th>
                                    <th>Present</th>
                                    <th>Late</th>
                                    <th>Absent</th>
                                    <th>Details</th>
                                </tr>
                            </thead>

                            <tbody>

                            <?php foreach ($sessions as $session): ?>

                                <tr>

                                    <td>

                                        <strong>
                                            <?= e($session['session_date']) ?>
                                        </strong>

                                        <small>
                                            <?= e(
                                                substr(
                                                    $session['start_time'],
                                                    0,
                                                    5
                                                )
                                            ) ?>
                                        </small>

                                    </td>

                                    <td>
                                        <?= e(
                                            $session['topic'] ?:
                                            'Class meeting'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= (int) $session['present_count'] ?>
                                    </td>

                                    <td>
                                        <?= (int) $session['late_count'] ?>
                                    </td>

                                    <td>
                                        <?= (int) $session['absent_count'] ?>
                                    </td>

                                    <td>

                                        <a
                                            href="attendance-history.php?class_id=<?= (int) $selectedClass['id'] ?>&amp;session_id=<?= (int) $session['id'] ?>"
                                        >
                                            View
                                        </a>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

            <!-- SELECTED SESSION DETAILS -->

            <?php if ($selectedSession): ?>

                <section class="surface attendance-panel">

                    <div class="attendance-topline">

                        <div>

                            <span class="eyebrow dark">
                                SESSION DETAILS
                            </span>

                            <h2>
                                <?= e($selectedSession['session_date']) ?>
                                ·
                                <?= e(
                                    substr(
                                        $selectedSession['start_time'],
                                        0,
                                        5
                                    )
                                ) ?>
                            </h2>

                            <p class="subtle">

                                <?= e(
                                    $selectedSession['topic'] ?:
                                    'Class meeting'
                                ) ?>

                                ·
                                <?= (int) $selectedSession['recorded'] ?>
                                saved records

                            </p>

                        </div>

                        <a
                            class="button button-primary"
                            href="take-attendance.php?session_id=<?= (int) $selectedSession['id'] ?>"
                        >
                            Edit attendance
                        </a>

                    </div>

                    <?php if (!$rows): ?>

                        <p>
                            No enrolled students for this class.
                        </p>

                    <?php else: ?>

                        <div class="attendance-table-wrap">

                            <table class="attendance-table">

                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Status</th>
                                        <th>Last recorded</th>
                                    </tr>
                                </thead>

                                <tbody>

                                <?php foreach ($rows as $row): ?>

                                    <tr>

                                        <td>

                                            <strong>
                                                <?= e($row['full_name']) ?>
                                            </strong>

                                            <small>
                                                <?= e($row['student_number']) ?>
                                            </small>

                                        </td>

                                        <td>
                                            <?= e(
                                                $row['status'] ?:
                                                'Not marked'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $row['recorded_at'] ?:
                                                '—'
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </section>

            <?php elseif (isset($_GET['session_id'])): ?>

                <div class="notice notice-error">
                    That session does not belong to
                    the selected class.
                </div>

            <?php endif; ?>

        <?php elseif (isset($_GET['class_id'])): ?>

            <div class="notice notice-error">
                You cannot view that class.
            </div>

        <?php endif; ?>

    <?php endif; ?>

    <footer class="page-footer">
        CampusTrack · Attendance History
    </footer>

</main>

</body>
</html>
