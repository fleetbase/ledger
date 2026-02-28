import Controller from '@ember/controller';

export default class AccountingAccountsIndexDetailsController extends Controller {
    get tabs() {
        return [
            { label: 'Details', route: 'console.ledger.accounting.accounts.index.details.index' },
            { label: 'General Ledger', route: 'console.ledger.accounting.accounts.index.details.ledger' },
        ];
    }

    get actionButtons() {
        return [];
    }
}
