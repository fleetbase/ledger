import Model, { attr } from '@ember-data/model';

export default class LedgerWalletTransactionModel extends Model {
    @attr('string') wallet_uuid;
    @attr('string') type;
    @attr('string') direction;
    @attr('string') status;
    @attr('string') currency;
    @attr('number') amount;
    @attr('number') balance_after;
    @attr('string') reference;
    @attr('string') description;
    @attr('string') notes;
    @attr('raw') meta;
    @attr('date') created_at;
    @attr('date') updated_at;
}
