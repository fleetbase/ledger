import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class WalletsIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service fetch;
    @service hostRouter;

    get tabs() {
        return [
            { label: 'Details', route: 'console.ledger.wallets.index.details.index' },
            { label: 'Transactions', route: 'console.ledger.wallets.index.details.transactions' },
        ];
    }

    get actionButtons() {
        const wallet = this.model;
        const buttons = [
            { label: 'Deposit', icon: 'arrow-down', type: 'success', onClick: this.depositFunds },
            { label: 'Withdraw', icon: 'arrow-up', type: 'default', onClick: this.withdrawFunds },
        ];
        if (wallet?.is_frozen) {
            buttons.push({ label: 'Unfreeze', icon: 'unlock', type: 'primary', onClick: this.unfreezeWallet });
        } else {
            buttons.push({ label: 'Freeze', icon: 'lock', type: 'danger', onClick: this.freezeWallet });
        }
        return buttons;
    }

    @action async depositFunds() {
        this.modalsManager.show('modals/wallet-deposit', { wallet: this.model });
    }

    @action async withdrawFunds() {
        this.modalsManager.show('modals/wallet-withdraw', { wallet: this.model });
    }

    @action async freezeWallet() {
        try {
            await this.fetch.post(`wallets/${this.model.id}/freeze`, {}, { namespace: 'ledger/int/v1' });
            this.notifications.success('Wallet frozen.');
            this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async unfreezeWallet() {
        try {
            await this.fetch.post(`wallets/${this.model.id}/unfreeze`, {}, { namespace: 'ledger/int/v1' });
            this.notifications.success('Wallet unfrozen.');
            this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
