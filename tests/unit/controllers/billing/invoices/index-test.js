import { module, test } from 'qunit';
import Service from '@ember/service';
import { setupTest } from 'fleetbase-ledger-engine/tests/helpers';

class IntlStub extends Service {
    t(key) {
        return key;
    }
}

class InvoiceActionsStub extends Service {
    transition = {
        view() {},
        create() {},
        edit() {},
    };

    refresh() {}
    bulkDelete() {}
    recordPayment() {}
    previewInvoice() {}
    copyInvoiceUrl() {}
    delete() {}
}

class TableContextStub extends Service {
    getSelectedRows() {
        return [];
    }
}

class HostRouterStub extends Service {
    transitionTo() {}
}

module('Unit | Controller | billing/invoices/index', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:intl', IntlStub);
        this.owner.register('service:invoice-actions', InvoiceActionsStub);
        this.owner.register('service:table-context', TableContextStub);
        this.owner.register('service:host-router', HostRouterStub);
    });

    test('it declares invoice filter query params', function (assert) {
        const controller = this.owner.lookup('controller:billing/invoices/index');

        assert.deepEqual(controller.queryParams, ['page', 'limit', 'sort', 'query', 'status', 'currency', 'order', 'customer', 'created_at', 'due_date', 'amount']);
    });

    test('it exposes filterable invoice table columns', function (assert) {
        const controller = this.owner.lookup('controller:billing/invoices/index');
        const filters = controller.columns
            .filter((column) => column.filterable)
            .reduce((map, column) => {
                map[column.filterParam] = column;
                return map;
            }, {});

        assert.strictEqual(filters.status.filterComponent, 'filter/select');
        assert.strictEqual(filters.currency.filterComponent, 'filter/string');
        assert.strictEqual(filters.order.filterComponent, 'filter/model');
        assert.strictEqual(filters.order.model, 'order');
        assert.strictEqual(filters.customer.filterComponent, 'filter/model');
        assert.strictEqual(filters.customer.model, 'customer');
        assert.strictEqual(filters.created_at.filterComponent, 'filter/date');
        assert.strictEqual(filters.due_date.filterComponent, 'filter/date');
        assert.strictEqual(filters.amount.filterComponent, 'filter/range');
        assert.strictEqual(filters.amount.min, 0);
        assert.strictEqual(filters.amount.step, 100);
    });
});
