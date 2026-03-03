import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';
import { format as formatDate, formatDistanceToNow, isValid as isValidDate } from 'date-fns';

export default class LedgerInvoiceModel extends Model {
    @attr('string') public_id;
    @attr('string') number;
    @attr('string') status;
    @attr('string') currency;
    @attr('string') subtotal;
    @attr('string') tax_amount;
    @attr('string') discount_amount;
    @attr('string') total;
    @attr('string') amount_paid;
    @attr('string') balance;
    @attr('string') customer_uuid;
    @attr('string') customer_type;
    @attr('string') notes;
    @attr('raw') meta;
    @attr('date') due_date;
    @attr('date') issued_at;
    @attr('date') paid_at;
    @attr('date') created_at;
    @attr('date') updated_at;

    @computed('created_at') get createdAtAgo() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDistanceToNow(this.created_at);
    }

    @computed('created_at') get createdAt() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDate(this.created_at, 'PP HH:mm');
    }

    @computed('created_at') get createdAtShort() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDate(this.created_at, 'dd, MMM');
    }
    @computed('updated_at') get updatedAtAgo() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDistanceToNow(this.updated_at);
    }

    @computed('updated_at') get updatedAt() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDate(this.updated_at, 'PP HH:mm');
    }

    @computed('updated_at') get updatedAtShort() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDate(this.updated_at, 'dd, MMM');
    }
    @computed('due_date') get dueDateAgo() {
        if (!isValidDate(this.due_date)) {
            return null;
        }
        return formatDistanceToNow(this.due_date);
    }

    @computed('due_date') get dueDate() {
        if (!isValidDate(this.due_date)) {
            return null;
        }
        return formatDate(this.due_date, 'PP HH:mm');
    }

    @computed('due_date') get dueDateShort() {
        if (!isValidDate(this.due_date)) {
            return null;
        }
        return formatDate(this.due_date, 'dd, MMM');
    }
    @computed('issued_at') get issuedAtAgo() {
        if (!isValidDate(this.issued_at)) {
            return null;
        }
        return formatDistanceToNow(this.issued_at);
    }

    @computed('issued_at') get issuedAt() {
        if (!isValidDate(this.issued_at)) {
            return null;
        }
        return formatDate(this.issued_at, 'PP HH:mm');
    }

    @computed('issued_at') get issuedAtShort() {
        if (!isValidDate(this.issued_at)) {
            return null;
        }
        return formatDate(this.issued_at, 'dd, MMM');
    }
    @computed('paid_at') get paidAtAgo() {
        if (!isValidDate(this.paid_at)) {
            return null;
        }
        return formatDistanceToNow(this.paid_at);
    }

    @computed('paid_at') get paidAt() {
        if (!isValidDate(this.paid_at)) {
            return null;
        }
        return formatDate(this.paid_at, 'PP HH:mm');
    }

    @computed('paid_at') get paidAtShort() {
        if (!isValidDate(this.paid_at)) {
            return null;
        }
        return formatDate(this.paid_at, 'dd, MMM');
    }
}
