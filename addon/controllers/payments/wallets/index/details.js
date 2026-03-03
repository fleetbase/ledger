import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class PaymentsWalletsIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service fetch;
    @service hostRouter;
    @service intl;

    @tracked overlay = null;

    get tabs() {
        return [
            { label: 'Overview', route: 'payments.wallets.index.details.index' },
            { label: 'Transactions', route: 'payments.wallets.index.details.transactions' },
        ];
    }

    get actionButtons() {
        return [
            { label: 'Add Funds', icon: 'plus-circle', type: 'primary', helpText: 'Add funds to this wallet balance.', onClick: this.topUpWallet },
            { label: 'Transfer', icon: 'exchange-alt', helpText: 'Transfer funds from this wallet to another wallet.', onClick: this.transferFunds },
        ];
    }

    @action async topUpWallet() {
        const wallet = this.model;

        const options = {
            title: `Add Funds — ${wallet.name}`,
            acceptButtonText: 'Add Funds',
            acceptButtonIcon: 'plus-circle',
            wallet,
            amount: null,
            description: '',
            setAmount: (event) => {
                options.amount = event.target.value;
            },
            setDescription: (event) => {
                options.description = event.target.value;
            },
            confirm: async (modal) => {
                const amountFloat = parseFloat(options.amount);
                if (!amountFloat || amountFloat <= 0) {
                    this.notifications.warning('Please enter a valid amount greater than zero.');
                    return;
                }
                modal.startLoading();
                try {
                    const amountInCents = Math.round(amountFloat * 100);
                    await this.fetch.post(
                        `wallets/${wallet.id}/credit`,
                        { amount: amountInCents, description: options.description || 'Manual top-up' },
                        { namespace: 'ledger/int/v1' }
                    );
                    this.notifications.success(`${wallet.currency} ${amountFloat.toFixed(2)} added to ${wallet.name}.`);
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
            amount: null,
            description: '',
            setToWallet: (selectedWallet) => {
                options.toWallet = selectedWallet;
            },
            setAmount: (event) => {
                options.amount = event.target.value;
            },
            setDescription: (event) => {
                options.description = event.target.value;
            },
            confirm: async (modal) => {
                if (!options.toWallet) {
                    this.notifications.warning('Please select a destination wallet.');
                    return;
                }
                const amountFloat = parseFloat(options.amount);
                if (!amountFloat || amountFloat <= 0) {
                    this.notifications.warning('Please enter a valid amount greater than zero.');
                    return;
                }
                if (options.toWallet.id === wallet.id) {
                    this.notifications.warning('Cannot transfer funds to the same wallet.');
                    return;
                }
                modal.startLoading();
                try {
                    const amountInCents = Math.round(amountFloat * 100);
                    await this.fetch.post(
                        `wallets/${wallet.id}/transfer`,
                        {
                            to_wallet_uuid: options.toWallet.id,
                            amount: amountInCents,
                            description: options.description || 'Internal transfer',
                        },
                        { namespace: 'ledger/int/v1' }
                    );
                    this.notifications.success(`${wallet.currency} ${amountFloat.toFixed(2)} transferred to ${options.toWallet.name}.`);
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
