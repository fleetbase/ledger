import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

export default class WidgetCashFlowSummaryComponent extends Component {
    @service fetch;
    @service ledgerDashboard;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.unsubscribeDashboard = this.ledgerDashboard.subscribe(() => this.load.perform());
        this.load.perform();
    }

    get formattedNetChange() {
        return formatCurrency(this.data?.net_cash_change ?? 0, this.data?.currency ?? 'USD');
    }

    get labels() {
        return ['Operating', 'Financing', 'Investing'];
    }

    get datasets() {
        return [
            {
                label: 'Net cash flow',
                data: [this.data?.operating ?? 0, this.data?.financing ?? 0, this.data?.investing ?? 0],
                backgroundColor: ['rgba(5, 150, 105, 0.72)', 'rgba(59, 130, 246, 0.72)', 'rgba(245, 158, 11, 0.72)'],
                borderColor: ['#059669', '#2563eb', '#d97706'],
                borderWidth: 1,
            },
        ];
    }

    get chartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { ticks: { precision: 0, font: { size: 10 } } },
            },
        };
    }

    @task *load() {
        try {
            const response = yield this.fetch.get('reports/dashboard/cash-flow-summary', this.ledgerDashboard.periodParams, { namespace: 'ledger/int/v1' });
            this.data = response?.data ?? response;
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? 'Unable to load cash flow';
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.unsubscribeDashboard?.();
    }
}
