import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * A single editable line-item row.
 *
 * Design
 * ------
 * The user-editable input fields (description, quantity, unit_price, tax_rate)
 * are PLAIN properties — not @tracked.  This is critical for MoneyInput:
 *
 *   MoneyInput uses AutoNumeric (initialised once via did-insert).  The inner
 *   <Input @value={{@value}}> sets element.value whenever @value changes on
 *   re-render.  AutoNumeric intercepts every programmatic element.value change
 *   and fires rawValueModified, which calls @onChange again.  If unit_price
 *   were @tracked, setting it in @onChange would re-render the row, update
 *   @value, AutoNumeric would fire again — an infinite loop.
 *
 *   With unit_price as a plain property, setting it in @onChange does NOT
 *   dirty the Glimmer tracking graph.  The MoneyInput row is never re-rendered
 *   after mount.  AutoNumeric never receives a programmatic @value update.
 *
 * Only `amount` and `tax_amount` are @tracked.  They are rendered in a
 * SEPARATE sub-component (Invoice::LineItems::Amount) that has no connection
 * to the MoneyInput row.  When _recalculate() sets them, only that sub-
 * component re-renders — the MoneyInput row is untouched.
 */
class LineItem {
    // Plain — mutated by handlers, never read by Glimmer's tracking system
    description = '';
    quantity    = 1;
    unit_price  = 0;   // integer cents (or smallest currency unit)
    tax_rate    = 0;   // percentage, e.g. 10 = 10%

    // @tracked — only these cause re-renders (in the Amount sub-component)
    @tracked amount     = 0;
    @tracked tax_amount = 0;

    constructor(attrs = {}) {
        this._tmpId      = attrs._tmpId ?? `_tmp_${Date.now()}_${Math.random()}`;
        this.uuid        = attrs.uuid        ?? null;
        this.description = attrs.description ?? '';
        this.quantity    = Number(attrs.quantity)   || 1;
        this.unit_price  = Number(attrs.unit_price) || 0;
        this.tax_rate    = Number(attrs.tax_rate)   || 0;
        this._recalculate();
    }

    _recalculate() {
        this.amount     = (this.quantity || 0) * (this.unit_price || 0);
        this.tax_amount = Math.round(this.amount * ((this.tax_rate || 0) / 100));
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
 *   @items       {Array}    – initial Ember Data records or plain objects
 *   @currency    {String}   – ISO 4217 currency code, e.g. "USD"
 *   @disabled    {Boolean}  – when true all inputs are read-only
 *   @registerRef {Function} – called once with this component instance so the
 *                             parent form can call getItems() before saving
 */
export default class InvoiceLineItemsComponent extends Component {
    @tracked items    = [];
    @tracked subtotal = 0;
    @tracked tax      = 0;
    @tracked total    = 0;

    constructor() {
        super(...arguments);
        this.items = (this.args.items ?? []).map((i) => this._toLineItem(i));
        this._updateTotals();
        if (typeof this.args.registerRef === 'function') {
            this.args.registerRef(this);
        }
    }

    // -------------------------------------------------------------------------
    // Public API — called by the parent form's save task
    // -------------------------------------------------------------------------

    getItems() {
        return this.items.map((item) => item.toPlain());
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    @action addItem() {
        this.items = [...this.items, new LineItem()];
        this._updateTotals();
    }

    @action removeItem(item) {
        this.items = this.items.filter((i) => i !== item);
        this._updateTotals();
    }

    @action updateDescription(item, event) {
        item.description = event.target.value;
    }

    @action updateQuantity(item, event) {
        item.quantity = Math.max(1, parseInt(event.target.value, 10) || 1);
        item._recalculate();
        this._updateTotals();
    }

    /**
     * Called by MoneyInput @onChange with the already-converted storage value
     * (integer cents for currencies with decimals, raw value otherwise).
     * unit_price is a plain property — setting it here does NOT trigger a
     * Glimmer re-render, so MoneyInput never receives a programmatic @value
     * update and AutoNumeric never fires rawValueModified spuriously.
     */
    @action updateUnitPrice(item, storedValue) {
        item.unit_price = storedValue;
        item._recalculate();
        this._updateTotals();
    }

    @action updateTaxRate(item, event) {
        item.tax_rate = Math.max(0, parseFloat(event.target.value) || 0);
        item._recalculate();
        this._updateTotals();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    _updateTotals() {
        this.subtotal = this.items.reduce((s, i) => s + i.amount,     0);
        this.tax      = this.items.reduce((s, i) => s + i.tax_amount, 0);
        this.total    = this.subtotal + this.tax;
    }

    _toLineItem(source) {
        if (source instanceof LineItem) return source;
        if (typeof source.getProperties === 'function') {
            // Ember Data record
            const { uuid, description, quantity, unit_price, tax_rate } = source;
            return new LineItem({ uuid, description, quantity, unit_price, tax_rate });
        }
        return new LineItem(source);
    }
}
