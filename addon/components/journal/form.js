import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class JournalFormComponent extends Component {
    @service store;

    @tracked debitAccount = null;
    @tracked creditAccount = null;

    constructor() {
        super(...arguments);
        this.loadExistingAccounts();
    }

    async loadExistingAccounts() {
        const resource = this.args.resource;
        if (resource?.debit_account_uuid) {
            try {
                this.debitAccount = await this.store.findRecord('ledger-account', resource.debit_account_uuid);
            } catch (_) {
                // Account may not be loaded yet
            }
        }
        if (resource?.credit_account_uuid) {
            try {
                this.creditAccount = await this.store.findRecord('ledger-account', resource.credit_account_uuid);
            } catch (_) {
                // Account may not be loaded yet
            }
        }
    }

    @action setDebitAccount(account) {
        this.debitAccount = account;
        this.args.resource.debit_account_uuid = account?.id ?? null;
        this.args.resource.debit_account_name = account?.name ?? null;
        this.args.resource.debit_account_code = account?.code ?? null;
    }

    @action setCreditAccount(account) {
        this.creditAccount = account;
        this.args.resource.credit_account_uuid = account?.id ?? null;
        this.args.resource.credit_account_name = account?.name ?? null;
        this.args.resource.credit_account_code = account?.code ?? null;
    }
}
