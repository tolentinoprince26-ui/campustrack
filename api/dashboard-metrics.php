<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function metrics_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    exit;
}

try {
    require_once dirname(__DIR__) . '/includes/init.php';

    $user = current_user();

    if (!$user) {
        metrics_response(401, [
            'error' => 'Your session has expired. Please sign in again.'
        ]);
    }

    $role = (string) ($user['role'] ?? '');

    if (!in_array($role, ['student', 'instructor'], true)) {
        metrics_response(403, [
            'error' => 'This account cannot access attendance analytics.'
        ]);
    }

    $pdo = db();
    $userId = (int) $user['id'];

    $since = (new DateTimeImmutable('first day of this month'))
        ->modify('-5 months')
        ->format('Y-m-01');

    // INSTRUCTOR DATA

    if ($role === 'instructor') {

        $from = 'FROM attendance a
                 JOIN class_sessions cs
                    ON cs.id = a.session_id
                 JOIN classes c
                    ON c.id = cs.class_id
                 WHERE c.instructor_id = ?';

        $params = [$userId];

        $classesQuery = $pdo->prepare(
            'SELECT
                c.class_code,
                c.section,
                COUNT(a.id) AS amount

             FROM classes c

             LEFT JOIN class_sessions cs
                ON cs.class_id = c.id

             LEFT JOIN attendance a
                ON a.session_id = cs.id

             WHERE c.instructor_id = ?

             GROUP BY
                c.id,
                c.class_code,
                c.section

             ORDER BY c.class_code, c.section

             LIMIT 10'
        );

        $classesQuery->execute([$userId]);
        $classData = [];

        foreach (
            $classesQuery->fetchAll(PDO::FETCH_ASSOC)
            as $row
        ) {
            $classData[] = [
                'label' =>
                    $row['class_code'] . ' · ' . $row['section'],
                'value' => (int) $row['amount']
            ];
        }

        $classTitle = 'Recorded entries by class';
        $classUnit = 'entries';

    } else {

        // STUDENT DATA

        $profile = $pdo->prepare(
            'SELECT id
             FROM students
             WHERE user_id = ?
             LIMIT 1'
        );

        $profile->execute([$userId]);

        $studentId = $profile->fetchColumn();

        if ($studentId === false) {
            metrics_response(403, [
                'error' => 'Your student profile could not be found.'
            ]);
        }

        $studentId = (int) $studentId;

        $from = 'FROM attendance a
                 JOIN class_sessions cs
                    ON cs.id = a.session_id
                 WHERE a.student_id = ?';

        $params = [$studentId];

        $classesQuery = $pdo->prepare(
            "SELECT
                c.class_code,
                c.section,

                COUNT(a.id) AS recorded,

                COALESCE(
                    SUM(
                        CASE
                            WHEN a.status IN ('Present', 'Late')
                            THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS attended

             FROM enrollments en

             JOIN classes c
                ON c.id = en.class_id

             LEFT JOIN class_sessions cs
                ON cs.class_id = c.id

             LEFT JOIN attendance a
                ON a.session_id = cs.id
               AND a.student_id = en.student_id

             WHERE en.student_id = ?

             GROUP BY
                c.id,
                c.class_code,
                c.section

             ORDER BY c.class_code, c.section

             LIMIT 10"
        );

        $classesQuery->execute([$studentId]);

        $classData = [];

        foreach (
            $classesQuery->fetchAll(PDO::FETCH_ASSOC)
            as $row
        ) {
            $recorded = (int) $row['recorded'];

            $classData[] = [
                'label' =>
                    $row['class_code'] . ' · ' . $row['section'],

                'value' => $recorded > 0
                    ? round(
                        (int) $row['attended'] * 100 / $recorded,
                        1
                    )
                    : null
            ];
        }

        $classTitle = 'Attendance rate by class';
        $classUnit = 'percent';
    }

    // TOTAL ATTENDANCE STATISTICS

    $summarySql = "
        SELECT
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

        $from
    ";

    $stmt = $pdo->prepare($summarySql);
    $stmt->execute($params);

    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    $present = (int) $counts['present_count'];
    $late = (int) $counts['late_count'];
    $absent = (int) $counts['absent_count'];

    $recorded = $present + $late + $absent;

    // MONTHLY STATISTICS

    $monthlySql = "
        SELECT
            DATE_FORMAT(
                cs.session_date,
                '%Y-%m'
            ) AS period,

            SUM(
                CASE WHEN a.status = 'Present'
                THEN 1 ELSE 0 END
            ) AS present_count,

            SUM(
                CASE WHEN a.status = 'Late'
                THEN 1 ELSE 0 END
            ) AS late_count,

            SUM(
                CASE WHEN a.status = 'Absent'
                THEN 1 ELSE 0 END
            ) AS absent_count

        $from

        AND cs.session_date >= ?

        GROUP BY
            DATE_FORMAT(cs.session_date, '%Y-%m')

        ORDER BY period
    ";

    $stmt = $pdo->prepare($monthlySql);
    $stmt->execute([...$params, $since]);

    $lookup = [];

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $row
    ) {
        $lookup[$row['period']] = [
            'present' => (int) $row['present_count'],
            'late' => (int) $row['late_count'],
            'absent' => (int) $row['absent_count']
        ];
    }

    $months = [];
    $trendPresent = [];
    $trendLate = [];
    $trendAbsent = [];

    $firstMonth = new DateTimeImmutable($since);

    for ($i = 0; $i < 6; $i++) {

        $month = $firstMonth->modify(
            '+' . $i . ' months'
        );

        $key = $month->format('Y-m');

        $entry = $lookup[$key] ?? [
            'present' => 0,
            'late' => 0,
            'absent' => 0
        ];

        $months[] = $month->format('M Y');
        $trendPresent[] = $entry['present'];
        $trendLate[] = $entry['late'];
        $trendAbsent[] = $entry['absent'];
    }

    // SEND JSON TO JAVASCRIPT

    metrics_response(200, [
        'role' => $role,

        'summary' => [
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'recorded' => $recorded,

            'rate' => $recorded > 0
                ? round(
                    ($present + $late) * 100 / $recorded,
                    1
                )
                : null
        ],

        'monthly' => [
            'labels' => $months,
            'present' => $trendPresent,
            'late' => $trendLate,
            'absent' => $trendAbsent
        ],

        'classes' => [
            'title' => $classTitle,
            'unit' => $classUnit,
            'items' => $classData
        ]
    ]);

} catch (Throwable $ex) {

    error_log(
        'Dashboard metrics: ' . $ex->getMessage()
    );

    metrics_response(500, [
        'error' =>
            'Could not load analytics. Check the server error log.'
    ]);
}
