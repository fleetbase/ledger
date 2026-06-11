import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class ApplicationController extends Controller {
    @service fetch;

    get navigationItems() {
        return [
            {
                label: 'Dashboard',
                description: 'Ledger dashboard and financial overview.',
                icon: 'chart-simple',
                route: 'console.ledger.home',
                keywords: ['overview', 'metrics', 'finance dashboard'],
            },
            {
                label: 'Billing',
                description: 'Invoices and reusable invoice templates.',
                icon: 'file-invoice-dollar',
                children: [
                    {
                        label: 'Invoices',
                        description: 'Create, send, and manage customer invoices.',
                        icon: 'file-invoice-dollar',
                        route: 'console.ledger.billing.invoices.index',
                        keywords: ['receivables', 'customers', 'payments due'],
                    },
                    {
                        label: 'Invoice Templates',
                        description: 'Design reusable invoice templates.',
                        icon: 'file-code',
                        route: 'console.ledger.billing.invoice-templates.index',
                        keywords: ['templates', 'invoice design', 'documents'],
                    },
                ],
            },
            {
                label: 'Payments',
                description: 'Transactions, wallets, and payment gateways.',
                icon: 'money-bill-transfer',
                children: [
                    {
                        label: 'Transactions',
                        description: 'Review payment and wallet transaction history.',
                        icon: 'money-bill-transfer',
                        route: 'console.ledger.payments.transactions.index',
                        keywords: ['payments', 'charges', 'refunds', 'settlements'],
                    },
                    {
                        label: 'Wallets',
                        description: 'Manage company, customer, and driver wallets.',
                        icon: 'wallet',
                        route: 'console.ledger.payments.wallets.index',
                        keywords: ['balances', 'top ups', 'payouts'],
                    },
                    {
                        label: 'Gateways',
                        description: 'Configure payment gateway integrations.',
                        icon: 'credit-card',
                        route: 'console.ledger.payments.gateways.index',
                        keywords: ['stripe', 'payment providers', 'checkout'],
                    },
                ],
            },
            {
                label: 'Accounting',
                description: 'Chart of accounts, journals, and general ledger.',
                icon: 'calculator',
                children: [
                    {
                        label: 'Chart of Accounts',
                        description: 'Manage the ledger account structure.',
                        icon: 'sitemap',
                        route: 'console.ledger.accounting.accounts.index',
                        keywords: ['accounts', 'coa', 'accounting'],
                    },
                    {
                        label: 'Journal Entries',
                        description: 'Browse and create double-entry journal entries.',
                        icon: 'book',
                        route: 'console.ledger.accounting.journal.index',
                        keywords: ['journals', 'debits', 'credits'],
                    },
                    {
                        label: 'General Ledger',
                        description: 'Review posted activity across accounts.',
                        icon: 'scroll',
                        route: 'console.ledger.accounting.general-ledger',
                        keywords: ['ledger', 'posted transactions', 'account activity'],
                    },
                ],
            },
            {
                label: 'Reports',
                description: 'Financial statements and operational reports.',
                icon: 'chart-line',
                children: [
                    {
                        label: 'Income Statement',
                        description: 'Revenue, expenses, and net income.',
                        icon: 'chart-line',
                        route: 'console.ledger.reports.income-statement',
                        keywords: ['profit and loss', 'pnl', 'revenue', 'expenses'],
                    },
                    {
                        label: 'Balance Sheet',
                        description: 'Assets, liabilities, and equity.',
                        icon: 'scale-balanced',
                        route: 'console.ledger.reports.balance-sheet',
                        keywords: ['assets', 'liabilities', 'equity'],
                    },
                    {
                        label: 'Trial Balance',
                        description: 'Debit and credit balances by account.',
                        icon: 'list-check',
                        route: 'console.ledger.reports.trial-balance',
                        keywords: ['debits', 'credits', 'balances'],
                    },
                    {
                        label: 'Cash Flow',
                        description: 'Cash movement over the selected period.',
                        icon: 'water',
                        route: 'console.ledger.reports.cash-flow',
                        keywords: ['cash', 'inflow', 'outflow'],
                    },
                    {
                        label: 'AR Aging',
                        description: 'Outstanding receivables by age bucket.',
                        icon: 'clock',
                        route: 'console.ledger.reports.ar-aging',
                        keywords: ['receivables', 'overdue invoices', 'aging'],
                    },
                    {
                        label: 'Wallet Summary',
                        description: 'Wallet balances and activity summary.',
                        icon: 'wallet',
                        route: 'console.ledger.reports.wallet-summary',
                        keywords: ['wallet report', 'balances'],
                    },
                ],
            },
            {
                label: 'Settings',
                description: 'Ledger billing, payment, and accounting settings.',
                icon: 'gear',
                children: [
                    {
                        label: 'Invoice Settings',
                        description: 'Configure invoice numbering, defaults, and templates.',
                        icon: 'file-invoice',
                        route: 'console.ledger.settings.invoice',
                        keywords: ['invoice defaults', 'numbering', 'template'],
                    },
                    {
                        label: 'Payment Settings',
                        description: 'Configure payment defaults and gateway behavior.',
                        icon: 'gear',
                        route: 'console.ledger.settings.payment',
                        keywords: ['payments', 'gateway defaults'],
                    },
                    {
                        label: 'Accounting Settings',
                        description: 'Configure accounting automation and posting behavior.',
                        icon: 'calculator',
                        route: 'console.ledger.settings.accounting',
                        keywords: ['journal automation', 'posting', 'accounts'],
                    },
                ],
            },
        ];
    }

    @action
    async searchNavigation({ query, limit = 12 }) {
        const trimmedQuery = query?.trim();

        if (!trimmedQuery) {
            return [];
        }

        try {
            const response = await this.fetch.get(
                'search',
                {
                    query: trimmedQuery,
                    limit,
                },
                {
                    namespace: 'ledger/int/v1',
                }
            );

            return response.results ?? [];
        } catch (_) {
            return [];
        }
    }
}
