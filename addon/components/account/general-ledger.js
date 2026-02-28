import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class AccountGeneralLedgerComponent extends Component {
    @service fetch;
    @tracked entries = [];
    @tracked summary = null;

    constructor(owner, args) {
        super(owner, args);
        this.loadLedger.perform();
    }

    @task *loadLedger() {
        const account = this.args.account;
        if (!account?.public_id) return;
        try {
            const result = yield this.fetch.get(`ledger/int/v1/accounts/${account.public_id}/ledger`);
            this.entries = result?.data?.entries ?? [];
            this.summary = result?.data?.summary ?? null;
        } catch {
            this.entries = [];
        }
    }
}
