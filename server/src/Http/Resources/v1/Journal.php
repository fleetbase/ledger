<?php

namespace Fleetbase\Ledger\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Journal extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        return [
            'id'                  => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'           => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'        => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'transaction_uuid'    => $this->when(Http::isInternalRequest(), $this->transaction_uuid),
            'debit_account_uuid'  => $this->when(Http::isInternalRequest(), $this->debit_account_uuid),
            'credit_account_uuid' => $this->when(Http::isInternalRequest(), $this->credit_account_uuid),
            'debit_account'       => $this->whenLoaded('debitAccount', fn () => new Account($this->debitAccount)),
            'credit_account'      => $this->whenLoaded('creditAccount', fn () => new Account($this->creditAccount)),
            'amount'              => $this->amount,
            'currency'            => $this->currency,
            'type'                => $this->type,
            'status'              => $this->status,
            'memo'                => $this->memo,
            'reference'           => $this->reference,
            'entry_date'          => $this->entry_date,
            'meta'                => $this->meta,
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }
}
