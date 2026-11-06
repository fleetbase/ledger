<?php

namespace Fleetbase\Ledger\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\CustomerAuth;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Models\Invoice;
use Illuminate\Http\JsonResponse;

/**
 * The invoice for a customer's own order, so their app can pay it through the public
 * invoice routes.
 */
class CustomerInvoiceController extends Controller
{
    /**
     * GET /ledger/v1/customers/orders/{id}/invoice.
     */
    public function forOrder(string $id): JsonResponse
    {
        $order = $this->findOrder($id);
        if (!$order || $order->customer_uuid !== $this->currentCustomer()?->uuid) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        $invoice = $this->findInvoice($order);

        return response()->json([
            'invoice' => $invoice ? [
                'id'           => $invoice->public_id,
                'status'       => $invoice->status,
                'total_amount' => $invoice->total_amount,
                'balance'      => $invoice->balance,
                'currency'     => $invoice->currency,
            ] : null,
        ]);
    }

    protected function currentCustomer(): ?Contact
    {
        return CustomerAuth::current();
    }

    protected function findOrder(string $id): ?Order
    {
        return Order::where('public_id', $id)->where('company_uuid', session('company'))->first();
    }

    /**
     * The newest invoice the customer can see. A draft isn't theirs to see yet.
     */
    protected function findInvoice(Order $order): ?Invoice
    {
        return Invoice::where('order_uuid', $order->uuid)
            ->where('company_uuid', $order->company_uuid)
            ->where('status', '!=', 'draft')
            ->latest()
            ->first();
    }
}
