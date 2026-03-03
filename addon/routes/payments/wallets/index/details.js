import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PaymentsWalletsIndexDetailsRoute extends Route {
    @service store;

    model({ id }) {
        return this.store.findRecord('ledger-wallet', id);
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        // Sync the tracked isFrozen state so actionButtons re-computes
        // correctly on initial load and after navigation between wallets.
        controller.isFrozen = model?.is_frozen ?? false;
    }
}
