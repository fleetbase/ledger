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
    @service intl;

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
            { label: 'Details', route: 'billing.invoices.index.details.index' },
            { label: 'Transactions', route: 'billing.invoices.index.details.transactions' },
        ];
    }

    get actionButtons() {
        const invoice = this.model;
        const buttons = [];

        // Preview — individual button, only when an invoice template is assigned.
        if (invoice?.template_uuid) {
            buttons.push({
                label: 'Preview',
                icon: 'eye',
                type: 'default',
                helpText: this.intl.t('invoice.actions.preview-invoice', { number: invoice.number }),
                onClick: () => this.invoiceActions.previewInvoice(invoice),
            });
        }

        // Edit — individual button, available for all statuses except paid / void / cancelled.
        if (!['paid', 'void', 'cancelled'].includes(invoice?.status)) {
            buttons.push({
                label: 'Edit',
                icon: 'pencil',
                type: 'default',
                helpText: this.intl.t('invoice.actions.edit'),
                onClick: () => this.hostRouter.transitionTo('console.ledger.billing.invoices.index.edit', invoice.id),
            });
        }

        // Dropdown — groups Send, Record Payment, Void, and Copy Invoice URL.
        const dropdownItems = [];

        // Send — draft invoices only.
        if (invoice?.status === 'draft') {
            dropdownItems.push({
                text: 'Send Invoice',
                icon: 'paper-plane',
                fn: () => this.sendInvoice(),
            });
        }

        // Record Payment — for open / overdue / partially-paid invoices.
        if (['sent', 'viewed', 'overdue', 'partial'].includes(invoice?.status)) {
            dropdownItems.push({
                text: this.intl.t('invoice.actions.record-payment'),
                icon: 'check-circle',
                fn: () => this.recordPayment(),
            });
        }

        // Refund - paid or partially refunded invoices with remaining paid funds.
        if (this.canIssueRefund) {
            dropdownItems.push({
                text: 'Issue Refund',
                icon: 'undo',
                class: 'text-red-500 hover:text-red-700',
                fn: () => this.issueRefund(),
            });
        }

        // Void — for any non-terminal status.
        if (!['paid', 'void', 'cancelled'].includes(invoice?.status)) {
            dropdownItems.push({
                text: this.intl.t('invoice.actions.void'),
                icon: 'ban',
                class: 'text-red-500 hover:text-red-700',
                fn: () => this.voidInvoice(),
            });
        }

        // Separator before copy URL.
        if (dropdownItems.length > 0) {
            dropdownItems.push({ separator: true });
        }

        // Copy Invoice URL — always available.
        dropdownItems.push({
            text: this.intl.t('invoice.actions.copy-invoice-url'),
            icon: 'link',
            fn: () => this.invoiceActions.copyInvoiceUrl(invoice),
        });

        if (dropdownItems.length > 0) {
            buttons.push({
                icon: 'ellipsis-h',
                iconPrefix: 'fas',
                renderInPlace: true,
                items: dropdownItems,
            });
        }

        return buttons;
    }

    get refundedAmount() {
        return Number(this.model?.meta?.refunded_amount ?? 0);
    }

    get remainingRefundableAmount() {
        return Math.max(0, Number(this.model?.amount_paid ?? 0) - this.refundedAmount);
    }

    get canIssueRefund() {
        const status = this.model?.status;

        return ['paid', 'partial', 'refunded'].includes(status) && this.remainingRefundableAmount > 0;
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
        const options = {
            title: this.intl.t('invoice.actions.record-payment-title', { number: invoice.number }),
            acceptButtonText: this.intl.t('invoice.actions.record-payment'),
            acceptButtonIcon: 'check-circle',
            invoice,
            amount: invoice.balance ?? 0,
            paymentMethod: 'bank_transfer',
            reference: '',
            paymentMethodOptions: [
                { label: 'Bank Transfer', value: 'bank_transfer' },
                { label: 'Cash', value: 'cash' },
                { label: 'Cheque', value: 'cheque' },
                { label: 'Credit Card', value: 'credit_card' },
                { label: 'Debit Card', value: 'debit_card' },
                { label: 'PayPal', value: 'paypal' },
                { label: 'Stripe', value: 'stripe' },
                { label: 'Other', value: 'other' },
            ],
            setAmount: (centsValue) => {
                options.amount = centsValue;
            },
            setPaymentMethod: (value) => {
                options.paymentMethod = value;
            },
            setReference: (event) => {
                options.reference = event.target.value;
            },
            confirm: async (modal) => {
                if (!options.amount || options.amount <= 0) {
                    this.notifications.warning('Please enter a valid payment amount greater than zero.');
                    return;
                }
                modal.startLoading();
                try {
                    await this.fetch.post(
                        `invoices/${invoice.id}/record-payment`,
                        {
                            amount: options.amount,
                            payment_method: options.paymentMethod,
                            reference: options.reference || null,
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
        };

        this.modalsManager.show('modals/record-payment', options);
    }

    @action async issueRefund() {
        const invoice = this.model;

        try {
            const result = await this.fetch.get(`invoices/${invoice.id}/refund-options`, {}, { namespace: 'ledger/int/v1' });
            const refundOptions = (result.options ?? []).map((option) => {
                const gatewayName = option.gateway?.name ?? option.gateway?.driver ?? 'Gateway';
                const driver = option.gateway?.driver ? ` (${option.gateway.driver})` : '';

                return {
                    ...option,
                    label: `${gatewayName}${driver} - ${option.gateway_transaction_id}`,
                };
            });

            if (refundOptions.length === 0) {
                this.notifications.warning('This invoice has no refundable gateway payments.');
                return;
            }

            const selectedOption = refundOptions[0];
            const options = {
                title: `Issue Refund ${invoice.number}`,
                acceptButtonText: 'Issue Refund',
                acceptButtonIcon: 'undo',
                acceptButtonScheme: 'danger',
                invoice,
                summary: result.invoice,
                refundOptions,
                selectedGatewayTransactionId: selectedOption.gateway_transaction_id,
                refundMode: 'full',
                amount: selectedOption.refundable_amount,
                reason: '',
                confirm: async (modal) => {
                    const selected = refundOptions.find((option) => option.gateway_transaction_id === options.selectedGatewayTransactionId);

                    if (!selected) {
                        this.notifications.warning('Select a refundable payment.');
                        return;
                    }

                    if (!options.amount || options.amount <= 0) {
                        this.notifications.warning('Enter a refund amount greater than zero.');
                        return;
                    }

                    if (options.amount > selected.refundable_amount) {
                        this.notifications.warning('Refund amount exceeds the selected payment remaining amount.');
                        return;
                    }

                    modal.startLoading();

                    try {
                        const response = await this.fetch.post(
                            `invoices/${invoice.id}/refund`,
                            {
                                gateway_transaction_id: options.selectedGatewayTransactionId,
                                amount: options.amount,
                                reason: options.reason || null,
                            },
                            { namespace: 'ledger/int/v1' }
                        );
                        const responseData = response.data ?? {};
                        const refundUrl = responseData.taler_refund_uri ?? responseData.refund_url;

                        this.notifications.success('Refund issued successfully.');
                        await invoice.reload();
                        this.hostRouter.refresh();
                        modal.done();

                        if (refundUrl) {
                            this.showRefundResult(response, refundUrl);
                        }
                    } catch (error) {
                        this.notifications.serverError(error);
                        modal.stopLoading();
                    }
                },
            };

            this.modalsManager.show('modals/issue-refund', options);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    showRefundResult(response, refundUrl) {
        this.modalsManager.show('modals/refund-result', {
            title: 'Refund Issued',
            acceptButtonText: 'Done',
            acceptButtonIcon: 'check',
            refundUrl,
            refundStatus: response.data?.refund_status ?? response.status,
            walletStatus: response.data?.wallet_status,
            gatewayTransactionId: response.gateway_transaction_id,
            confirm: (modal) => modal.done(),
        });
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
