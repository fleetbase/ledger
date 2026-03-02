import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class JournalActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('ledger-journal', {
            permissionPrefix: 'ledger',
            mountPrefix: 'console.ledger',
        });
    }

    transition = {
        view: (journal) => this.transitionTo('accounting.journal.index.details', journal),
        create: () => this.transitionTo('accounting.journal.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const journal = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'journal/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.journal')?.toLowerCase() }),
                saveOptions: { callback: this.refresh },
                useDefaultSaveTask: true,
                journal,
                ...options,
            });
        },

        view: (journal, options = {}) => {
            return this.resourceContextPanel.open({
                journal,
                tabs: [{ label: this.intl.t('common.overview'), component: 'journal/details' }],
                ...options,
            });
        },
    };
}
