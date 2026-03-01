import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetWalletBalancesComponent extends Component {
    @service fetch;
    @tracked totals = null;

    constructor() {
        super(...arguments);
        this.loadData.perform();
    }

    @task *loadData() {
        try {
            const response = yield this.fetch.get('reports/dashboard', { namespace: 'ledger/int/v1' });
            this.totals = response?.data?.wallet_totals ?? null;
        } catch {
            this.totals = null;
        }
    }
}
