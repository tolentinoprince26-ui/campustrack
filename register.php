<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

if (current_user() !== null) {
    redirect('dashboard.php');
}

// PROGRAM AND COLLEGE LIST
// From the provided PLM FIND registration reference.

$programColleges = [
    'Bachelor of Science in Accountancy' =>
        'College of Business Administration',

    'Bachelor of Science in Architecture' =>
        'College of Architecture and Sustainable Built Environments',

    'Bachelor of Science in Business Administration - Marketing Management' =>
        'College of Business Administration',

    'Bachelor of Science in Business Administration - Financial Management' =>
        'College of Business Administration',

    'Bachelor of Science in Business Administration - Human Resource Management' =>
        'College of Business Administration',

    'BSEd Major in Mathematics' =>
        'College of Education',

    'BSEd Major in Science' =>
        'College of Education',

    'BSEd Major in English' =>
        'College of Education',

    'BSEd Major in Social Studies' =>
        'College of Education',

    'BSEd Major in Filipino' =>
        'College of Education',

    'BSEd Major in Physical Education' =>
        'College of Education',

    'Bachelor of Science in Civil Engineering' =>
        'College of Engineering',

    'Bachelor of Science in Electrical Engineering' =>
        'College of Engineering',

    'Bachelor of Science in Electronics Engineering' =>
        'College of Engineering',

    'Bachelor of Arts in Communication' =>
        'College of Humanities & Social Sciences',

    'Bachelor of Arts in Political Science' =>
        'College of Humanities & Social Sciences',

    'Bachelor of Arts in Psychology' =>
        'College of Humanities & Social Sciences',

    'Bachelor of Science in Information Technology' =>
        'College of Information Systems and Technology Management',

    'Bachelor of Science in Computer Science' =>
        'College of Information Systems and Technology Management',

    'Bachelor of Science in Information Systems' =>
        'College of Information Systems and Technology Management',

    'Juris Doctor' =>
        'College of Law',

    'Doctor of Medicine' =>
        'College of Medicine',

    'Bachelor of Science in Nursing' =>
        'College of Nursing',

    'Bachelor of Science in Physical Therapy' =>
        'College of Physical Therapy',

    'Bachelor of Public Administration' =>
        'College of Public Administration',

    'Bachelor of Science in Biology' =>
        'College of Science',

    'Bachelor of Science in Chemistry' =>
        'College of Science',

    'Bachelor of Science in Mathematics' =>
        'College of Science',

    'Bachelor of Science in Tourism Management' =>
        'College of Tourism and Hospitality Management',

    'Bachelor of Science in Hospitality Management' =>
        'College of Tourism and Hospitality Management',

    'Bachelor of Science in Pharmacy' =>
        'College of Pharmacy',

    'Bachelor of Science in Radiologic Technology' =>
        'College of Allied Health',
];

// Preserve fields after failed validation.

$old = [
    'full_name' => '',
    'student_number' => '',
    'year_level' => '',
    'program' => '',
    'email' => '',
];

$error = '';

// REGISTRATION PROCESS

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach (array_keys($old) as $field) {
        $input = $_POST[$field] ?? '';
        $old[$field] = is_string($input)
            ? trim($input)
            : '';
    }

    $name = $old['full_name'];
    $studentNumber = $old['student_number'];
    $program = $old['program'];
    $email = strtolower($old['email']);
    $old['email'] = $email;

    $year = filter_var(
        $old['year_level'],
        FILTER_VALIDATE_INT
    );

    $password = is_string($_POST['password'] ?? null)
        ? $_POST['password']
        : '';

    $confirmation = is_string(
        $_POST['confirm_password'] ?? null
    )
        ? $_POST['confirm_password']
        : '';

    try {

        // FULL NAME VALIDATION

        $nameLength = function_exists('mb_strlen')
            ? mb_strlen($name, 'UTF-8')
            : strlen($name);

        if (
            $nameLength < 3 ||
            $nameLength > 120 ||
            !preg_match(
                '/^[\p{L}][\p{L}\p{M} .,\x{2019}\'-]*$/u',
                $name
            )
        ) {
            throw new RuntimeException(
                'Enter a valid full name using letters.'
            );
        }

        // STUDENT NUMBER VALIDATION

        if (!preg_match(
            '/^\d{4}-\d{5}$/',
            $studentNumber
        )) {
            throw new RuntimeException(
                'Student number must follow YYYY-12345.'
            );
        }

        // PROGRAM VALIDATION

        if (!array_key_exists(
            $program,
            $programColleges
        )) {
            throw new RuntimeException(
                'Select a valid program from the list.'
            );
        }

        // YEAR LEVEL VALIDATION

        if (
            $year === false ||
            $year < 1 ||
            $year > 7
        ) {
            throw new RuntimeException(
                'Choose a valid year level.'
            );
        }

        // PLM EMAIL VALIDATION

        if (
            strlen($email) > 190 ||
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) ||
            !preg_match(
                '/@plm\.edu\.ph$/i',
                $email
            )
        ) {
            throw new RuntimeException(
                'Only valid @plm.edu.ph email addresses are allowed.'
            );
        }

        // PASSWORD VALIDATION

        if (
            strlen($password) < 10 ||
            !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password)
        ) {
            throw new RuntimeException(
                'Password needs 10+ characters, uppercase, lowercase, and a number.'
            );
        }

        // PASSWORD CONFIRMATION

        if ($password !== $confirmation) {
            throw new RuntimeException(
                'Passwords do not match.'
            );
        }

        // COLLEGE ASSIGNMENT ON SERVER
        // Do not trust college values sent by users.

        $college = $programColleges[$program];

        // SAVE USER AND STUDENT IN ONE TRANSACTION

        $pdo = db();
        $pdo->beginTransaction();

        $insertUser = $pdo->prepare(
            "INSERT INTO users (
                full_name,
                email,
                password_hash,
                role
            )
            VALUES (?, ?, ?, 'student')"
        );

        $insertUser->execute([
            $name,
            $email,
            password_hash(
                $password,
                PASSWORD_DEFAULT
            )
        ]);

        $userId = (int) $pdo->lastInsertId();

        $insertStudent = $pdo->prepare(
            'INSERT INTO students (
                user_id,
                student_number,
                program,
                college,
                year_level
            )
            VALUES (?, ?, ?, ?, ?)'
        );

        $insertStudent->execute([
            $userId,
            $studentNumber,
            $program,
            $college,
            $year
        ]);

        $pdo->commit();

        flash(
            'success',
            'Registration successful. You can now sign in.'
        );

        redirect('login.php');

    } catch (RuntimeException $exception) {

        $error = $exception->getMessage();

    } catch (PDOException $exception) {

        if (
            isset($pdo) &&
            $pdo instanceof PDO &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        if ($exception->getCode() === '23000') {

            $error =
                'That email or student number is already registered.';

        } else {

            error_log(
                'CampusTrack registration: ' .
                $exception->getMessage()
            );

            $error =
                'Registration could not be completed. Check the database setup.';
        }
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

    <meta name="theme-color" content="#10264a">

    <title>Student Registration | <?= e(APP_NAME) ?></title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

    <style>
        .college-preview {
            background: #f0f4fa !important;
            color: #53647d !important;
        }

        .register-help {
            margin: 3px 0 20px;
            color: #718099;
            font-size: 12px;
        }

        .show-password-row {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            margin: 1px 0 23px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
        }

        .show-password-row input {
            width: auto;
            accent-color: #2763dc;
        }

        .field input.invalid {
            border-color: #be334b;
        }
    </style>
</head>
<body>

<main class="auth-shell">

    <!-- LEFT UNIVERSITY PORTAL PANEL -->

    <aside class="auth-hero">

        <a class="brand brand-inverse" href="index.php">
            <span class="brand-icon">C</span>

            <span>
                <strong>CampusTrack</strong>
                <small>UNIVERSITY PORTAL</small>
            </span>
        </a>

        <div class="hero-copy">

            <span class="eyebrow">
                STUDENT REGISTRATION
            </span>

            <h1>
                Your attendance.
                <br>
                <span>Your progress.</span>
            </h1>

            <p>
                Register using your PLM student information
                and monitor your class participation.
            </p>

        </div>

        <span class="hero-foot">
            MODERN UNIVERSITY PORTAL
        </span>

    </aside>

    <!-- REGISTRATION PANEL -->

    <section class="auth-content">

        <div class="auth-card auth-card-wide">

            <span class="eyebrow dark">
                CREATE AN ACCOUNT
            </span>

            <h2>Student registration</h2>

            <p class="subtle">
                Enter your PLM student details below.
            </p>

            <?php if ($error !== ''): ?>
                <div class="notice notice-error" role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form
                method="post"
                action="register.php"
                id="registrationForm"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <!-- FULL NAME -->

                <div class="field">

                    <label for="full_name">
                        Full name
                    </label>

                    <input
                        id="full_name"
                        name="full_name"
                        type="text"
                        maxlength="120"
                        autocomplete="name"
                        placeholder="Juan Dela Cruz"
                        value="<?= e($old['full_name']) ?>"
                        required
                    >

                </div>

                <!-- STUDENT NUMBER AND YEAR LEVEL -->

                <div class="field-grid">

                    <div class="field">

                        <label for="student_number">
                            Student number
                        </label>

                        <input
                            id="student_number"
                            name="student_number"
                            type="text"
                            maxlength="10"
                            inputmode="numeric"
                            pattern="[0-9]{4}-[0-9]{5}"
                            placeholder="2026-12345"
                            value="<?= e($old['student_number']) ?>"
                            required
                        >

                    </div>

                    <div class="field">

                        <label for="year_level">
                            Year level
                        </label>

                        <select
                            id="year_level"
                            name="year_level"
                            required
                        >

                            <option value="">
                                Select year level
                            </option>

                            <?php for ($yearOption = 1; $yearOption <= 7; $yearOption++): ?>

                                <option
                                    value="<?= $yearOption ?>"
                                    <?= $old['year_level'] === (string) $yearOption
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Year <?= $yearOption ?>
                                </option>

                            <?php endfor; ?>

                        </select>

                    </div>

                </div>

                <!-- PROGRAM SELECTION -->

                <div class="field">

                    <label for="program">
                        Program / Course
                    </label>

                    <select
                        id="program"
                        name="program"
                        required
                    >

                        <option value="">
                            Select program
                        </option>

                        <?php foreach (
                            $programColleges as $programName => $collegeName
                        ): ?>

                            <option
                                value="<?= e($programName) ?>"
                                data-college="<?= e($collegeName) ?>"
                                <?= $old['program'] === $programName
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($programName) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <!-- AUTOMATIC COLLEGE -->

                <div class="field">

                    <label for="college">
                        College
                    </label>

                    <input
                        id="college"
                        class="college-preview"
                        type="text"
                        readonly
                        placeholder="Select a program first"
                        value="<?= e(
                            $programColleges[$old['program']] ?? ''
                        ) ?>"
                    >

                </div>

                <!-- PLM EMAIL -->

                <div class="field">

                    <label for="email">
                        PLM email address
                    </label>

                    <input
                        id="email"
                        name="email"
                        type="email"
                        maxlength="190"
                        placeholder="you@plm.edu.ph"
                        autocomplete="email"
                        value="<?= e($old['email']) ?>"
                        required
                    >

                </div>

                <!-- PASSWORD AND CONFIRMATION -->

                <div class="field-grid">

                    <div class="field">

                        <label for="password">
                            Password
                        </label>

                        <input
                            id="password"
                            name="password"
                            type="password"
                            minlength="10"
                            autocomplete="new-password"
                            required
                        >

                    </div>

                    <div class="field">

                        <label for="confirm_password">
                            Confirm password
                        </label>

                        <input
                            id="confirm_password"
                            name="confirm_password"
                            type="password"
                            minlength="10"
                            autocomplete="new-password"
                            required
                        >

                    </div>

                </div>

                <p class="register-help">
                    At least 10 characters, with uppercase
                    and lowercase letters and a number.
                </p>

                <p
                    id="password-feedback"
                    class="form-hint"
                    aria-live="polite"
                ></p>

                <!-- SHOW PASSWORDS -->

                <label
                    class="show-password-row"
                    for="show_password"
                >

                    <input
                        id="show_password"
                        type="checkbox"
                    >

                    Show passwords

                </label>

                <!-- REGISTER BUTTON -->

                <button
                    class="button button-primary button-full"
                    type="submit"
                >
                    Create account
                    <span aria-hidden="true">→</span>
                </button>

            </form>

            <p class="auth-switch">
                Already registered?
                <a href="login.php">Sign in</a>
            </p>

        </div>

    </section>

</main>

<!-- PAGE-SPECIFIC REGISTRATION JAVASCRIPT -->

<script>
(() => {
    'use strict';

    const form = document.getElementById(
        'registrationForm'
    );

    if (!form) return;

    const program = document.getElementById('program');
    const college = document.getElementById('college');

    const studentNumber = document.getElementById(
        'student_number'
    );

    const email = document.getElementById('email');

    const password = document.getElementById('password');

    const confirmation = document.getElementById(
        'confirm_password'
    );

    const showPasswords = document.getElementById(
        'show_password'
    );

    const feedback = document.getElementById(
        'password-feedback'
    );

    // AUTOMATIC COLLEGE FROM SELECTED PROGRAM

    function updateCollege() {
        const option = program.options[
            program.selectedIndex
        ];

        college.value = option
            ? (option.getAttribute('data-college') || '')
            : '';
    }

    // AUTOMATIC STUDENT NUMBER FORMATTING

    function formatStudentNumber() {
        const digits = studentNumber.value
            .replace(/\D/g, '')
            .slice(0, 9);

        studentNumber.value = digits.length > 4
            ? `${digits.slice(0, 4)}-${digits.slice(4)}`
            : digits;
    }

    // PLM EMAIL VALIDATION

    function validateEmail() {
        const value = email.value.trim();

        email.setCustomValidity(
            value &&
            !/^[^\s@]+@plm\.edu\.ph$/i.test(value)
                ? 'Please use your @plm.edu.ph email.'
                : ''
        );
    }

    // PASSWORD STRENGTH AND MATCH VALIDATION

    function validatePasswords() {
        const value = password.value;

        const strong =
            /^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9]).{10,}$/
                .test(value);

        password.setCustomValidity(
            value && !strong
                ? 'Use at least 10 characters, uppercase, lowercase, and a number.'
                : ''
        );

        const mismatch =
            Boolean(confirmation.value) &&
            confirmation.value !== value;

        confirmation.setCustomValidity(
            mismatch
                ? 'Passwords do not match.'
                : ''
        );

        if (!feedback) return;

        feedback.classList.remove(
            'hint-good',
            'hint-error'
        );

        if (!value) {
            feedback.textContent = '';

        } else if (!strong) {
            feedback.textContent =
                'Use 10+ characters, uppercase, lowercase, and a number.';

            feedback.classList.add('hint-error');

        } else if (mismatch) {
            feedback.textContent =
                'Passwords do not match.';

            feedback.classList.add('hint-error');

        } else {
            feedback.textContent = confirmation.value
                ? 'Passwords match.'
                : 'Password format looks good.';

            feedback.classList.add('hint-good');
        }
    }

    // EVENT LISTENERS

    program.addEventListener(
        'change',
        updateCollege
    );

    program.addEventListener(
        'input',
        updateCollege
    );

    studentNumber.addEventListener(
        'input',
        formatStudentNumber
    );

    email.addEventListener(
        'input',
        validateEmail
    );

    password.addEventListener(
        'input',
        validatePasswords
    );

    confirmation.addEventListener(
        'input',
        validatePasswords
    );

    // SHOW OR HIDE BOTH PASSWORD FIELDS

    showPasswords.addEventListener('change', () => {
        const type = showPasswords.checked
            ? 'text'
            : 'password';

        password.type = type;
        confirmation.type = type;
    });

    // VALIDATION BEFORE SUBMISSION

    form.addEventListener('submit', (event) => {
        updateCollege();
        validateEmail();
        validatePasswords();

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });

    // SET CORRECT COLLEGE ON INITIAL PAGE LOAD

    updateCollege();

    window.addEventListener(
        'pageshow',
        updateCollege
    );

})();
</script>

</body>
</html>
