import buildRoutes from 'ember-engines/routes';
export default buildRoutes(function () {
    // Dashboard
    this.route('home', { path: '/' });

    // Receivables
    this.route('receivables', function () {
        this.route('invoices', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('line-items');
                    this.route('transactions');
                });
            });
        });
    });

    // Payments
    this.route('payments', function () {
        this.route('transactions', function () {
            this.route('index', { path: '/' }, function () {
                this.route('details', { path: '/:id' }, function () {
                    this.route('index', { path: '/' });
                });
            });
        });
        this.route('wallets', function () {
            this.route('index', { path: '/' }, function () {
                this.route('details', { path: '/:id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('transactions');
                });
            });
        });
        this.route('gateways', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('webhooks');
                });
            });
        });
    });

    // Accounting
    this.route('accounting', function () {
        this.route('accounts', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('ledger');
                });
            });
        });
        this.route('journal', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:id' }, function () {
                    this.route('index', { path: '/' });
                });
            });
        });
        this.route('general-ledger');
    });

    // Reports
    this.route('reports', function () {
        this.route('income-statement');
        this.route('balance-sheet');
        this.route('trial-balance');
        this.route('cash-flow');
        this.route('ar-aging');
        this.route('wallet-summary');
    });

    // Settings
    this.route('settings', function () {
        this.route('invoice');
        this.route('payment');
        this.route('accounting');
    });
});
