import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class PaymentsWalletsIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service fetch;
    @service hostRouter;

    @tracked overlay = null;

    get tabs() {
        return [
            { label: 'Overview', route: 'payments.wallets.index.details.index' },
            { label: 'Transactions', route: 'payments.wallets.index.details.transactions' },
        ];
    }

    get actionButtons() {
        return [
            { label: 'Top Up', icon: 'plus-circle', type: 'primary', onClick: this.topUpWallet },
            { label: 'Transfer', icon: 'exchange-alt', onClick: this.transferFunds },
        ];
    }

    @action async topUpWallet() {
        const wallet = this.model;
        this.modalsManager.show('modals/wallet-top-up', { wallet });
    }

    @action async transferFunds() {
        const wallet = this.model;
        this.modalsManager.show('modals/wallet-transfer', { wallet });
    }
}
