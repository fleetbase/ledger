<?php

namespace Fleetbase\Ledger\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class GatewayFilter extends Filter
{
    public function queryForInternal(): void
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function queryForPublic(): void
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $searchQuery): void
    {
        $this->builder->where(function ($q) use ($searchQuery) {
            $q->searchWhere('name', $searchQuery)
              ->orWhere('driver', 'like', "%{$searchQuery}%");
        });
    }

    public function driver(?string $driver): void
    {
        $this->builder->where('driver', $driver);
    }

    public function status(?string $status): void
    {
        $this->builder->where('status', $status);
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
