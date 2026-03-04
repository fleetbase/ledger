import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Invoice form component.
 *
 * Receives @resource (a ledger-invoice Ember Data record) and manages
 * the line-items array locally, writing it back to @resource.items
 * whenever items change so the parent save action picks it up.
 */
export default class InvoiceFormComponent extends Component {
    @tracked items = [];
    @tracked currency = 'USD';

    constructor() {
        super(...arguments);
        const invoice = this.args.resource;

        // Seed the local items array from the record
        if (invoice?.items?.length) {
            this.items = invoice.items.map((item) => ({
                uuid:        item.uuid ?? null,
                description: item.description ?? '',
                quantity:    item.quantity ?? 1,
                unit_price:  item.unit_price ?? 0,
                tax_rate:    item.tax_rate ?? 0,
                amount:      item.amount ?? 0,
                tax_amount:  item.tax_amount ?? 0,
            }));
        }

        this.currency = invoice?.currency ?? 'USD';
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    @action
    onItemsChange(updatedItems) {
        this.items = updatedItems;
        // Expose items on the resource so the save action can include them
        if (this.args.resource) {
            this.args.resource.set('_pendingItems', updatedItems);
        }
    }

    @action
    onCurrencyChange(code) {
        this.currency = code;
        if (this.args.resource) {
            this.args.resource.currency = code;
        }
    }

    @action
    onCustomerChange(customer) {
        if (this.args.resource) {
            this.args.resource.customer      = customer;
            this.args.resource.customer_uuid = customer?.id ?? null;
            this.args.resource.customer_type = customer?.modelName ?? null;
        }
    }

    @action
    onTemplateChange(template) {
        if (this.args.resource) {
            this.args.resource.template      = template;
            this.args.resource.template_uuid = template?.id ?? null;
        }
    }
}
