<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Models\Account;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\Journal;
use Fleetbase\Ledger\Models\Transaction;
use Fleetbase\Ledger\Models\Wallet;
use Fleetbase\Models\Template;
use Fleetbase\Support\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    private const SEARCH_TYPES = ['invoices', 'templates', 'wallets', 'transactions', 'gateways', 'accounts', 'journals'];

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) ($request->input('query') ?: $request->input('q')));
        $limit = max(1, min((int) $request->input('limit', 12), 24));

        if ($query === '') {
            return response()->json(['results' => []]);
        }

        $types        = $this->requestedTypes($request);
        $perTypeLimit = max(1, (int) ceil($limit / max(count($types), 1)));
        $results      = collect();

        foreach ($types as $type) {
            if (!$this->canSearchType($type)) {
                continue;
            }

            $results = $results->merge($this->searchType($type, $query, $perTypeLimit));
        }

        return response()->json([
            'results' => $results->take($limit)->values(),
        ]);
    }

    private function requestedTypes(Request $request): array
    {
        $types = $request->input('types', self::SEARCH_TYPES);

        if (is_string($types)) {
            $types = array_filter(array_map('trim', explode(',', $types)));
        }

        if (!is_array($types)) {
            return self::SEARCH_TYPES;
        }

        $types = array_values(array_intersect($types, self::SEARCH_TYPES));

        return empty($types) ? self::SEARCH_TYPES : $types;
    }

    private function canSearchType(string $type): bool
    {
        $permissions = [
            'invoices'     => 'ledger see invoice',
            'templates'    => 'ledger see invoice-template',
            'wallets'      => 'ledger see wallet',
            'transactions' => 'ledger see transaction',
            'gateways'     => 'ledger see gateway',
            'accounts'     => 'ledger see account',
            'journals'     => 'ledger see journal',
        ];

        $user = Auth::getUserFromSession();

        if ($user?->isAdmin()) {
            return true;
        }

        return Auth::can($permissions[$type]);
    }

    private function searchType(string $type, string $query, int $limit): Collection
    {
        return match ($type) {
            'invoices'     => $this->searchInvoices($query, $limit),
            'templates'    => $this->searchTemplates($query, $limit),
            'wallets'      => $this->searchWallets($query, $limit),
            'transactions' => $this->searchTransactions($query, $limit),
            'gateways'     => $this->searchGateways($query, $limit),
            'accounts'     => $this->searchAccounts($query, $limit),
            'journals'     => $this->searchJournals($query, $limit),
            default        => collect(),
        };
    }

    private function searchInvoices(string $query, int $limit): Collection
    {
        return Invoice::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['number', 'public_id', 'uuid', 'status', 'currency', 'customer_uuid', 'customer_type'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'number', 'status', 'currency', 'total_amount', 'balance'])
            ->map(fn (Invoice $invoice) => [
                'label'       => $invoice->number ?: $invoice->public_id,
                'description' => trim(implode(' ', array_filter([$invoice->status, $invoice->currency, $invoice->public_id]))),
                'icon'        => 'file-invoice-dollar',
                'type'        => 'Invoice',
                'route'       => 'console.ledger.billing.invoices.index.details',
                'models'      => [$invoice->uuid],
                'breadcrumb'  => 'Ledger > Billing > Invoices',
            ]);
    }

    private function searchTemplates(string $query, int $limit): Collection
    {
        return Template::where('company_uuid', session('company'))
            ->where('context_type', 'ledger-invoice')
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['name', 'description', 'context_type', 'public_id', 'uuid'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'description', 'context_type'])
            ->map(fn (Template $template) => [
                'label'       => $template->name ?: $template->public_id,
                'description' => $template->description ?: 'Ledger invoice template',
                'icon'        => 'file-code',
                'type'        => 'Invoice Template',
                'route'       => 'console.ledger.billing.invoice-templates.index.edit',
                'models'      => [$template->uuid],
                'breadcrumb'  => 'Ledger > Billing > Invoice Templates',
            ]);
    }

    private function searchWallets(string $query, int $limit): Collection
    {
        return Wallet::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'name', 'description', 'currency', 'status'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'description', 'currency', 'status', 'balance'])
            ->map(fn (Wallet $wallet) => [
                'label'       => $wallet->name ?: $wallet->public_id,
                'description' => trim(implode(' ', array_filter([$wallet->status, $wallet->currency, $wallet->description]))),
                'icon'        => 'wallet',
                'type'        => 'Wallet',
                'route'       => 'console.ledger.payments.wallets.index.details',
                'models'      => [$wallet->uuid],
                'breadcrumb'  => 'Ledger > Payments > Wallets',
            ]);
    }

    private function searchTransactions(string $query, int $limit): Collection
    {
        return Transaction::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'amount', 'currency', 'status', 'type', 'description', 'reference'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'amount', 'currency', 'status', 'type', 'description', 'reference'])
            ->map(fn (Transaction $transaction) => [
                'label'       => $transaction->public_id ?: $transaction->reference,
                'description' => trim(implode(' ', array_filter([$transaction->status, $transaction->currency, $transaction->description ?: $transaction->reference]))),
                'icon'        => 'money-bill-transfer',
                'type'        => 'Transaction',
                'route'       => 'console.ledger.payments.transactions.index.details',
                'models'      => [$transaction->uuid],
                'breadcrumb'  => 'Ledger > Payments > Transactions',
            ]);
    }

    private function searchGateways(string $query, int $limit): Collection
    {
        return Gateway::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'name', 'driver', 'description', 'status', 'environment'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'driver', 'description', 'status', 'environment'])
            ->map(fn (Gateway $gateway) => [
                'label'       => $gateway->name ?: $gateway->public_id,
                'description' => trim(implode(' ', array_filter([$gateway->driver, $gateway->status, $gateway->environment, $gateway->description]))),
                'icon'        => 'credit-card',
                'type'        => 'Gateway',
                'route'       => 'console.ledger.payments.gateways.index.details',
                'models'      => [$gateway->uuid],
                'breadcrumb'  => 'Ledger > Payments > Gateways',
            ]);
    }

    private function searchAccounts(string $query, int $limit): Collection
    {
        return Account::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'name', 'code', 'type', 'description', 'currency', 'status'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'code', 'type', 'description', 'currency', 'status'])
            ->map(fn (Account $account) => [
                'label'       => $account->name ?: $account->code,
                'description' => trim(implode(' ', array_filter([$account->code, $account->type, $account->currency, $account->description]))),
                'icon'        => 'sitemap',
                'type'        => 'Account',
                'route'       => 'console.ledger.accounting.accounts.index.details',
                'models'      => [$account->uuid],
                'breadcrumb'  => 'Ledger > Accounting > Chart of Accounts',
            ]);
    }

    private function searchJournals(string $query, int $limit): Collection
    {
        return Journal::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'number', 'reference', 'memo', 'type', 'status', 'description', 'currency'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'number', 'reference', 'memo', 'type', 'status', 'description', 'currency'])
            ->map(fn (Journal $journal) => [
                'label'       => $journal->number ?: $journal->public_id,
                'description' => trim(implode(' ', array_filter([$journal->status, $journal->type, $journal->currency, $journal->description ?: $journal->memo]))),
                'icon'        => 'book',
                'type'        => 'Journal Entry',
                'route'       => 'console.ledger.accounting.journal.index.details',
                'models'      => [$journal->uuid],
                'breadcrumb'  => 'Ledger > Accounting > Journal Entries',
            ]);
    }

    private function whereLike(Builder $builder, array $columns, string $query): void
    {
        $like = '%' . Str::replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $builder->{$method}($column, 'like', $like);
        }
    }
}
