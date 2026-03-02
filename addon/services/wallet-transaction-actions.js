import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class WalletTransactionActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('ledger-wallet-transaction', {
            permissionPrefix: 'ledger',
            mountPrefix: 'console.ledger',
        });
    }
    transition = {
        view: (wallet-transaction) => this.transitionTo('console.ledger.payments.wallets.index.details.transactions', wallet-transaction),
    };
    panel = {
        view: (walletTransaction, options = {}) => {
            return this.resourceContextPanel.open({
                walletTransaction,
                tabs: [{ label: this.intl.t('common.overview'), component: 'wallet-transaction/details' }],
                ...options,
            });
        },
    };
}
