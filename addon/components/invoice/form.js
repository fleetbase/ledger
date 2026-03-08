import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * Invoice form component.
 *
 * Receives @resource (a ledger-invoice Ember Data record) and manages
 * the line-items array by creating / updating ledger-invoice-item records
 * in the Ember Data store and setting them on the invoice's `items` hasMany.
 *
 * The saveTask in invoice-actions.js reads `record.items` directly — no
 * side-channel properties needed.
 */
export default class InvoiceFormComponent extends Component {
    @service store;
    @service currentUser;

    @tracked items = [];
    @tracked currency;

    get companyCurrency() {
        return this.currentUser.getCompany()?.currency ?? 'USD';
    }

    constructor() {
        super(...arguments);
        const invoice = this.args.resource;
        this.currency = invoice?.currency ?? this.companyCurrency;

        // Seed local items from the existing hasMany relationship (edit case)
        if (invoice?.items?.length) {
            this.items = invoice.items.map((item) => this._toPlain(item));
        }
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Called by Invoice::LineItems @onChange whenever the user adds, removes,
     * or edits a line item. Receives a plain-object array from the child
     * component. We upsert each item as a ledger-invoice-item store record and
     * update the invoice's `items` hasMany so the saveTask can serialise them.
     */
    @action
    onItemsChange(updatedItems) {
        this.items = updatedItems;

        const invoice = this.args.resource;
        if (!invoice) return;

        // Build or update ledger-invoice-item records in the store
        const itemRecords = updatedItems.map((plain) => {
            const existing = plain.uuid
                ? this.store.peekRecord('ledger-invoice-item', plain.uuid)
                : null;

            if (existing) {
                existing.description = plain.description;
                existing.quantity    = plain.quantity;
                existing.unit_price  = plain.unit_price;
                existing.tax_rate    = plain.tax_rate;
                existing.amount      = plain.amount;
                existing.tax_amount  = plain.tax_amount;
                return existing;
            }

            return this.store.createRecord('ledger-invoice-item', {
                description: plain.description,
                quantity:    plain.quantity,
                unit_price:  plain.unit_price,
                tax_rate:    plain.tax_rate,
                amount:      plain.amount,
                tax_amount:  plain.tax_amount,
                invoice,
            });
        });

        // Replace the hasMany content so saveTask reads the latest set
        invoice.items = itemRecords;
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
        if (customer) {
            this.args.resource.customer      = customer;
            this.args.resource.customer_uuid = customer?.id ?? null;

            // Derive the correct PolymorphicType value from the customer record.
            //
            // The API returns customer objects with a `customer_type` field in the
            // format "customer-{ember-type}", e.g.:
            //   "customer-vendor"  -> fleet-ops:vendor  (Fleetbase\FleetOps\Models\Vendor)
            //   "customer-contact" -> fleet-ops:contact (Fleetbase\FleetOps\Models\Contact)
            //
            // We strip the "customer-" prefix and prepend "fleet-ops:" so that
            // the backend PolymorphicType cast resolves the correct model class.
            // If the field is absent we fall back to inspecting the public_id prefix.
            const rawType = customer.customer_type ?? customer.get?.('customer_type');
            let polymorphicType;

            if (rawType && rawType.startsWith('customer-')) {
                const emberType = rawType.slice('customer-'.length); // e.g. "vendor"
                polymorphicType = `fleet-ops:${emberType}`;
            } else {
                // Fallback: inspect the public_id prefix
                const publicId = customer.public_id ?? customer.get?.('public_id') ?? '';
                polymorphicType = publicId.startsWith('vendor_') ? 'fleet-ops:vendor' : 'fleet-ops:contact';
            }

            this.args.resource.customer_type = polymorphicType;
        } else {
            // Customer cleared
            this.args.resource.customer      = null;
            this.args.resource.customer_uuid = null;
            this.args.resource.customer_type = null;
        }
    }

    @action
    onTemplateChange(template) {
        if (this.args.resource) {
            this.args.resource.template      = template;
            this.args.resource.template_uuid = template?.id ?? null;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    _toPlain(item) {
        if (typeof item.getProperties === 'function') {
            const { uuid, description, quantity, unit_price, amount, tax_rate, tax_amount } = item;
            return { uuid, description, quantity, unit_price, amount, tax_rate, tax_amount };
        }
        return { ...item };
    }
}
