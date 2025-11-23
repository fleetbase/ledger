<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Http\Resources\v1\Invoice as InvoiceResource;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Models\InvoiceItem;
use Fleetbase\Ledger\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'invoice';

    /**
     * The model to query.
     *
     * @var \Fleetbase\Ledger\Models\Invoice
     */
    public $model = Invoice::class;

    /**
     * Query for invoices.
     *
     * @return \Illuminate\Http\Response
     */
    public function query(Request $request)
    {
        $results = Invoice::where('company_uuid', session('company'))
            ->with(['customer', 'items'])
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->filled('customer'), function ($query) use ($request) {
                $query->where('customer_uuid', $request->input('customer'));
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('number', 'like', "%{$search}%")
                        ->orWhere('public_id', 'like', "%{$search}%");
                });
            })
            ->orderBy($request->input('sort', 'created_at'), $request->input('order', 'desc'))
            ->paginate($request->input('limit', 15));

        return InvoiceResource::collection($results);
    }

    /**
     * Find a single invoice.
     *
     * @return \Illuminate\Http\Response
     */
    public function find($id, Request $request)
    {
        $invoice = Invoice::where('company_uuid', session('company'))
            ->with(['customer', 'items', 'order'])
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id)->orWhere('number', $id);
            })
            ->firstOrFail();

        return new InvoiceResource($invoice);
    }

    /**
     * Create a new invoice.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $request->validate([
            'customer_uuid' => 'required|string',
            'customer_type' => 'required|string',
            'date'          => 'required|date',
            'due_date'      => 'nullable|date',
            'items'         => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity'    => 'required|integer|min:1',
            'items.*.unit_price'  => 'required|integer|min:0',
        ]);

        return DB::transaction(function () use ($request) {
            $invoice = Invoice::create([
                'company_uuid'  => session('company'),
                'customer_uuid' => $request->input('customer_uuid'),
                'customer_type' => $request->input('customer_type'),
                'order_uuid'    => $request->input('order_uuid'),
                'number'        => Invoice::generateNumber(),
                'date'          => $request->input('date'),
                'due_date'      => $request->input('due_date'),
                'currency'      => $request->input('currency', 'USD'),
                'status'        => 'draft',
                'notes'         => $request->input('notes'),
                'terms'         => $request->input('terms'),
            ]);

            // Create invoice items
            foreach ($request->input('items', []) as $itemData) {
                $item = new InvoiceItem([
                    'description' => $itemData['description'],
                    'quantity'    => $itemData['quantity'],
                    'unit_price'  => $itemData['unit_price'],
                    'tax_rate'    => $itemData['tax_rate'] ?? 0,
                ]);
                $item->calculateAmount();
                $invoice->items()->save($item);
            }

            // Calculate totals
            $invoice->calculateTotals();
            $invoice->save();

            return new InvoiceResource($invoice->load(['customer', 'items']));
        });
    }

    /**
     * Update an invoice.
     *
     * @return \Illuminate\Http\Response
     */
    public function update($id, Request $request)
    {
        $invoice = Invoice::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        return DB::transaction(function () use ($invoice, $request) {
            $invoice->update($request->only([
                'customer_uuid',
                'customer_type',
                'date',
                'due_date',
                'notes',
                'terms',
                'status',
            ]));

            // Update items if provided
            if ($request->has('items')) {
                // Delete existing items
                $invoice->items()->delete();

                // Create new items
                foreach ($request->input('items', []) as $itemData) {
                    $item = new InvoiceItem([
                        'description' => $itemData['description'],
                        'quantity'    => $itemData['quantity'],
                        'unit_price'  => $itemData['unit_price'],
                        'tax_rate'    => $itemData['tax_rate'] ?? 0,
                    ]);
                    $item->calculateAmount();
                    $invoice->items()->save($item);
                }

                // Recalculate totals
                $invoice->calculateTotals();
                $invoice->save();
            }

            return new InvoiceResource($invoice->load(['customer', 'items']));
        });
    }

    /**
     * Delete an invoice.
     *
     * @return \Illuminate\Http\Response
     */
    public function delete($id, Request $request)
    {
        $invoice = Invoice::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        if ($invoice->status === 'paid') {
            return response()->json(['error' => 'Cannot delete paid invoice'], 400);
        }

        $invoice->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Create an invoice from an order.
     *
     * @return \Illuminate\Http\Response
     */
    public function createFromOrder(Request $request)
    {
        $request->validate([
            'order_uuid' => 'required|string|exists:orders,uuid',
        ]);

        $order = Order::where('company_uuid', session('company'))
            ->where('uuid', $request->input('order_uuid'))
            ->firstOrFail();

        $invoiceService = app(InvoiceService::class);
        $invoice        = $invoiceService->createFromOrder($order);

        return new InvoiceResource($invoice->load(['customer', 'items']));
    }

    /**
     * Record a payment for an invoice.
     *
     * @return \Illuminate\Http\Response
     */
    public function recordPayment($id, Request $request)
    {
        $request->validate([
            'amount' => 'required|integer|min:1',
        ]);

        $invoice = Invoice::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $invoiceService = app(InvoiceService::class);
        $invoice        = $invoiceService->recordPayment($invoice, $request->input('amount'));

        return new InvoiceResource($invoice->load(['customer', 'items']));
    }

    /**
     * Mark an invoice as sent.
     *
     * @return \Illuminate\Http\Response
     */
    public function markAsSent($id, Request $request)
    {
        $invoice = Invoice::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $invoice->markAsSent();

        return new InvoiceResource($invoice);
    }
}
