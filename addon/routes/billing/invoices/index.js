import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class BillingInvoicesIndexRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        status: { refreshModel: true },
        currency: { refreshModel: true },
        order: { refreshModel: true },
        customer: { refreshModel: true },
        created_at: { refreshModel: true },
        due_date: { refreshModel: true },
        amount: { refreshModel: true },
    };

    model(params) {
        return this.store.query('ledger-invoice', params);
    }
}
