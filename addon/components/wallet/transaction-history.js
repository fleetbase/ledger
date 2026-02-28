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
        if (!wallet?.public_id) return;
        try {
            const result = yield this.fetch.get(`ledger/int/v1/wallets/${wallet.public_id}/transactions`, {
                params: { page: this.page, limit: 20 },
            });
            this.transactions = result?.data ?? [];
            this.meta = result?.meta ?? null;
        } catch {
            this.transactions = [];
        }
    }
}
