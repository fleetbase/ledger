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

        // ── Storefront order details tab: Invoice ────────────────────────────
        // Reuses the same order-invoice component inside the Storefront order
        // details panel so commerce orders expose their generated invoice.
        menuService.registerMenuItem(
            'storefront:component:order:details',
            new MenuItem({
                title: 'Invoice',
                route: 'orders.index.view.virtual',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'order-invoice'),
                icon: 'file-invoice-dollar',
                slug: 'invoice',
            })
        );

        // Register dashboard and widgets
        this.registerWidgets(widgetService);
    },

    registerWidgets(widgetService) {
        const widgets = [
            new Widget({
                id: 'ledger-kpi-revenue',
                name: 'Revenue',
                description: 'Total revenue for the current period with comparison.',
                icon: 'sack-dollar',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-revenue'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-expenses',
                name: 'Expenses',
                description: 'Total expenses for the current period with comparison.',
                icon: 'receipt',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-expenses'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-net-income',
                name: 'Net Income',
                description: 'Revenue less expenses for the current period.',
                icon: 'chart-line',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-net-income'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-outstanding-ar',
                name: 'Outstanding AR',
                description: 'Unpaid receivables balance across open invoices.',
                icon: 'file-invoice-dollar',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-outstanding-ar'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-overdue-ar',
                name: 'Overdue AR',
                description: 'Overdue receivables requiring collection attention.',
                icon: 'clock',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-overdue-ar'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-open-invoices',
                name: 'Open Invoices',
                description: 'Draft, sent, and overdue invoices still requiring action.',
                icon: 'file-circle-exclamation',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-open-invoices'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-wallet-balance',
                name: 'Wallet Balance',
                description: 'Active wallet balances, grouped by currency when needed.',
                icon: 'wallet',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-wallet-balance'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-active-wallets',
                name: 'Active Wallets',
                description: 'Count of active wallets with current balances.',
                icon: 'wallet',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-active-wallets'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),

            new Widget({
                id: 'ledger-revenue-trend',
                name: 'Revenue Trend',
                description: 'Revenue and expense trend over selectable periods.',
                icon: 'chart-line',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/revenue-trend'),
                grid_options: { w: 6, h: 9, minW: 5, minH: 8 },
                category: 'Analytics',
                default: true,
            }),
            new Widget({
                id: 'ledger-cash-flow-summary',
                name: 'Cash Flow Summary',
                description: 'Operating, financing, and investing cash movement.',
                icon: 'money-bill-transfer',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/cash-flow-summary'),
                grid_options: { w: 6, h: 9, minW: 5, minH: 8 },
                category: 'Analytics',
                default: true,
            }),
            new Widget({
                id: 'ledger-invoice-status',
                name: 'Invoice Pipeline',
                description: 'Counts and balances by invoice status.',
                icon: 'file-invoice-dollar',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/invoice-status'),
                grid_options: { w: 4, h: 8, minW: 4, minH: 7 },
                category: 'Operations',
                default: true,
            }),
            new Widget({
                id: 'ledger-ar-aging-summary',
                name: 'AR Aging Risk',
                description: 'Condensed accounts receivable aging buckets.',
                icon: 'clock',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/ar-aging-summary'),
                grid_options: { w: 4, h: 8, minW: 4, minH: 7 },
                category: 'Operations',
                default: true,
            }),
            new Widget({
                id: 'ledger-wallet-balances',
                name: 'Wallet Balances',
                description: 'Total wallet balances grouped by currency across all driver and customer wallets.',
                icon: 'wallet',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/wallet-balances'),
                grid_options: { w: 4, h: 8, minW: 4, minH: 7 },
                category: 'Operations',
                default: true,
            }),
            new Widget({
                id: 'ledger-activity-feed',
                name: 'Recent Financial Activity',
                description: 'Live feed of the most recent double-entry journal entries in the ledger.',
                icon: 'book',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/activity-feed'),
                grid_options: { w: 8, h: 10, minW: 6, minH: 8 },
                category: 'Operations',
                default: true,
            }),
            new Widget({
                id: 'ledger-report-shortcuts',
                name: 'Financial Reports',
                description: 'Shortcuts into Ledger financial reports.',
                icon: 'file-lines',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/report-shortcuts'),
                grid_options: { w: 4, h: 10, minW: 4, minH: 7 },
                category: 'Reports',
                default: true,
            }),

            new Widget({
                id: 'ledger-overview',
                name: 'Financial Overview (Legacy)',
                description: 'Legacy grouped KPI widget. Replaced by individual Ledger KPI tiles.',
                icon: 'gauge-high',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/overview'),
                grid_options: { w: 12, h: 4, minW: 8, minH: 4 },
                options: { title: 'Financial Overview' },
                category: 'Legacy',
                default: false,
            }),
            new Widget({
                id: 'ledger-revenue-chart',
                name: 'Revenue Chart (Legacy)',
                description: 'Legacy daily revenue bar widget. Replaced by Revenue Trend.',
                icon: 'chart-line',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/revenue-chart'),
                grid_options: { w: 8, h: 6, minW: 6, minH: 6 },
                options: { title: 'Revenue Chart' },
                category: 'Legacy',
                default: false,
            }),
            new Widget({
                id: 'ledger-invoice-summary',
                name: 'Invoice Summary (Legacy)',
                description: 'Legacy invoice counts widget. Replaced by Invoice Pipeline.',
                icon: 'file-invoice-dollar',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/invoice-summary'),
                grid_options: { w: 4, h: 6, minW: 3, minH: 5 },
                options: { title: 'Invoice Summary' },
                category: 'Legacy',
                default: false,
            }),
        ];

        const getWidgetById = (id = null, mutate = null) => {
            if (!id) return null;
            const widget = widgets.find((w) => w.id === id);
            if (typeof mutate === 'function') {
                mutate(widget);
            }
            return widget;
        };

        widgetService.registerDashboard('ledger');
        widgetService.registerWidgets('ledger', widgets);
        widgetService.registerWidgets('dashboard', [
            getWidgetById('ledger-activity-feed', (widget) => {
                widget.withGridOptions({ w: 6, minW: 6, h: 8, minH: 8 });
            }),
            getWidgetById('ledger-kpi-revenue'),
            getWidgetById('ledger-kpi-net-income'),
            getWidgetById('ledger-kpi-outstanding-ar'),
            getWidgetById('ledger-kpi-expenses'),
        ]);
    },
};
