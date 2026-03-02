import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class AccountActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('ledger-account', {
            permissionPrefix: 'ledger',
            mountPrefix: 'console.ledger',
        });
    }
    transition = {
        view: (account) => this.transitionTo('console.ledger.accounting.accounts.index.details', account),
        create: () => this.transitionTo('console.ledger.accounting.accounts.index.new'),
    };
    panel = {
        create: (attributes = {}, options = {}) => {
            const account = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'account/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.account')?.toLowerCase() }),
                saveOptions: { callback: this.refresh },
                useDefaultSaveTask: true,
                account,
                ...options,
            });
        },
        edit: (account, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'account/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: account.name }),
                useDefaultSaveTask: true,
                account,
                ...options,
            });
        },
        view: (account, options = {}) => {
            return this.resourceContextPanel.open({
                account,
                tabs: [
                    { label: this.intl.t('common.overview'), component: 'account/details' },
                    { label: this.intl.t('common.ledger'), component: 'account/ledger' },
                ],
                ...options,
            });
        },
    };
}
