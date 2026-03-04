import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class BillingInvoiceTemplatesIndexEditRoute extends Route {
    @service store;
    @service fetch;

    async model({ id }) {
        const [template, contextSchemas] = await Promise.all([
            this.store.findRecord('template', id),
            this.fetch.get('templates/context-schemas', { for: 'ledger-invoice' }, { namespace: '~api/v1' }).catch(() => []),
        ]);
        return { template, contextSchemas };
    }
}
