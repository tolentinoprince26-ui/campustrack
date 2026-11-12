<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

// Only instructors may access Class Management.
$user = require_user('instructor');
$instructorId = (int) $user['id'];

$notice = take_flash();
$errors = [];
$editingId = 0;

// Default form values.
$values = [
    'class_code' => '',
    'class_name' => '',
    'section' => '',
    'school_year' => date('Y') . '-' . (date('Y') + 1),
    'semester' => '1st Semester',
];

$semesters = [
    '1st Semester',
    '2nd Semester',
    'Summer'
];

// PROCESS CREATE, UPDATE, AND DELETE

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    // CREATE OR UPDATE

    if ($action === 'save') {

        $editingId = filter_var(
            $_POST['class_id'] ?? '',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        ) ?: 0;

        foreach ($values as $field => $default) {
            $input = $_POST[$field] ?? '';

            $values[$field] = is_string($input)
                ? trim($input)
                : '';
        }

        // CLASS CODE VALIDATION

        if (!preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9._ -]{1,29}$/',
            $values['class_code']
        )) {
            $errors[] =
                'Class code must have 2–30 letters, numbers, spaces, dots, underscores, or hyphens.';
        }

        // CLASS NAME VALIDATION

        if (
            strlen($values['class_name']) < 3 ||
            strlen($values['class_name']) > 120 ||
            preg_match(
                '/[\x00-\x1F\x7F]/',
                $values['class_name']
            )
        ) {
            $errors[] =
                'Class name must be 3–120 characters without control characters.';
        }

        // SECTION VALIDATION

        if (!preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9._ -]{0,29}$/',
            $values['section']
        )) {
            $errors[] =
                'Section must have 1–30 letters, numbers, spaces, dots, underscores, or hyphens.';
        }

        // SCHOOL YEAR VALIDATION

        if (
            !preg_match(
                '/^(20\d{2})-(20\d{2})$/',
                $values['school_year'],
                $yearParts
            ) ||
            (int) ($yearParts[2] ?? 0) !==
                (int) ($yearParts[1] ?? 0) + 1
        ) {
            $errors[] =
                'School year must use consecutive years, such as 2026-2027.';
        }

        // SEMESTER VALIDATION

        if (!in_array(
            $values['semester'],
            $semesters,
            true
        )) {
            $errors[] = 'Select a valid semester.';
        }

        // CONFIRM CLASS OWNERSHIP BEFORE EDITING

        if ($editingId > 0 && !$errors) {

            $owner = db()->prepare(
                'SELECT id
                 FROM classes
                 WHERE id = ? AND instructor_id = ?
                 LIMIT 1'
            );

            $owner->execute([
                $editingId,
                $instructorId
            ]);

            if (!$owner->fetch()) {
                $errors[] =
                    'That class was not found in your account.';
            }
        }

        // SAVE VALID CLASS INFORMATION

        if (!$errors) {

            try {

                if ($editingId > 0) {

                    $stmt = db()->prepare(
                        'UPDATE classes
                         SET
                            class_code = ?,
                            class_name = ?,
                            section = ?,
                            school_year = ?,
                            semester = ?
                         WHERE id = ?
                           AND instructor_id = ?'
                    );

                    $stmt->execute([
                        $values['class_code'],
                        $values['class_name'],
                        $values['section'],
                        $values['school_year'],
                        $values['semester'],
                        $editingId,
                        $instructorId,
                    ]);

                    flash(
                        'success',
                        'Class updated successfully.'
                    );

                } else {

                    $stmt = db()->prepare(
                        'INSERT INTO classes (
                            instructor_id,
                            class_code,
                            class_name,
                            section,
                            school_year,
                            semester
                         )
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );

                    $stmt->execute([
                        $instructorId,
                        $values['class_code'],
                        $values['class_name'],
                        $values['section'],
                        $values['school_year'],
                        $values['semester'],
                    ]);

                    flash(
                        'success',
                        'Class created successfully.'
                    );

                }

                redirect('classes.php');

            } catch (PDOException $exception) {

                if ($exception->getCode() === '23000') {

                    $errors[] =
                        'You already have that class code and section for this school year and semester.';

                } else {

                    error_log(
                        'Class save error: ' .
                        $exception->getMessage()
                    );

                    $errors[] =
                        'Unable to save the class. Please try again.';
                }
            }
        }

    // DELETE AN EMPTY CLASS

    } elseif ($action === 'delete') {

        $deleteId = filter_var(
            $_POST['class_id'] ?? '',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($deleteId === false) {

            $errors[] = 'Choose a valid class to delete.';

        } else {

            try {

                // Prevent deleting classes that have
                // enrolled students or attendance sessions.

                $stmt = db()->prepare(
                    'DELETE FROM classes
                     WHERE id = ?
                       AND instructor_id = ?
                       AND NOT EXISTS (
                           SELECT 1 FROM enrollments
                           WHERE class_id = ?
                       )
                       AND NOT EXISTS (
                           SELECT 1 FROM class_sessions
                           WHERE class_id = ?
                       )'
                );

                $stmt->execute([
                    $deleteId,
                    $instructorId,
                    $deleteId,
                    $deleteId,
                ]);

                if ($stmt->rowCount() === 1) {

                    flash(
                        'success',
                        'Empty class deleted successfully.'
                    );

                    redirect('classes.php');
                }

                $errors[] =
                    'Class not found or it already has students/sessions. Those classes cannot be deleted.';

            } catch (PDOException $exception) {

                error_log(
                    'Class delete error: ' .
                    $exception->getMessage()
                );

                $errors[] = 'Unable to delete the class.';
            }
        }

    } else {

        $errors[] = 'Unrecognized action.';
    }
}

// LOAD EXISTING CLASS WHEN EDITING

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['edit'])
) {

    $editId = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($editId === false) {

        $errors[] = 'Invalid class ID.';

    } else {

        $stmt = db()->prepare(
            'SELECT
                id,
                class_code,
                class_name,
                section,
                school_year,
                semester
             FROM classes
             WHERE id = ?
               AND instructor_id = ?
             LIMIT 1'
        );

        $stmt->execute([
            $editId,
            $instructorId
        ]);

        $existing = $stmt->fetch();

        if ($existing) {

            $editingId = (int) $existing['id'];

            foreach ($values as $field => $default) {
                $values[$field] = $existing[$field];
            }

        } else {

            $errors[] =
                'Class not found in your account.';
        }
    }
}

// GET ALL CLASSES OWNED BY THIS INSTRUCTOR

$stmt = db()->prepare(
    'SELECT
        c.id,
        c.class_code,
        c.class_name,
        c.section,
        c.school_year,
        c.semester,

        (
            SELECT COUNT(*)
            FROM enrollments e
            WHERE e.class_id = c.id
        ) AS student_count,

        (
            SELECT COUNT(*)
            FROM class_sessions s
            WHERE s.class_id = c.id
        ) AS session_count

     FROM classes c
     WHERE c.instructor_id = ?
     ORDER BY
        c.school_year DESC,
        c.semester,
        c.class_code,
        c.section'
);

$stmt->execute([$instructorId]);

$classes = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Manage Classes | <?= e(APP_NAME) ?></title>

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
                INSTRUCTOR WORKSPACE / CLASSES
            </span>

            <h1>Class Management</h1>

            <p class="subtle">
                Create, edit, and organize classes
                assigned to your account.
            </p>
        </div>

        <span class="date-badge">
            <?= count($classes) ?>
            <?= count($classes) === 1
                ? 'class'
                : 'classes' ?>
        </span>

    </div>

    <!-- SUCCESS MESSAGE -->

    <?php if ($notice): ?>

        <div
            class="notice notice-<?= e($notice['type']) ?>"
            role="status"
        >
            <?= e($notice['message']) ?>
        </div>

    <?php endif; ?>

    <!-- VALIDATION ERRORS -->

    <?php if ($errors): ?>

        <div class="notice notice-error" role="alert">

            <?php foreach ($errors as $error): ?>

                <p><?= e($error) ?></p>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <div class="classes-layout">

        <!-- CLASS FORM -->

        <section
            class="surface classes-panel"
            id="class-form"
        >

            <span class="eyebrow dark">
                <?= $editingId
                    ? 'EDIT CLASS'
                    : 'NEW CLASS' ?>
            </span>

            <h2>
                <?= $editingId
                    ? 'Update class details'
                    : 'Create a class' ?>
            </h2>

            <p class="subtle">
                Class codes and sections help distinguish
                attendance groups.
            </p>

            <form
                action="classes.php"
                method="post"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="save"
                >

                <input
                    type="hidden"
                    name="class_id"
                    value="<?= $editingId ?>"
                >

                <!-- CLASS CODE -->

                <div class="field">

                    <label for="class_code">
                        Class code
                    </label>

                    <input
                        id="class_code"
                        name="class_code"
                        maxlength="30"
                        required
                        value="<?= e($values['class_code']) ?>"
                        placeholder="IT 301"
                    >

                </div>

                <!-- SUBJECT NAME -->

                <div class="field">

                    <label for="class_name">
                        Class / Subject name
                    </label>

                    <input
                        id="class_name"
                        name="class_name"
                        maxlength="120"
                        required
                        value="<?= e($values['class_name']) ?>"
                        placeholder="Web Systems Technology"
                    >

                </div>

                <!-- SECTION -->

                <div class="field">

                    <label for="section">
                        Section
                    </label>

                    <input
                        id="section"
                        name="section"
                        maxlength="30"
                        required
                        value="<?= e($values['section']) ?>"
                        placeholder="BSIT 3A"
                    >

                </div>

                <!-- SCHOOL YEAR AND SEMESTER -->

                <div class="classes-form-grid">

                    <div class="field">

                        <label for="school_year">
                            School year
                        </label>

                        <input
                            id="school_year"
                            name="school_year"
                            maxlength="9"
                            pattern="20[0-9]{2}-20[0-9]{2}"
                            required
                            value="<?= e($values['school_year']) ?>"
                            placeholder="2026-2027"
                        >

                    </div>

                    <div class="field">

                        <label for="semester">
                            Semester
                        </label>

                        <select
                            id="semester"
                            name="semester"
                            required
                        >

                            <?php foreach ($semesters as $semester): ?>

                                <option
                                    value="<?= e($semester) ?>"
                                    <?= $values['semester'] === $semester
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($semester) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

                <!-- SAVE BUTTON -->

                <button
                    type="submit"
                    class="button button-primary button-full"
                >
                    <?= $editingId
                        ? 'Save changes'
                        : 'Create class' ?>
                </button>

                <?php if ($editingId): ?>

                    <a
                        class="button button-outline button-full form-cancel"
                        href="classes.php"
                    >
                        Cancel editing
                    </a>

                <?php endif; ?>

            </form>

        </section>

        <!-- CLASS LIST -->

        <section class="surface classes-panel classes-list-panel">

            <span class="eyebrow dark">
                YOUR CLASSES
            </span>

            <h2>Class records</h2>

            <p class="subtle">
                Once students and sessions are added,
                their totals will appear here.
            </p>

            <?php if (!$classes): ?>

                <div class="classes-empty">

                    <strong>
                        No classes created yet
                    </strong>

                    <p>
                        Create your first class using the form.
                    </p>

                </div>

            <?php else: ?>

                <div class="classes-table-wrap">

                    <table class="classes-table">

                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Academic term</th>
                                <th>Students</th>
                                <th>Sessions</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($classes as $class): ?>

                            <tr>

                                <td>
                                    <strong>
                                        <?= e($class['class_code']) ?>
                                    </strong>

                                    <span class="table-detail">
                                        <?= e($class['class_name']) ?>
                                    </span>

                                    <span class="table-detail">
                                        Section <?= e($class['section']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= e($class['school_year']) ?>

                                    <span class="table-detail">
                                        <?= e($class['semester']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= (int) $class['student_count'] ?>
                                </td>

                                <td>
                                    <?= (int) $class['session_count'] ?>
                                </td>

                                <td>

                                    <div class="class-actions">

                                        <a
                                            class="class-action-link"
                                            href="classes.php?edit=<?= (int) $class['id'] ?>#class-form"
                                        >
                                            Edit
                                        </a>

                                        <?php if (
                                            (int) $class['student_count'] === 0 &&
                                            (int) $class['session_count'] === 0
                                        ): ?>

                                            <form
                                                method="post"
                                                action="classes.php"
                                                onsubmit="return confirm('Delete this empty class? This cannot be undone.');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e(csrf_token()) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="class_id"
                                                    value="<?= (int) $class['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="class-delete"
                                                >
                                                    Delete
                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <span
                                                class="table-detail"
                                                title="Classes with students or sessions cannot be deleted"
                                            >
                                                In use
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

    </div>

    <footer class="page-footer">
        CampusTrack · Class Management · Phase 2
    </footer>

</main>

</body>
</html>
