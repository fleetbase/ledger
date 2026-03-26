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
        view: (gateway) => this.transitionTo('payments.gateways.index.details', gateway),
        create: () => this.transitionTo('payments.gateways.index.new'),
        edit: (gateway) => this.transitionTo('payments.gateways.index.edit', gateway),
    };

    @action edit(gateway) {
        return this.transitionTo('payments.gateways.index.edit', gateway);
    }

    panel = {
        create: (attributes = {}, options = {}) => {
            const gateway = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'gateway/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.gateway')?.toLowerCase() }),
                saveOptions: { callback: this.refresh },
                useDefaultSaveTask: true,
                gateway,
                ...options,
            });
        },

        edit: (gateway, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'gateway/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: gateway.public_id }),
                useDefaultSaveTask: true,
                gateway,
                ...options,
            });
        },

        view: (gateway, options = {}) => {
            return this.resourceContextPanel.open({
                gateway,
                tabs: [{ label: this.intl.t('common.overview'), component: 'gateway/details' }],
                ...options,
            });
        },
    };
}
