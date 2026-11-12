<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/report_helpers.php';

$user = require_user('instructor');

$instructorId = (int) $user['id'];

// VALIDATE FILTERS

try {

    $filters = attendance_report_filters($_GET);

} catch (RuntimeException $ex) {

    http_response_code(400);

    exit($ex->getMessage());
}

// VERIFY REQUESTED CLASS BELONGS TO INSTRUCTOR

if ($filters['class_id'] !== null) {

    $check = db()->prepare(
        'SELECT id
         FROM classes
         WHERE id = ?
           AND instructor_id = ?
         LIMIT 1'
    );

    $check->execute([
        $filters['class_id'],
        $instructorId
    ]);

    if (!$check->fetch()) {

        http_response_code(403);

        exit(
            'You cannot export attendance for this class.'
        );
    }
}

// LOAD RECORDED ATTENDANCE

$sql = "
    SELECT
        cs.session_date,
        cs.start_time,

        c.class_code,
        c.class_name,
        c.section,

        s.student_number,
        u.full_name,

        a.status,
        a.recorded_at

    FROM attendance a

    JOIN class_sessions cs
        ON cs.id = a.session_id

    JOIN classes c
        ON c.id = cs.class_id

    JOIN students s
        ON s.id = a.student_id

    JOIN users u
        ON u.id = s.user_id

    WHERE c.instructor_id = ?
";

$params = [$instructorId];

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
        c.class_code,
        u.full_name
';

$stmt = db()->prepare($sql);

$stmt->execute($params);

// PROTECT EXPORTED SPREADSHEET CELLS

function attendance_csv_cell(
    ?string $value
): string {

    $value = $value ?? '';

    // Prevent user-entered text from being
    // interpreted as a spreadsheet formula.

    return preg_match(
        '/^\s*[=+\-@]/u',
        $value
    )
        ? "'" . $value
        : $value;
}

// CSV DOWNLOAD HEADERS

header(
    'Content-Type: text/csv; charset=UTF-8'
);

header(
    'Content-Disposition: attachment; filename="campustrack-attendance-' .
    date('Ymd-His') .
    '.csv"'
);

header('Cache-Control: no-store');

header('X-Content-Type-Options: nosniff');

// WRITE CSV FILE

$output = fopen('php://output', 'wb');

if ($output === false) {
    exit;
}

// UTF-8 BYTE ORDER MARK FOR EXCEL

fwrite(
    $output,
    "\xEF\xBB\xBF"
);

// COLUMN HEADERS

fputcsv(
    $output,
    [
        'Date',
        'Time',
        'Class code',
        'Class name',
        'Section',
        'Student number',
        'Student name',
        'Status',
        'Recorded at'
    ],
    ',',
    '"',
    ''
);

// DATA ROWS

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

    $values = [
        $row['session_date'],
        $row['start_time'],
        $row['class_code'],
        $row['class_name'],
        $row['section'],
        $row['student_number'],
        $row['full_name'],
        $row['status'],
        $row['recorded_at']
    ];

    $safeValues = array_map(
        static fn($value): string =>
            attendance_csv_cell(
                is_string($value)
                    ? $value
                    : (string) ($value ?? '')
            ),
        $values
    );

    fputcsv(
        $output,
        $safeValues,
        ',',
        '"',
        ''
    );
}

fclose($output);

exit;
