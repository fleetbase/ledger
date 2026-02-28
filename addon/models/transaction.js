import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';

export default class TransactionModel extends Model {
    @attr('string') public_id;
    @attr('string') gateway_transaction_id;
    @attr('string') gateway_code;
    @attr('string') type;
    @attr('string') status;
    @attr('string') currency;
    @attr('number') amount;
    @attr('string') description;
    @attr('string') customer_name;
    @attr('string') customer_email;
    @attr('raw') meta;
    @attr('date') created_at;
    @attr('date') updated_at;

    @computed('amount', 'currency')
    get formatted_amount() {
        const value = (this.amount || 0) / 100;
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency || 'USD' }).format(value);
    }

    @computed('status')
    get status_badge_color() {
        const colors = {
            pending: 'yellow',
            succeeded: 'green',
            failed: 'red',
            refunded: 'blue',
            partially_refunded: 'blue',
            cancelled: 'gray',
        };
        return colors[this.status] || 'gray';
    }
}
