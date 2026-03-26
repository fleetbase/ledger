import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';

export default class AccountingAccountsIndexDetailsController extends Controller {
    @tracked overlay = null;

    get tabs() {
        return [
            { label: 'Overview', route: 'accounting.accounts.index.details.index' },
            { label: 'General Ledger', route: 'accounting.accounts.index.details.ledger' },
        ];
    }

    get actionButtons() {
        return [];
    }
}
