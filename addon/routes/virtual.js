import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Top-level virtual route for the Ledger engine.
 *
 * Resolves a registered menu item from the 'engine:ledger' registry by the
 * URL slug and optional section, then passes it as the route model so the
 * template can render the associated component via {{lazy-engine-component}}.
 *
 * URL pattern: /ledger/:section/:slug
 * Example:     /ledger/invoice/INV-0001
 *              /ledger/invoice/abc123-uuid
 */
export default class LedgerVirtualRoute extends Route {
    @service universe;
    @service('universe/menu-service') menuService;

    queryParams = {
        view: {
            refreshModel: true,
        },
    };

    model({ section = null, slug }, transition) {
        const view = this.universe.getViewFromTransition(transition);
        return this.menuService.lookupMenuItem('engine:ledger', slug, view, section);
    }
}
