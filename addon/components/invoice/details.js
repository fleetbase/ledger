import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

export default class InvoiceDetailsComponent extends Component {
    @service invoiceActions;

    /**
     * The public customer-facing invoice URL.
     * Resolves to: <origin>/~/invoice?id=<invoice.public_id>
     */
    get invoiceUrl() {
        return this.invoiceActions.getInvoiceUrl(this.args.resource);
    }
}
