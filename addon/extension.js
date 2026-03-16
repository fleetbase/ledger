import { MenuItem, Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('universe/menu-service');
        const widgetService = universe.getService('universe/widget-service');

        // Register Ledger in the console header navigation
        menuService.registerHeaderMenuItem('Ledger', 'console.ledger', {
            icon: 'calculator',
            priority: 4,
            description: 'Invoicing, payments, accounting, and real-time financial reporting.',
            shortcuts: [
                {
                    title: 'Invoices',
                    description: 'Create, send, and manage customer invoices.',
                    icon: 'file-invoice-dollar',
                    route: 'console.ledger.billing.invoices',
                },
                {
                    title: 'Wallets',
                    description: 'Manage driver, customer, and company wallets and balances.',
                    icon: 'wallet',
                    route: 'console.ledger.payments.wallets',
                },
                {
                    title: 'Transactions',
                    description: 'A chronological record of every transaction.',
                    icon: 'money-bill-transfer',
                    route: 'console.ledger.payments.transactions',
                },
                {
                    title: 'Payment Gateways',
                    description: 'Configure and manage payment gateway integrations.',
                    icon: 'credit-card',
                    route: 'console.ledger.payments.gateways',
                },
                {
                    title: 'Chart of Accounts',
                    description: 'View and manage the full chart of accounts.',
                    icon: 'sitemap',
                    route: 'console.ledger.accounting.accounts',
                },
                {
                    title: 'Journal Entries',
                    description: 'Browse and create double-entry journal entries.',
                    icon: 'book',
                    route: 'console.ledger.accounting.journal',
                },
                {
                    title: 'General Ledger',
                    description: 'Review all posted transactions across every account.',
                    icon: 'book-open',
                    route: 'console.ledger.accounting.general-ledger',
                },
            ],
        });

        // ── Public customer invoice view ───────────────────────────────────────
        // Registers the customer-facing invoice view to the 'engine:ledger'
        // registry so it is accessible at /ledger/invoice/<public_id> without
        // requiring the customer to be authenticated in the console.
        //
        // URL pattern: /ledger/invoice/<invoice-public_id-or-uuid>
        //
        // The customer-invoice component reads @slug from the route model and
        // fetches the invoice from the public API endpoint:
        //   GET /ledger/public/invoices/<public_id>
        //
        // wrapperClass: 'hidden' keeps this item invisible in all navigation
        // menus while still making it resolvable via the virtual route.
        menuService.registerMenuItem(
            'auth:login',
            new MenuItem({
                title: 'Invoice',
                slug: 'invoice',
                route: 'virtual',
                type: 'link',
                wrapperClass: 'hidden',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'customer-invoice'),
                onClick: (menuItem) => {
                    universe.transitionMenuItem('virtual', menuItem);
                },
            })
        );

        // ── Fleet-Ops order details tab: Invoice ──────────────────────────────
        // Injects an "Invoice" tab into the Fleet-Ops order details panel.
        // The tab renders the order-invoice component which fetches and displays
        // the Ledger invoice associated with the order, including line items and
        // payment summary.
        menuService.registerMenuItem(
            'fleet-ops:component:order:details',
            new MenuItem({
                title: 'Invoice',
                route: 'operations.orders.index.details.virtual',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'order-invoice'),
                icon: 'file-invoice-dollar',
                slug: 'invoice',
            })
        );
        console.log('[menuService]', menuService);
        console.log(menuService.getMenuItems('fleet-ops:component:order:details'));

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
                grid_options: { w: 12, h: 4, minW: 8, minH: 4 },
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
                grid_options: { w: 8, h: 10, minW: 6, minH: 8 },
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
        widgetService.registerWidgets(
            'dashboard',
            widgets.filter((w) => ['ledger-overview', 'ledger-revenue-chart', 'ledger-invoice-summary'].includes(w.id))
        );
    },
};
