import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class HomeRoute extends Route {
    @service fetch;

    async model() {
        try {
            const response = await this.fetch.get('ledger/int/v1/reports/dashboard');
            return response?.data ?? {};
        } catch {
            return {};
        }
    }
}
