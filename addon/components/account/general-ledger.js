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
        if (!account?.id) return;
        try {
            const result = yield this.fetch.get(`accounts/${account.id}/general-ledger`, { namespace: 'ledger/int/v1' });
            this.entries = result?.entries ?? [];
            this.summary = result?.summary ?? null;
        } catch {
            this.entries = [];
        }
    }
}
