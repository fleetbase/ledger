import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class AccountingJournalIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service hostRouter;

    get tabs() {
        return [{ label: 'Details', route: 'console.ledger.accounting.journal.index.details.index' }];
    }

    get actionButtons() {
        const entry = this.model;
        if (entry?.is_system_entry) return [];
        return [{ label: 'Delete', icon: 'trash', type: 'danger', onClick: this.deleteEntry }];
    }

    @action async deleteEntry() {
        const entry = this.model;
        this.modalsManager.confirm({
            title: 'Delete Journal Entry?',
            body: 'This action cannot be undone.',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await entry.destroyRecord();
                    this.notifications.success('Journal entry deleted.');
                    this.hostRouter.transitionTo('console.ledger.accounting.journal.index');
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
