import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class SettingsAccountingController extends Controller {
    @service fetch;
    @service notifications;
    @service store;

    // ── Tracked settings fields ───────────────────────────────────────────────
    @tracked base_currency = 'USD';
    @tracked fiscal_year_start_month = 1;
    @tracked auto_post_journal_entries = false;
    @tracked default_revenue_account_uuid = null;
    @tracked default_expense_account_uuid = null;
    @tracked default_ar_account_uuid = null;
    @tracked default_ap_account_uuid = null;

    // ── Account lists for selectors ───────────────────────────────────────────
    @tracked revenueAccounts = [];
    @tracked expenseAccounts = [];
    @tracked arAccounts = [];
    @tracked apAccounts = [];

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
        { label: 'QAR – Qatari Riyal', value: 'QAR' },
        { label: 'KWD – Kuwaiti Dinar', value: 'KWD' },
        { label: 'BHD – Bahraini Dinar', value: 'BHD' },
        { label: 'OMR – Omani Rial', value: 'OMR' },
        { label: 'JOD – Jordanian Dinar', value: 'JOD' },
    ];

    fiscalYearMonthOptions = [
        { label: 'January', value: 1 },
        { label: 'February', value: 2 },
        { label: 'March', value: 3 },
        { label: 'April', value: 4 },
        { label: 'May', value: 5 },
        { label: 'June', value: 6 },
        { label: 'July', value: 7 },
        { label: 'August', value: 8 },
        { label: 'September', value: 9 },
        { label: 'October', value: 10 },
        { label: 'November', value: 11 },
        { label: 'December', value: 12 },
    ];

    constructor() {
        super(...arguments);
        this.loadAccounts.perform();
        this.getSettings.perform();
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    @task *loadAccounts() {
        try {
            const allAccounts = yield this.store.query('ledger-account', { limit: 500, sort: 'name' });
            const accounts = allAccounts.toArray();
            this.revenueAccounts = accounts.filter((a) => a.type === 'revenue');
            this.expenseAccounts = accounts.filter((a) => a.type === 'expense');
            this.arAccounts = accounts.filter((a) => a.type === 'asset');
            this.apAccounts = accounts.filter((a) => a.type === 'liability');
        } catch {
            this.revenueAccounts = [];
            this.expenseAccounts = [];
            this.arAccounts = [];
            this.apAccounts = [];
        }
    }

    @task *getSettings() {
        try {
            const { accountingSettings } = yield this.fetch.get('ledger/settings/accounting-settings');
            if (accountingSettings) {
                this.base_currency = accountingSettings.base_currency ?? 'USD';
                this.fiscal_year_start_month = accountingSettings.fiscal_year_start_month ?? 1;
                this.auto_post_journal_entries = accountingSettings.auto_post_journal_entries ?? false;
                this.default_revenue_account_uuid = accountingSettings.default_revenue_account_uuid ?? null;
                this.default_expense_account_uuid = accountingSettings.default_expense_account_uuid ?? null;
                this.default_ar_account_uuid = accountingSettings.default_ar_account_uuid ?? null;
                this.default_ap_account_uuid = accountingSettings.default_ap_account_uuid ?? null;
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *saveSettings() {
        try {
            yield this.fetch.post('ledger/settings/accounting-settings', {
                accountingSettings: {
                    base_currency: this.base_currency,
                    fiscal_year_start_month: this.fiscal_year_start_month,
                    auto_post_journal_entries: this.auto_post_journal_entries,
                    default_revenue_account_uuid: this.default_revenue_account_uuid,
                    default_expense_account_uuid: this.default_expense_account_uuid,
                    default_ar_account_uuid: this.default_ar_account_uuid,
                    default_ap_account_uuid: this.default_ap_account_uuid,
                },
            });
            this.notifications.success('Accounting settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    @action onSelectCurrency(option) {
        this.base_currency = option.value;
    }

    @action onSelectFiscalYearMonth(option) {
        this.fiscal_year_start_month = option.value;
    }

    _accountId(account) {
        // Prefer uuid (internal), fall back to public_id or ember id
        return account ? (account.uuid || account.public_id || account.id) : null;
    }

    @action onSelectRevenueAccount(account) {
        this.default_revenue_account_uuid = this._accountId(account);
    }

    @action onSelectExpenseAccount(account) {
        this.default_expense_account_uuid = this._accountId(account);
    }

    @action onSelectArAccount(account) {
        this.default_ar_account_uuid = this._accountId(account);
    }

    @action onSelectApAccount(account) {
        this.default_ap_account_uuid = this._accountId(account);
    }

    // ── Computed helpers ──────────────────────────────────────────────────────

    _matchAccount(list, uuid) {
        if (!uuid) return null;
        return list.find((a) => a.uuid === uuid || a.public_id === uuid || a.id === uuid) ?? null;
    }

    get selectedRevenueAccount() {
        return this._matchAccount(this.revenueAccounts, this.default_revenue_account_uuid);
    }

    get selectedExpenseAccount() {
        return this._matchAccount(this.expenseAccounts, this.default_expense_account_uuid);
    }

    get selectedArAccount() {
        return this._matchAccount(this.arAccounts, this.default_ar_account_uuid);
    }

    get selectedApAccount() {
        return this._matchAccount(this.apAccounts, this.default_ap_account_uuid);
    }
}
