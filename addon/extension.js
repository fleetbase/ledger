import { Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');
        const widgetService = universe.getService('widget');

        // Register Ledger in the console header navigation
        menuService.registerHeaderMenuItem('Ledger', 'console.ledger', { icon: 'calculator', priority: 4 });

        // Register dashboard widget
        const widgets = [
            new Widget({
                id: 'ledger-financial-summary-widget',
                name: 'Financial Summary',
                description: 'Key financial metrics including revenue, expenses, and outstanding invoices.',
                icon: 'calculator',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/financial-summary'),
                grid_options: { w: 6, h: 8, minW: 6, minH: 8 },
                options: { title: 'Financial Summary' },
            }),
        ];

        widgetService.registerWidgets('dashboard', widgets);
    },
};
