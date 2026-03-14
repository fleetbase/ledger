import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import getCurrency from '@fleetbase/ember-ui/utils/get-currency';

export default class SettingsInvoiceController extends Controller {
    @service fetch;
    @service notifications;

    // ── Tracked settings fields ───────────────────────────────────────────────
    @tracked invoice_prefix = 'INV';
    @tracked default_currency = 'USD';
    @tracked payment_terms_days = 30;
    @tracked due_date_offset_days = 30;
    @tracked default_notes = '';
    @tracked default_terms = '';
    @tracked auto_send_on_creation = false;

    // ── Static options ────────────────────────────────────────────────────────
    currencies = getCurrency();

    paymentTermsOptions = [
        { label: 'Due on Receipt', value: 0 },
        { label: 'Net 7', value: 7 },
        { label: 'Net 14', value: 14 },
        { label: 'Net 15', value: 15 },
        { label: 'Net 30', value: 30 },
        { label: 'Net 45', value: 45 },
        { label: 'Net 60', value: 60 },
        { label: 'Net 90', value: 90 },
    ];

    constructor() {
        super(...arguments);
        this.getSettings.perform();
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    @task *getSettings() {
        try {
            const { invoiceSettings } = yield this.fetch.get('settings/invoice-settings', {}, { namespace: 'ledger/int/v1' });
            if (invoiceSettings) {
                this.invoice_prefix = invoiceSettings.invoice_prefix ?? 'INV';
                this.default_currency = invoiceSettings.default_currency ?? 'USD';
                this.payment_terms_days = invoiceSettings.payment_terms_days ?? 30;
                this.due_date_offset_days = invoiceSettings.due_date_offset_days ?? 30;
                this.default_notes = invoiceSettings.default_notes ?? '';
                this.default_terms = invoiceSettings.default_terms ?? '';
                this.auto_send_on_creation = invoiceSettings.auto_send_on_creation ?? false;
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *saveSettings() {
        try {
            yield this.fetch.post(
                'settings/invoice-settings',
                {
                    invoiceSettings: {
                        invoice_prefix: this.invoice_prefix,
                        default_currency: this.default_currency,
                        payment_terms_days: this.payment_terms_days,
                        due_date_offset_days: this.due_date_offset_days,
                        default_notes: this.default_notes,
                        default_terms: this.default_terms,
                        auto_send_on_creation: this.auto_send_on_creation,
                    },
                },
                { namespace: 'ledger/int/v1' }
            );
            this.notifications.success('Invoice settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    @action onSelectCurrency(currency) {
        // CurrencySelect passes the full currency object; we store the ISO code
        this.default_currency = currency?.code ?? currency;
    }

    @action onSelectPaymentTerms(option) {
        this.payment_terms_days = option.value;
        this.due_date_offset_days = option.value;
    }

    // ── Computed helpers ──────────────────────────────────────────────────────

    get selectedCurrency() {
        return getCurrency(this.default_currency) ?? null;
    }
}
