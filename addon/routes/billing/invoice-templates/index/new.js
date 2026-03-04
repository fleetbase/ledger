import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { hash } from 'rsvp';

export default class BillingInvoiceTemplatesIndexNewRoute extends Route {
    @service store;
    @service fetch;

    async model() {
        const [template, contextSchemas] = await Promise.all([
            Promise.resolve(
                this.store.createRecord('template', {
                    context_type: 'ledger-invoice',
                    name: 'New Invoice Template',
                    orientation: 'portrait',
                    unit: 'mm',
                    width: 210,
                    height: 297,
                    content: [],
                })
            ),
            this.fetch.get('templates/context-schemas', { for: 'ledger-invoice' }, { namespace: '~api/v1' }).catch(() => []),
        ]);
        return { template, contextSchemas };
    }

    resetController(controller, isExiting) {
        if (isExiting) {
            // Roll back unsaved record if user navigates away without saving
            const template = controller.template;
            if (template?.isNew) {
                template.rollbackAttributes();
            }
        }
    }
}
