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
    @attr('number') total_amount;  // integer cents — canonical "total" field
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
    // Monetary aliases
    // -------------------------------------------------------------------------

    /** Alias for total_amount — used by format-currency helpers. */
    @computed('total_amount') get total() {
        return this.total_amount;
    }

    // -------------------------------------------------------------------------
    // Customer convenience accessors
    // -------------------------------------------------------------------------

    @computed('customer.name') get customerName() {
        return this.customer?.name ?? null;
    }

    @computed('customer.email') get customerEmail() {
        return this.customer?.email ?? null;
    }

    @computed('customer.phone') get customerPhone() {
        return this.customer?.phone ?? null;
    }

    // -------------------------------------------------------------------------
    // Template convenience accessor
    // -------------------------------------------------------------------------

    @computed('template.name') get templateName() {
        return this.template?.name ?? null;
    }

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

    /** Alias so templates can use @resource.issuedAt consistently. */
    @computed('date') get issuedAt() {
        return this.invoiceDate;
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
    // Date helpers — sent_at / viewed_at
    // -------------------------------------------------------------------------
    @computed('sent_at') get sentAt() {
        if (!isValidDate(this.sent_at)) { return null; }
        return formatDate(this.sent_at, 'PP HH:mm');
    }

    @computed('viewed_at') get viewedAt() {
        if (!isValidDate(this.viewed_at)) { return null; }
        return formatDate(this.viewed_at, 'PP HH:mm');
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

    @computed('status') get isSent() {
        return this.status === 'sent';
    }

    @computed('status') get isVoid() {
        return this.status === 'void' || this.status === 'cancelled';
    }

    /**
     * Returns a Tailwind colour name appropriate for the current status,
     * compatible with the <Badge @color=...> component.
     */
    @computed('status') get statusBadgeColor() {
        const map = {
            draft:     'gray',
            sent:      'blue',
            viewed:    'indigo',
            partial:   'yellow',
            paid:      'green',
            overdue:   'red',
            cancelled: 'red',
            void:      'red',
        };
        return map[this.status] ?? 'gray';
    }
}
