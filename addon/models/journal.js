import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';

export default class JournalModel extends Model {
    @attr('string') number;
    @attr('string') type;
    @attr('string') currency;
    @attr('number') amount;
    @attr('string') description;
    @attr('string') debit_account_uuid;
    @attr('string') debit_account_name;
    @attr('string') debit_account_code;
    @attr('string') credit_account_uuid;
    @attr('string') credit_account_name;
    @attr('string') credit_account_code;
    @attr('boolean') is_system_entry;
    @attr('raw') meta;
    @attr('date') date;
    @attr('date') created_at;
    @attr('date') updated_at;

    @computed('amount', 'currency')
    get formatted_amount() {
        const value = (this.amount || 0) / 100;
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency || 'USD' }).format(value);
    }

    @computed('is_system_entry')
    get entry_source() {
        return this.is_system_entry ? 'System' : 'Manual';
    }

    @computed('type')
    get type_label() {
        const labels = {
            general: 'General',
            payment: 'Payment',
            refund: 'Refund',
            adjustment: 'Adjustment',
            deposit: 'Deposit',
            withdrawal: 'Withdrawal',
            transfer: 'Transfer',
        };
        return labels[this.type] || this.type;
    }
}
