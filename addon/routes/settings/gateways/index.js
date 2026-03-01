import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class SettingsGatewaysIndexRoute extends Route {
    @service store;

    model() {
        return this.store.query('gateway', { namespace: 'ledger/int/v1' });
    }
}
