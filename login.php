<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

// Support older links without retaining
// the old shared authentication form.

if (($_GET['role'] ?? '') === 'instructor') {
    redirect('instructor-login.php');
}

redirect('student-login.php');
