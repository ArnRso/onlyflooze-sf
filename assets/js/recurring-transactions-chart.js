import {Chart, registerables} from 'chart.js';

Chart.register(...registerables);

document.addEventListener('DOMContentLoaded', function () {
    const chartCanvas = document.getElementById('monthlyTotalsChart');

    if (!chartCanvas || !window.monthlyTotalsData) {
        return;
    }

    const monthlyData = window.monthlyTotalsData;
    const labels = Object.keys(monthlyData);
    const rawData = Object.values(monthlyData);

    // Vérifier si toutes les valeurs sont négatives
    const allNegative = rawData.every(value => value < 0);

    // Utiliser les valeurs absolues si toutes les valeurs sont négatives
    const data = allNegative ? rawData.map(value => Math.abs(value)) : rawData;

    const positiveColor = 'rgba(34, 197, 94, 0.8)';
    const negativeColor = 'rgba(239, 68, 68, 0.8)';
    const positiveBorderColor = 'rgba(34, 197, 94, 1)';
    const negativeBorderColor = 'rgba(239, 68, 68, 1)';

    // Ajuster les couleurs selon les données originales ou transformées
    const backgroundColors = allNegative
        ? data.map(() => negativeColor)  // Toutes rouges si toutes négatives
        : data.map(value => value >= 0 ? positiveColor : negativeColor);
    const borderColors = allNegative
        ? data.map(() => negativeBorderColor)  // Toutes rouges si toutes négatives
        : data.map(value => value >= 0 ? positiveBorderColor : negativeBorderColor);

    new Chart(chartCanvas, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Total par mois (€)',
                data: data,
                borderColor: allNegative ? negativeBorderColor : positiveBorderColor,
                backgroundColor: allNegative ? negativeColor : positiveColor,
                borderWidth: 3,
                pointBackgroundColor: backgroundColors,
                pointBorderColor: borderColors,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: false,
                tension: 0.3,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            // Afficher la valeur originale dans le tooltip
                            const originalValue = rawData[context.dataIndex];
                            return `Total : ${originalValue.toLocaleString('fr-FR', {
                                style: 'currency',
                                currency: 'EUR'
                            })}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function (value) {
                            return value.toLocaleString('fr-FR', {
                                style: 'currency',
                                currency: 'EUR',
                                minimumFractionDigits: 0,
                                maximumFractionDigits: 0
                            });
                        }
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.1)'
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        }
    });
});
