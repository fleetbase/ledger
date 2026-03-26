import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetTopWalletsComponent extends Component {
    @service fetch;
    @service currentUser;
    @tracked wallets = [];

    get companyCurrency() {
        return this.currentUser.company?.currency ?? this.currentUser.whoisData?.currency?.code ?? 'USD';
    }

    constructor() {
        super(...arguments);
        this.loadData.perform();
    }

    @task *loadData() {
        try {
            const response = yield this.fetch.get('reports/wallet-summary', {}, { namespace: 'ledger/int/v1' });
            this.wallets = response?.data?.top_wallets ?? [];
        } catch {
            this.wallets = [];
        }
    }
}
