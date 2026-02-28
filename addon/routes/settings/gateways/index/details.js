import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class SettingsGatewaysIndexDetailsRoute extends Route {
    @service store;

    model({ public_id }) {
        return this.store.findRecord('gateway', public_id, {
            adapterOptions: { namespace: 'ledger/int/v1' },
        });
    }
}
