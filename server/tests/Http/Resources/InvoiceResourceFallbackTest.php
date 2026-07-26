<?php

use Fleetbase\Ledger\Http\Resources\v1\Invoice as InvoiceResource;
use Fleetbase\Ledger\Models\Invoice;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class InvoiceResourceProbe extends InvoiceResource
{
    public function transformCustomerModel(?Model $model): ?array
    {
        return $this->transformMorphResource($model);
    }
}

test('invoice resource preserves empty customers and resolves unregistered morph models through Fleetbase fallback', function () {
    Container::getInstance()->instance('request', Request::create('/'));
    $invoice = new Invoice();
    $invoice->setRawAttributes(['customer_type' => 'App\\Customer'], true);
    $resource = new InvoiceResourceProbe($invoice);

    $generic = new class extends Model {
        protected $guarded = [];
    };
    $generic->setRawAttributes(['uuid' => 'generic-customer', 'name' => 'Generic Customer'], true);

    expect($resource->setCustomerType(null))->toBeNull()
        ->and($resource->setCustomerType([]))->toBe([])
        ->and($resource->transformCustomerModel(null))->toBeNull()
        ->and($resource->transformCustomerModel($generic))->toMatchArray([
            'uuid' => 'generic-customer',
            'name' => 'Generic Customer',
        ]);
});
