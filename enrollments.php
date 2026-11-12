<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

// Only instructors can manage students in their own classes.
$user = require_user('instructor');
$instructorId = (int) $user['id'];
$notice = take_flash();
$errors = [];

// Handle changes before loading the page data.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';
    $classId = filter_var(
        $_POST['class_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $studentId = filter_var(
        $_POST['student_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!is_int($classId) || !is_int($studentId)) {
        flash('error', 'Select a valid class and student.');
        redirect('enrollments.php');
    }

    $back = 'enrollments.php?class_id=' . $classId;

    // Verify the class belongs to this instructor.
    $stmt = db()->prepare(
        'SELECT id FROM classes
         WHERE id = ? AND instructor_id = ? LIMIT 1'
    );
    $stmt->execute([$classId, $instructorId]);

    if (!$stmt->fetch()) {
        flash('error', 'You cannot manage that class.');
        redirect('enrollments.php');
    }

    // ENROLL STUDENT
    if ($action === 'enroll') {

        // Only active registered student accounts are eligible.
        $stmt = db()->prepare(
            "SELECT s.id FROM students s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND u.role = 'student'
               AND u.is_active = 1 LIMIT 1"
        );
        $stmt->execute([$studentId]);

        if (!$stmt->fetch()) {
            flash(
                'error',
                'The selected student is not available.'
            );
            redirect($back);
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO enrollments (class_id, student_id)
                 VALUES (?, ?)'
            );
            $stmt->execute([$classId, $studentId]);

            flash('success', 'Student enrolled successfully.');

        } catch (PDOException $exception) {

            if ($exception->getCode() === '23000') {
                flash(
                    'error',
                    'This student is already enrolled in the class.'
                );
            } else {
                error_log(
                    'Enrollment error: ' .
                    $exception->getMessage()
                );
                flash(
                    'error',
                    'Unable to enroll the student.'
                );
            }
        }

        redirect($back);
    }

    // REMOVE STUDENT FROM A CLASS
    if ($action === 'remove') {

        // Preserve attendance records.
        // A student with recorded attendance cannot be removed.
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM attendance a
             JOIN class_sessions cs ON cs.id = a.session_id
             WHERE cs.class_id = ? AND a.student_id = ?'
        );
        $stmt->execute([$classId, $studentId]);

        if ((int) $stmt->fetchColumn() > 0) {
            flash(
                'error',
                'Cannot remove a student with recorded attendance in this class.'
            );
            redirect($back);
        }

        $stmt = db()->prepare(
            'DELETE FROM enrollments
             WHERE class_id = ? AND student_id = ?'
        );
        $stmt->execute([$classId, $studentId]);

        flash(
            $stmt->rowCount() === 1 ? 'success' : 'error',
            $stmt->rowCount() === 1
                ? 'Student removed from this class.'
                : 'That student is not enrolled in this class.'
        );

        redirect($back);
    }

    flash('error', 'Unknown enrollment action.');
    redirect($back);
}

// LOAD CLASSES OWNED BY THE INSTRUCTOR
$stmt = db()->prepare(
    'SELECT id, class_code, class_name, section,
            school_year, semester
     FROM classes
     WHERE instructor_id = ?
     ORDER BY school_year DESC, semester,
              class_code, section'
);
$stmt->execute([$instructorId]);

$classes = $stmt->fetchAll();

$selectedClass = null;
$selectedClassId = 0;

// SELECT CLASS
if ($classes) {

    if (isset($_GET['class_id'])) {

        $requested = filter_var(
            $_GET['class_id'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        foreach ($classes as $class) {
            if (
                is_int($requested) &&
                (int) $class['id'] === $requested
            ) {
                $selectedClass = $class;
                break;
            }
        }

        if ($selectedClass === null) {
            $errors[] =
                'That class was not found in your account.';
        }

    } else {
        $selectedClass = $classes[0];
    }
}

if ($selectedClass !== null) {
    $selectedClassId = (int) $selectedClass['id'];
}

$enrolled = [];
$candidates = [];

$search = is_string($_GET['q'] ?? null)
    ? trim($_GET['q'])
    : '';

if (strlen($search) > 100) {
    $errors[] = 'Search must be 100 characters or fewer.';
    $search = '';
}

// LOAD ROSTER AND SEARCH RESULTS
if ($selectedClass !== null) {

    $stmt = db()->prepare(
        "SELECT
            s.id AS student_id,
            s.student_number,
            s.program,
            s.year_level,
            u.full_name,
            u.email,
            u.is_active,
            (
                SELECT COUNT(*)
                FROM attendance a
                JOIN class_sessions cs
                    ON cs.id = a.session_id
                WHERE cs.class_id = e.class_id
                  AND a.student_id = e.student_id
            ) AS attendance_count

         FROM enrollments e
         JOIN students s ON s.id = e.student_id
         JOIN users u ON u.id = s.user_id
         WHERE e.class_id = ?
         ORDER BY u.full_name, s.student_number"
    );

    $stmt->execute([$selectedClassId]);
    $enrolled = $stmt->fetchAll();

    if ($search !== '' && strlen($search) < 2) {

        $errors[] =
            'Enter at least 2 characters to search for a student.';

    } elseif ($search !== '') {

        // Escape SQL LIKE wildcards.
        $escaped = str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $search
        );

        $pattern = '%' . $escaped . '%';

        $stmt = db()->prepare(
            "SELECT
                s.id AS student_id,
                s.student_number,
                s.program,
                s.year_level,
                u.full_name,
                u.email

             FROM students s
             JOIN users u ON u.id = s.user_id

             LEFT JOIN enrollments e
                ON e.student_id = s.id
               AND e.class_id = ?

             WHERE u.role = 'student'
               AND u.is_active = 1
               AND e.id IS NULL
               AND (
                    s.student_number LIKE ? ESCAPE '!'
                    OR u.full_name LIKE ? ESCAPE '!'
                    OR u.email LIKE ? ESCAPE '!'
               )

             ORDER BY u.full_name, s.student_number
             LIMIT 20"
        );

        $stmt->execute([
            $selectedClassId,
            $pattern,
            $pattern,
            $pattern
        ]);

        $candidates = $stmt->fetchAll();
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

    <title>Student Enrollment | <?= e(APP_NAME) ?></title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/portal.css"
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
                INSTRUCTOR WORKSPACE / ENROLLMENT
            </span>

            <h1>Student Enrollment</h1>

            <p class="subtle">
                Find registered students and assign
                them to your classes.
            </p>
        </div>

        <a
            href="classes.php"
            class="button button-outline"
        >
            Manage classes
        </a>

    </div>

    <!-- FLASH MESSAGE -->

    <?php if ($notice): ?>

        <div
            class="notice notice-<?= e($notice['type']) ?>"
            role="status"
        >
            <?= e($notice['message']) ?>
        </div>

    <?php endif; ?>

    <!-- ERRORS -->

    <?php if ($errors): ?>

        <div class="notice notice-error" role="alert">

            <?php foreach ($errors as $error): ?>

                <p><?= e($error) ?></p>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <!-- NO CLASSES CREATED -->

    <?php if (!$classes): ?>

        <section class="surface classes-panel">

            <h2>Create a class first</h2>

            <p class="subtle">
                You need an instructor class before
                you can enroll students.
            </p>

            <a
                class="button button-primary"
                href="classes.php"
            >
                Create a class
            </a>

        </section>

    <?php else: ?>

        <div class="classes-layout">

            <!-- CLASS AND STUDENT SEARCH -->

            <section class="surface classes-panel">

                <span class="eyebrow dark">
                    SELECT CLASS &amp; STUDENT
                </span>

                <h2>Find students</h2>

                <p class="subtle">
                    Search registered students by name,
                    student number, or PLM email.
                </p>

                <form
                    method="get"
                    action="enrollments.php"
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
                                    <?= (int) $class['id'] === $selectedClassId
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e(
                                        $class['class_code'] .
                                        ' — ' .
                                        $class['section'] .
                                        ' (' .
                                        $class['school_year'] .
                                        ')'
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="field">

                        <label for="q">
                            Student search
                        </label>

                        <input
                            id="q"
                            name="q"
                            type="search"
                            maxlength="100"
                            value="<?= e($search) ?>"
                            placeholder="Name, student no., or PLM email"
                        >

                    </div>

                    <button
                        type="submit"
                        class="button button-primary button-full"
                    >
                        View class / Search
                    </button>

                </form>

                <!-- MATCHING REGISTERED STUDENTS -->

                <?php if (
                    $search !== '' &&
                    strlen($search) >= 2 &&
                    $selectedClass
                ): ?>

                    <h2 style="margin-top: 30px;">
                        Search results
                    </h2>

                    <p class="subtle">
                        Showing up to 20 registered students
                        not yet enrolled in this class.
                    </p>

                    <?php if (!$candidates): ?>

                        <div class="classes-empty">

                            <strong>
                                No available students found
                            </strong>

                            <p>
                                Try a different name, email,
                                or student number. The student
                                may already be enrolled.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="classes-table-wrap">

                            <table class="classes-table">

                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>

                                <tbody>

                                <?php foreach ($candidates as $candidate): ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= e($candidate['full_name']) ?>
                                            </strong>

                                            <span class="table-detail">
                                                <?= e($candidate['student_number']) ?>
                                            </span>

                                            <span class="table-detail">
                                                <?= e($candidate['email']) ?>
                                            </span>

                                            <span class="table-detail">
                                                <?= e($candidate['program']) ?>
                                            </span>
                                        </td>

                                        <td>

                                            <form
                                                method="post"
                                                action="enrollments.php"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e(csrf_token()) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="enroll"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="class_id"
                                                    value="<?= $selectedClassId ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="student_id"
                                                    value="<?= (int) $candidate['student_id'] ?>"
                                                >

                                                <button
                                                    class="button button-primary button-small"
                                                    type="submit"
                                                >
                                                    Enroll
                                                </button>

                                            </form>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </section>

            <!-- CLASS ENROLLMENT LIST -->

            <section class="surface classes-panel classes-list-panel">

                <span class="eyebrow dark">
                    CLASS ROSTER
                </span>

                <h2>
                    Enrolled students
                    (<?= count($enrolled) ?>)
                </h2>

                <?php if ($selectedClass): ?>

                    <p class="subtle">
                        <?= e($selectedClass['class_code']) ?>
                        —
                        <?= e($selectedClass['class_name']) ?>,
                        Section
                        <?= e($selectedClass['section']) ?>
                    </p>

                    <?php if (!$enrolled): ?>

                        <div class="classes-empty">

                            <strong>
                                No students enrolled yet
                            </strong>

                            <p>
                                Search for a registered student
                                and click Enroll.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="classes-table-wrap">

                            <table class="classes-table">

                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Program</th>
                                        <th>Attendance</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>

                                <tbody>

                                <?php foreach ($enrolled as $person): ?>

                                    <tr>

                                        <td>

                                            <strong>
                                                <?= e($person['full_name']) ?>
                                            </strong>

                                            <span class="table-detail">
                                                <?= e($person['student_number']) ?>
                                            </span>

                                            <span class="table-detail">
                                                <?= e($person['email']) ?>
                                            </span>

                                        </td>

                                        <td>

                                            <?= e($person['program']) ?>

                                            <span class="table-detail">
                                                Year
                                                <?= (int) $person['year_level'] ?>
                                            </span>

                                        </td>

                                        <td>
                                            <?= (int) $person['attendance_count'] ?>
                                            recorded
                                        </td>

                                        <td>

                                            <?php if (
                                                (int) $person['attendance_count'] > 0
                                            ): ?>

                                                <span
                                                    class="table-detail"
                                                    title="Student has recorded attendance"
                                                >
                                                    Cannot remove
                                                </span>

                                            <?php else: ?>

                                                <form
                                                    method="post"
                                                    action="enrollments.php"
                                                    onsubmit="return confirm('Remove this student from the class?');"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e(csrf_token()) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="remove"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="class_id"
                                                        value="<?= $selectedClassId ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="student_id"
                                                        value="<?= (int) $person['student_id'] ?>"
                                                    >

                                                    <button
                                                        class="class-delete"
                                                        type="submit"
                                                    >
                                                        Remove
                                                    </button>

                                                </form>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                <?php else: ?>

                    <div class="classes-empty">

                        <strong>
                            Select one of your classes
                        </strong>

                        <p>
                            Use the dropdown on the left
                            to open a valid class.
                        </p>

                    </div>

                <?php endif; ?>

            </section>

        </div>

    <?php endif; ?>

    <footer class="page-footer">
        CampusTrack · Student Enrollment · Phase 2B
    </footer>

</main>

</body>
</html>
