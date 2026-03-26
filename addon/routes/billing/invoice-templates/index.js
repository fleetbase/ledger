import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
export default class BillingInvoiceTemplatesIndexRoute extends Route {
    @service store;
    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
    };
    model(params) {
        return this.store.query('template', { ...params, context_type: 'ledger-invoice' });
    }
}
