import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * A single line-item row backed by @tracked properties.
 *
 * Using a class with individual @tracked fields instead of replacing the whole
 * items array on every keystroke.  This lets Glimmer do fine-grained DOM
 * updates — only the changed cell re-renders — so input elements are never
 * destroyed mid-edit and focus is never lost.
 */
class LineItem {
    @tracked description = '';
    @tracked quantity    = 1;
    @tracked unit_price  = 0;
    @tracked tax_rate    = 0;

    // Derived (recomputed on read — no @tracked needed, getters are reactive
    // because they depend on @tracked fields above)
    get amount() {
        return (this.quantity || 0) * (this.unit_price || 0);
    }

    get tax_amount() {
        return Math.round(this.amount * ((this.tax_rate || 0) / 100));
    }

    constructor(attrs = {}) {
        this._tmpId      = attrs._tmpId      ?? `_tmp_${Date.now()}_${Math.random()}`;
        this.uuid        = attrs.uuid        ?? null;
        this.description = attrs.description ?? '';
        this.quantity    = attrs.quantity    ?? 1;
        this.unit_price  = attrs.unit_price  ?? 0;
        this.tax_rate    = attrs.tax_rate    ?? 0;
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
 *   @items    {Array}    - initial array of item plain objects or Ember Data records
 *   @currency {String}   - ISO 4217 currency code, e.g. "USD"
 *   @disabled {Boolean}  - when true all inputs are read-only
 *   @onChange {Function} - called with the updated plain-object array on every change
 */
export default class InvoiceLineItemsComponent extends Component {
    /**
     * @tracked array of LineItem instances.
     *
     * We only replace this array when adding or removing rows.  For in-row
     * edits we mutate the LineItem instance's @tracked properties directly,
     * which triggers fine-grained re-renders without destroying input elements.
     */
    @tracked items = [];

    constructor() {
        super(...arguments);
        this.items = (this.args.items ?? []).map((item) => this._toLineItem(item));
    }

    // -------------------------------------------------------------------------
    // Computed totals (all values in integer cents)
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
        this.items = [
            ...this.items,
            new LineItem({ _tmpId: `_tmp_${Date.now()}` }),
        ];
        this._notifyChange();
    }

    @action
    removeItem(item) {
        this.items = this.items.filter((i) => i !== item);
        this._notifyChange();
    }

    /**
     * Description uses `input` event so the value updates on every keystroke
     * without losing focus.  We mutate the LineItem directly — no array swap.
     */
    @action
    updateDescription(item, event) {
        item.description = event.target.value;
        this._notifyChange();
    }

    /**
     * Quantity uses `change` event (fires on blur / Enter) which is fine for a
     * number spinner.  We mutate the LineItem directly.
     */
    @action
    updateQuantity(item, event) {
        item.quantity = Math.max(1, parseInt(event.target.value, 10) || 1);
        this._notifyChange();
    }

    /**
     * MoneyInput calls onChange with the integer-cents value directly.
     */
    @action
    updateUnitPrice(item, value) {
        item.unit_price = value;
        this._notifyChange();
    }

    /**
     * Tax rate uses `input` event so the value updates on every keystroke
     * without losing focus.
     */
    @action
    updateTaxRate(item, event) {
        item.tax_rate = Math.max(0, parseFloat(event.target.value) || 0);
        this._notifyChange();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    _toLineItem(source) {
        if (source instanceof LineItem) {
            return source;
        }
        // Accept both Ember Data model instances and plain objects.
        if (typeof source.getProperties === 'function') {
            const { uuid, description, quantity, unit_price, tax_rate } = source;
            return new LineItem({ uuid, description, quantity, unit_price, tax_rate });
        }
        return new LineItem(source);
    }

    _notifyChange() {
        if (typeof this.args.onChange === 'function') {
            this.args.onChange(this.items.map((item) => item.toPlain()));
        }
    }
}
