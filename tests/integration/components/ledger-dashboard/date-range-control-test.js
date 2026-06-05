import { module, test } from 'qunit';
import { setupRenderingTest } from 'fleetbase-ledger-engine/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | ledger-dashboard/date-range-control', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the dashboard period DatePicker wrapper', async function (assert) {
        await render(hbs`<LedgerDashboard::DateRangeControl />`);

        assert.dom('.ledger-dashboard-period-control').exists();
        assert.dom('.ledger-dashboard-period-control .form-input').exists();
    });
});
