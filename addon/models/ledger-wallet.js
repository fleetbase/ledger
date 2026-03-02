import Model, { attr } from '@ember-data/model';

export default class LedgerWalletModel extends Model {
    @attr('string') name;
    @attr('string') owner_uuid;
    @attr('string') owner_type;
    @attr('string') type;
    @attr('string') status;
    @attr('string') currency;
    @attr('number') balance;
    @attr('boolean') is_frozen;
    @attr('raw') meta;
    @attr('date') created_at;
    @attr('date') updated_at;
}
