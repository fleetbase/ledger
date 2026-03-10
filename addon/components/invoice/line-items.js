import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * A single editable line-item row.
 *
 * Tracking strategy
 * -----------------
 * Only `amount` and `tax_amount` are @tracked — these are the computed display
 * values that Glimmer needs to re-render when inputs change.
 *
 * The user-editable fields (description, quantity, unit_price, tax_rate) are
 * plain properties.  This is intentional:
 *
 *   - MoneyInput manages its own display via AutoNumeric (did-insert, once).
 *     If `unit_price` were @tracked, setting it in @onChange would re-render
 *     the row, which would update <Input @value={{@value}}>, which AutoNumeric
 *     would intercept and fire rawValueModified again — an infinite loop.
 *
 *   - Plain <input> elements with {{on "change"}} handlers read their value
 *     from the DOM on change; they do not need @tracked to show the current
 *     value because the browser keeps the DOM state between re-renders.
 *
 * When any input changes, the handler updates the plain property and then
 * calls _recalculate() which sets the @tracked `amount` and `tax_amount`.
 * Only those two cells (and the footer totals) re-render — nothing else.
 */
class LineItem {
    // Plain (non-tracked) — mutated by input handlers, not read by Glimmer
    description = '';
    quantity    = 1;
    unit_price  = 0;  // stored in the currency's smallest unit (e.g. cents)
    tax_rate    = 0;  // percentage, e.g. 10 = 10%

    // @tracked — Glimmer re-renders only these cells when they change
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
 *
 * Footer totals (subtotal / tax / total) are @tracked on the component and
 * updated by _updateTotals() after every input change.
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

    @action
    addItem() {
        this.items = [...this.items, new LineItem()];
        this._updateTotals();
    }

    @action
    removeItem(item) {
        this.items = this.items.filter((i) => i !== item);
        this._updateTotals();
    }

    @action
    updateDescription(item, event) {
        item.description = event.target.value;
        // No recalculate needed — description does not affect amounts
    }

    @action
    updateQuantity(item, event) {
        item.quantity = Math.max(1, parseInt(event.target.value, 10) || 1);
        item._recalculate();
        this._updateTotals();
    }

    /**
     * Called by MoneyInput @onChange.
     * storedValue is already in the currency's smallest unit (cents for USD),
     * exactly matching what the backend Money cast stores and returns.
     * unit_price is a plain (non-tracked) property so setting it here does NOT
     * trigger a re-render of the MoneyInput — no feedback loop.
     */
    @action
    updateUnitPrice(item, storedValue) {
        item.unit_price = storedValue;
        item._recalculate();
        this._updateTotals();
    }

    @action
    updateTaxRate(item, event) {
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
        // Ember Data record
        if (typeof source.getProperties === 'function') {
            const { uuid, description, quantity, unit_price, tax_rate } = source;
            return new LineItem({ uuid, description, quantity, unit_price, tax_rate });
        }
        return new LineItem(source);
    }
}
