import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import getCurrency from '@fleetbase/ember-ui/utils/get-currency';

export default class SettingsInvoiceController extends Controller {
    @service fetch;
    @service notifications;
    @service currentUser;

    // ── Tracked settings fields ───────────────────────────────────────────────
    @tracked invoice_prefix = 'INV';
    @tracked default_currency = null; // null = "use company default"
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

    // ── Computed helpers ──────────────────────────────────────────────────────

    /**
     * The organisation's currency from the organisations list.
     * currentUser.organizations is @tracked and populated by loadOrganizations()
     * during promiseUser() — it is always available by the time any console
     * route is activated. Reading it in a getter is safe (no store.peekRecord,
     * no mutation). Falls back to 'USD' if the org record is not yet loaded.
     */
    get companyCurrency() {
        const companyId = this.currentUser.companyId;
        if (!companyId) return 'USD';
        const org = this.currentUser.organizations.find((o) => o.uuid === companyId || o.id === companyId);
        return org?.currency ?? 'USD';
    }

    /**
     * The effective currency: the explicitly saved setting if present,
     * otherwise the organisation's default currency.
     */
    get effectiveCurrency() {
        return this.default_currency || this.companyCurrency;
    }

    get selectedCurrency() {
        return getCurrency(this.effectiveCurrency) ?? null;
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    @task *getSettings() {
        try {
            const { invoiceSettings } = yield this.fetch.get('settings/invoice-settings', {}, { namespace: 'ledger/int/v1' });
            if (invoiceSettings) {
                this.invoice_prefix = invoiceSettings.invoice_prefix ?? 'INV';
                // null means "not yet set — use company default"
                this.default_currency = invoiceSettings.default_currency ?? null;
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
                        // Persist null when no override chosen so backend knows
                        // to fall back to company default on future reads.
                        default_currency: this.default_currency || null,
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
        // CurrencySelect passes the full currency object; store the ISO code.
        // Clearing the selection (null/undefined) resets to company default.
        this.default_currency = currency?.code ?? null;
    }

    @action onSelectPaymentTerms(option) {
        this.payment_terms_days = option.value;
        this.due_date_offset_days = option.value;
    }
}
