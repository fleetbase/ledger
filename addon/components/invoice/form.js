import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * Invoice form component.
 *
 * Receives @resource (a ledger-invoice Ember Data record) and @saveTask.
 *
 * Line-items architecture
 * -----------------------
 * The Invoice::LineItems child component registers itself via @registerRef so
 * we can call lineItemsRef.getItems() just before saving.  This avoids the
 * previous per-keystroke @onChange pattern that caused:
 *
 *   1. store.createRecord on every keystroke → duplicate items with uuid:null
 *   2. this.items update → @items arg change → component destroyed/recreated
 *   3. All MoneyInput values reset to $0 because the component was recreated
 *
 * The @items arg passed to Invoice::LineItems is set ONCE from the existing
 * hasMany relationship (edit case) and never changed again during editing.
 * Glimmer will therefore never destroy/recreate the child component while the
 * user is typing.
 */
export default class InvoiceFormComponent extends Component {
    @service store;
    @service currentUser;

    /** Holds the Invoice::LineItems component instance after it registers. */
    lineItemsRef = null;

    @tracked currency;

    /**
     * Snapshot of the invoice's items taken ONCE in the constructor.
     *
     * This MUST be a plain property, NOT a getter.  If it were a getter it
     * would re-evaluate on every render cycle (e.g. when tax_amount changes
     * on a LineItem and triggers a re-render of the form).  A new array
     * reference would be passed as @items to Invoice::LineItems, causing
     * Glimmer to destroy/recreate the child component and reset all user
     * edits to the original Ember Data values.
     */
    initialItems = [];

    /**
     * Syncs the current line items from the child component into the Ember Data
     * store and updates the invoice's `items` hasMany.  Called by the
     * controller's save task just before `yield invoice.save()`.
     */
    syncItemsToInvoice(invoice) {
        if (!this.lineItemsRef || !invoice) return;

        const plainItems = this.lineItemsRef.getItems();
        const itemRecords = plainItems.map((plain) => {
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

        // Replace the hasMany content so the serializer picks up the latest set
        invoice.items = itemRecords;
    }

    get companyCurrency() {
        return this.currentUser.getCompany()?.currency ?? 'USD';
    }

    constructor() {
        super(...arguments);
        const invoice = this.args.resource;
        this.currency = invoice?.currency ?? this.companyCurrency;
        // Snapshot items ONCE so @items on Invoice::LineItems never changes.
        // If this were a getter it would return a new array on every render,
        // causing Glimmer to recreate the child component and reset user edits.
        this.initialItems = invoice?.items?.toArray?.() ?? [];
        // Register this form component instance with the controller so the
        // save task can call formRef.syncItemsToInvoice(invoice) before saving.
        if (typeof this.args.registerRef === 'function') {
            this.args.registerRef(this);
        }
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Called by Invoice::LineItems with its own component instance.
     * Stored so syncItemsToInvoice() can call lineItemsRef.getItems().
     */
    @action
    registerLineItemsRef(ref) {
        this.lineItemsRef = ref;
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
        this.args.resource.customer = customer ?? null;
    }

    @action
    onTemplateChange(template) {
        if (this.args.resource) {
            this.args.resource.template      = template;
            this.args.resource.template_uuid = template?.id ?? null;
        }
    }
}
