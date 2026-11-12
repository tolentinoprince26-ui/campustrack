<?php
// CampusTrack Dashboard Analytics
// Uses the existing users, attendance,
// class_sessions, classes and enrollments tables.

$insightUser = $user ?? require_user();
$insightRole = (string) $insightUser['role'];
$insightUserId = (int) $insightUser['id'];
$insightDb = db();

if ($insightRole === 'instructor') {

    $insightSource =
        'FROM attendance a
         JOIN class_sessions cs
            ON cs.id = a.session_id
         JOIN classes c
            ON c.id = cs.class_id
         WHERE c.instructor_id = ?';

    $insightOwnerId = $insightUserId;

    $insightClassSql =
        'SELECT
            CONCAT(c.class_code, " · ", c.section) AS label,
            COUNT(a.id) AS value
         FROM classes c
         LEFT JOIN class_sessions cs
            ON cs.class_id = c.id
         LEFT JOIN attendance a
            ON a.session_id = cs.id
         WHERE c.instructor_id = ?
         GROUP BY c.id, c.class_code, c.section
         ORDER BY c.class_code, c.section
         LIMIT 10';

    $insightClassTitle = 'Recorded marks by class';
    $insightUnit = 'entries';

} else {

    $studentLookup = $insightDb->prepare(
        'SELECT id FROM students
         WHERE user_id = ? LIMIT 1'
    );

    $studentLookup->execute([$insightUserId]);

    $insightOwnerId = (int) $studentLookup->fetchColumn();

    $insightSource =
        'FROM attendance a
         JOIN class_sessions cs
            ON cs.id = a.session_id
         WHERE a.student_id = ?';

    $insightClassSql =
        'SELECT
            CONCAT(c.class_code, " · ", c.section) AS label,
            COUNT(a.id) AS recorded,
            COALESCE(
                SUM(
                    CASE
                        WHEN a.status IN ("Present", "Late")
                        THEN 1 ELSE 0
                    END
                ), 0
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
         GROUP BY c.id, c.class_code, c.section
         ORDER BY c.class_code, c.section
         LIMIT 10';

    $insightClassTitle = 'Attendance rate by class';
    $insightUnit = 'percent';
}

// TOTAL ATTENDANCE

$summaryStmt = $insightDb->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN a.status = "Present"
            THEN 1 ELSE 0 END), 0) AS present_count,
        COALESCE(SUM(CASE WHEN a.status = "Late"
            THEN 1 ELSE 0 END), 0) AS late_count,
        COALESCE(SUM(CASE WHEN a.status = "Absent"
            THEN 1 ELSE 0 END), 0) AS absent_count
     ' . $insightSource
);

$summaryStmt->execute([$insightOwnerId]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$present = (int) $summary['present_count'];
$late = (int) $summary['late_count'];
$absent = (int) $summary['absent_count'];
$total = $present + $late + $absent;

// MONTHLY ATTENDANCE

$start = (new DateTimeImmutable('first day of this month'))
    ->modify('-5 months');

$monthlyStmt = $insightDb->prepare(
    'SELECT
        DATE_FORMAT(cs.session_date, "%Y-%m") AS month_key,
        COALESCE(SUM(CASE WHEN a.status = "Present"
            THEN 1 ELSE 0 END), 0) AS present_count,
        COALESCE(SUM(CASE WHEN a.status = "Late"
            THEN 1 ELSE 0 END), 0) AS late_count,
        COALESCE(SUM(CASE WHEN a.status = "Absent"
            THEN 1 ELSE 0 END), 0) AS absent_count
     ' . $insightSource .
     ' AND cs.session_date >= ?
       GROUP BY DATE_FORMAT(cs.session_date, "%Y-%m")
       ORDER BY month_key'
);

$monthlyStmt->execute([
    $insightOwnerId,
    $start->format('Y-m-01')
]);

$monthLookup = [];

foreach ($monthlyStmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
    $monthLookup[$record['month_key']] = $record;
}

$monthLabels = [];
$monthPresent = [];
$monthLate = [];
$monthAbsent = [];

for ($i = 0; $i < 6; $i++) {

    $month = $start->modify("+$i months");
    $record = $monthLookup[$month->format('Y-m')] ?? [];

    $monthLabels[] = $month->format('M Y');
    $monthPresent[] = (int) ($record['present_count'] ?? 0);
    $monthLate[] = (int) ($record['late_count'] ?? 0);
    $monthAbsent[] = (int) ($record['absent_count'] ?? 0);
}

// CLASS COMPARISON

$classStmt = $insightDb->prepare($insightClassSql);
$classStmt->execute([$insightOwnerId]);

$classItems = [];

foreach ($classStmt->fetchAll(PDO::FETCH_ASSOC) as $record) {

    $classItems[] = [
        'label' => $record['label'],

        'value' => $insightUnit === 'entries'
            ? (int) $record['value']
            : (
                (int) $record['recorded'] > 0
                    ? round(
                        100 * (int) $record['attended']
                        / (int) $record['recorded'],
                        1
                    )
                    : null
            )
    ];
}

// DATA FOR CHART.JS

$insightsData = [
    'summary' => [
        'present' => $present,
        'late' => $late,
        'absent' => $absent,
        'recorded' => $total,
        'rate' => $total > 0
            ? round(100 * ($present + $late) / $total, 1)
            : null
    ],

    'monthly' => [
        'labels' => $monthLabels,
        'present' => $monthPresent,
        'late' => $monthLate,
        'absent' => $monthAbsent
    ],

    'classes' => [
        'title' => $insightClassTitle,
        'unit' => $insightUnit,
        'items' => $classItems
    ]
];
?>

<style>
.ct-insights {
    margin: 28px 0;
    color: #10264a;
}

.ct-insights h2 {
    font-size: 24px;
    margin: 0 0 6px;
}

.ct-insights p {
    color: #65758e;
    margin: 0 0 16px;
}

.ct-insight-head {
    display: flex;
    gap: 16px;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.ct-insight-head button,
.ct-theme-toggle {
    background: #173b70;
    border: 1px solid #315e9e;
    color: #fff;
    padding: 9px 13px;
    border-radius: 9px;
    cursor: pointer;
    font-weight: 700;
}

.ct-insight-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.ct-insight-stats > div,
.ct-chart-panel {
    background: #fff;
    border: 1px solid #e0e7f0;
    border-radius: 13px;
    padding: 18px;
}

.ct-insight-stats small {
    display: block;
    color: #667895;
}

.ct-insight-stats strong {
    display: block;
    font-size: 26px;
    margin-top: 7px;
}

.ct-chart-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.ct-chart-panel {
    min-width: 0;
}

.ct-chart-panel:last-child {
    grid-column: 1 / -1;
}

.ct-chart-panel h3 {
    margin: 0 0 12px;
    font-size: 17px;
}

.ct-chart-wrap {
    height: 255px;
    position: relative;
}

.ct-chart-wrap canvas {
    width: 100% !important;
    height: 100% !important;
}

#ct-insight-note {
    font-size: 13px;
    margin: 0 0 12px;
}

@media (max-width: 850px) {
    .ct-insight-stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 650px) {
    .ct-insight-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .ct-chart-grid {
        grid-template-columns: 1fr;
    }

    .ct-chart-panel:last-child {
        grid-column: auto;
    }
}

/* WORKING DASHBOARD DARK MODE */

html[data-theme="dark"] body.dashboard-body {
    background: #0c1728 !important;
    color: #e8f0fd !important;
}

html[data-theme="dark"] body.dashboard-body .site-header,
html[data-theme="dark"] body.dashboard-body .portal-nav,
html[data-theme="dark"] body.dashboard-body .surface,
html[data-theme="dark"] body.dashboard-body .stat-card,
html[data-theme="dark"] body.dashboard-body .dashboard-panel,
html[data-theme="dark"] body.dashboard-body .attendance-panel,
html[data-theme="dark"] body.dashboard-body .ct-insight-stats > div,
html[data-theme="dark"] body.dashboard-body .ct-chart-panel {
    background: #172942 !important;
    border-color: #354860 !important;
    color: #e8f0fd !important;
}

html[data-theme="dark"] body.dashboard-body h1,
html[data-theme="dark"] body.dashboard-body h2,
html[data-theme="dark"] body.dashboard-body h3,
html[data-theme="dark"] body.dashboard-body strong,
html[data-theme="dark"] body.dashboard-body .brand,
html[data-theme="dark"] body.dashboard-body .portal-nav a,
html[data-theme="dark"] body.dashboard-body .header-name {
    color: #e8f0fd !important;
}

html[data-theme="dark"] body.dashboard-body p,
html[data-theme="dark"] body.dashboard-body small,
html[data-theme="dark"] body.dashboard-body .subtle,
html[data-theme="dark"] body.dashboard-body .fineprint,
html[data-theme="dark"] body.dashboard-body .page-footer,
html[data-theme="dark"] body.dashboard-body .ct-insights p {
    color: #aabdd8 !important;
}

html[data-theme="dark"] body.dashboard-body input,
html[data-theme="dark"] body.dashboard-body select {
    background: #0e2035 !important;
    color: #e8f0fd !important;
    border-color: #476181 !important;
}

html[data-theme="dark"] body.dashboard-body .theme-toggle,
html[data-theme="dark"] body.dashboard-body .ct-theme-toggle {
    background: #365985 !important;
    color: white !important;
}
</style>

<section class="ct-insights" aria-label="Attendance insights">

    <div class="ct-insight-head">
        <div>
            <h2>Attendance Insights</h2>
            <p>Chart.js analytics based on saved attendance records.</p>
        </div>

        <button id="ct-reload" type="button">
            Refresh charts
        </button>
    </div>

    <p id="ct-insight-note" role="status">
        Loading charts...
    </p>

    <div class="ct-insight-stats">

        <div>
            <small>Recorded</small>
            <strong id="ct-total">0</strong>
        </div>

        <div>
            <small>Present</small>
            <strong id="ct-present">0</strong>
        </div>

        <div>
            <small>Late</small>
            <strong id="ct-late">0</strong>
        </div>

        <div>
            <small>Absent</small>
            <strong id="ct-absent">0</strong>
        </div>

        <div>
            <small>Attendance rate</small>
            <strong id="ct-rate">N/A</strong>
        </div>

    </div>

    <div class="ct-chart-grid">

        <div class="ct-chart-panel">
            <h3>Attendance status</h3>
            <div class="ct-chart-wrap">
                <canvas id="ct-status-chart"></canvas>
            </div>
        </div>

        <div class="ct-chart-panel">
            <h3>Last 6 months</h3>
            <div class="ct-chart-wrap">
                <canvas id="ct-monthly-chart"></canvas>
            </div>
        </div>

        <div class="ct-chart-panel">
            <h3 id="ct-class-title">Attendance by class</h3>
            <div class="ct-chart-wrap">
                <canvas id="ct-class-chart"></canvas>
            </div>
        </div>

    </div>

</section>

<script id="ct-insight-data" type="application/json">
<?= json_encode(
    $insightsData,
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_THROW_ON_ERROR
) ?>
</script>

<script>
(() => {
    'use strict';

    const start = () => {

        const root = document.documentElement;

        // DARK MODE BUTTON:
        // Use existing button, or add one to header.

        let toggle = document.getElementById(
            'campustrack-theme-toggle'
        );

        if (!toggle) {

            toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.id = 'campustrack-theme-toggle';
            toggle.className = 'ct-theme-toggle';

            const location =
                document.querySelector('.header-actions') ||
                document.querySelector('.site-header');

            if (location) {
                location.appendChild(toggle);
            }
        }

        const readTheme = () => {
            try {
                return localStorage.getItem(
                    'campustrack-theme'
                ) === 'dark' ? 'dark' : 'light';
            } catch (_) {
                return 'light';
            }
        };

        const applyTheme = mode => {

            root.setAttribute('data-theme', mode);

            if (toggle) {
                toggle.textContent =
                    mode === 'dark'
                        ? 'Light mode'
                        : 'Dark mode';

                toggle.setAttribute(
                    'aria-pressed',
                    String(mode === 'dark')
                );
            }
        };

        applyTheme(readTheme());

        // CHART DATA

        let data = JSON.parse(
            document.getElementById(
                'ct-insight-data'
            ).textContent
        );

        const charts = [];

        const note = document.getElementById(
            'ct-insight-note'
        );

        const setText = (id, value) => {
            document.getElementById(id).textContent =
                String(value);
        };

        // DRAW CHARTS

        function render(payload) {

            data = payload;
            const s = data.summary;

            setText('ct-total', s.recorded);
            setText('ct-present', s.present);
            setText('ct-late', s.late);
            setText('ct-absent', s.absent);

            setText(
                'ct-rate',
                s.rate === null
                    ? 'N/A'
                    : Number(s.rate).toFixed(1) + '%'
            );

            setText('ct-class-title', data.classes.title);

            if (typeof window.Chart !== 'function') {

                note.textContent =
                    'Chart.js did not load. Check the library link.';
                return;
            }

            // Destroy old charts before redrawing.

            while (charts.length) {
                charts.pop().destroy();
            }

            const dark =
                root.getAttribute('data-theme') === 'dark';

            const text = dark ? '#e3eaf5' : '#314563';

            const grid = dark
                ? 'rgba(255,255,255,.12)'
                : 'rgba(10,40,70,.10)';

            const green = '#16a36b';
            const amber = '#e7a52c';
            const red = '#e26868';

            const common = {
                responsive: true,
                maintainAspectRatio: false
            };

            const axes = {
                x: {
                    ticks: { color: text },
                    grid: { color: grid }
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: text },
                    grid: { color: grid }
                }
            };

            // DOUGHNUT CHART

            charts.push(new Chart(
                document.getElementById('ct-status-chart'),
                {
                    type: 'doughnut',

                    data: {
                        labels: [
                            'Present',
                            'Late',
                            'Absent'
                        ],

                        datasets: [{
                            data: [
                                s.present,
                                s.late,
                                s.absent
                            ],

                            backgroundColor: [
                                green,
                                amber,
                                red
                            ],

                            borderWidth: 0
                        }]
                    },

                    options: {
                        ...common,

                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { color: text }
                            }
                        }
                    }
                }
            ));

            // MONTHLY LINE CHART

            charts.push(new Chart(
                document.getElementById('ct-monthly-chart'),
                {
                    type: 'line',

                    data: {
                        labels: data.monthly.labels,

                        datasets: [
                            {
                                label: 'Present',
                                data: data.monthly.present,
                                borderColor: green,
                                tension: 0.25
                            },

                            {
                                label: 'Late',
                                data: data.monthly.late,
                                borderColor: amber,
                                tension: 0.25
                            },

                            {
                                label: 'Absent',
                                data: data.monthly.absent,
                                borderColor: red,
                                tension: 0.25
                            }
                        ]
                    },

                    options: {
                        ...common,
                        scales: axes,

                        plugins: {
                            legend: {
                                labels: { color: text }
                            }
                        }
                    }
                }
            ));

            // CLASS BAR CHART

            charts.push(new Chart(
                document.getElementById('ct-class-chart'),
                {
                    type: 'bar',

                    data: {
                        labels: data.classes.items.map(
                            item => item.label
                        ),

                        datasets: [{
                            label:
                                data.classes.unit === 'percent'
                                    ? 'Rate (%)'
                                    : 'Recorded',

                            data: data.classes.items.map(
                                item => item.value
                            ),

                            backgroundColor: '#3b82f6',
                            borderRadius: 5
                        }]
                    },

                    options: {
                        ...common,

                        scales: {
                            ...axes,

                            y: {
                                ...axes.y,
                                max:
                                    data.classes.unit === 'percent'
                                        ? 100
                                        : undefined
                            }
                        },

                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                }
            ));

            note.textContent = s.recorded === 0
                ? 'No attendance recorded yet. Charts will fill when attendance is saved.'
                : 'Showing saved attendance from MySQL.';
        }

        // DARK MODE CLICK

        if (toggle) {

            toggle.addEventListener(
                'click',
                event => {

                    // Prevent an older theme handler
                    // from toggling twice.

                    event.stopImmediatePropagation();

                    const next =
                        root.getAttribute('data-theme') === 'dark'
                            ? 'light'
                            : 'dark';

                    try {
                        localStorage.setItem(
                            'campustrack-theme',
                            next
                        );
                    } catch (_) {}

                    applyTheme(next);

                    // Redraw charts with matching colors.
                    render(data);

                },
                true
            );
        }

        // INITIAL CHART RENDER

        render(data);

        // OPTIONAL FETCH API REFRESH

        const refresh = document.getElementById(
            'ct-reload'
        );

        refresh.addEventListener('click', async () => {

            refresh.disabled = true;
            note.textContent = 'Refreshing...';

            try {

                const response = await fetch(
                    'api/dashboard-metrics.php',
                    {
                        cache: 'no-store',
                        credentials: 'same-origin'
                    }
                );

                if (!response.ok) {
                    throw new Error('API unavailable');
                }

                render(await response.json());

            } catch (_) {

                // Keep the server-rendered data
                // if the optional API is unavailable.

                render(data);

                note.textContent =
                    'Saved dashboard data is shown. Reload the page to update.';

            } finally {

                refresh.disabled = false;
            }
        });
    };

    // Wait until the Chart.js CDN script is loaded.

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            start
        );
    } else {
        start();
    }

})();
</script>
