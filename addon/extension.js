import { Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('universe/menu-service');
        const widgetService = universe.getService('universe/widget-service');

        // Register Ledger in the console header navigation
        menuService.registerHeaderMenuItem('Ledger', 'console.ledger', {
            icon: 'calculator',
            priority: 4,
            description: 'Accounting and invoice management.',
            shortcuts: [{ title: 'Transactions', description: 'Ledger transaction records.', route: 'console.ledger.payments.transactions' }],
        });

        // Register dashboard and widgets
        this.registerWidgets(widgetService);
    },

    registerWidgets(widgetService) {
        const widgets = [
            new Widget({
                id: 'ledger-overview',
                name: 'Financial Overview',
                description: 'Key financial KPIs: revenue, expenses, net income, and outstanding AR for the current period.',
                icon: 'gauge-high',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/overview'),
                grid_options: { w: 12, h: 4, minW: 8, minH: 3 },
                options: { title: 'Financial Overview' },
                default: true,
            }),

            new Widget({
                id: 'ledger-revenue-chart',
                name: 'Revenue Chart',
                description: 'Daily revenue trend chart for the current period.',
                icon: 'chart-line',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/revenue-chart'),
                grid_options: { w: 8, h: 6, minW: 6, minH: 5 },
                options: { title: 'Revenue Chart' },
                default: true,
            }),

            new Widget({
                id: 'ledger-invoice-summary',
                name: 'Invoice Summary',
                description: 'Breakdown of invoices by status: draft, sent, paid, overdue, and cancelled.',
                icon: 'file-invoice-dollar',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/invoice-summary'),
                grid_options: { w: 4, h: 6, minW: 3, minH: 5 },
                options: { title: 'Invoice Summary' },
                default: true,
            }),

            new Widget({
                id: 'ledger-wallet-balances',
                name: 'Wallet Balances',
                description: 'Total wallet balances grouped by currency across all driver and customer wallets.',
                icon: 'wallet',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/wallet-balances'),
                grid_options: { w: 4, h: 5, minW: 3, minH: 4 },
                options: { title: 'Wallet Balances' },
                default: true,
            }),

            new Widget({
                id: 'ledger-activity-feed',
                name: 'Recent Journal Entries',
                description: 'Live feed of the most recent double-entry journal entries in the ledger.',
                icon: 'book',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/activity-feed'),
                grid_options: { w: 8, h: 6, minW: 6, minH: 5 },
                options: { title: 'Recent Journal Entries' },
                default: true,
            }),

            new Widget({
                id: 'ledger-ar-aging',
                name: 'AR Aging Summary',
                description: 'Accounts receivable aging buckets: current, 1–30, 31–60, 61–90, and 90+ days overdue.',
                icon: 'clock',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/ar-aging'),
                grid_options: { w: 6, h: 5, minW: 5, minH: 4 },
                options: { title: 'AR Aging Summary' },
            }),

            new Widget({
                id: 'ledger-top-wallets',
                name: 'Top Driver Wallets',
                description: 'Leaderboard of the top 10 driver wallets by current balance.',
                icon: 'ranking-star',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/top-wallets'),
                grid_options: { w: 6, h: 6, minW: 4, minH: 5 },
                options: { title: 'Top Driver Wallets' },
            }),
        ];

        widgetService.registerDashboard('ledger');
        widgetService.registerWidgets('ledger', widgets);
    },
};
