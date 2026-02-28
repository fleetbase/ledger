import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class AccountingJournalIndexController extends Controller {
    @service hostRouter;
    @service notifications;
    @service modalsManager;

    queryParams = ['page', 'limit', 'sort', 'query', 'type'];

    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-date';
    @tracked query = null;
    @tracked type = null;
    @tracked table = null;

    columns = [
        { label: 'Date', valuePath: 'date', width: '120px', sortable: true, component: 'table/cell/date' },
        { label: 'Entry #', valuePath: 'number', width: '120px' },
        { label: 'Description', valuePath: 'description', width: '220px' },
        { label: 'Debit Account', valuePath: 'debit_account_name', width: '160px' },
        { label: 'Credit Account', valuePath: 'credit_account_name', width: '160px' },
        { label: 'Amount', valuePath: 'formatted_amount', width: '120px', sortable: true },
        { label: 'Source', valuePath: 'entry_source', width: '90px' },
    ];

    get actionButtons() {
        return [
            { label: 'New Entry', icon: 'plus', type: 'primary', onClick: this.createEntry },
        ];
    }

    get bulkActions() {
        return [
            { label: 'Delete Selected', icon: 'trash', fn: this.bulkDeleteEntries },
        ];
    }

    @task({ restartable: true }) *search(query) {
        this.query = query;
    }

    @action createEntry() {
        this.hostRouter.transitionTo('console.ledger.accounting.journal.index.new');
    }

    @action viewEntry(entry) {
        this.hostRouter.transitionTo('console.ledger.accounting.journal.index.details', entry.public_id);
    }

    @action async deleteEntry(entry) {
        if (entry.is_system_entry) {
            return this.notifications.warning('System journal entries cannot be deleted.');
        }
        this.modalsManager.confirm({
            title: 'Delete Journal Entry?',
            body: 'This action cannot be undone.',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await entry.destroyRecord();
                    this.notifications.success('Journal entry deleted.');
                    this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action async bulkDeleteEntries(selected) {
        const manual = selected.filter((e) => !e.is_system_entry);
        if (!manual.length) {
            return this.notifications.warning('Only manual entries can be deleted.');
        }
        this.modalsManager.confirm({
            title: `Delete ${manual.length} manual entry(s)?`,
            body: 'This action cannot be undone.',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await Promise.all(manual.map((e) => e.destroyRecord()));
                    this.notifications.success(`${manual.length} entry(s) deleted.`);
                    this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action reload() {
        return this.hostRouter.refresh();
    }
}
