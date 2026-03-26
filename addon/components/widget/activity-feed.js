import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetActivityFeedComponent extends Component {
    @service fetch;
    @service currentUser;
    @tracked entries = [];

    get companyCurrency() {
        return this.currentUser.company?.currency ?? this.currentUser.whoisData?.currency?.code ?? 'USD';
    }

    constructor() {
        super(...arguments);
        this.loadData.perform();
    }

    @task *loadData() {
        try {
            const response = yield this.fetch.get('reports/dashboard', {}, { namespace: 'ledger/int/v1' });
            this.entries = response?.data?.recent_journals ?? [];
        } catch {
            this.entries = [];
        }
    }
}
