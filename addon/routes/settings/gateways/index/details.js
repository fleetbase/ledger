import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class SettingsGatewaysIndexDetailsRoute extends Route {
    @service store;

    model({ id }) {
        return this.store.findRecord('gateway', id, {
            adapterOptions: { namespace: 'ledger/int/v1' },
        });
    }
}
