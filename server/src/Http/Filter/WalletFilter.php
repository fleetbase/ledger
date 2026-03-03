<?php

namespace Fleetbase\Ledger\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class WalletFilter extends Filter
{
    public function queryForInternal(): void
    {
        $this->builder
            ->where('company_uuid', $this->session->get('company'))
            ->with(['subject']);
    }

    public function queryForPublic(): void
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $searchQuery): void
    {
        $this->builder->where(function ($q) use ($searchQuery) {
            $q->searchWhere('name', $searchQuery)
              ->orWhere('public_id', 'like', "%{$searchQuery}%");
        });
    }

    /**
     * Filter by wallet type.
     *
     * The `type` attribute is computed from `subject_type` (not a real column),
     * so we translate the friendly type name to a subject_type LIKE pattern.
     */
    public function type(?string $type): void
    {
        if (!$type) {
            return;
        }

        $this->builder->where('subject_type', 'like', '%' . strtolower($type) . '%');
    }

    public function status(?string $status): void
    {
        $this->builder->where('status', $status);
    }

    public function currency(?string $currency): void
    {
        $this->builder->where('currency', strtoupper($currency));
    }

    public function isFrozen(?string $isFrozen): void
    {
        if ($isFrozen === null) {
            return;
        }
        $this->builder->where('is_frozen', filter_var($isFrozen, FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * Filter by subject UUID (the entity that owns the wallet).
     */
    public function subject(?string $subject): void
    {
        $this->builder->where('subject_uuid', $subject);
    }

    /**
     * Filter by subject_type class name fragment (e.g. 'driver', 'customer', 'company').
     */
    public function subjectType(?string $subjectType): void
    {
        if (!$subjectType) {
            return;
        }
        $this->builder->where('subject_type', 'like', '%' . strtolower($subjectType) . '%');
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
