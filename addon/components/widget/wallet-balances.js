import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetWalletBalancesComponent extends Component {
    @service fetch;
    @service currentUser;
    @service ledgerDashboard;
    @tracked totals = null;
    @tracked topWallets = [];
    @tracked error = null;

    get companyCurrency() {
        return this.currentUser.company?.currency ?? this.currentUser.whoisData?.currency?.code ?? 'USD';
    }

    constructor() {
        super(...arguments);
        this.unsubscribeDashboard = this.ledgerDashboard.subscribe(() => this.loadData.perform());
        this.loadData.perform();
    }

    @task *loadData() {
        try {
            const response = yield this.fetch.get('reports/dashboard/wallet-balances', this.ledgerDashboard.walletPeriodParams, { namespace: 'ledger/int/v1' });
            const data = response?.data ?? response;
            this.totals = data?.totals ?? [];
            this.topWallets = data?.top_wallets ?? [];
            this.error = null;
        } catch (error) {
            this.totals = null;
            this.topWallets = [];
            this.error = error?.message ?? 'Unable to load wallet balances';
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.unsubscribeDashboard?.();
    }
}
