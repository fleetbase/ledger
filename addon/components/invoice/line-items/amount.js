import Component from '@glimmer/component';

/**
 * Renders the Amount cell for a single line-item row.
 *
 * This is intentionally a separate component so that when item.amount or
 * item.tax_amount (@tracked) change, only this cell re-renders — the parent
 * row (which contains MoneyInput) is completely untouched.
 *
 * Args:
 *   @item     {LineItem} – the line item instance
 *   @currency {String}   – ISO 4217 currency code
 */
export default class InvoiceLineItemsAmountComponent extends Component {}
