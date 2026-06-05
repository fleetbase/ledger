import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class InvoiceDetailsComponent extends Component {
    @service invoiceActions;
    @service hostRouter;

    /**
     * The public customer-facing invoice URL.
     * Resolves to: <origin>/~/invoice?id=<invoice.public_id>
     */
    get invoiceUrl() {
        return this.invoiceActions.getInvoiceUrl(this.args.resource);
    }

    get orderLabel() {
        return this.args.resource?.orderTrackingLabel;
    }

    get orderIdentifier() {
        return this.args.resource?.orderRouteIdentifier;
    }

    @action transitionToOrder() {
        if (!this.orderIdentifier) {
            return;
        }

        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', this.orderIdentifier);
    }
}
