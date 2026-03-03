import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WalletTransactionHistoryComponent extends Component {
    @service fetch;
    @service store;
    @service intl;
    @tracked transactions = [];
    @tracked meta = null;
    @tracked page = 1;

    constructor() {
        super(...arguments);
        this.loadTransactions.perform();
    }

    get columns() {
        return [
            {
                label: this.intl.t('column.date'),
                valuePath: 'createdAtShort',
                resizable: true,
                sortable: false,
            },
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
                humanize: true,
                resizable: true,
                sortable: false,
            },
            {
                label: this.intl.t('column.description'),
                valuePath: 'description',
                resizable: true,
                sortable: false,
            },
            {
                label: this.intl.t('column.amount'),
                valuePath: 'amount',
                cellComponent: 'table/cell/currency',
                resizable: true,
                sortable: false,
            },
            {
                label: this.intl.t('column.balance-after'),
                valuePath: 'balance_after',
                cellComponent: 'table/cell/currency',
                resizable: true,
                sortable: false,
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: false,
            },
        ];
    }

    @task *loadTransactions() {
        const wallet = this.args.wallet;
        if (!wallet?.id) return;

        try {
            console.log('fetching wallet transactions');
            const result = yield this.fetch.get(
                `wallets/${wallet.id}/transactions`,
                { page: this.page, limit: 20 },
                { namespace: 'ledger/int/v1' }
            );

            const transactions = (result?.transactions ?? []).map((trx) => this.store.push(this.store.normalize('ledger-transaction', trx)))

            console.log('[result]', result);
            console.log('[transactions]', transactions);
            this.transactions = transactions;
            this.meta = result?.meta ?? null;
        } catch (e) {
            console.error(e);
            console.log(e.message);
            this.transactions = [];
        }
    }
}
