import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class TransactionActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('ledger-transaction', {
            permissionPrefix: 'ledger',
            mountPrefix: 'console.ledger',
        });
    }

    transition = {
        view: (transaction) => this.transitionTo('payments.transactions.index.details', transaction),
    };

    panel = {
        view: (transaction, options = {}) => {
            return this.resourceContextPanel.open({
                transaction,
                tabs: [{ label: this.intl.t('common.overview'), component: 'transaction/details' }],
                ...options,
            });
        },
    };
}
