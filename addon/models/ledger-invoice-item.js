import Model, { attr, belongsTo } from '@ember-data/model';

export default class LedgerInvoiceItemModel extends Model {
    @attr('string') uuid;
    @attr('string') invoice_uuid;
    @attr('string') description;
    @attr('number') quantity;
    @attr('number') unit_price;  // integer cents
    @attr('number') amount;      // integer cents (computed server-side)
    @attr('number') tax_rate;    // decimal e.g. 10.00 = 10%
    @attr('number') tax_amount;  // integer cents (computed server-side)
    @attr('raw')    meta;
    @attr('date')   created_at;
    @attr('date')   updated_at;

    @belongsTo('ledger-invoice', { async: false, inverse: 'items' }) invoice;
}
