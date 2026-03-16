import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * OrderInvoice component.
 *
 * Renders the Ledger invoice associated with a Fleet-Ops order inside the
 * order details tab panel.  Registered via extension.js as:
 *
 *   menuService.registerMenuItem(
 *     'fleet-ops:component:order:details',
 *     new MenuItem({
 *       title: 'Invoice',
 *       route: 'operations.orders.index.details.virtual',
 *       component: new ExtensionComponent('@fleetbase/ledger-engine', 'order-invoice'),
 *       icon: 'file-alt',
 *       slug: 'invoice',
 *     })
 *   );
 *
 * Args:
 *   @order   {Model}  The Fleet-Ops order model instance (primary).
 *   @resource {Model} Also the order model instance (alias passed by the host).
 *   @params  {Object} Optional componentParams passed in by the host.
 */
export default class OrderInvoiceComponent extends Component {
    @service store;
    @service fetch;
    @service invoiceActions;
    @service hostRouter;
    @service notifications;

    /** The resolved LedgerInvoice record, or null if not yet loaded / not found. */
    @tracked invoice = null;

    constructor(owner, args) {
        super(owner, args);
        this.loadInvoice.perform();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The order model — prefer @order, fall back to @resource (both are the
     * same Fleet-Ops order instance; the host may pass either or both).
     */
    get order() {
        return this.args.order ?? this.args.resource;
    }

    /**
     * The public customer-facing invoice URL.
     * Resolves to: <origin>/~/invoice?id=<invoice.public_id>
     */
    get invoiceUrl() {
        if (!this.invoice) return null;
        return this.invoiceActions.getInvoiceUrl(this.invoice);
    }

    // ── Data loading ──────────────────────────────────────────────────────────

    /**
     * Fetch the invoice for this order from the Ledger API.
     *
     * The backend InvoiceController supports filtering by `order_uuid`, so we
     * use `store.query` which normalises the response into proper Ember Data
     * records (including sideloaded `ledger-invoice-item` records).
     *
     * We take the first result — an order should only ever have one invoice.
     */
    @task *loadInvoice() {
        const order = this.order;

        if (!order?.id) {
            this.invoice = null;
            return;
        }

        try {
            const results = yield this.store.query('ledger-invoice', {
                order_uuid: order.id,
                with: 'items',
                limit: 1,
            });

            this.invoice = results.firstObject ?? null;
        } catch {
            this.invoice = null;
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Navigate to the full invoice detail view inside the Ledger engine.
     * Uses the invoiceActions transition helper so the correct Ledger route
     * is resolved regardless of the current engine context.
     */
    @action openInLedger() {
        if (!this.invoice) return;
        this.invoiceActions.transition.view(this.invoice);
    }
}
