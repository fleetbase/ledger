import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class BillingInvoicesIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service fetch;
    @service hostRouter;
    @service invoiceActions;

    @tracked overlay = null;

    /**
     * Tab navigation for the details panel.
     *
     * "Line Items" has been removed — they are now displayed inline inside
     * the Invoice::Details component.  "Transactions" remains as its own tab
     * because it loads asynchronously from a separate endpoint.
     */
    get tabs() {
        return [
            { label: 'Details',      route: 'billing.invoices.index.details.index' },
            { label: 'Transactions', route: 'billing.invoices.index.details.transactions' },
        ];
    }

    get actionButtons() {
        const invoice = this.model;
        const buttons = [];

        // Preview — only when an invoice template is assigned.
        if (invoice?.template_uuid) {
            buttons.push({
                label:    'Preview',
                icon:     'eye',
                type:     'default',
                helpText: 'Preview this invoice rendered with its assigned template.',
                onClick:  () => this.invoiceActions.previewInvoice(invoice),
            });
        }

        // Edit — available for all statuses except paid / void / cancelled.
        if (!['paid', 'void', 'cancelled'].includes(invoice?.status)) {
            buttons.push({
                label:    'Edit',
                icon:     'pencil',
                type:     'default',
                helpText: 'Edit this invoice.',
                onClick:  () => this.invoiceActions.panel.edit(invoice),
            });
        }

        // Send — draft invoices only.
        if (invoice?.status === 'draft') {
            buttons.push({
                label:    'Send',
                icon:     'paper-plane',
                type:     'primary',
                helpText: 'Send this invoice to the customer via email.',
                onClick:  this.sendInvoice,
            });
        }

        // Record Payment — for open / overdue / partially-paid invoices.
        if (['sent', 'viewed', 'overdue', 'partial'].includes(invoice?.status)) {
            buttons.push({
                label:    'Record Payment',
                icon:     'check-circle',
                type:     'success',
                helpText: 'Mark a manual payment received against this invoice.',
                onClick:  this.recordPayment,
            });
        }

        // Void — for any non-terminal status.
        if (!['paid', 'void', 'cancelled'].includes(invoice?.status)) {
            buttons.push({
                label:    'Void',
                icon:     'ban',
                type:     'danger',
                helpText: 'Cancel this invoice and mark it as void. This cannot be undone.',
                onClick:  this.voidInvoice,
            });
        }

        return buttons;
    }

    @action async sendInvoice() {
        const invoice = this.model;
        try {
            await this.fetch.post(`invoices/${invoice.id}/send`, {}, { namespace: 'ledger/int/v1' });
            this.notifications.success('Invoice sent successfully.');
            this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async recordPayment() {
        const invoice = this.model;
        this.modalsManager.show('modals/record-payment', { invoice });
    }

    @action async voidInvoice() {
        const invoice = this.model;
        this.modalsManager.confirm({
            title: `Void Invoice ${invoice.number}?`,
            body: 'This will mark the invoice as void and cannot be undone.',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`invoices/${invoice.id}/void`, {}, { namespace: 'ledger/int/v1' });
                    this.notifications.success('Invoice voided.');
                    this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
