import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetArAgingComponent extends Component {
    @service fetch;
    @service currentUser;
    @tracked buckets = null;

    get companyCurrency() {
        return this.currentUser.company?.currency ?? this.currentUser.whoisData?.currency?.code ?? 'USD';
    }

    constructor() {
        super(...arguments);
        this.loadData.perform();
    }

    @task *loadData() {
        try {
            const response = yield this.fetch.get('reports/ar-aging', {}, { namespace: 'ledger/int/v1' });
            this.buckets = response?.data?.summary ?? null;
        } catch {
            this.buckets = null;
        }
    }
}
