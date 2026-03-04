import Model, { attr, belongsTo, hasMany } from '@ember-data/model';
import { computed } from '@ember/object';
import { format as formatDate, formatDistanceToNow, isValid as isValidDate } from 'date-fns';

export default class LedgerInvoiceModel extends Model {
    // -------------------------------------------------------------------------
    // Identifiers
    // -------------------------------------------------------------------------
    @attr('string') public_id;
    @attr('string') number;

    // -------------------------------------------------------------------------
    // Foreign keys
    // -------------------------------------------------------------------------
    @attr('string') customer_uuid;
    @attr('string') customer_type;
    @attr('string') order_uuid;
    @attr('string') transaction_uuid;
    @attr('string') template_uuid;

    // -------------------------------------------------------------------------
    // Scalar fields
    // -------------------------------------------------------------------------
    @attr('string') status;
    @attr('string') currency;
    @attr('string') notes;
    @attr('string') terms;
    @attr('number') subtotal;      // integer cents
    @attr('number') tax;           // integer cents
    @attr('number') total_amount;  // integer cents
    @attr('number') amount_paid;   // integer cents
    @attr('number') balance;       // integer cents
    @attr('raw')    meta;

    // -------------------------------------------------------------------------
    // Dates
    // -------------------------------------------------------------------------
    @attr('date') date;
    @attr('date') due_date;
    @attr('date') paid_at;
    @attr('date') sent_at;
    @attr('date') viewed_at;
    @attr('date') created_at;
    @attr('date') updated_at;

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------
    @hasMany('ledger-invoice-item', { async: false, inverse: 'invoice' }) items;
    @belongsTo('customer', { async: false, polymorphic: true, inverse: null }) customer;
    @belongsTo('template', { async: false, inverse: null }) template;

    // -------------------------------------------------------------------------
    // Date helpers — date (invoice date)
    // -------------------------------------------------------------------------
    @computed('date') get invoiceDateAgo() {
        if (!isValidDate(this.date)) { return null; }
        return formatDistanceToNow(this.date);
    }

    @computed('date') get invoiceDate() {
        if (!isValidDate(this.date)) { return null; }
        return formatDate(this.date, 'PP');
    }

    @computed('date') get invoiceDateShort() {
        if (!isValidDate(this.date)) { return null; }
        return formatDate(this.date, 'dd MMM');
    }

    // -------------------------------------------------------------------------
    // Date helpers — due_date
    // -------------------------------------------------------------------------
    @computed('due_date') get dueDateAgo() {
        if (!isValidDate(this.due_date)) { return null; }
        return formatDistanceToNow(this.due_date);
    }

    @computed('due_date') get dueDate() {
        if (!isValidDate(this.due_date)) { return null; }
        return formatDate(this.due_date, 'PP');
    }

    @computed('due_date') get dueDateShort() {
        if (!isValidDate(this.due_date)) { return null; }
        return formatDate(this.due_date, 'dd MMM');
    }

    // -------------------------------------------------------------------------
    // Date helpers — paid_at
    // -------------------------------------------------------------------------
    @computed('paid_at') get paidAtAgo() {
        if (!isValidDate(this.paid_at)) { return null; }
        return formatDistanceToNow(this.paid_at);
    }

    @computed('paid_at') get paidAt() {
        if (!isValidDate(this.paid_at)) { return null; }
        return formatDate(this.paid_at, 'PP HH:mm');
    }

    // -------------------------------------------------------------------------
    // Date helpers — created_at / updated_at
    // -------------------------------------------------------------------------
    @computed('created_at') get createdAtAgo() {
        if (!isValidDate(this.created_at)) { return null; }
        return formatDistanceToNow(this.created_at);
    }

    @computed('created_at') get createdAt() {
        if (!isValidDate(this.created_at)) { return null; }
        return formatDate(this.created_at, 'PP HH:mm');
    }

    @computed('created_at') get createdAtShort() {
        if (!isValidDate(this.created_at)) { return null; }
        return formatDate(this.created_at, 'dd MMM');
    }

    @computed('updated_at') get updatedAtAgo() {
        if (!isValidDate(this.updated_at)) { return null; }
        return formatDistanceToNow(this.updated_at);
    }

    @computed('updated_at') get updatedAt() {
        if (!isValidDate(this.updated_at)) { return null; }
        return formatDate(this.updated_at, 'PP HH:mm');
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------
    @computed('status') get isOverdue() {
        return this.status === 'overdue';
    }

    @computed('status') get isPaid() {
        return this.status === 'paid';
    }

    @computed('status') get isDraft() {
        return this.status === 'draft';
    }
}
