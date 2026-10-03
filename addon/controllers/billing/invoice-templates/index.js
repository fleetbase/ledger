import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class BillingInvoiceTemplatesIndexController extends Controller {
    @service invoiceTemplateActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = this.invoiceTemplateActions.queryParamsFor(['page', 'limit', 'sort', 'query']);
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked table = null;

    get actionButtons() {
        return [
            {
                id: 'refresh',
                icon: 'refresh',
                onClick: this.invoiceTemplateActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                id: 'create',
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.invoiceTemplateActions.transition.create,
            },
        ];
    }

    get bulkActions() {
        const selected = this.tableContext.getSelectedRows();
        return [
            {
                id: 'bulk-delete',
                label: this.intl.t('common.delete-selected-count', { count: selected.length }),
                class: 'text-red-500',
                fn: this.invoiceTemplateActions.bulkDelete,
            },
        ];
    }

    get columns() {
        return [
            {
                id: 'name',
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                onClick: (template, event) => {
                    event.preventDefault();
                    this.invoiceTemplateActions.transition.edit(template);
                },
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            {
                id: 'description',
                label: this.intl.t('column.description'),
                valuePath: 'description',
                resizable: true,
            },
            {
                id: 'orientation',
                label: this.intl.t('column.orientation'),
                valuePath: 'orientation',
                resizable: true,
                sortable: true,
            },
            {
                id: 'is-default',
                label: this.intl.t('column.default'),
                valuePath: 'is_default',
                resizable: true,
                sortable: true,
            },
            {
                id: 'created-at',
                label: this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                resizable: true,
                sortable: true,
            },
            // ── Row actions dropdown ─────────────────────────────────────────
            {
                id: 'row-actions',
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.invoice-template') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
                actions: [
                    {
                        id: 'edit',
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.invoice-template') }),
                        icon: 'pencil',
                        fn: this.invoiceTemplateActions.transition.edit,
                        permission: 'ledger update template',
                    },
                    {
                        id: 'preview',
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.invoice-template') }),
                        icon: 'eye',
                        fn: this.invoiceTemplateActions.preview,
                        permission: 'ledger view template',
                    },
                    {
                        id: 'delete',
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.invoice-template') }),
                        icon: 'trash',
                        fn: this.invoiceTemplateActions.delete,
                        className: 'text-red-500 hover:text-red-700',
                        permission: 'ledger delete template',
                    },
                ],
            },
        ];
    }
}
