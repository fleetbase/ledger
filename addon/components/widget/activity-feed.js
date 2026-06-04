import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetActivityFeedComponent extends Component {
    @service fetch;
    @service currentUser;
    @tracked entries = [];
    @tracked error = null;

    get companyCurrency() {
        return this.currentUser.company?.currency ?? this.currentUser.whoisData?.currency?.code ?? 'USD';
    }

    constructor() {
        super(...arguments);
        this.loadData.perform();
    }

    @task *loadData() {
        try {
            const response = yield this.fetch.get('reports/dashboard/activity', {}, { namespace: 'ledger/int/v1' });
            this.entries = response?.data?.items ?? response?.items ?? [];
            this.error = null;
        } catch (error) {
            this.entries = [];
            this.error = error?.message ?? 'Unable to load activity';
        }
    }
}
