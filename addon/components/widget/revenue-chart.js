import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetRevenueChartComponent extends Component {
    @service fetch;
    @service currentUser;
    @tracked data = [];

    get companyCurrency() {
        return this.currentUser.getCompany()?.currency ?? 'USD';
    }

    constructor() {
        super(...arguments);
        this.loadData.perform();
    }

    @task *loadData() {
        try {
            const response = yield this.fetch.get('reports/dashboard', {}, { namespace: 'ledger/int/v1' });

            // The API returns the array under `revenue_trend`, where each item has
            // the shape { date, daily_revenue }. We map it to { date, amount, pct }
            // so the template can render the bar width and formatted amount.
            const trend = response?.data?.revenue_trend ?? [];
            const maxAmount = trend.reduce((max, row) => Math.max(max, row.daily_revenue ?? 0), 0);

            this.data = trend.map((row) => ({
                date: row.date,
                amount: row.daily_revenue ?? 0,
                // Percentage of the tallest bar; guard against division-by-zero.
                pct: maxAmount > 0 ? Math.round(((row.daily_revenue ?? 0) / maxAmount) * 100) : 0,
            }));
        } catch {
            this.data = [];
        }
    }
}
