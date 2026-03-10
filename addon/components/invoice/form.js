import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * Invoice form component.
 *
 * Receives @resource (a ledger-invoice Ember Data record) and @registerRef.
 *
 * Line-items architecture
 * -----------------------
 * Invoice::LineItems works directly with `ledger-invoice-item` Ember Data
 * records — no intermediate POJO/LineItem class.  Existing items are passed
 * in via @items (snapshotted once in the constructor).  New items are created
 * via store.createRecord inside the child component.
 *
 * Before saving, the controller calls formRef.syncItemsToInvoice(invoice).
 * This sets invoice.items to the current array from the child component so
 * the EmbeddedRecordsMixin serializer includes them in the request payload.
 */
export default class InvoiceFormComponent extends Component {
    @service currentUser;

    /** Holds the Invoice::LineItems component instance after it registers. */
    lineItemsRef = null;

    @tracked currency;

    /**
     * Snapshot of the invoice's items taken ONCE in the constructor.
     * Must be a plain property (not a getter) so @items on Invoice::LineItems
     * never changes reference after mount.
     */
    initialItems = [];

    /**
     * Syncs the current line items from the child component into the invoice's
     * hasMany so the EmbeddedRecordsMixin serializer picks them up on save.
     * Called by the controller's save task just before `yield invoice.save()`.
     *
     * The child component holds real ledger-invoice-item Ember Data records
     * (both existing and newly created via store.createRecord), so we simply
     * assign the array directly — no POJO-to-record conversion needed.
     */
    syncItemsToInvoice(invoice) {
        if (!this.lineItemsRef || !invoice) return;
        invoice.items = this.lineItemsRef.getItems();
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
