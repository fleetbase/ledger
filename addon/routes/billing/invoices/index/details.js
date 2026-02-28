import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class BillingInvoicesIndexDetailsRoute extends Route {
    @service store;

    model({ public_id }) {
        return this.store.findRecord('invoice', public_id, {
            adapterOptions: { namespace: 'ledger/int/v1' },
        });
    }
}
