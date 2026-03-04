import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Invoice line-items editor.
 *
 * Args:
 *   @items    {Array}    - initial array of item plain objects
 *   @currency {String}   - ISO 4217 currency code, e.g. "USD"
 *   @disabled {Boolean}  - when true all inputs are read-only
 *   @onChange {Function} - called with the updated items array on every change
 */
export default class InvoiceLineItemsComponent extends Component {
    @tracked items = [];

    constructor() {
        super(...arguments);
        // Initialise from the @items arg, creating plain-object copies so we
        // are not mutating Ember Data records directly.
        this.items = (this.args.items ?? []).map((item) => this._toPlain(item));
    }

    // -------------------------------------------------------------------------
    // Computed totals (all values in integer cents)
    // -------------------------------------------------------------------------

    get subtotal() {
        return this.items.reduce((sum, item) => sum + this._itemAmount(item), 0);
    }

    get tax() {
        return this.items.reduce((sum, item) => sum + this._itemTaxAmount(item), 0);
    }

    get total() {
        return this.subtotal + this.tax;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    @action
    addItem() {
        this.items = [
            ...this.items,
            { _tmpId: `_tmp_${Date.now()}`, description: '', quantity: 1, unit_price: 0, tax_rate: 0, amount: 0, tax_amount: 0 },
        ];
        this._notifyChange();
    }

    @action
    removeItem(index) {
        this.items = this.items.filter((_, i) => i !== index);
        this._notifyChange();
    }

    @action
    updateDescription(index, event) {
        this._updateField(index, 'description', event.target.value);
    }

    @action
    updateQuantity(index, event) {
        const qty = Math.max(1, parseInt(event.target.value, 10) || 1);
        this._updateField(index, 'quantity', qty);
    }

    @action
    updateUnitPrice(index, value) {
        // MoneyInput calls onChange with the integer-cents value directly
        this._updateField(index, 'unit_price', value);
    }

    @action
    updateTaxRate(index, event) {
        const rate = Math.max(0, parseFloat(event.target.value) || 0);
        this._updateField(index, 'tax_rate', rate);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    _toPlain(item) {
        // Accept both Ember Data model instances and plain objects
        if (typeof item.getProperties === 'function') {
            const { uuid, description, quantity, unit_price, amount, tax_rate, tax_amount } = item;
            return { uuid, description, quantity, unit_price, amount, tax_rate, tax_amount };
        }
        return { ...item };
    }

    _itemAmount(item) {
        return (item.quantity || 0) * (item.unit_price || 0);
    }

    _itemTaxAmount(item) {
        return Math.round(this._itemAmount(item) * ((item.tax_rate || 0) / 100));
    }

    _updateField(index, field, value) {
        const updated = [...this.items];
        updated[index] = { ...updated[index], [field]: value };
        // Recompute derived fields
        updated[index].amount     = this._itemAmount(updated[index]);
        updated[index].tax_amount = this._itemTaxAmount(updated[index]);
        this.items = updated;
        this._notifyChange();
    }

    _notifyChange() {
        if (typeof this.args.onChange === 'function') {
            this.args.onChange([...this.items]);
        }
    }
}
