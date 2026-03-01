import Controller from '@ember/controller';

export default class BillingTransactionsIndexDetailsController extends Controller {
    get tabs() {
        return [{ label: 'Details', route: 'console.ledger.billing.transactions.index.details.index' }];
    }

    get actionButtons() {
        return [];
    }
}
