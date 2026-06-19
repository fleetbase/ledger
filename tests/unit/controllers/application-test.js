import { module, test } from 'qunit';
import { setupTest } from 'fleetbase-ledger-engine/tests/helpers';

class FetchStub {
    requests = [];
    response = {
        results: [
            {
                label: 'INV-0001',
                description: 'draft USD invoice_123',
                icon: 'file-invoice-dollar',
                type: 'Invoice',
                route: 'console.ledger.billing.invoices.index.details',
                breadcrumb: 'Ledger > Billing > Invoices',
                models: ['invoice_uuid'],
            },
        ],
    };

    get(url, params, options) {
        this.requests.push({ url, params, options });
        return Promise.resolve(this.response);
    }
}

module('Unit | Controller | application', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStub);
    });

    test('it builds Ledger sidebar navigator root items and nested sections', function (assert) {
        const controller = this.owner.lookup('controller:application');
        const items = controller.navigationItems;

        assert.deepEqual(
            items.map((item) => item.label),
            ['Dashboard', 'Billing', 'Payments', 'Accounting', 'Reports', 'Settings'],
            'root items match the Ledger sidebar sections'
        );
        assert.deepEqual(
            items.map((item) => item.icon),
            ['chart-simple', 'file-invoice-dollar', 'money-bill-transfer', 'calculator', 'chart-line', 'gear'],
            'root items keep Ledger-specific icons'
        );

        assert.strictEqual(items[0].route, 'console.ledger.home');
        assert.deepEqual(
            items[1].children.map((item) => item.route),
            ['console.ledger.billing.invoices.index', 'console.ledger.billing.invoice-templates.index'],
            'billing children keep existing routes'
        );
        assert.deepEqual(
            items[2].children.map((item) => item.route),
            ['console.ledger.payments.transactions.index', 'console.ledger.payments.wallets.index', 'console.ledger.payments.gateways.index'],
            'payment children keep existing routes'
        );
        assert.deepEqual(
            items[3].children.map((item) => item.route),
            ['console.ledger.accounting.accounts.index', 'console.ledger.accounting.journal.index', 'console.ledger.accounting.general-ledger'],
            'accounting children keep existing routes'
        );
        assert.deepEqual(
            items[4].children.map((item) => item.route),
            [
                'console.ledger.reports.income-statement',
                'console.ledger.reports.balance-sheet',
                'console.ledger.reports.trial-balance',
                'console.ledger.reports.cash-flow',
                'console.ledger.reports.ar-aging',
                'console.ledger.reports.wallet-summary',
            ],
            'report children keep existing routes'
        );
        assert.deepEqual(
            items[5].children.map((item) => item.route),
            ['console.ledger.settings.invoice', 'console.ledger.settings.payment', 'console.ledger.settings.accounting'],
            'settings children keep existing routes'
        );
    });

    test('it fetches Ledger resource search results for the sidebar navigator', async function (assert) {
        const controller = this.owner.lookup('controller:application');
        const fetch = this.owner.lookup('service:fetch');
        const results = await controller.searchNavigation({ query: ' inv ', limit: 12 });

        assert.deepEqual(
            fetch.requests,
            [
                {
                    url: 'search',
                    params: { query: 'inv', limit: 12 },
                    options: { namespace: 'ledger/int/v1' },
                },
            ],
            'calls the Ledger search endpoint with the trimmed query'
        );
        assert.deepEqual(results, fetch.response.results, 'returns navigator-ready endpoint results');
    });

    test('it skips blank Ledger search queries', async function (assert) {
        const controller = this.owner.lookup('controller:application');
        const fetch = this.owner.lookup('service:fetch');
        const results = await controller.searchNavigation({ query: '   ', limit: 12 });

        assert.deepEqual(results, []);
        assert.deepEqual(fetch.requests, [], 'blank queries do not call the adapter');
    });

    test('it returns empty Ledger search results when the adapter fails', async function (assert) {
        const controller = this.owner.lookup('controller:application');
        const fetch = this.owner.lookup('service:fetch');

        fetch.get = () => Promise.reject(new Error('adapter failed'));

        const results = await controller.searchNavigation({ query: 'invoice', limit: 12 });

        assert.deepEqual(results, []);
    });
});
