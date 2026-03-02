import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class WalletActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('ledger-wallet', {
            permissionPrefix: 'ledger',
            mountPrefix: 'console.ledger',
        });
    }

    transition = {
        view: (wallet) => this.transitionTo('payments.wallets.index.details', wallet),
    };

    panel = {
        view: (wallet, options = {}) => {
            return this.resourceContextPanel.open({
                wallet,
                tabs: [{ label: this.intl.t('common.overview'), component: 'wallet/details' }, { label: this.intl.t('common.transactions'), component: 'wallet/transactions' }],
                ...options,
            });
        },
    };
}
