import Model, { attr, belongsTo } from '@ember-data/model';
import numbersOnly from '@fleetbase/ember-core/utils/numbers-only';

/**
 * LedgerInvoiceItem model.
 *
 * Monetary fields (unit_price, amount, tax_amount) use @attr('string') so that
 * Ember Data's string transform leaves the raw value untouched.  The backend
 * Money cast accepts any string — it strips currency symbols and non-digit
 * characters via numbersOnly() — so sending "20000" or "$200.00" both work.
 *
 * Using @attr('number') would cause Ember Data to coerce the formatted string
 * that MoneyInput's <Input @value={{@value}}> two-way binding writes back
 * (e.g. "$200.00") to NaN.
 *
 * computedAmount and computedTaxAmount are native getters that derive from
 * the tracked @attr fields.  Because @attr fields are tracked by Ember Data,
 * these getters are reactive — they update automatically whenever unit_price,
 * quantity, or tax_rate change.  The template uses these for live display.
 */
export default class LedgerInvoiceItemModel extends Model {
    @attr('string') uuid;
    @attr('string') invoice_uuid;
    @attr('string') description;
    @attr('number') quantity;
    @attr('string') unit_price; // integer cents as string, e.g. "20000"
    @attr('string') amount; // integer cents as string (server-computed)
    @attr('number') tax_rate; // percentage, e.g. 10 = 10%
    @attr('string') tax_amount; // integer cents as string (server-computed)
    @attr('raw') meta;
    @attr('date') created_at;
    @attr('date') updated_at;

    @belongsTo('ledger-invoice', { async: false, inverse: 'items' }) invoice;

    /**
     * Live-computed line total in integer cents.
     * Reads from the tracked @attr fields so it updates reactively.
     */
    get computedAmount() {
        const qty = parseInt(numbersOnly(this.quantity)) || 0;
        const price = parseInt(numbersOnly(this.unit_price)) || 0;
        return qty * price;
    }

    /**
     * Live-computed tax amount in integer cents.
     */
    get computedTaxAmount() {
        return Math.round(this.computedAmount * ((Number(this.tax_rate) || 0) / 100));
    }
}
