import { module, test } from 'qunit';
import { setupTest } from 'fleetbase-ledger-engine/tests/helpers';

function formatDate(date) {
    const year = date.getFullYear();
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${year}-${month}-${day}`;
}

module('Unit | Service | ledger-dashboard', function (hooks) {
    setupTest(hooks);

    test('it defaults to month-to-date params', function (assert) {
        const service = this.owner.lookup('service:ledger-dashboard');
        const today = new Date();
        const start = new Date(today.getFullYear(), today.getMonth(), 1);

        assert.deepEqual(service.dateRange, [formatDate(start), formatDate(today)]);
        assert.deepEqual(service.periodParams, {
            start_date: formatDate(start),
            end_date: formatDate(today),
        });
        assert.deepEqual(service.walletPeriodParams, {
            date_from: formatDate(start),
            date_to: formatDate(today),
        });
        assert.deepEqual(service.asOfParams, {
            as_of_date: formatDate(today),
        });
    });

    test('it updates subscribers when the period changes', function (assert) {
        assert.expect(4);

        const service = this.owner.lookup('service:ledger-dashboard');
        const unsubscribe = service.subscribe((dashboard) => {
            assert.strictEqual(dashboard.startDate, '2026-05-01');
            assert.strictEqual(dashboard.endDate, '2026-05-31');
        });

        service.setDateRange({ formattedDate: ['2026-05-01', '2026-05-31'] });

        assert.deepEqual(service.periodParams, {
            start_date: '2026-05-01',
            end_date: '2026-05-31',
        });

        unsubscribe();
        service.setDateRange({ formattedDate: ['2026-04-01', '2026-04-30'] });
        assert.deepEqual(service.periodParams, {
            start_date: '2026-04-01',
            end_date: '2026-04-30',
        });
    });
});
