import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PaymentsWalletsIndexDetailsRoute extends Route {
    @service store;

    model({ id }) {
        return this.store.findRecord('ledger-wallet', id);
    }
}
