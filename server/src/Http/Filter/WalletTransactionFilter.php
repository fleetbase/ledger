<?php

namespace Fleetbase\Ledger\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class WalletTransactionFilter extends Filter
{
    public function queryForInternal(): void
    {
        $this->builder
            ->where('company_uuid', $this->session->get('company'))
            ->with(['wallet']);
    }

    public function queryForPublic(): void
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $searchQuery): void
    {
        $this->builder->where(function ($q) use ($searchQuery) {
            $q->searchWhere('description', $searchQuery)
              ->orWhere('reference', 'like', "%{$searchQuery}%")
              ->orWhere('public_id', 'like', "%{$searchQuery}%");
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

    public function wallet(?string $wallet): void
    {
        $this->builder->where('wallet_uuid', $wallet);
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
