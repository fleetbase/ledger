<?php

namespace Fleetbase\Ledger\Http\Filter;

use Fleetbase\Http\Filter\Filter;
use Fleetbase\Support\Utils;

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
        if (!$status) {
            return;
        }

        $this->builder->where('status', $status);
    }

    public function currency(?string $currency): void
    {
        if (!$currency) {
            return;
        }

        $this->builder->where('currency', strtoupper($currency));
    }

    public function customer(?string $customer): void
    {
        if (!$customer) {
            return;
        }

        $this->builder->where('customer_uuid', $customer);
    }

    public function customerUuid(?string $customer): void
    {
        $this->customer($customer);
    }

    public function order(?string $order): void
    {
        if (!$order) {
            return;
        }

        $this->builder->where(function ($query) use ($order) {
            $query->where('order_uuid', $order)
                ->orWhereHas('order', function ($orderQuery) use ($order) {
                    $orderQuery->where('uuid', $order)
                        ->orWhere('public_id', $order)
                        ->orWhereHas('trackingNumber', function ($trackingQuery) use ($order) {
                            $trackingQuery->where('tracking_number', $order);
                        });
                });
        });
    }

    public function orderUuid(?string $order): void
    {
        $this->order($order);
    }

    public function amount(?string $amount): void
    {
        if (!$amount) {
            return;
        }

        [$min, $max] = array_pad(array_map('trim', explode(',', $amount, 2)), 2, null);
        $min         = is_numeric($min) ? (int) $min : null;
        $max         = is_numeric($max) ? (int) $max : null;

        if ($min !== null && $max !== null) {
            $this->builder->whereBetween('total_amount', [$min, $max]);

            return;
        }

        if ($min !== null) {
            $this->builder->where('total_amount', '>=', $min);
        }

        if ($max !== null) {
            $this->builder->where('total_amount', '<=', $max);
        }
    }

    public function publicId(?string $publicId): void
    {
        $this->builder->searchWhere('public_id', $publicId);
    }

    public function createdAt($createdAt): void
    {
        $createdAt = Utils::dateRange($createdAt);
        if (is_array($createdAt)) {
            $this->builder->whereBetween('created_at', $createdAt);
        } else {
            $this->builder->whereDate('created_at', $createdAt);
        }
    }

    public function dueDate($dueDate): void
    {
        $dueDate = Utils::dateRange($dueDate);
        if (is_array($dueDate)) {
            $this->builder->whereBetween('due_date', $dueDate);
        } else {
            $this->builder->whereDate('due_date', $dueDate);
        }
    }
}
