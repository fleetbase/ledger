import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetArAgingSummaryComponent extends Component {
    @service fetch;
    @service ledgerDashboard;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.unsubscribeDashboard = this.ledgerDashboard.subscribe(() => this.load.perform());
        this.load.perform();
    }

    get buckets() {
        return this.data?.buckets ?? [];
    }

    get bucketRows() {
        return this.buckets.map((bucket) => ({
            ...bucket,
            agingClass: `ledger-aging-${bucket.key?.replace(/_/g, '-')}`,
            pct: Math.round(((bucket.total ?? 0) / this.maxTotal) * 100),
        }));
    }

    get maxTotal() {
        return Math.max(...this.buckets.map((bucket) => bucket.total), 1);
    }

    @task *load() {
        try {
            const response = yield this.fetch.get('reports/dashboard/ar-aging-summary', this.ledgerDashboard.asOfParams, { namespace: 'ledger/int/v1' });
            this.data = response?.data ?? response;
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? 'Unable to load AR aging';
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.unsubscribeDashboard?.();
    }
}
