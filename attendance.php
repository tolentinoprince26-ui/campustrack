<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$user = require_user('instructor');
$instructorId = (int) $user['id'];
$notice = take_flash();
$error = '';

// Only load classes owned by this instructor.

$stmt = db()->prepare(
    'SELECT
        id,
        class_code,
        class_name,
        section,
        school_year,
        semester
     FROM classes
     WHERE instructor_id = ?
     ORDER BY
        school_year DESC,
        semester,
        class_code'
);

$stmt->execute([$instructorId]);
$classes = $stmt->fetchAll();

// Determine selected class.

$requested = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['class_id'] ?? null)
    : ($_GET['class_id'] ?? null);

$requestedId = filter_var(
    $requested,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$selected = null;

foreach ($classes as $class) {
    if (
        is_int($requestedId) &&
        (int) $class['id'] === $requestedId
    ) {
        $selected = $class;
        break;
    }
}

if ($requested === null && $classes) {
    $selected = $classes[0];

} elseif ($requested !== null && !$selected) {
    $error =
        'That class was not found in your instructor account.';
}

// CREATE ATTENDANCE SESSION

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

    verify_csrf();

    if (!$selected) {
        $error = 'Select one of your classes.';

    } else {

        $date = is_string(
            $_POST['session_date'] ?? null
        ) ? trim($_POST['session_date']) : '';

        $time = is_string(
            $_POST['start_time'] ?? null
        ) ? trim($_POST['start_time']) : '';

        $topic = is_string(
            $_POST['topic'] ?? null
        ) ? trim($_POST['topic']) : '';

        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date
        );

        if (
            !$parsed ||
            $parsed->format('Y-m-d') !== $date
        ) {

            $error = 'Enter a valid session date.';

        } elseif (!preg_match(
            '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
            $time
        )) {

            $error = 'Enter a valid start time.';

        } elseif (
            strlen($topic) > 190 ||
            preg_match('/[\x00-\x1F\x7F]/', $topic)
        ) {

            $error =
                'Topic must be 190 characters or fewer.';

        } else {

            try {

                $insert = db()->prepare(
                    'INSERT INTO class_sessions (
                        class_id,
                        session_date,
                        start_time,
                        topic
                     )
                     VALUES (?, ?, ?, ?)'
                );

                $insert->execute([
                    (int) $selected['id'],
                    $date,
                    $time,
                    $topic !== '' ? $topic : null
                ]);

                $sessionId = (int) db()->lastInsertId();

                flash(
                    'success',
                    'Attendance session created. Mark your students below.'
                );

                redirect(
                    'take-attendance.php?session_id=' .
                    $sessionId
                );

            } catch (PDOException $ex) {

                if ($ex->getCode() === '23000') {

                    $error =
                        'A session for that class, date, and start time already exists.';

                } else {

                    error_log(
                        'Create attendance session: ' .
                        $ex->getMessage()
                    );

                    $error =
                        'Could not create the session. Check your database connection.';
                }
            }
        }
    }
}

// LOAD EXISTING CLASS SESSIONS

$sessions = [];

if ($selected) {

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
        (int) $selected['id']
    ]);

    $sessions = $stmt->fetchAll();
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
        Attendance Sessions | <?= e(APP_NAME) ?>
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

    <!-- PAGE HEADING -->

    <div class="page-intro">

        <div>

            <span class="eyebrow dark">
                INSTRUCTOR / ATTENDANCE
            </span>

            <h1>Attendance Sessions</h1>

            <p class="subtle">
                Choose a class, create a meeting,
                and record attendance.
            </p>

        </div>

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

    <!-- NO CLASSES -->

    <?php if (!$classes): ?>

        <section class="surface attendance-panel">

            <h2>No classes yet</h2>

            <p>
                Create a class first, then enroll students.
            </p>

            <a
                class="button button-primary"
                href="classes.php"
            >
                Manage classes
            </a>

        </section>

    <?php else: ?>

        <!-- SELECT CLASS -->

        <section class="surface attendance-panel">

            <form
                method="get"
                action="attendance.php"
                class="attendance-class-picker"
            >

                <div class="field">

                    <label for="class_id">
                        Choose class
                    </label>

                    <select
                        id="class_id"
                        name="class_id"
                        required
                    >

                        <?php foreach ($classes as $class): ?>

                            <option
                                value="<?= (int) $class['id'] ?>"
                                <?= $selected &&
                                    (int) $selected['id'] ===
                                    (int) $class['id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e(
                                    $class['class_code'] .
                                    ' — ' .
                                    $class['class_name'] .
                                    ' / ' .
                                    $class['section']
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <button
                    class="button button-outline"
                    type="submit"
                >
                    View sessions
                </button>

            </form>

        </section>

        <?php if ($selected): ?>

            <div class="attendance-layout">

                <!-- CREATE SESSION -->

                <section class="surface attendance-panel">

                    <span class="eyebrow dark">
                        NEW MEETING
                    </span>

                    <h2>Create attendance session</h2>

                    <form
                        method="post"
                        action="attendance.php"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e(csrf_token()) ?>"
                        >

                        <input
                            type="hidden"
                            name="class_id"
                            value="<?= (int) $selected['id'] ?>"
                        >

                        <!-- SESSION DATE -->

                        <div class="field">

                            <label for="session_date">
                                Session date
                            </label>

                            <input
                                id="session_date"
                                name="session_date"
                                type="date"
                                value="<?= e(
                                    is_string(
                                        $_POST['session_date'] ?? null
                                    )
                                        ? $_POST['session_date']
                                        : date('Y-m-d')
                                ) ?>"
                                required
                            >

                        </div>

                        <!-- START TIME -->

                        <div class="field">

                            <label for="start_time">
                                Start time
                            </label>

                            <input
                                id="start_time"
                                name="start_time"
                                type="time"
                                value="<?= e(
                                    is_string(
                                        $_POST['start_time'] ?? null
                                    )
                                        ? $_POST['start_time']
                                        : '08:00'
                                ) ?>"
                                required
                            >

                        </div>

                        <!-- TOPIC -->

                        <div class="field">

                            <label for="topic">
                                Topic (optional)
                            </label>

                            <input
                                id="topic"
                                name="topic"
                                type="text"
                                maxlength="190"
                                placeholder="Introduction to PHP"
                                value="<?= e(
                                    is_string(
                                        $_POST['topic'] ?? null
                                    )
                                        ? $_POST['topic']
                                        : ''
                                ) ?>"
                            >

                        </div>

                        <button
                            class="button button-primary button-full"
                            type="submit"
                        >
                            Create session
                        </button>

                    </form>

                </section>

                <!-- SESSIONS LIST -->

                <section class="surface attendance-panel">

                    <span class="eyebrow dark">
                        CLASS MEETINGS
                    </span>

                    <h2>
                        Existing sessions
                        (<?= count($sessions) ?>)
                    </h2>

                    <?php if (!$sessions): ?>

                        <p class="subtle">
                            No sessions yet. Create the first
                            meeting using the form.
                        </p>

                    <?php else: ?>

                        <div class="attendance-table-wrap">

                            <table class="attendance-table">

                                <thead>
                                    <tr>
                                        <th>Date / Time</th>
                                        <th>Topic</th>
                                        <th>Recorded</th>
                                        <th>Actions</th>
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

                                            <?= (int) $session['recorded'] ?>
                                            marked

                                            <small>
                                                <?= (int) $session['present_count'] ?>
                                                Present ·

                                                <?= (int) $session['late_count'] ?>
                                                Late ·

                                                <?= (int) $session['absent_count'] ?>
                                                Absent
                                            </small>

                                        </td>

                                        <td>

                                            <a
                                                href="take-attendance.php?session_id=<?= (int) $session['id'] ?>"
                                            >
                                                Mark / Edit
                                            </a>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                    <p class="attendance-link-row">

                        <a
                            href="attendance-history.php?class_id=<?= (int) $selected['id'] ?>"
                        >
                            View complete attendance history
                        </a>

                    </p>

                </section>

            </div>

        <?php endif; ?>

    <?php endif; ?>

    <footer class="page-footer">
        CampusTrack · Attendance Recording
    </footer>

</main>

</body>
</html>
