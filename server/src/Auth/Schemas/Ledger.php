<?php

namespace Fleetbase\Ledger\Auth\Schemas;

class Ledger
{
    /**
     * The permission schema Name.
     */
    public string $name = 'ledger';

    /**
     * The permission schema Policy Name.
     */
    public string $policyName = 'Ledger';

    /**
     * Guards these permissions should apply to.
     */
    public array $guards = ['sanctum'];

    /**
     * The permission schema resources.
     *
     * Each entry generates the standard CRUD actions (create, read, update, delete,
     * list, view, see, export) automatically.  Additional custom actions are listed
     * in the 'actions' key.  Use 'remove_actions' to strip standard actions that do
     * not apply to a given resource (e.g. settings panels that cannot be deleted).
     */
    public array $resources = [
        // ── Billing ──────────────────────────────────────────────────────────────
        [
            'name'    => 'invoice',
            'actions' => ['send', 'preview', 'record-payment', 'mark-as-sent', 'render-pdf', 'export', 'import', 'create-from-order'],
        ],
        [
            'name'    => 'invoice-item',
            'actions' => [],
        ],
        [
            'name'    => 'invoice-template',
            'actions' => ['export', 'import'],
        ],

        // ── Payments ─────────────────────────────────────────────────────────────
        [
            'name'    => 'transaction',
            'actions' => ['export'],
        ],
        [
            'name'    => 'gateway',
            'actions' => ['charge', 'refund', 'setup-intent'],
        ],
        [
            'name'    => 'gateway-transaction',
            'actions' => ['export'],
        ],
        [
            'name'    => 'wallet',
            'actions' => ['transfer', 'credit', 'topup', 'payout', 'freeze', 'unfreeze', 'recalculate', 'export'],
        ],

        // ── Accounting ───────────────────────────────────────────────────────────
        [
            'name'    => 'account',
            'actions' => ['export', 'import'],
        ],
        [
            'name'    => 'journal',
            'actions' => ['export'],
        ],

        // ── Reports ──────────────────────────────────────────────────────────────
        [
            'name'           => 'report',
            'actions'        => ['view-general-ledger', 'view-trial-balance', 'view-balance-sheet', 'view-income-statement', 'view-cash-flow', 'view-ar-aging', 'view-wallet-summary', 'view-dashboard'],
            'remove_actions' => ['create', 'update', 'delete', 'export', 'import'],
        ],

        // ── Settings ─────────────────────────────────────────────────────────────
        [
            'name'           => 'invoice-settings',
            'actions'        => [],
            'remove_actions' => ['delete', 'export', 'list', 'create'],
        ],
        [
            'name'           => 'payment-settings',
            'actions'        => [],
            'remove_actions' => ['delete', 'export', 'list', 'create'],
        ],
        [
            'name'           => 'accounting-settings',
            'actions'        => [],
            'remove_actions' => ['delete', 'export', 'list', 'create'],
        ],
    ];

    /**
     * Policies provided by this schema.
     *
     * Each policy bundles a set of permission strings that can be assigned
     * to users or groups.  Permission strings follow the pattern:
     *   "{action} {resource}"   e.g. "create invoice"
     *   "* {resource}"          wildcard — all actions on that resource
     *   "see extension"         grants access to the Ledger extension in the sidebar
     */
    public array $policies = [
        [
            'name'        => 'InvoiceManager',
            'description' => 'Policy for creating, sending, and managing invoices and invoice templates.',
            'permissions' => [
                'see extension',
                '* invoice',
                '* invoice-item',
                '* invoice-template',
                'see transaction',
                'list transaction',
                'view transaction',
                'see wallet',
                'list wallet',
                'view wallet',
            ],
        ],
        [
            'name'        => 'PaymentsManager',
            'description' => 'Policy for managing payment gateways, processing charges and refunds, and reviewing transactions.',
            'permissions' => [
                'see extension',
                '* gateway',
                '* gateway-transaction',
                'see transaction',
                'list transaction',
                'view transaction',
                'export transaction',
                'see wallet',
                'list wallet',
                'view wallet',
                'transfer wallet',
                'topup wallet',
                'payout wallet',
            ],
        ],
        [
            'name'        => 'WalletManager',
            'description' => 'Policy for managing wallets, including top-ups, transfers, payouts, and freezing.',
            'permissions' => [
                'see extension',
                '* wallet',
                'see transaction',
                'list transaction',
                'view transaction',
            ],
        ],
        [
            'name'        => 'AccountingManager',
            'description' => 'Policy for managing the chart of accounts and journal entries.',
            'permissions' => [
                'see extension',
                '* account',
                '* journal',
                'see transaction',
                'list transaction',
                'view transaction',
            ],
        ],
        [
            'name'        => 'FinancialReporter',
            'description' => 'Policy for viewing all financial reports and the accounting dashboard.',
            'permissions' => [
                'see extension',
                'see report',
                'view-general-ledger report',
                'view-trial-balance report',
                'view-balance-sheet report',
                'view-income-statement report',
                'view-cash-flow report',
                'view-ar-aging report',
                'view-wallet-summary report',
                'view-dashboard report',
                'see invoice',
                'list invoice',
                'view invoice',
                'see transaction',
                'list transaction',
                'view transaction',
                'see wallet',
                'list wallet',
                'view wallet',
                'see account',
                'list account',
                'view account',
                'see journal',
                'list journal',
                'view journal',
            ],
        ],
        [
            'name'        => 'LedgerSettingsManager',
            'description' => 'Policy for managing Ledger invoice, payment, and accounting settings.',
            'permissions' => [
                'see extension',
                'see invoice-settings',
                'view invoice-settings',
                'update invoice-settings',
                'see payment-settings',
                'view payment-settings',
                'update payment-settings',
                'see accounting-settings',
                'view accounting-settings',
                'update accounting-settings',
            ],
        ],
        [
            'name'        => 'LedgerReadOnly',
            'description' => 'Policy for read-only access to all Ledger resources.',
            'permissions' => [
                'see extension',
                'see invoice',
                'list invoice',
                'view invoice',
                'see invoice-item',
                'list invoice-item',
                'view invoice-item',
                'see invoice-template',
                'list invoice-template',
                'view invoice-template',
                'see transaction',
                'list transaction',
                'view transaction',
                'see gateway',
                'list gateway',
                'view gateway',
                'see gateway-transaction',
                'list gateway-transaction',
                'view gateway-transaction',
                'see wallet',
                'list wallet',
                'view wallet',
                'see account',
                'list account',
                'view account',
                'see journal',
                'list journal',
                'view journal',
                'see report',
                'view-general-ledger report',
                'view-trial-balance report',
                'view-balance-sheet report',
                'view-income-statement report',
                'view-cash-flow report',
                'view-ar-aging report',
                'view-wallet-summary report',
                'view-dashboard report',
            ],
        ],
    ];

    /**
     * Roles provided by this schema.
     *
     * Roles bundle one or more policies and can be assigned directly to users.
     */
    public array $roles = [
        [
            'name'        => 'Ledger Administrator',
            'description' => 'Full administrative access to all Ledger billing, payments, accounting, and settings.',
            'policies'    => [
                'InvoiceManager',
                'PaymentsManager',
                'WalletManager',
                'AccountingManager',
                'FinancialReporter',
                'LedgerSettingsManager',
            ],
        ],
        [
            'name'        => 'Billing Specialist',
            'description' => 'Role for staff responsible for creating and managing invoices and following up on payments.',
            'policies'    => [
                'InvoiceManager',
                'PaymentsManager',
            ],
        ],
        [
            'name'        => 'Accountant',
            'description' => 'Role for accountants who manage the chart of accounts, journal entries, and financial reports.',
            'policies'    => [
                'AccountingManager',
                'FinancialReporter',
            ],
        ],
        [
            'name'        => 'Finance Viewer',
            'description' => 'Read-only role for stakeholders who need visibility into financial data without the ability to make changes.',
            'policies'    => [
                'LedgerReadOnly',
            ],
        ],
        [
            'name'        => 'Payments Operator',
            'description' => 'Role for staff who process payments, manage gateways, and handle wallet operations.',
            'policies'    => [
                'PaymentsManager',
                'WalletManager',
            ],
        ],
    ];
}
