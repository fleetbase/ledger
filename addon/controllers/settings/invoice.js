import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

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

    // ── Options ───────────────────────────────────────────────────────────────
    currencyOptions = [
        { label: 'USD – US Dollar', value: 'USD' },
        { label: 'EUR – Euro', value: 'EUR' },
        { label: 'GBP – British Pound', value: 'GBP' },
        { label: 'AUD – Australian Dollar', value: 'AUD' },
        { label: 'CAD – Canadian Dollar', value: 'CAD' },
        { label: 'JPY – Japanese Yen', value: 'JPY' },
        { label: 'CNY – Chinese Yuan', value: 'CNY' },
        { label: 'INR – Indian Rupee', value: 'INR' },
        { label: 'SGD – Singapore Dollar', value: 'SGD' },
        { label: 'AED – UAE Dirham', value: 'AED' },
        { label: 'SAR – Saudi Riyal', value: 'SAR' },
        { label: 'MYR – Malaysian Ringgit', value: 'MYR' },
        { label: 'IDR – Indonesian Rupiah', value: 'IDR' },
        { label: 'THB – Thai Baht', value: 'THB' },
        { label: 'PHP – Philippine Peso', value: 'PHP' },
        { label: 'VND – Vietnamese Dong', value: 'VND' },
        { label: 'KRW – South Korean Won', value: 'KRW' },
        { label: 'BRL – Brazilian Real', value: 'BRL' },
        { label: 'MXN – Mexican Peso', value: 'MXN' },
        { label: 'ZAR – South African Rand', value: 'ZAR' },
        { label: 'NGN – Nigerian Naira', value: 'NGN' },
        { label: 'KES – Kenyan Shilling', value: 'KES' },
        { label: 'GHS – Ghanaian Cedi', value: 'GHS' },
        { label: 'EGP – Egyptian Pound', value: 'EGP' },
        { label: 'PKR – Pakistani Rupee', value: 'PKR' },
        { label: 'BDT – Bangladeshi Taka', value: 'BDT' },
        { label: 'NZD – New Zealand Dollar', value: 'NZD' },
        { label: 'CHF – Swiss Franc', value: 'CHF' },
        { label: 'SEK – Swedish Krona', value: 'SEK' },
        { label: 'NOK – Norwegian Krone', value: 'NOK' },
        { label: 'DKK – Danish Krone', value: 'DKK' },
        { label: 'HKD – Hong Kong Dollar', value: 'HKD' },
        { label: 'TWD – Taiwan Dollar', value: 'TWD' },
        { label: 'CZK – Czech Koruna', value: 'CZK' },
        { label: 'PLN – Polish Zloty', value: 'PLN' },
        { label: 'HUF – Hungarian Forint', value: 'HUF' },
        { label: 'ILS – Israeli Shekel', value: 'ILS' },
        { label: 'TRY – Turkish Lira', value: 'TRY' },
        { label: 'RUB – Russian Ruble', value: 'RUB' },
        { label: 'UAH – Ukrainian Hryvnia', value: 'UAH' },
        { label: 'QAR – Qatari Riyal', value: 'QAR' },
        { label: 'KWD – Kuwaiti Dinar', value: 'KWD' },
        { label: 'BHD – Bahraini Dinar', value: 'BHD' },
        { label: 'OMR – Omani Rial', value: 'OMR' },
        { label: 'JOD – Jordanian Dinar', value: 'JOD' },
        { label: 'LBP – Lebanese Pound', value: 'LBP' },
    ];

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
            const { invoiceSettings } = yield this.fetch.get('ledger/settings/invoice-settings');
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
            yield this.fetch.post('ledger/settings/invoice-settings', {
                invoiceSettings: {
                    invoice_prefix: this.invoice_prefix,
                    default_currency: this.default_currency,
                    payment_terms_days: this.payment_terms_days,
                    due_date_offset_days: this.due_date_offset_days,
                    default_notes: this.default_notes,
                    default_terms: this.default_terms,
                    auto_send_on_creation: this.auto_send_on_creation,
                },
            });
            this.notifications.success('Invoice settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    @action onSelectCurrency(option) {
        this.default_currency = option.value;
    }

    @action onSelectPaymentTerms(option) {
        this.payment_terms_days = option.value;
        this.due_date_offset_days = option.value;
    }
}
