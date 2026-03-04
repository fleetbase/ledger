import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class InvoiceTemplateActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('template', {
            permissionPrefix: 'ledger',
            mountPrefix: 'console.ledger',
        });
    }

    transition = {
        create: () => this.transitionTo('billing.invoice-templates.index.new'),
        edit: (template) => this.transitionTo('billing.invoice-templates.index.edit', template.id),
    };
}
