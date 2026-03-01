import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class BillingInvoicesIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service fetch;
    @service hostRouter;

    get tabs() {
        return [
            { label: 'Details', route: 'console.ledger.billing.invoices.index.details.index' },
            { label: 'Line Items', route: 'console.ledger.billing.invoices.index.details.line-items' },
            { label: 'Transactions', route: 'console.ledger.billing.invoices.index.details.transactions' },
        ];
    }

    get actionButtons() {
        const invoice = this.model;
        const buttons = [];

        if (invoice?.status === 'draft') {
            buttons.push({ label: 'Send', icon: 'paper-plane', type: 'primary', onClick: this.sendInvoice });
        }
        if (['sent', 'overdue', 'partial'].includes(invoice?.status)) {
            buttons.push({ label: 'Record Payment', icon: 'check-circle', type: 'success', onClick: this.recordPayment });
        }
        if (!['paid', 'void'].includes(invoice?.status)) {
            buttons.push({ label: 'Void', icon: 'ban', type: 'danger', onClick: this.voidInvoice });
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
