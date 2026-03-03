import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WalletTransactionHistoryComponent extends Component {
    @service fetch;
    @tracked transactions = [];
    @tracked meta = null;
    @tracked page = 1;

    constructor(owner, args) {
        super(owner, args);
        this.loadTransactions.perform();
    }

    @task *loadTransactions() {
        const wallet = this.args.wallet;
        if (!wallet?.id) return;
        try {
            const result = yield this.fetch.get(
                `wallets/${wallet.id}/transactions`,
                { page: this.page, limit: 20 },
                { namespace: 'ledger/int/v1' }
            );
            this.transactions = result?.data ?? [];
            this.meta = result?.meta ?? null;
        } catch {
            this.transactions = [];
        }
    }
}
