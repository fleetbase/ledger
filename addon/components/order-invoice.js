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
 *   @order    {Model}  The Fleet-Ops order model instance (primary).
 *   @resource {Model}  Also the order model instance (alias passed by the host).
 *   @params   {Object} Optional componentParams passed in by the host.
 *
 * NOTE: This component is rendered inside the Fleet-Ops engine context, so it
 * must NOT inject any services that are only registered in the Ledger engine
 * (e.g. invoiceActions).  All cross-engine functionality must be implemented
 * inline or via services that are in the shared hostServices list.
 */
export default class OrderInvoiceComponent extends Component {
    @service store;
    @service fetch;
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
     *
     * Built inline so this component does not depend on the Ledger-only
     * invoiceActions service, which is not available in the Fleet-Ops engine
     * container and would resolve to undefined, causing a TypeError.
     */
    get invoiceUrl() {
        if (!this.invoice?.public_id) return null;
        const origin = window.location.origin;
        return `${origin}/~/invoice?id=${this.invoice.public_id}`;
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
     *
     * Uses hostRouter to transition to the Ledger billing route so this works
     * from any engine context without needing the Ledger-only invoiceActions
     * service.
     */
    @action openInLedger() {
        if (!this.invoice) return;
        try {
            this.hostRouter.transitionTo('console.ledger.billing.invoices.index.details', this.invoice.id);
        } catch {
            // Fallback: open the public invoice URL in a new tab if the Ledger
            // route is not reachable from the current engine context.
            if (this.invoiceUrl) {
                window.open(this.invoiceUrl, '_blank');
            }
        }
    }
}
