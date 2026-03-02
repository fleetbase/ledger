import Model, { attr } from '@ember-data/model';

export default class LedgerGatewayModel extends Model {
    @attr('string') name;
    @attr('string') code;
    @attr('string') type;
    @attr('string') driver;
    @attr('string') description;
    @attr('string') status;
    @attr('string') environment;
    @attr('boolean') is_default;
    @attr('raw') config;
    @attr('raw') config_schema;
    @attr('date') created_at;
    @attr('date') updated_at;
}
