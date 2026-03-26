import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class BillingTransactionsIndexController extends Controller {
    @service hostRouter;

    queryParams = ['page', 'limit', 'sort', 'query', 'status'];

    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked status = null;
    @tracked table = null;

    columns = [
        { label: 'Date', valuePath: 'created_at', width: '140px', sortable: true, component: 'table/cell/date' },
        { label: 'Reference', valuePath: 'gateway_transaction_id', width: '200px' },
        { label: 'Gateway', valuePath: 'gateway_code', width: '120px' },
        { label: 'Customer', valuePath: 'customer_name', width: '160px' },
        { label: 'Amount', valuePath: 'formatted_amount', width: '120px', sortable: true },
        { label: 'Status', valuePath: 'status', width: '100px', component: 'table/cell/status' },
    ];

    get actionButtons() {
        return [];
    }

    @task({ restartable: true }) *search(query) {
        yield Promise.resolve();
        this.query = query;
    }

    @action viewTransaction(txn) {
        this.hostRouter.transitionTo('console.ledger.billing.transactions.index.details', txn.id);
    }

    @action reload() {
        return this.hostRouter.refresh();
    }
}
