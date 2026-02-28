import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class AccountingAccountsIndexController extends Controller {
    @service hostRouter;
    @service notifications;
    @service modalsManager;

    queryParams = ['page', 'limit', 'sort', 'query', 'type'];

    @tracked page = 1;
    @tracked limit = 100;
    @tracked sort = 'code';
    @tracked query = null;
    @tracked type = null;
    @tracked table = null;

    columns = [
        { label: 'Code', valuePath: 'code', width: '80px', sortable: true },
        { label: 'Account Name', valuePath: 'name', width: '220px', sortable: true },
        { label: 'Type', valuePath: 'type_label', width: '100px' },
        { label: 'Balance', valuePath: 'formatted_balance', width: '140px', sortable: true },
        { label: 'Status', valuePath: 'status_label', width: '90px', component: 'table/cell/status' },
    ];

    get actionButtons() {
        return [
            { label: 'New Account', icon: 'plus', type: 'primary', onClick: this.createAccount },
        ];
    }

    @task({ restartable: true }) *search(query) {
        this.query = query;
    }

    @action createAccount() {
        this.hostRouter.transitionTo('console.ledger.accounting.accounts.index.new');
    }

    @action viewAccount(account) {
        this.hostRouter.transitionTo('console.ledger.accounting.accounts.index.details', account.public_id);
    }

    @action reload() {
        return this.hostRouter.refresh();
    }
}
