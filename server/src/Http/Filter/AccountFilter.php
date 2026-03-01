<?php

namespace Fleetbase\Ledger\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class AccountFilter extends Filter
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
              ->orWhere('code', 'like', "%{$searchQuery}%");
        });
    }

    public function type(?string $type): void
    {
        $this->builder->where('type', $type);
    }

    public function status(?string $status): void
    {
        $this->builder->where('status', $status);
    }

    public function code(?string $code): void
    {
        $this->builder->searchWhere('code', $code);
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
