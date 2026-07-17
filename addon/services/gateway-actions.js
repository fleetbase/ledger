import { action } from '@ember/object';
import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class GatewayActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('ledger-gateway', {
            permissionPrefix: 'ledger',
            mountPrefix: 'console.ledger',
        });
    }

    transition = {
        view: (gateway) => this.transitionTo('payments.gateways.details', gateway),
        create: (driver) =>
            this.transitionTo('payments.gateways.new', {
                queryParams: {
                    driver: typeof driver === 'string' ? driver : null,
                },
            }),
        edit: (gateway) => this.transitionTo('payments.gateways.edit', gateway),
    };

    @action edit(gateway) {
        return this.transitionTo('payments.gateways.edit', gateway);
    }

    panel = {
        create: (attributes = {}) => this.transition.create(attributes.driver),

        edit: (gateway) => this.transition.edit(gateway),

        view: (gateway) => this.transition.view(gateway),
    };
}
