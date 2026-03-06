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
        view: (invoice) => this.transitionTo('billing.invoices.index.details', invoice),
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
    @action async previewInvoice(invoice, options = {}) {
        const title = invoice.number
            ? this.intl.t('invoice.actions.preview-invoice', { number: invoice.number })
            : this.intl.t('invoice.actions.preview-invoice-fallback');

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
     * Print the rendered invoice HTML via the iframe's contentWindow.
     * The iframe is rendered inside the modal body by invoice-preview.hbs.
     */
    _printInvoicePreview() {
        // Try the iframe first (rendered HTML preview).
        const iframe = document.querySelector('.modal-xl iframe');
        if (iframe?.contentWindow) {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
            return;
        }
        // Fallback: print the whole page if the iframe is not yet available.
        window.print();
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
