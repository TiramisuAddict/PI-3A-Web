(function () {
    function destroyExistingChart(canvas) {
        if (!canvas || typeof Chart === 'undefined' || typeof Chart.getChart !== 'function') {
            return;
        }

        var existingChart = Chart.getChart(canvas);
        if (existingChart) {
            existingChart.destroy();
        }
    }

    function initDashboardCharts() {
        if (typeof Chart === 'undefined') {
            return;
        }

        // Pie Chart - Annonces vs Evenements
        var pieEl = document.getElementById('chartPieTypes');
        if (pieEl) {
            destroyExistingChart(pieEl);

            var pieAnnonces = parseInt(pieEl.dataset.pieAnnonces || 0, 10);
            var pieEvenements = parseInt(pieEl.dataset.pieEvenements || 0, 10);

            new Chart(pieEl, {
                type: 'pie',
                data: {
                    labels: ['Annonces (type 1)', 'Evenements (type 2)'],
                    datasets: [{
                        data: [pieAnnonces, pieEvenements],
                        backgroundColor: ['#206bc4', '#f59f00'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }

        // Bar Chart - Engagement
        var barEl = document.getElementById('chartBarEngagement');
        if (barEl) {
            destroyExistingChart(barEl);

            var barLabels = [];
            var barValues = [];

            try {
                barLabels = JSON.parse(barEl.dataset.labels || '[]');
                barValues = JSON.parse(barEl.dataset.values || '[]');
            } catch (e) {
                console.error('Error parsing bar chart data:', e);
            }

            new Chart(barEl, {
                type: 'bar',
                data: {
                    labels: barLabels,
                    datasets: [{
                        label: 'Likes + commentaires',
                        data: barValues,
                        backgroundColor: 'rgba(32, 107, 196, 0.65)',
                        borderColor: '#206bc4',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        },
                        x: {
                            ticks: { maxRotation: 45, minRotation: 0 }
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }

        // Line Chart - Participations
        var lineEl = document.getElementById('chartLineParticipation');
        if (lineEl) {
            destroyExistingChart(lineEl);

            var lineLabels = [];
            var lineValues = [];

            try {
                lineLabels = JSON.parse(lineEl.dataset.labels || '[]');
                lineValues = JSON.parse(lineEl.dataset.values || '[]');
            } catch (e) {
                console.error('Error parsing line chart data:', e);
            }

            new Chart(lineEl, {
                type: 'line',
                data: {
                    labels: lineLabels,
                    datasets: [{
                        label: 'Participations (evenements)',
                        data: lineValues,
                        fill: true,
                        tension: 0.25,
                        borderColor: '#2fb344',
                        backgroundColor: 'rgba(47, 179, 68, 0.15)',
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        }
                    },
                    plugins: {
                        legend: { display: true }
                    }
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', initDashboardCharts);
    document.addEventListener('turbo:load', initDashboardCharts);
})();
