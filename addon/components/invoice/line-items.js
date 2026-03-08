import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * A single line-item row.
 *
 * IMPORTANT: `unit_price`, `quantity`, `tax_rate`, and `description` are
 * intentionally NOT @tracked.  Making them @tracked would cause a feedback
 * loop with MoneyInput:
 *
 *   updateUnitPrice → item.unit_price (tracked) → @value on <MoneyInput> updates
 *   → Ember <Input> sets element.value = raw-cents integer
 *   → AutoNumeric fires autoNumeric:rawValueModified with that integer
 *   → onChange called again with integer * 100 (double-multiplied)
 *   → _recalculate with corrupted value → totals go to 0
 *
 * Instead, only `amount` and `tax_amount` are @tracked.  They are the only
 * values that need to drive reactive re-renders (the per-row Amount cell and
 * the footer totals).  All input fields manage their own DOM state; we only
 * read their values through event handlers.
 */
class LineItem {
    // Plain (non-reactive) input fields — managed by DOM inputs directly
    description = '';
    quantity    = 1;
    unit_price  = 0;
    tax_rate    = 0;

    // Reactive computed fields — drive the Amount cell and footer totals
    @tracked amount     = 0;
    @tracked tax_amount = 0;

    constructor(attrs = {}) {
        this._tmpId      = attrs._tmpId      ?? `_tmp_${Date.now()}_${Math.random()}`;
        this.uuid        = attrs.uuid        ?? null;
        this.description = attrs.description ?? '';
        this.quantity    = attrs.quantity    ?? 1;
        this.unit_price  = attrs.unit_price  ?? 0;
        this.tax_rate    = attrs.tax_rate    ?? 0;
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
 *   @items       {Array}    - initial array of item plain objects or Ember Data records
 *   @currency    {String}   - ISO 4217 currency code, e.g. "USD"
 *   @disabled    {Boolean}  - when true all inputs are read-only
 *   @registerRef {Function} - called once with this component instance so the
 *                             parent form can call getItems() before saving.
 *
 * The component does NOT call @onChange on every keystroke.  Instead, the
 * parent form calls getItems() on this component (via the registered ref) just
 * before calling invoice.save().  This eliminates the feedback loop where:
 *
 *   @onChange → parent updates @items → @items arg changes → component is
 *   destroyed/recreated → all user input (MoneyInput values) is lost.
 */
export default class InvoiceLineItemsComponent extends Component {
    /**
     * @tracked array of LineItem instances.
     *
     * Only replaced when adding or removing rows.  In-row edits mutate the
     * LineItem instance directly (amount/tax_amount are @tracked on LineItem)
     * so Glimmer only re-renders the cells that changed.
     */
    @tracked items = [];

    constructor() {
        super(...arguments);
        this.items = (this.args.items ?? []).map((item) => this._toLineItem(item));
        // Register this component instance with the parent so it can call getItems()
        if (typeof this.args.registerRef === 'function') {
            this.args.registerRef(this);
        }
    }

    // -------------------------------------------------------------------------
    // Public API (called by parent before save)
    // -------------------------------------------------------------------------

    /**
     * Returns the current items as plain objects, ready to be pushed onto the
     * invoice record before save.
     */
    getItems() {
        return this.items.map((item) => item.toPlain());
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
    // Actions — NO _notifyChange calls on per-field edits
    // -------------------------------------------------------------------------

    @action
    addItem() {
        this.items = [
            ...this.items,
            new LineItem({ _tmpId: `_tmp_${Date.now()}` }),
        ];
        // Notify parent only on structural changes (row added/removed) so it can
        // update any item-count display, but NOT on every keystroke.
        this._notifyChange();
    }

    @action
    removeItem(item) {
        this.items = this.items.filter((i) => i !== item);
        this._notifyChange();
    }

    @action
    updateDescription(item, event) {
        item.description = event.target.value;
        // No _notifyChange — parent reads via getItems() on save
    }

    @action
    updateQuantity(item, event) {
        item.quantity = Math.max(1, parseInt(event.target.value, 10) || 1);
        item._recalculate();
        // No _notifyChange — parent reads via getItems() on save
    }

    /**
     * MoneyInput calls onChange with the integer-cents value directly.
     * We do NOT store this back into @value — MoneyInput owns its own display.
     */
    @action
    updateUnitPrice(item, value) {
        item.unit_price = value;
        item._recalculate();
        // No _notifyChange — parent reads via getItems() on save
    }

    @action
    updateTaxRate(item, event) {
        item.tax_rate = Math.max(0, parseFloat(event.target.value) || 0);
        item._recalculate();
        // No _notifyChange — parent reads via getItems() on save
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    _toLineItem(source) {
        if (source instanceof LineItem) {
            return source;
        }
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
