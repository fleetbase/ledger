import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetInvoiceStatusComponent extends Component {
    @service fetch;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get statuses() {
        return this.data?.summary ?? [];
    }

    get statusRows() {
        return this.statuses.map((row) => ({
            ...row,
            pct: Math.round(((row.count ?? 0) / this.maxCount) * 100),
        }));
    }

    get hasData() {
        return (this.data?.total_count ?? 0) > 0;
    }

    get maxCount() {
        return Math.max(...this.statuses.map((row) => row.count), 1);
    }

    @task *load() {
        try {
            const response = yield this.fetch.get('reports/dashboard/invoice-status', {}, { namespace: 'ledger/int/v1' });
            this.data = response?.data ?? response;
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? 'Unable to load invoice status';
        }
    }
}
