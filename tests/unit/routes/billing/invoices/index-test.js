import { module, test } from 'qunit';
import Service from '@ember/service';
import { setupTest } from 'fleetbase-ledger-engine/tests/helpers';

class StoreStub extends Service {
    requests = [];

    query(modelName, params) {
        this.requests.push({ modelName, params });
        return Promise.resolve([]);
    }
}

module('Unit | Route | billing/invoices/index', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:store', StoreStub);
    });

    test('it refreshes the model for invoice filter query params', function (assert) {
        const route = this.owner.lookup('route:billing/invoices/index');

        assert.deepEqual(Object.keys(route.queryParams), ['page', 'limit', 'sort', 'query', 'status', 'currency', 'order', 'customer', 'created_at', 'due_date', 'amount']);
        assert.true(route.queryParams.currency.refreshModel);
        assert.true(route.queryParams.order.refreshModel);
        assert.true(route.queryParams.customer.refreshModel);
        assert.true(route.queryParams.created_at.refreshModel);
        assert.true(route.queryParams.due_date.refreshModel);
        assert.true(route.queryParams.amount.refreshModel);
    });

    test('it queries ledger invoices with the active params', async function (assert) {
        const route = this.owner.lookup('route:billing/invoices/index');
        const store = this.owner.lookup('service:store');
        const params = {
            status: 'paid',
            currency: 'usd',
            order: 'order_123',
            customer: 'contact_123',
            created_at: '2026-06-01,2026-06-30',
            due_date: '2026-07-01,2026-07-31',
            amount: '1000,5000',
        };

        await route.model(params);

        assert.deepEqual(store.requests, [{ modelName: 'ledger-invoice', params }]);
    });
});
