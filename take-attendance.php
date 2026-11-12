<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$user = require_user('instructor');
$instructorId = (int) $user['id'];

$notice = take_flash();
$error = '';

// GET SESSION ID

$rawSessionId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['session_id'] ?? null)
    : ($_GET['session_id'] ?? null);

$sessionId = filter_var(
    $rawSessionId,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!is_int($sessionId)) {

    flash(
        'error',
        'Choose a valid attendance session.'
    );

    redirect('attendance.php');
}

// VERIFY SESSION OWNERSHIP

$stmt = db()->prepare(
    'SELECT
        cs.id,
        cs.class_id,
        cs.session_date,
        cs.start_time,
        cs.topic,
        c.class_code,
        c.class_name,
        c.section

     FROM class_sessions cs

     JOIN classes c
        ON c.id = cs.class_id

     WHERE cs.id = ?
       AND c.instructor_id = ?

     LIMIT 1'
);

$stmt->execute([
    $sessionId,
    $instructorId
]);

$session = $stmt->fetch();

if (!$session) {

    http_response_code(403);

    exit(
        'Attendance session not found or not assigned to your account.'
    );
}

// LOAD CLASS ROSTER AND EXISTING ATTENDANCE

function getAttendanceRoster(
    int $sessionId,
    int $classId
): array {

    $stmt = db()->prepare(
        'SELECT
            s.id AS student_id,
            s.student_number,
            s.program,
            u.full_name,
            a.status

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
        $sessionId,
        $classId
    ]);

    return $stmt->fetchAll();
}

$roster = getAttendanceRoster(
    $sessionId,
    (int) $session['class_id']
);

$submitted = [];

// SAVE ATTENDANCE

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

    verify_csrf();

    $submitted = $_POST['status'] ?? [];

    if (!is_array($submitted)) {
        $submitted = [];
    }

    if (!$roster) {

        $error =
            'This class has no enrolled students. Enroll students first.';

    } else {

        $allowed = [
            'Present',
            'Late',
            'Absent'
        ];

        // Every enrolled student must have a valid status.

        foreach ($roster as $student) {

            $key = (string) $student['student_id'];
            $value = $submitted[$key] ?? null;

            if (
                !is_string($value) ||
                !in_array($value, $allowed, true)
            ) {

                $error =
                    'Choose Present, Late, or Absent for every enrolled student.';

                break;
            }
        }

        if ($error === '') {

            $pdo = db();

            try {

                $pdo->beginTransaction();

                // INSERT OR UPDATE THE EXISTING RECORD.
                // The unique (session_id, student_id)
                // index prevents duplicate entries.

                $save = $pdo->prepare(
                    'INSERT INTO attendance (
                        session_id,
                        student_id,
                        status,
                        recorded_by
                     )
                     VALUES (?, ?, ?, ?)

                     ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        recorded_by = VALUES(recorded_by),
                        recorded_at = CURRENT_TIMESTAMP'
                );

                foreach ($roster as $student) {

                    $key = (string) $student['student_id'];

                    $save->execute([
                        $sessionId,
                        (int) $student['student_id'],
                        $submitted[$key],
                        $instructorId
                    ]);
                }

                $pdo->commit();

                flash(
                    'success',
                    'Attendance saved for ' .
                    count($roster) .
                    ' students.'
                );

                redirect(
                    'take-attendance.php?session_id=' .
                    $sessionId
                );

            } catch (PDOException $ex) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Save attendance: ' .
                    $ex->getMessage()
                );

                $error =
                    'Attendance could not be saved. Please try again.';
            }
        }
    }
}

// CURRENT SAVED COUNTS

$present = 0;
$late = 0;
$absent = 0;
$unmarked = 0;

foreach ($roster as $student) {

    switch ($student['status']) {

        case 'Present':
            $present++;
            break;

        case 'Late':
            $late++;
            break;

        case 'Absent':
            $absent++;
            break;

        default:
            $unmarked++;
            break;
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
        Take Attendance | <?= e(APP_NAME) ?>
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

    <!-- SESSION INFORMATION -->

    <div class="page-intro">

        <div>

            <span class="eyebrow dark">
                INSTRUCTOR / TAKE ATTENDANCE
            </span>

            <h1>
                <?= e($session['class_code']) ?>
                —
                <?= e($session['class_name']) ?>
            </h1>

            <p class="subtle">

                Section <?= e($session['section']) ?>

                · <?= e($session['session_date']) ?>

                at <?= e(
                    substr(
                        $session['start_time'],
                        0,
                        5
                    )
                ) ?>

                · <?= e(
                    $session['topic'] ?: 'Class meeting'
                ) ?>

            </p>

        </div>

        <a
            href="attendance.php?class_id=<?= (int) $session['class_id'] ?>"
            class="button button-outline"
        >
            Back to sessions
        </a>

    </div>

    <!-- MESSAGES -->

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

    <!-- CURRENT ATTENDANCE COUNTS -->

    <div class="attendance-stats">

        <div class="surface">
            <strong><?= count($roster) ?></strong>
            <span>Enrolled</span>
        </div>

        <div class="surface">
            <strong><?= $present ?></strong>
            <span>Present</span>
        </div>

        <div class="surface">
            <strong><?= $late ?></strong>
            <span>Late</span>
        </div>

        <div class="surface">
            <strong><?= $absent ?></strong>
            <span>Absent</span>
        </div>

        <div class="surface">
            <strong><?= $unmarked ?></strong>
            <span>Unmarked</span>
        </div>

    </div>

    <!-- ATTENDANCE FORM -->

    <section class="surface attendance-panel">

        <div class="attendance-topline">

            <div>

                <span class="eyebrow dark">
                    CLASS ROSTER
                </span>

                <h2>Mark attendance</h2>

                <p class="subtle">
                    Choose a status for every student,
                    then save. You can return to correct
                    a status.
                </p>

            </div>

            <?php if ($roster): ?>

                <button
                    class="button button-outline"
                    type="button"
                    id="markAllPresent"
                >
                    Mark all Present
                </button>

            <?php endif; ?>

        </div>

        <?php if (!$roster): ?>

            <p>
                No enrolled students in this class yet.
            </p>

            <a
                class="button button-primary"
                href="enrollments.php?class_id=<?= (int) $session['class_id'] ?>"
            >
                Enroll students
            </a>

        <?php else: ?>

            <form
                method="post"
                action="take-attendance.php"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="session_id"
                    value="<?= $sessionId ?>"
                >

                <div class="attendance-table-wrap">

                    <table class="attendance-table">

                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Attendance status</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($roster as $student): ?>

                            <?php

                            $key = (string) $student['student_id'];

                            $selectedStatus =
                                isset($submitted[$key]) &&
                                is_string($submitted[$key])
                                    ? $submitted[$key]
                                    : ($student['status'] ?? '');

                            ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= e($student['full_name']) ?>
                                    </strong>

                                    <small>
                                        <?= e($student['student_number']) ?>
                                    </small>

                                </td>

                                <td>
                                    <?= e($student['program']) ?>
                                </td>

                                <td>

                                    <label
                                        class="attendance-sr-only"
                                        for="status_<?= (int) $student['student_id'] ?>"
                                    >
                                        Attendance for
                                        <?= e($student['full_name']) ?>
                                    </label>

                                    <select
                                        class="attendance-status"
                                        id="status_<?= (int) $student['student_id'] ?>"
                                        name="status[<?= (int) $student['student_id'] ?>]"
                                        required
                                    >

                                        <option
                                            value=""
                                            <?= $selectedStatus === ''
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            Choose status
                                        </option>

                                        <?php foreach (
                                            ['Present', 'Late', 'Absent'] as $status
                                        ): ?>

                                            <option
                                                value="<?= e($status) ?>"
                                                <?= $selectedStatus === $status
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                <?= e($status) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <!-- SAVE / HISTORY -->

                <div class="attendance-form-actions">

                    <button
                        class="button button-primary"
                        type="submit"
                    >
                        Save attendance
                    </button>

                    <a
                        class="button button-outline"
                        href="attendance-history.php?class_id=<?= (int) $session['class_id'] ?>&amp;session_id=<?= $sessionId ?>"
                    >
                        View saved history
                    </a>

                </div>

            </form>

        <?php endif; ?>

    </section>

</main>

<!-- MARK ALL PRESENT SHORTCUT -->

<script>
(() => {
    'use strict';

    const button = document.getElementById(
        'markAllPresent'
    );

    if (!button) return;

    button.addEventListener('click', () => {

        document
            .querySelectorAll('select.attendance-status')
            .forEach((select) => {

                select.value = 'Present';

            });

    });

})();
</script>

</body>
</html>
