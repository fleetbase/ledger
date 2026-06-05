import { module, test } from 'qunit';
import extension from '@fleetbase/ledger-engine/extension';

module('Unit | extension', function () {
    test('it registers invoice tabs for FleetOps and Storefront order details', function (assert) {
        assert.expect(11);

        const registrations = [];
        const dashboards = [];
        const widgetRegistrations = [];
        const universe = {
            getService(name) {
                if (name === 'universe/menu-service') {
                    return {
                        registerHeaderMenuItem() {},
                        registerMenuItem(registry, menuItem) {
                            registrations.push({ registry, menuItem });
                        },
                    };
                }

                if (name === 'universe/widget-service') {
                    return {
                        registerDashboard(id) {
                            dashboards.push(id);
                        },
                        registerWidgets(id, widgets) {
                            widgetRegistrations.push({ id, widgets });
                        },
                    };
                }
            },
        };

        extension.setupExtension(null, universe);

        const fleetOpsTab = registrations.find(({ registry, menuItem }) => registry === 'fleet-ops:component:order:details' && menuItem.slug === 'invoice');
        const storefrontTab = registrations.find(({ registry, menuItem }) => registry === 'storefront:component:order:details' && menuItem.slug === 'invoice');

        assert.ok(fleetOpsTab);
        assert.strictEqual(fleetOpsTab.menuItem.route, 'operations.orders.index.details.virtual');
        assert.strictEqual(fleetOpsTab.menuItem.icon, 'file-invoice-dollar');
        assert.strictEqual(fleetOpsTab.menuItem.component.name, 'order-invoice');

        assert.ok(storefrontTab);
        assert.strictEqual(storefrontTab.menuItem.route, 'orders.index.view.virtual');
        assert.strictEqual(storefrontTab.menuItem.icon, 'file-invoice-dollar');
        assert.strictEqual(storefrontTab.menuItem.component.name, 'order-invoice');

        assert.deepEqual(dashboards, ['ledger']);

        const ledgerWidgets = widgetRegistrations.find((registration) => registration.id === 'ledger')?.widgets ?? [];
        assert.ok(ledgerWidgets.find((widget) => widget.id === 'ledger-kpi-revenue'));
        assert.ok(ledgerWidgets.find((widget) => widget.id === 'ledger-report-shortcuts'));
        assert.notOk(ledgerWidgets.find((widget) => widget.id === 'ledger-overview')?.default);
    });
});
