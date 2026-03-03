import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WalletTransactionHistoryComponent extends Component {
    @service fetch;
    @service intl;
    @tracked transactions = [];
    @tracked meta = null;
    @tracked page = 1;

    get columns() {
        return [
            {
                label: this.intl.t('column.date'),
                valuePath: 'createdAt',
                resizable: true,
                sortable: false,
            },
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
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

    constructor(owner, args) {
        super(owner, args);
        this.loadTransactions.perform();
    }

    @task *loadTransactions() {
        const wallet = this.args.wallet;
        if (!wallet?.id) {
            return;
        }
        try {
            const result = yield this.fetch.get(
                `wallets/${wallet.id}/transactions`,
                { page: this.page, limit: 20 },
                { namespace: 'ledger/int/v1', normalizeToEmberData: true, normalizeModelType: 'ledger-transaction' }
            );
            this.transactions = result?.data ?? [];
            this.meta = result?.meta ?? null;
        } catch {
            this.transactions = [];
        }
    }
}
