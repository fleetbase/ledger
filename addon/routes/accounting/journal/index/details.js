import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class AccountingJournalIndexDetailsRoute extends Route {
    @service store;

    model({ public_id }) {
        return this.store.findRecord('journal', public_id, {
            adapterOptions: { namespace: 'ledger/int/v1' },
        });
    }
}
