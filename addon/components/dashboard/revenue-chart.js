import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class DashboardRevenueChartComponent extends Component {
    @tracked chartData = null;

    @action
    setupChart(element) {
        const data = this.args.data || [];
        const labels = data.map((d) => d.date);
        const values = data.map((d) => (d.amount || 0) / 100);

        this.chartData = { labels, values };

        if (typeof window !== 'undefined' && window.Chart) {
            new window.Chart(element, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Revenue',
                            data: values,
                            borderColor: 'rgb(59, 130, 246)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4,
                            fill: true,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { callback: (v) => '$' + v.toLocaleString() } },
                    },
                },
            });
        }
    }
}
