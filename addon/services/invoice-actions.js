import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';

export default class InvoiceActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('ledger-invoice', {
            permissionPrefix: 'ledger',
            mountPrefix: 'console.ledger',
        });
    }

    transition = {
        view: (invoice) => this.panel.view(invoice),
        create: () => this.transitionTo('billing.invoices.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const invoice = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'invoice/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.invoice')?.toLowerCase() }),
                saveOptions: { callback: this.refresh },
                resource: invoice,
                ...options,
            });
        },

        edit: (invoice, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'invoice/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: invoice.public_id }),
                saveOptions: { callback: this.refresh },
                resource: invoice,
                ...options,
            });
        },

        view: (invoice, options = {}) => {
            return this.resourceContextPanel.open({
                resource: invoice,
                tabs: [{ label: this.intl.t('common.overview'), component: 'invoice/details' }],
                ...options,
            });
        },
    };

    /**
     * Render the invoice using its assigned template and display the HTML
     * in a modal, with Print and Download PDF actions in the footer.
     *
     * @param {Model} invoice - A persisted ledger-invoice Ember Data record.
     * @param {Object} [options={}] - Optional overrides forwarded to modalsManager.show.
     */
    @action async recordPayment(invoice, options = {}) {
        const modalOptions = {
            title:                `Record Payment — ${invoice.number}`,
            acceptButtonText:     'Record Payment',
            acceptButtonIcon:     'check-circle',
            invoice,
            amount:               invoice.balance ?? 0,
            paymentMethod:        'bank_transfer',
            reference:            '',
            paymentMethodOptions: [
                { label: 'Bank Transfer', value: 'bank_transfer' },
                { label: 'Cash',          value: 'cash' },
                { label: 'Cheque',        value: 'cheque' },
                { label: 'Credit Card',   value: 'credit_card' },
                { label: 'Debit Card',    value: 'debit_card' },
                { label: 'PayPal',        value: 'paypal' },
                { label: 'Stripe',        value: 'stripe' },
                { label: 'Other',         value: 'other' },
            ],
            setAmount: (centsValue) => {
                modalOptions.amount = centsValue;
            },
            setPaymentMethod: (value) => {
                modalOptions.paymentMethod = value;
            },
            setReference: (event) => {
                modalOptions.reference = event.target.value;
            },
            confirm: async (modal) => {
                if (!modalOptions.amount || modalOptions.amount <= 0) {
                    this.notifications.warning('Please enter a valid payment amount greater than zero.');
                    return;
                }
                modal.startLoading();
                try {
                    await this.fetch.post(
                        `invoices/${invoice.id}/record-payment`,
                        {
                            amount:         modalOptions.amount,
                            payment_method: modalOptions.paymentMethod,
                            reference:      modalOptions.reference || null,
                        },
                        { namespace: 'ledger/int/v1' }
                    );
                    this.notifications.success('Payment recorded successfully.');
                    await invoice.reload();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        };
        this.modalsManager.show('modals/record-payment', modalOptions);
    }

    @action async previewInvoice(invoice, options = {}) {
        const title = invoice.number
            ? this.intl.t('invoice.actions.preview-invoice', { number: invoice.number })
            : this.intl.t('invoice.actions.preview-invoice-fallback');

        // Reset cached HTML each time a new preview is opened.
        this._previewHtml = null;

        // Open immediately with a loading spinner for instant feedback.
        this.modalsManager.show('modals/invoice-preview', {
            title,
            modalClass: 'modal-xl',
            footerComponent: 'modals/invoice-preview/footer',
            hideFooterActions: true,
            isLoading: true,
            isPdfLoading: false,
            html: null,
            onPrint: () => this._printInvoicePreview(),
            onDownloadPdf: () => this._downloadInvoicePdf(invoice),
            ...options,
        });

        try {
            const { html } = await this.fetch.post(`invoices/${invoice.id}/preview`, {}, { namespace: 'ledger/int/v1' });
            // Cache the HTML so _printInvoicePreview can access it without
            // querying the DOM (which is unreliable inside a sandboxed iframe).
            this._previewHtml = html;
            this.modalsManager.setOptions({ isLoading: false, html });
        } catch (err) {
            this.notifications.serverError(err);
            this.modalsManager.done();
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Print the rendered invoice HTML.
     *
     * Sandboxed iframes block print() and scripts, so we cannot call
     * iframe.contentWindow.print() directly.  Instead we open the HTML in a
     * new, unsandboxed popup window and trigger print() there.  The popup is
     * closed automatically after the print dialog is dismissed.
     */
    _printInvoicePreview() {
        // Use the HTML cached when the preview was loaded.
        // We cannot read it from the sandboxed iframe's srcdoc attribute
        // because cross-origin sandbox restrictions block DOM access.
        const html = this._previewHtml;
        if (!html) {
            return;
        }

        // Open a blank popup window.
        const printWindow = window.open('', '_blank', 'width=900,height=700,scrollbars=yes');
        if (!printWindow) {
            // Popup was blocked — fall back to printing the current page.
            window.print();
            return;
        }

        // Write the full invoice HTML into the popup and trigger print.
        printWindow.document.open();
        printWindow.document.write(html);
        printWindow.document.close();

        // Wait for resources to load before printing.
        printWindow.onload = () => {
            printWindow.focus();
            printWindow.print();
            // Close the popup after the print dialog is dismissed.
            printWindow.onafterprint = () => printWindow.close();
        };
    }

    /**
     * Trigger a PDF download by hitting the render-pdf endpoint.
     * Uses this.fetch.download() which is the correct method on the fetch service
     * (this.fetch.blob does not exist — the service exposes `download` instead).
     */
    async _downloadInvoicePdf(invoice) {
        this.modalsManager.setOptions({ isPdfLoading: true });
        try {
            const filename = `invoice-${invoice.number ?? invoice.id}.pdf`;
            await this.fetch.download(
                `invoices/${invoice.id}/render-pdf`,
                {},
                {
                    method: 'POST',
                    fileName: filename,
                    mimeType: 'application/pdf',
                    namespace: 'ledger/int/v1',
                }
            );
        } catch (err) {
            this.notifications.serverError(err);
        } finally {
            this.modalsManager.setOptions({ isPdfLoading: false });
        }
    }
}
