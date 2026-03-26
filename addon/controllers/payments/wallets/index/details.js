import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class PaymentsWalletsIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service fetch;
    @service intl;

    get tabs() {
        return [
            { label: 'Overview', route: 'payments.wallets.index.details.index' },
            { label: 'Transactions', route: 'payments.wallets.index.details.transactions' },
        ];
    }

    get actionButtons() {
        const wallet = this.model;
        const frozen = wallet?.is_frozen;

        return [
            { label: 'Add Funds', icon: 'plus-circle', type: 'primary', helpText: 'Add funds to this wallet balance.', onClick: this.topUpWallet },
            { label: 'Transfer', icon: 'exchange-alt', helpText: 'Transfer funds from this wallet to another wallet.', onClick: this.transferFunds },
            {
                label: frozen ? 'Unfreeze' : 'Freeze',
                icon: frozen ? 'unlock' : 'lock',
                type: frozen ? 'default' : 'danger',
                helpText: frozen ? 'Unfreeze this wallet to allow debits.' : 'Freeze this wallet to block all debits.',
                onClick: frozen ? this.unfreezeWallet : this.freezeWallet,
            },
        ];
    }

    @action async freezeWallet() {
        const wallet = this.model;

        this.modalsManager.confirm({
            title: `Freeze ${wallet.name}?`,
            body: 'Freezing this wallet will block all debit transactions. Credits will still be accepted. You can unfreeze it at any time.',
            acceptButtonText: 'Freeze Wallet',
            acceptButtonIcon: 'lock',
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`wallets/${wallet.id}/freeze`, {}, { namespace: 'ledger/int/v1' });
                    this.notifications.success(`${wallet.name} has been frozen.`);
                    await wallet.reload();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action async unfreezeWallet() {
        const wallet = this.model;

        this.modalsManager.confirm({
            title: `Unfreeze ${wallet.name}?`,
            body: 'Unfreezing this wallet will restore normal operation and allow debit transactions.',
            acceptButtonText: 'Unfreeze Wallet',
            acceptButtonIcon: 'unlock',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`wallets/${wallet.id}/unfreeze`, {}, { namespace: 'ledger/int/v1' });
                    this.notifications.success(`${wallet.name} has been unfrozen.`);
                    await wallet.reload();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action async topUpWallet() {
        const wallet = this.model;

        const options = {
            title: `Add Funds — ${wallet.name}`,
            acceptButtonText: 'Add Funds',
            acceptButtonIcon: 'plus-circle',
            wallet,
            amount: 0,
            description: '',
            setAmount: (centsValue) => {
                options.amount = centsValue;
            },
            setDescription: (event) => {
                options.description = event.target.value;
            },
            confirm: async (modal) => {
                if (!options.amount || options.amount <= 0) {
                    this.notifications.warning('Please enter a valid amount greater than zero.');
                    return;
                }
                modal.startLoading();
                try {
                    await this.fetch.post(`wallets/${wallet.id}/credit`, { amount: options.amount, description: options.description || 'Manual top-up' }, { namespace: 'ledger/int/v1' });
                    this.notifications.success(`Funds added to ${wallet.name}.`);
                    await wallet.reload();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        };

        this.modalsManager.show('modals/wallet-top-up', options);
    }

    @action async transferFunds() {
        const wallet = this.model;

        const options = {
            title: `Transfer Funds — ${wallet.name}`,
            acceptButtonText: 'Transfer',
            acceptButtonIcon: 'exchange-alt',
            wallet,
            toWallet: null,
            amount: 0,
            description: '',
            setToWallet: (selectedWallet) => {
                options.toWallet = selectedWallet;
            },
            setAmount: (centsValue) => {
                options.amount = centsValue;
            },
            setDescription: (event) => {
                options.description = event.target.value;
            },
            confirm: async (modal) => {
                if (!options.toWallet) {
                    this.notifications.warning('Please select a destination wallet.');
                    return;
                }
                if (!options.amount || options.amount <= 0) {
                    this.notifications.warning('Please enter a valid amount greater than zero.');
                    return;
                }
                if (options.toWallet.id === wallet.id) {
                    this.notifications.warning('Cannot transfer funds to the same wallet.');
                    return;
                }
                modal.startLoading();
                try {
                    await this.fetch.post(
                        `wallets/${wallet.id}/transfer`,
                        {
                            to_wallet_uuid: options.toWallet.id,
                            amount: options.amount,
                            description: options.description || 'Internal transfer',
                        },
                        { namespace: 'ledger/int/v1' }
                    );
                    this.notifications.success(`Funds transferred to ${options.toWallet.name}.`);
                    await wallet.reload();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        };

        this.modalsManager.show('modals/wallet-transfer', options);
    }
}
