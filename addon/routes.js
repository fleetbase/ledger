import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    // Dashboard
    this.route('home', { path: '/' });

    // Billing
    this.route('billing', function () {
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
        this.route('transactions', function () {
            this.route('index', { path: '/' }, function () {
                this.route('details', { path: '/:id' }, function () {
                    this.route('index', { path: '/' });
                });
            });
        });
    });

    // Wallets
    this.route('wallets', function () {
        this.route('index', { path: '/' }, function () {
            this.route('details', { path: '/:id' }, function () {
                this.route('index', { path: '/' });
                this.route('transactions');
            });
        });
    });

    // Accounting
    this.route('accounting', function () {
        this.route('journal', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:id' }, function () {
                    this.route('index', { path: '/' });
                });
            });
        });
        this.route('accounts', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('ledger');
                });
            });
        });
    });

    // Reports
    this.route('reports', function () {
        this.route('index', { path: '/' });
    });

    // Settings
    this.route('settings', function () {
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
});
