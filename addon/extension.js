import { Widget, ExtensionComponent, Hook } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('universe/menu-service');
        const hookService = universe.getService('universe/hook-service');
        const widgetService = universe.getService('universe/widget-service');

        // Register header navigation
        menuService.registerHeaderMenuItem('Ledger', 'console.ledger', { icon: 'calculator', priority: 1 });
    }
};
