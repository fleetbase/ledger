import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * Invoice line-items editor.
 *
 * Architecture
 * ------------
 * Each row is a real `ledger-invoice-item` Ember Data record — either an
 * existing record loaded from the store (edit case) or a new one created via
 * `store.createRecord` (add case).  There is no intermediate POJO/LineItem
 * class.
 *
 * Because @attr fields on Ember Data records are tracked by Glimmer, the
 * computed getters on the model (`computedAmount`, `computedTaxAmount`) update
 * reactively whenever `unit_price`, `quantity`, or `tax_rate` change.  The
 * template reads these getters via the `format-currency` helper for live
 * display.
 *
 * MoneyInput receives `@value={{item.unit_price}}` and NO `@onChange`.
 * AutoNumeric formats the display; Ember's `<Input @value={{@value}}>` two-way
 * binding writes the formatted string back to `item.unit_price` when the user
 * types.  The backend Money cast's `set()` strips currency symbols via
 * `numbersOnly()`, so `"$200.00"` → `20000` on save.  No @onChange needed.
 *
 * Footer totals are getters on this component that reduce over `this.items`.
 * They are reactive because `this.items` is @tracked and each item's
 * `computedAmount` / `computedTaxAmount` are reactive model getters.
 *
 * Args:
 *   @items       {Array}    – initial ledger-invoice-item records
 *   @invoice     {Model}    – the parent ledger-invoice record
 *   @currency    {String}   – ISO 4217 currency code, e.g. "USD"
 *   @disabled    {Boolean}  – when true all inputs are read-only
 *   @registerRef {Function} – called once with this component instance
 */
export default class InvoiceLineItemsComponent extends Component {
    @service store;

    @tracked items = [];

    constructor() {
        super(...arguments);
        // Snapshot the existing items array once.  We keep our own @tracked
        // array so addItem / removeItem can splice it without touching the
        // hasMany relationship directly (the form's syncItemsToInvoice does
        // that just before save).
        this.items = [...(this.args.items ?? [])];
        if (typeof this.args.registerRef === 'function') {
            this.args.registerRef(this);
        }
    }

    // -------------------------------------------------------------------------
    // Footer totals — reactive getters that reduce over the items array.
    // Because this.items is @tracked and each item's computedAmount /
    // computedTaxAmount are reactive model getters, these update automatically.
    // -------------------------------------------------------------------------

    get subtotal() {
        return this.items.reduce((sum, item) => sum + (item.computedAmount ?? 0), 0);
    }

    get tax() {
        return this.items.reduce((sum, item) => sum + (item.computedTaxAmount ?? 0), 0);
    }

    get total() {
        return this.subtotal + this.tax;
    }

    // -------------------------------------------------------------------------
    // Public API — called by form.js syncItemsToInvoice before save
    // -------------------------------------------------------------------------

    getItems() {
        return this.items;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    @action addItem() {
        const newItem = this.store.createRecord('ledger-invoice-item', {
            invoice:     this.args.invoice,
            description: '',
            quantity:    1,
            unit_price:  '0',
            tax_rate:    0,
        });
        this.items = [...this.items, newItem];
    }

    @action removeItem(item) {
        // If the record was persisted, mark it for deletion on next save.
        // For new (unsaved) records, just unload from the store.
        if (item.isNew) {
            item.unloadRecord();
        } else {
            item.deleteRecord();
        }
        this.items = this.items.filter((i) => i !== item);
    }

    @action updateQuantity(item, event) {
        item.quantity = Math.max(1, parseInt(event.target.value, 10) || 1);
    }

    @action updateTaxRate(item, event) {
        item.tax_rate = Math.max(0, parseFloat(event.target.value) || 0);
    }
}
