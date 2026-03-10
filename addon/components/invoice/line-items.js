import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * A single editable line-item row.
 *
 * All user-editable fields are @tracked so Glimmer re-renders the row when
 * they change.  `amount` and `tax_amount` are getters that derive from the
 * tracked fields — no manual _recalculate() call needed anywhere.
 *
 * Unit price is stored in integer cents (same as the backend Money cast).
 * The unit_price input shows a decimal dollar value (cents / 100) and
 * converts back to cents on change.  This avoids the MoneyInput / AutoNumeric
 * feedback loop where setting @value on re-render fires rawValueModified again.
 */
class LineItem {
    @tracked description = '';
    @tracked quantity    = 1;
    @tracked unit_price  = 0;   // integer cents
    @tracked tax_rate    = 0;   // percentage, e.g. 10 = 10%

    /** Display value for the unit price input: converts cents to dollars. */
    get unitPriceDollars() {
        return (this.unit_price || 0) / 100;
    }

    /** Computed in cents: quantity × unit_price */
    get amount() {
        return (this.quantity || 0) * (this.unit_price || 0);
    }

    /** Computed in cents: amount × tax_rate / 100 */
    get tax_amount() {
        return Math.round(this.amount * ((this.tax_rate || 0) / 100));
    }

    constructor(attrs = {}) {
        this._tmpId      = attrs._tmpId ?? `_tmp_${Date.now()}_${Math.random()}`;
        this.uuid        = attrs.uuid   ?? null;
        this.description = attrs.description ?? '';
        this.quantity    = Number(attrs.quantity)   || 1;
        this.unit_price  = Number(attrs.unit_price) || 0;
        this.tax_rate    = Number(attrs.tax_rate)   || 0;
    }

    toPlain() {
        return {
            _tmpId:      this._tmpId,
            uuid:        this.uuid,
            description: this.description,
            quantity:    this.quantity,
            unit_price:  this.unit_price,
            tax_rate:    this.tax_rate,
            amount:      this.amount,
            tax_amount:  this.tax_amount,
        };
    }
}

/**
 * Invoice line-items editor.
 *
 * Args:
 *   @items       {Array}    – initial array of Ember Data records or plain objects
 *   @currency    {String}   – ISO 4217 currency code, e.g. "USD"
 *   @disabled    {Boolean}  – when true all inputs are read-only
 *   @registerRef {Function} – called once with this component instance so the
 *                             parent form can call getItems() before saving
 *
 * Design
 * ------
 * Each row is a LineItem instance with @tracked fields.  The per-row Amount
 * cell and the footer totals are getters — they update automatically whenever
 * any tracked field changes.  No _notifyChange, no @onChange, no store
 * interaction during editing.
 *
 * Unit price input
 * ----------------
 * We use a plain <input type="number"> instead of MoneyInput.  MoneyInput
 * uses AutoNumeric which fires rawValueModified on every programmatic value
 * update (including Ember's <Input @value=…> re-render), creating a feedback
 * loop: onChange → unit_price (tracked) → re-render → @value update →
 * rawValueModified → onChange again with a double-multiplied value.
 *
 * The plain input shows unit_price / 100 (dollar amount) and converts back
 * to cents on change.  The Amount column uses the format-currency helper for
 * display.
 */
export default class InvoiceLineItemsComponent extends Component {
    @tracked items = [];

    constructor() {
        super(...arguments);
        this.items = (this.args.items ?? []).map((item) => this._toLineItem(item));
        if (typeof this.args.registerRef === 'function') {
            this.args.registerRef(this);
        }
    }

    // -------------------------------------------------------------------------
    // Public API — called by the parent form before save
    // -------------------------------------------------------------------------

    getItems() {
        return this.items.map((item) => item.toPlain());
    }

    // -------------------------------------------------------------------------
    // Footer totals — derived from tracked LineItem fields
    // -------------------------------------------------------------------------

    get subtotal() {
        return this.items.reduce((sum, item) => sum + item.amount, 0);
    }

    get tax() {
        return this.items.reduce((sum, item) => sum + item.tax_amount, 0);
    }

    get total() {
        return this.subtotal + this.tax;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    @action
    addItem() {
        this.items = [...this.items, new LineItem()];
    }

    @action
    removeItem(item) {
        this.items = this.items.filter((i) => i !== item);
    }

    @action
    updateDescription(item, event) {
        item.description = event.target.value;
    }

    @action
    updateQuantity(item, event) {
        item.quantity = Math.max(1, parseInt(event.target.value, 10) || 1);
    }

    /**
     * Unit price input shows dollars (cents / 100).
     * On change we convert back to integer cents.
     */
    @action
    updateUnitPrice(item, event) {
        const dollars = parseFloat(event.target.value) || 0;
        item.unit_price = Math.round(dollars * 100);
    }

    @action
    updateTaxRate(item, event) {
        item.tax_rate = Math.max(0, parseFloat(event.target.value) || 0);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    _toLineItem(source) {
        if (source instanceof LineItem) return source;
        // Ember Data record
        if (typeof source.getProperties === 'function') {
            const { uuid, description, quantity, unit_price, tax_rate } = source;
            return new LineItem({ uuid, description, quantity, unit_price, tax_rate });
        }
        return new LineItem(source);
    }
}
