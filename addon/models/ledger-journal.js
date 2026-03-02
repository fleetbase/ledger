import Model, { attr } from '@ember-data/model';

export default class LedgerJournalModel extends Model {
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
    @attr('string') transaction_uuid;
    @attr('boolean') is_system_entry;
    @attr('raw') meta;
    @attr('date') entry_date;
    @attr('date') created_at;
    @attr('date') updated_at;

    get entry_source() {
        return this.is_system_entry ? 'System' : 'Manual';
    }
}
