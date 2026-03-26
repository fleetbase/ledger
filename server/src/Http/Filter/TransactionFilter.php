<?php

namespace Fleetbase\Ledger\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class TransactionFilter extends Filter
{
    public function queryForInternal(): void
    {
        $this->builder
            ->where('company_uuid', $this->session->get('company'))
            ->with([
                'items',
                'journal.debitAccount',
                'journal.creditAccount',
                'subject',
                'payer',
                'payee',
                'initiator',
                'context',
            ]);
    }

    public function queryForPublic(): void
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $searchQuery): void
    {
        $this->builder->where(function ($q) use ($searchQuery) {
            $q->where('gateway_transaction_id', 'like', "%{$searchQuery}%")
              ->orWhere('public_id', 'like', "%{$searchQuery}%")
              ->orWhere('description', 'like', "%{$searchQuery}%")
              ->orWhere('reference', 'like', "%{$searchQuery}%");
        });
    }

    public function type(?string $type): void
    {
        $this->builder->where('type', $type);
    }

    public function direction(?string $direction): void
    {
        $this->builder->where('direction', $direction);
    }

    public function status(?string $status): void
    {
        $this->builder->where('status', $status);
    }

    public function gateway(?string $gateway): void
    {
        $this->builder->where('gateway', $gateway);
    }

    public function customer(?string $customer): void
    {
        $this->builder->where('customer_uuid', $customer);
    }

    /**
     * Filter by payer UUID (any morph type).
     */
    public function payer(?string $payer): void
    {
        $this->builder->where('payer_uuid', $payer);
    }

    /**
     * Filter by subject UUID (the resource the transaction is about).
     */
    public function subject(?string $subject): void
    {
        $this->builder->where('subject_uuid', $subject);
    }

    /**
     * Filter by context UUID (e.g. an Order or Invoice linked to the transaction).
     */
    public function context(?string $context): void
    {
        $this->builder->where('context_uuid', $context);
    }

    /**
     * Filter by external reference string.
     */
    public function reference(?string $reference): void
    {
        $this->builder->where('reference', 'like', "%{$reference}%");
    }

    /**
     * Filter by payment method (card, bank_transfer, wallet, cash).
     */
    public function paymentMethod(?string $paymentMethod): void
    {
        $this->builder->where('payment_method', $paymentMethod);
    }

    public function publicId(?string $publicId): void
    {
        $this->builder->searchWhere('public_id', $publicId);
    }

    public function createdAt($createdAt): void
    {
        $createdAt = \Fleetbase\Support\Utils::dateRange($createdAt);
        if (is_array($createdAt)) {
            $this->builder->whereBetween('created_at', $createdAt);
        } else {
            $this->builder->whereDate('created_at', $createdAt);
        }
    }
}
