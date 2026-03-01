<?php

namespace Fleetbase\Ledger\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class InvoiceFilter extends Filter
{
    public function queryForInternal(): void
    {
        $this->builder
            ->where('company_uuid', $this->session->get('company'))
            ->with(['customer', 'items']);
    }

    public function queryForPublic(): void
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $searchQuery): void
    {
        $this->builder->where(function ($q) use ($searchQuery) {
            $q->searchWhere('number', $searchQuery)
              ->orWhere('notes', 'like', "%{$searchQuery}%");
        });
    }

    public function status(?string $status): void
    {
        $this->builder->where('status', $status);
    }

    public function customer(?string $customer): void
    {
        $this->builder->where('customer_uuid', $customer);
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

    public function dueDate($dueDate): void
    {
        $dueDate = \Fleetbase\Support\Utils::dateRange($dueDate);
        if (is_array($dueDate)) {
            $this->builder->whereBetween('due_date', $dueDate);
        } else {
            $this->builder->whereDate('due_date', $dueDate);
        }
    }
}
