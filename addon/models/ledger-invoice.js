import Model, { attr } from '@ember-data/model';

export default class LedgerInvoiceModel extends Model {
    @attr('string') number;
    @attr('string') status;
    @attr('string') currency;
    @attr('number') subtotal;
    @attr('number') tax_amount;
    @attr('number') discount_amount;
    @attr('number') total;
    @attr('number') amount_paid;
    @attr('number') balance;
    @attr('string') customer_uuid;
    @attr('string') customer_type;
    @attr('string') notes;
    @attr('raw') meta;
    @attr('date') due_date;
    @attr('date') issued_at;
    @attr('date') paid_at;
    @attr('date') created_at;
    @attr('date') updated_at;
}
