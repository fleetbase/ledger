import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class InvoiceTransactionsComponent extends Component {
    @service fetch;
    @tracked transactions = [];

    constructor(owner, args) {
        super(owner, args);
        this.loadTransactions.perform();
    }

    @task *loadTransactions() {
        const invoice = this.args.invoice;
        if (!invoice?.id) return;
        try {
            const result = yield this.fetch.get('transactions', {
                namespace: 'ledger/int/v1',
                params: { invoice_uuid: invoice.id },
            });
            this.transactions = result?.data ?? [];
        } catch {
            this.transactions = [];
        }
    }
}
