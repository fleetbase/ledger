import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class WalletsIndexController extends Controller {
    @service hostRouter;
    @service notifications;
    @service modalsManager;
    @service fetch;

    queryParams = ['page', 'limit', 'sort', 'query', 'type'];

    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked type = null;
    @tracked table = null;

    columns = [
        { label: 'Name', valuePath: 'name', width: '200px', sortable: true },
        { label: 'Type', valuePath: 'type_label', width: '100px' },
        { label: 'Currency', valuePath: 'currency', width: '80px' },
        { label: 'Balance', valuePath: 'formatted_balance', width: '140px', sortable: true },
        { label: 'Status', valuePath: 'status_label', width: '100px', component: 'table/cell/status' },
    ];

    get actionButtons() {
        return [];
    }

    get bulkActions() {
        return [];
    }

    @task({ restartable: true }) *search(query) {
        this.query = query;
    }

    @action viewWallet(wallet) {
        this.hostRouter.transitionTo('console.ledger.wallets.index.details', wallet.public_id);
    }

    @action async freezeWallet(wallet) {
        try {
            await this.fetch.post(`ledger/int/v1/wallets/${wallet.public_id}/freeze`);
            this.notifications.success('Wallet frozen.');
            this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async unfreezeWallet(wallet) {
        try {
            await this.fetch.post(`ledger/int/v1/wallets/${wallet.public_id}/unfreeze`);
            this.notifications.success('Wallet unfrozen.');
            this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action reload() {
        return this.hostRouter.refresh();
    }
}
