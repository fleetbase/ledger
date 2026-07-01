<?php

namespace Fleetbase\Ledger\Seeders\Testing;

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Models\Company;
use Fleetbase\Seeders\Concerns\ResolvesSeedCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Milon\Barcode\Facades\DNS2DFacade as DNS2D;

class TalerDemoSeeder extends Seeder
{
    use ResolvesSeedCompany;

    private const SEED = 'taler_demo';
    private const CURRENCY = 'KUDOS';
    private const DEFAULT_AMOUNT = '0.50';

    private array $ids = [];
    private string $seedRunId = '';
    private string $seedRunSuffix = '';
    private int $amount = 50;
    private int $baseAmount = 40;
    private int $feeAmount = 10;

    public function run(): void
    {
        $company = $this->resolveCompany();

        if (!$company) {
            $this->command?->error('[Ledger/Taler] No company found. Create a company or set TALER_DEMO_COMPANY_UUID/TALER_DEMO_COMPANY_PUBLIC_ID.');

            return;
        }

        session(['company' => $company->uuid]);
        $now       = Carbon::now();
        $this->seedRunId     = $this->resolveSeedRunId($now);
        $this->seedRunSuffix = $this->seedRunSuffix($this->seedRunId);
        $this->ids           = $this->stableIds($company->uuid, $this->seedRunId);

        if (!$this->resolveAmount()) {
            return;
        }

        DB::transaction(function () use ($company, $now) {
            $this->seedFleetOpsRoutePlaces($company, $now);
            $this->seedFleetOpsQuoteFixture($company, $now);
            $this->seedCoreTransaction($company, $now);
            $this->seedFleetOpsOrderFixture($company, $now);
            $this->seedFleetOpsTrackingFixture($company, $now);
            $this->seedInvoice($company, $now);
        });

        $publicId = $this->invoicePublicId($company->uuid);
        $orderId  = $this->publicId('order', $company->uuid);
        $this->command?->info("[Ledger/Taler] Seeded KUDOS demo invoice {$publicId} for company {$company->public_id}.");
        $this->command?->info("[Ledger/Taler] Run ID: {$this->seedRunId}");
        $this->command?->info("[Ledger/Taler] Amount: " . self::CURRENCY . ' ' . $this->formatAmount($this->amount));
        $this->command?->info("[Ledger/Taler] FleetOps order: {$orderId} / {$this->orderInternalId()}");
        $this->command?->info("[Ledger/Taler] Public payment link: /~/invoice?id={$publicId}");
        $this->command?->info("[Ledger/Taler] Ledger route: /ledger/invoice/{$publicId}");
        $this->command?->info('[Ledger/Taler] Local gateway: backend_url=http://taler-merchant.lvh.me:9966 and the generated token from packages/ledger/docker/taler/generated-token.txt.');
        $this->command?->info('[Ledger/Taler] Hosted sandbox gateway: backend_url=https://merchant.taler.fleetbase.io, instance_id=default, and the hosted token.');
    }

    protected function resolveCompany(): ?Company
    {
        return $this->resolveSeedCompany(
            'TALER_DEMO_COMPANY_UUID',
            'TALER_DEMO_COMPANY_PUBLIC_ID'
        );
    }

    private function stableIds(string $companyUuid, string $seedRunId): array
    {
        $runScope = $companyUuid . ':' . $seedRunId;

        return [
            'pickup'           => $this->stableUuid($companyUuid . ':pickup'),
            'dropoff'          => $this->stableUuid($companyUuid . ':dropoff'),
            'payload'          => $this->stableUuid($runScope . ':payload'),
            'order'            => $this->stableUuid($runScope . ':order'),
            'tracking_number'  => $this->stableUuid($runScope . ':tracking-number'),
            'tracking_status'  => $this->stableUuid($runScope . ':tracking-status-created'),
            'service_quote'    => $this->stableUuid($runScope . ':service-quote'),
            'quote_item_base'  => $this->stableUuid($runScope . ':quote-item-base'),
            'quote_item_fee'   => $this->stableUuid($runScope . ':quote-item-fee'),
            'purchase_rate'    => $this->stableUuid($runScope . ':purchase-rate'),
            'transaction'      => $this->stableUuid($runScope . ':transaction'),
            'transaction_base' => $this->stableUuid($runScope . ':transaction-item-base'),
            'transaction_fee'  => $this->stableUuid($runScope . ':transaction-item-fee'),
            'invoice'          => $this->stableUuid($runScope . ':invoice'),
            'invoice_base'     => $this->stableUuid($runScope . ':invoice-item-base'),
            'invoice_fee'      => $this->stableUuid($runScope . ':invoice-item-fee'),
        ];
    }

    private function seedFleetOpsRoutePlaces(Company $company, Carbon $now): void
    {
        if (!class_exists(Place::class) || !Schema::hasTable('places')) {
            return;
        }

        $this->upsertPlace('pickup', $company, [
            'name'        => 'Taler Demo Pickup - Tanjong Pagar',
            'street1'     => '1 Raffles Quay',
            'city'        => 'Singapore',
            'country'     => 'SG',
            'postal_code' => '048583',
            'lat'         => 1.2816,
            'lng'         => 103.8510,
        ], $now);

        $this->upsertPlace('dropoff', $company, [
            'name'        => 'Taler Demo Dropoff - Orchard',
            'street1'     => '2 Orchard Turn',
            'city'        => 'Singapore',
            'country'     => 'SG',
            'postal_code' => '238801',
            'lat'         => 1.3048,
            'lng'         => 103.8318,
        ], $now);
    }

    private function seedFleetOpsQuoteFixture(Company $company, Carbon $now): void
    {
        if (!Schema::hasTable('payloads') || !Schema::hasTable('service_quotes')) {
            return;
        }

        DB::table('payloads')->updateOrInsert(
            ['uuid' => $this->ids['payload']],
            [
                '_key'               => $this->fixtureKey('payload'),
                'public_id'          => $this->publicId('payload', $company->uuid),
                'company_uuid'       => $company->uuid,
                'pickup_uuid'        => Schema::hasTable('places') ? $this->ids['pickup'] : null,
                'dropoff_uuid'       => Schema::hasTable('places') ? $this->ids['dropoff'] : null,
                'provider'           => 'fleetbase',
                'payment_method'     => 'taler',
                'cod_amount'         => $this->amount,
                'cod_currency'       => self::CURRENCY,
                'cod_payment_method' => 'taler',
                'type'               => 'transport',
                'meta'               => $this->meta('payload', [
                    'description' => 'GNU Taler KUDOS demo payload',
                ]),
                'created_at'         => $now,
                'updated_at'         => $now,
            ]
        );

        DB::table('service_quotes')->updateOrInsert(
            ['uuid' => $this->ids['service_quote']],
            [
                '_key'         => $this->fixtureKey('service_quote'),
                'public_id'    => $this->publicId('quote', $company->uuid),
                'request_id'   => $this->seedRunId,
                'company_uuid' => $company->uuid,
                'payload_uuid' => $this->ids['payload'],
                'amount'       => $this->amount,
                'currency'     => self::CURRENCY,
                'meta'         => $this->meta('service_quote', [
                    'description' => 'GNU Taler KUDOS demo service quote',
                ]),
                'expired_at'   => $now->copy()->addWeek()->toDateTimeString(),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]
        );

        $this->seedServiceQuoteItem('quote_item_base', 'taler-demo-base', 'Taler demo delivery service', $this->baseAmount, $now);
        $this->seedServiceQuoteItem('quote_item_fee', 'taler-demo-fee', 'Taler demo handling fee', $this->feeAmount, $now);
    }

    private function seedCoreTransaction(Company $company, Carbon $now): void
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        DB::table('transactions')->updateOrInsert(
            ['uuid' => $this->ids['transaction']],
            $this->columns('transactions', [
                '_key'                   => $this->fixtureKey('transaction'),
                'public_id'              => $this->publicId('txn', $company->uuid),
                'company_uuid'           => $company->uuid,
                'subject_uuid'           => $this->ids['order'],
                'subject_type'           => 'Fleetbase\\FleetOps\\Models\\Order',
                'context_uuid'           => $this->ids['purchase_rate'],
                'context_type'           => 'Fleetbase\\FleetOps\\Models\\PurchaseRate',
                'gateway_transaction_id' => 'taler-demo-' . $this->seedRunSuffix,
                'gateway'                => 'internal',
                'amount'                 => $this->amount,
                'fee_amount'             => 0,
                'tax_amount'             => 0,
                'net_amount'             => $this->amount,
                'currency'               => self::CURRENCY,
                'exchange_rate'          => 1,
                'description'            => 'GNU Taler KUDOS demo dispatch order',
                'reference'              => 'taler-demo-' . $this->seedRunSuffix,
                'type'                   => 'dispatch',
                'direction'              => 'credit',
                'status'                 => 'success',
                'settlement_status'      => Schema::hasColumn('transactions', 'settlement_status') ? 'unpaid' : null,
                'meta'                   => $this->meta('transaction', [
                    'invoice_uuid' => $this->ids['invoice'],
                ]),
                'period'                 => $now->format('Y-m'),
                'created_at'             => $now,
                'updated_at'             => $now,
            ])
        );

        $this->seedTransactionItem('transaction_base', 'taler-demo-base', 'Taler demo delivery service', $this->baseAmount, 0, $now);
        $this->seedTransactionItem('transaction_fee', 'taler-demo-fee', 'Taler demo handling fee', $this->feeAmount, 1, $now);
    }

    private function seedFleetOpsOrderFixture(Company $company, Carbon $now): void
    {
        if (!Schema::hasTable('orders') || !Schema::hasTable('purchase_rates')) {
            return;
        }

        $orderConfigUuid = null;
        if (class_exists(FleetOps::class)) {
            $orderConfigUuid = FleetOps::createTransportConfig($company)->uuid;
        }

        DB::table('purchase_rates')->updateOrInsert(
            ['uuid' => $this->ids['purchase_rate']],
            [
                '_key'               => $this->fixtureKey('purchase_rate'),
                'public_id'          => $this->publicId('rate', $company->uuid),
                'meta'               => $this->meta('purchase_rate', [
                    'currency' => self::CURRENCY,
                    'amount'   => $this->amount,
                ]),
                'company_uuid'       => $company->uuid,
                'transaction_uuid'   => Schema::hasTable('transactions') ? $this->ids['transaction'] : null,
                'service_quote_uuid' => Schema::hasTable('service_quotes') ? $this->ids['service_quote'] : null,
                'payload_uuid'       => Schema::hasTable('payloads') ? $this->ids['payload'] : null,
                'status'             => 'success',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]
        );

        DB::table('orders')->updateOrInsert(
            ['uuid' => $this->ids['order']],
            $this->columns('orders', [
                '_key'               => $this->fixtureKey('order'),
                'public_id'          => $this->publicId('order', $company->uuid),
                'internal_id'        => $this->orderInternalId(),
                'company_uuid'       => $company->uuid,
                'payload_uuid'       => Schema::hasTable('payloads') ? $this->ids['payload'] : null,
                'transaction_uuid'   => Schema::hasTable('transactions') ? $this->ids['transaction'] : null,
                'purchase_rate_uuid' => $this->ids['purchase_rate'],
                'order_config_uuid'  => $orderConfigUuid,
                'meta'               => $this->meta('order', [
                    'currency' => self::CURRENCY,
                    'total'    => $this->amount,
                ]),
                'notes'              => 'GNU Taler KUDOS demo order for local invoice payment testing.',
                'pod_method'         => 'scan',
                'pod_required'       => false,
                'type'               => 'transport',
                'status'             => 'created',
                'created_at'         => $now,
                'updated_at'         => $now,
            ])
        );
    }

    private function seedFleetOpsTrackingFixture(Company $company, Carbon $now): void
    {
        if (!Schema::hasTable('tracking_numbers') || !Schema::hasTable('orders')) {
            return;
        }

        DB::table('tracking_numbers')->updateOrInsert(
            ['uuid' => $this->ids['tracking_number']],
            [
                '_key'            => $this->fixtureKey('tracking_number'),
                'public_id'       => $this->publicId('track', $company->uuid),
                'company_uuid'    => $company->uuid,
                'owner_uuid'      => $this->ids['order'],
                'owner_type'      => 'Fleetbase\\FleetOps\\Models\\Order',
                'tracking_number' => 'TALER-DEMO-' . strtoupper($this->seedRunSuffix),
                'region'          => 'SG',
                'qr_code'         => DNS2D::getBarcodePNG($this->orderInternalId(), 'QRCODE'),
                'barcode'         => DNS2D::getBarcodePNG($this->orderInternalId(), 'PDF417'),
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );

        DB::table('orders')
            ->where('uuid', $this->ids['order'])
            ->update([
                'tracking_number_uuid' => $this->ids['tracking_number'],
                'updated_at'           => $now,
            ]);

        $this->seedTrackingStatus($company, $now);
    }

    private function seedTrackingStatus(Company $company, Carbon $now): void
    {
        if (!class_exists(TrackingStatus::class) || !Schema::hasTable('tracking_statuses')) {
            return;
        }

        TrackingStatus::withoutEvents(function () use ($company, $now) {
            $status = TrackingStatus::where('uuid', $this->ids['tracking_status'])->first() ?? new TrackingStatus();
            $status->forceFill([
                '_key'                 => $this->fixtureKey('tracking_status_created'),
                'uuid'                 => $this->ids['tracking_status'],
                'public_id'            => $this->publicId('status', $company->uuid),
                'company_uuid'         => $company->uuid,
                'tracking_number_uuid' => $this->ids['tracking_number'],
                'status'               => 'Order Created',
                'details'              => 'Taler demo order created for KUDOS invoice payment testing.',
                'code'                 => 'created',
                'complete'             => false,
                'city'                 => 'Singapore',
                'country'              => 'SG',
                'location'             => new Point(1.2816, 103.8510),
                'meta'                 => $this->metaArray('tracking_status_created'),
                'created_at'           => $now,
                'updated_at'           => $now,
            ])->save();
        });

        DB::table('tracking_numbers')
            ->where('uuid', $this->ids['tracking_number'])
            ->update([
                'status_uuid' => $this->ids['tracking_status'],
                'updated_at'  => $now,
            ]);
    }

    private function seedServiceQuoteItem(string $idKey, string $code, string $details, int $amount, Carbon $now): void
    {
        if (!Schema::hasTable('service_quote_items')) {
            return;
        }

        DB::table('service_quote_items')->updateOrInsert(
            ['uuid' => $this->ids[$idKey]],
            [
                '_key'               => $this->fixtureKey($idKey),
                'service_quote_uuid' => $this->ids['service_quote'],
                'amount'             => $amount,
                'currency'           => self::CURRENCY,
                'details'            => $details,
                'code'               => $code,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]
        );
    }

    private function seedTransactionItem(string $idKey, string $code, string $description, int $amount, int $sortOrder, Carbon $now): void
    {
        if (!Schema::hasTable('transaction_items')) {
            return;
        }

        DB::table('transaction_items')->updateOrInsert(
            ['uuid' => $this->ids[$idKey]],
            $this->columns('transaction_items', [
                '_key'             => $this->fixtureKey($idKey),
                'public_id'        => Schema::hasColumn('transaction_items', 'public_id') ? $this->publicId('txni-' . $sortOrder, $this->ids[$idKey]) : null,
                'transaction_uuid' => $this->ids['transaction'],
                'quantity'         => Schema::hasColumn('transaction_items', 'quantity') ? 1 : null,
                'unit_price'       => Schema::hasColumn('transaction_items', 'unit_price') ? $amount : null,
                'amount'           => $amount,
                'currency'         => self::CURRENCY,
                'tax_rate'         => Schema::hasColumn('transaction_items', 'tax_rate') ? 0 : null,
                'tax_amount'       => Schema::hasColumn('transaction_items', 'tax_amount') ? 0 : null,
                'details'          => $description,
                'description'      => Schema::hasColumn('transaction_items', 'description') ? $description : null,
                'code'             => $code,
                'sort_order'       => Schema::hasColumn('transaction_items', 'sort_order') ? $sortOrder : null,
                'meta'             => $this->meta($idKey),
                'created_at'       => $now,
                'updated_at'       => $now,
            ])
        );
    }

    private function seedInvoice(Company $company, Carbon $now): void
    {
        $invoicePublicId = $this->invoicePublicId($company->uuid);
        $existingInvoice = Invoice::where('uuid', $this->ids['invoice'])->first();
        $isPaidInvoice   = $existingInvoice && ($existingInvoice->status === 'paid' || (int) $existingInvoice->amount_paid > 0);

        if ($isPaidInvoice) {
            $this->command?->warn("[Ledger/Taler] Run {$this->seedRunId} already has a paid invoice; leaving invoice totals and line items unchanged.");

            return;
        }

        DB::table('ledger_invoices')->updateOrInsert(
            ['uuid' => $this->ids['invoice']],
            [
                '_key'             => $this->fixtureKey('invoice'),
                'public_id'        => $invoicePublicId,
                'company_uuid'     => $company->uuid,
                'order_uuid'       => Schema::hasTable('orders') ? $this->ids['order'] : null,
                'transaction_uuid' => Schema::hasTable('transactions') ? $this->ids['transaction'] : null,
                'number'           => $this->invoiceNumber(),
                'date'             => $now->toDateString(),
                'due_date'         => $now->copy()->addDays(7)->toDateString(),
                'subtotal'         => $this->amount,
                'tax'              => 0,
                'total_amount'     => $this->amount,
                'amount_paid'      => 0,
                'balance'          => $this->amount,
                'currency'         => self::CURRENCY,
                'status'           => 'sent',
                'notes'            => 'GNU Taler KUDOS demo invoice for local payment testing.',
                'terms'            => 'Demo currency only. Not for production settlement.',
                'meta'             => $this->meta('invoice', [
                    'service_quote_uuid' => $this->ids['service_quote'],
                    'purchase_rate_uuid' => $this->ids['purchase_rate'],
                ]),
                'sent_at'          => $now,
                'paid_at'          => null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]
        );

        $this->seedInvoiceItem('invoice_base', 'Taler demo delivery service', $this->baseAmount, $now);
        $this->seedInvoiceItem('invoice_fee', 'Taler demo handling fee', $this->feeAmount, $now);

        Invoice::where('uuid', $this->ids['invoice'])->first()?->calculateTotals();

        DB::table('ledger_invoices')
            ->where('uuid', $this->ids['invoice'])
            ->update([
                'amount_paid' => 0,
                'balance'     => $this->amount,
                'status'      => 'sent',
                'sent_at'     => $now,
                'updated_at'  => $now,
            ]);
    }

    private function seedInvoiceItem(string $idKey, string $description, int $amount, Carbon $now): void
    {
        DB::table('ledger_invoice_items')->updateOrInsert(
            ['uuid' => $this->ids[$idKey]],
            [
                '_key'         => $this->fixtureKey($idKey),
                'invoice_uuid' => $this->ids['invoice'],
                'description'  => $description,
                'quantity'     => 1,
                'unit_price'   => $amount,
                'amount'       => $amount,
                'tax_rate'     => 0,
                'tax_amount'   => 0,
                'meta'         => $this->meta($idKey),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]
        );
    }

    private function upsertPlace(string $idKey, Company $company, array $place, Carbon $now): void
    {
        Place::withoutEvents(function () use ($idKey, $company, $place, $now) {
            $model = Place::where('uuid', $this->ids[$idKey])->first() ?? new Place();
            $model->forceFill([
                '_key'         => $this->fixtureKey($idKey),
                'uuid'         => $this->ids[$idKey],
                'public_id'    => $this->publicId('place-' . $idKey, $company->uuid),
                'company_uuid' => $company->uuid,
                'name'         => $place['name'],
                'street1'      => $place['street1'],
                'city'         => $place['city'],
                'country'      => $place['country'],
                'postal_code'  => $place['postal_code'],
                'location'     => new Point($place['lat'], $place['lng']),
                'latitude'     => (string) $place['lat'],
                'longitude'    => (string) $place['lng'],
                'type'         => 'address',
                'meta'         => $this->metaArray($idKey, includeRunId: false),
                'created_at'   => $now,
                'updated_at'   => $now,
            ])->save();
        });
    }

    private function stableUuid(string $value): string
    {
        $hash = md5(self::SEED . ':' . $value);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    private function publicId(string $prefix, string $value): string
    {
        if (!str_starts_with($prefix, 'place-')) {
            $value .= ':' . $this->seedRunId;
        }

        return $prefix . '_taler_demo_' . substr(hash('sha256', self::SEED . ':' . $value), 0, 12);
    }

    private function invoicePublicId(string $companyUuid): string
    {
        return $this->publicId('invoice', $companyUuid);
    }

    private function fixtureKey(string $seedId): string
    {
        if (in_array($seedId, ['pickup', 'dropoff'], true)) {
            return self::SEED . ':' . $seedId;
        }

        if ($this->seedRunId) {
            return self::SEED . ':' . $this->seedRunId . ':' . $seedId;
        }

        return self::SEED . ':' . $seedId;
    }

    private function meta(string $seedId, array $extra = []): string
    {
        return json_encode($this->metaArray($seedId, $extra));
    }

    private function metaArray(string $seedId, array $extra = [], bool $includeRunId = true): array
    {
        return array_merge([
            'seed'     => self::SEED,
            'seed_id'  => $seedId,
            'currency' => self::CURRENCY,
        ], $includeRunId && $this->seedRunId ? ['seed_run_id' => $this->seedRunId] : [], $extra);
    }

    private function resolveSeedRunId(Carbon $now): string
    {
        $configured = trim((string) env('TALER_DEMO_RUN_ID', ''));

        if ($configured !== '') {
            return $this->sanitizeRunId($configured);
        }

        return $this->sanitizeRunId(sprintf(
            'taler_demo_%s_%s',
            $now->format('YmdHis'),
            Str::lower(Str::random(6))
        ));
    }

    private function sanitizeRunId(string $runId): string
    {
        $runId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $runId) ?: 'taler-demo';
        $runId = trim($runId, '-_');

        return substr($runId ?: 'taler-demo', 0, 48);
    }

    private function resolveAmount(): bool
    {
        $amount = trim((string) env('TALER_DEMO_AMOUNT', self::DEFAULT_AMOUNT));

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            $this->command?->error('[Ledger/Taler] Invalid TALER_DEMO_AMOUNT. Use a positive decimal amount with up to 2 decimals, for example TALER_DEMO_AMOUNT=5.00.');

            return false;
        }

        $minorUnits = $this->amountToMinorUnits($amount);

        if ($minorUnits <= 0) {
            $this->command?->error('[Ledger/Taler] Invalid TALER_DEMO_AMOUNT. Amount must be greater than 0.');

            return false;
        }

        $this->amount     = $minorUnits;
        $this->baseAmount = intdiv($minorUnits * 80, 100);
        $this->feeAmount  = $minorUnits - $this->baseAmount;

        return true;
    }

    private function amountToMinorUnits(string $amount): int
    {
        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction           = str_pad($fraction, 2, '0');

        return ((int) $units * 100) + (int) substr($fraction, 0, 2);
    }

    private function formatAmount(int $minorUnits): string
    {
        return sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }

    private function seedRunSuffix(string $runId): string
    {
        return substr(hash('sha256', self::SEED . ':' . $runId), 0, 12);
    }

    private function orderInternalId(): string
    {
        return 'TALER-DEMO-KUDOS-' . strtoupper($this->seedRunSuffix);
    }

    private function invoiceNumber(): string
    {
        return 'TALER-DEMO-' . strtoupper($this->seedRunSuffix);
    }

    private function columns(string $tableName, array $values): array
    {
        return array_filter(
            $values,
            fn ($value, $column) => $value !== null && Schema::hasColumn($tableName, $column),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
