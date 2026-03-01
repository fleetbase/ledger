import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';

export default class InvoiceModel extends Model {
    @attr('string') number;
    @attr('string') status;
    @attr('string') currency;
    @attr('number') subtotal;
    @attr('number') tax;
    @attr('number') total;
    @attr('number') amount_paid;
    @attr('number') balance;
    @attr('string') notes;
    @attr('string') customer_name;
    @attr('string') customer_email;
    @attr('string') customer_phone;
    @attr('raw') line_items;
    @attr('raw') meta;
    @attr('date') due_at;
    @attr('date') paid_at;
    @attr('date') voided_at;
    @attr('date') created_at;
    @attr('date') updated_at;

    @computed('total', 'currency')
    get formatted_total() {
        const amount = (this.total || 0) / 100;
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency || 'USD' }).format(amount);
    }

    @computed('balance', 'currency')
    get formatted_balance() {
        const amount = (this.balance || 0) / 100;
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency || 'USD' }).format(amount);
    }

    @computed('amount_paid', 'currency')
    get formatted_amount_paid() {
        const amount = (this.amount_paid || 0) / 100;
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency || 'USD' }).format(amount);
    }

    @computed('status')
    get status_badge_color() {
        const colors = {
            draft: 'gray',
            sent: 'blue',
            paid: 'green',
            partial: 'yellow',
            overdue: 'red',
            void: 'gray',
        };
        return colors[this.status] || 'gray';
    }

    @computed('status')
    get is_paid() {
        return this.status === 'paid';
    }

    @computed('status')
    get is_overdue() {
        return this.status === 'overdue';
    }

    @computed('status')
    get is_draft() {
        return this.status === 'draft';
    }
}
