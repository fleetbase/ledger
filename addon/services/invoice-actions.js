import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class InvoiceActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('ledger-invoice', {
            permissionPrefix: 'ledger',
            mountPrefix: 'console.ledger',
        });
    }
    transition = {
        view: (invoice) => this.transitionTo('console.ledger.billing.invoices.index.details', invoice),
        create: () => this.transitionTo('console.ledger.billing.invoices.index.new'),
    };
    panel = {
        create: (attributes = {}, options = {}) => {
            const invoice = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'invoice/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.invoice')?.toLowerCase() }),
                saveOptions: { callback: this.refresh },
                useDefaultSaveTask: true,
                invoice,
                ...options,
            });
        },
        edit: (invoice, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'invoice/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: invoice.public_id }),
                useDefaultSaveTask: true,
                invoice,
                ...options,
            });
        },
        view: (invoice, options = {}) => {
            return this.resourceContextPanel.open({
                invoice,
                tabs: [{ label: this.intl.t('common.overview'), component: 'invoice/details' }],
                ...options,
            });
        },
    };
}
