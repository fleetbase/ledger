import Model, { attr } from '@ember-data/model';

export default class LedgerAccountModel extends Model {
    @attr('string') name;
    @attr('string') code;
    @attr('string') type;
    @attr('string') status;
    @attr('string') currency;
    @attr('number') balance;
    @attr('string') description;
    @attr('boolean') is_active;
    @attr('raw') meta;
    @attr('date') created_at;
    @attr('date') updated_at;
}
