import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';

export default class PaymentsTransactionsIndexDetailsController extends Controller {
    @tracked overlay = null;

    get tabs() {
        return [{ label: 'Overview', route: 'payments.transactions.index.details.index' }];
    }

    get actionButtons() {
        return [];
    }
}
