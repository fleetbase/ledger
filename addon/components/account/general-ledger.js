import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class AccountGeneralLedgerComponent extends Component {
    @service fetch;
    @service intl;
    @tracked entries = [];
    @tracked summary = null;

    constructor(owner, args) {
        super(owner, args);
        this.loadLedger.perform();
    }

    get columns() {
        return [
            {
                label: this.intl.t('column.date'),
                valuePath: 'date',
                cellComponent: 'table/cell/anchor',
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
                label: 'Debit',
                valuePath: 'debit_amount',
                cellComponent: 'table/cell/currency',
                resizable: true,
                sortable: false,
            },
            {
                label: 'Credit',
                valuePath: 'credit_amount',
                cellComponent: 'table/cell/currency',
                resizable: true,
                sortable: false,
            },
            {
                label: 'Balance',
                valuePath: 'running_balance',
                cellComponent: 'table/cell/currency',
                resizable: true,
                sortable: false,
            },
        ];
    }

    @task *loadLedger() {
        const account = this.args.account;
        if (!account?.id) return;
        try {
            const result = yield this.fetch.get(`accounts/${account.id}/general-ledger`, {}, { namespace: 'ledger/int/v1' });
            this.entries = result?.entries ?? [];
            this.summary = result?.summary ?? null;
        } catch {
            this.entries = [];
        }
    }
}
