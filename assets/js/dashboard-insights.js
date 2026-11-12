(() => {
    'use strict';

    const refresh = document.getElementById(
        'insights-refresh'
    );

    const message = document.getElementById(
        'insights-message'
    );

    if (!refresh || !message) return;

    const charts = [];
    let latest = null;

    // UPDATE ELEMENT TEXT

    const setText = (id, value) => {

        const target = document.getElementById(id);

        if (target) {
            target.textContent = String(value);
        }
    };

    // CHART COLORS FOR BOTH THEMES

    const getPalette = () => {

        const dark =
            document.documentElement.getAttribute(
                'data-theme'
            ) === 'dark';

        return {
            present: '#16a36b',
            late: '#e7a52c',
            absent: '#e26868',
            primary: '#3b82f6',

            text: dark
                ? '#d5e1f4'
                : '#304565',

            grid: dark
                ? 'rgba(210,226,255,0.12)'
                : 'rgba(16,38,74,0.10)'
        };
    };

    // CLEAN UP PREVIOUS CHART INSTANCES

    const destroyCharts = () => {

        while (charts.length) {
            charts.pop().destroy();
        }
    };

    // RENDER CHARTS

    const drawCharts = (data) => {

        if (typeof window.Chart !== 'function') {
            throw new Error(
                'Chart.js did not load. Check your internet connection.'
            );
        }

        destroyCharts();

        const color = getPalette();

        const summary = data.summary;
        const monthly = data.monthly;
        const classInfo = data.classes;

        // UPDATE SUMMARY CARDS

        setText('insights-total', summary.recorded);
        setText('insights-present', summary.present);
        setText('insights-late', summary.late);
        setText('insights-absent', summary.absent);

        setText(
            'insights-rate',
            summary.rate === null
                ? 'N/A'
                : `${summary.rate.toFixed(1)}%`
        );

        setText(
            'insights-class-heading',
            classInfo.title
        );

        setText(
            'insights-class-note',
            classInfo.unit === 'percent'
                ? 'Late counts as attended. Classes without records have no calculated rate.'
                : 'Number of saved attendance marks per class.'
        );

        // SHARED CHART SETTINGS

        const common = {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {
                legend: {
                    labels: {
                        color: color.text
                    }
                }
            }
        };

        const axes = (max = undefined) => ({

            x: {
                ticks: {
                    color: color.text
                },

                grid: {
                    color: color.grid
                }
            },

            y: {
                beginAtZero: true,

                ...(
                    max === undefined
                        ? {}
                        : { max }
                ),

                ticks: {
                    color: color.text,
                    precision: 0
                },

                grid: {
                    color: color.grid
                }
            }
        });

        // CHART 1: ATTENDANCE DISTRIBUTION

        charts.push(
            new Chart(
                document.getElementById(
                    'insights-status-chart'
                ),
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
                                summary.present,
                                summary.late,
                                summary.absent
                            ],

                            backgroundColor: [
                                color.present,
                                color.late,
                                color.absent
                            ],

                            borderWidth: 0
                        }]
                    },

                    options: {
                        ...common,

                        cutout: '65%',

                        plugins: {
                            legend: {
                                position: 'bottom',

                                labels: {
                                    color: color.text
                                }
                            }
                        }
                    }
                }
            )
        );

        // CHART 2: SIX-MONTH ATTENDANCE TREND

        charts.push(
            new Chart(
                document.getElementById(
                    'insights-monthly-chart'
                ),
                {
                    type: 'line',

                    data: {
                        labels: monthly.labels,

                        datasets: [
                            {
                                label: 'Present',
                                data: monthly.present,
                                borderColor: color.present,
                                tension: 0.25
                            },

                            {
                                label: 'Late',
                                data: monthly.late,
                                borderColor: color.late,
                                tension: 0.25
                            },

                            {
                                label: 'Absent',
                                data: monthly.absent,
                                borderColor: color.absent,
                                tension: 0.25
                            }
                        ]
                    },

                    options: {
                        ...common,
                        scales: axes()
                    }
                }
            )
        );

        // CHART 3: CLASS COMPARISON

        const isPercent =
            classInfo.unit === 'percent';

        charts.push(
            new Chart(
                document.getElementById(
                    'insights-class-chart'
                ),
                {
                    type: 'bar',

                    data: {
                        labels: classInfo.items.map(
                            item => item.label
                        ),

                        datasets: [{
                            label: isPercent
                                ? 'Attendance rate (%)'
                                : 'Recorded marks',

                            data: classInfo.items.map(
                                item => item.value
                            ),

                            backgroundColor: color.primary,
                            borderRadius: 5
                        }]
                    },

                    options: {
                        ...common,

                        scales: axes(
                            isPercent ? 100 : undefined
                        ),

                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                }
            )
        );
    };

    // FETCH DATA FROM PHP API

    const load = async () => {

        refresh.disabled = true;

        message.textContent =
            'Loading attendance analytics...';

        try {

            const response = await fetch(
                'api/dashboard-metrics.php',
                {
                    method: 'GET',

                    credentials: 'same-origin',

                    cache: 'no-store',

                    headers: {
                        Accept: 'application/json'
                    }
                }
            );

            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    data.error ||
                    'Could not load attendance analytics.'
                );
            }

            latest = data;

            drawCharts(data);

            message.textContent =
                data.summary.recorded === 0
                    ? 'No attendance marks saved yet. Charts will populate after an instructor records attendance.'
                    : 'Showing saved attendance records from the database.';

        } catch (error) {

            message.textContent =
                error instanceof Error
                    ? error.message
                    : 'An unexpected error occurred.';

        } finally {

            refresh.disabled = false;
        }
    };

    // REFRESH BUTTON

    refresh.addEventListener('click', load);

    // UPDATE CHART COLORS AFTER THEME CHANGE

    document.addEventListener(
        'campustrack:themechange',
        () => {

            if (latest) {
                drawCharts(latest);
            }
        }
    );

    // INITIAL LOAD

    load();

})();
