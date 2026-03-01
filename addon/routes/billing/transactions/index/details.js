import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class BillingTransactionsIndexDetailsRoute extends Route {
    @service store;

    model({ id }) {
        return this.store.findRecord('transaction', id, {
            adapterOptions: { namespace: 'ledger/int/v1' },
        });
    }
}
