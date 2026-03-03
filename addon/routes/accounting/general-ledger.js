import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class AccountingGeneralLedgerRoute extends Route {
    @service fetch;

    queryParams = {
        date_from: { refreshModel: true },
        date_to: { refreshModel: true },
        type: { refreshModel: true },
    };

    async model(params) {
        try {
            const result = await this.fetch.get('reports/general-ledger', params, { namespace: 'ledger/int/v1' });
            return result?.data ?? { accounts: [], date_from: null, date_to: null };
        } catch {
            return { accounts: [], date_from: null, date_to: null };
        }
    }
}
