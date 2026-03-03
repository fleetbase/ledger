import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetInvoiceSummaryComponent extends Component {
    @service fetch;
    @service currentUser;
    @tracked counts = null;

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
            this.counts = response?.data?.invoice_counts ?? null;
        } catch {
            this.counts = null;
        }
    }
}
