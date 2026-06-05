<?php

namespace Fleetbase\Ledger\Notifications;

use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Models\Company;
use Fleetbase\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InvoiceSent extends Notification implements ShouldQueue
{
    use Queueable;

    protected ?Company $company = null;

    public function __construct(protected Invoice $invoice)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $this->invoice->loadMissing(['customer', 'items', 'order.trackingNumber']);

        $number      = $this->invoiceNumber();
        $companyName = $this->companyName();
        $currency    = strtoupper((string) ($this->invoice->currency ?: 'USD'));

        return (new MailMessage())
            ->from(config('mail.from.address'), $companyName)
            ->subject("Invoice {$number} from {$companyName}")
            ->view('ledger::mail.invoice-sent', [
                'companyName'    => $companyName,
                'companyLogoUrl' => $this->companyLogoUrl(),
                'customerName'   => $this->customerName(),
                'customerEmail'  => $this->customerEmail(),
                'invoiceNumber'  => $number,
                'invoiceDate'    => $this->formatDate($this->invoice->date),
                'dueDate'        => $this->formatDate($this->invoice->due_date),
                'orderLabel'     => $this->orderLabel(),
                'items'          => $this->lineItems(),
                'subtotal'       => $this->formatMoney($this->invoice->subtotal, $currency),
                'tax'            => $this->formatMoney($this->invoice->tax, $currency),
                'total'          => $this->formatMoney($this->invoice->total_amount, $currency),
                'amountPaid'     => $this->formatMoney($this->invoice->amount_paid, $currency),
                'balance'        => $this->formatMoney($this->invoice->balance, $currency),
                'hasAmountPaid'  => (int) $this->invoice->amount_paid > 0,
                'invoiceUrl'     => $this->invoiceUrl(),
            ]);
    }

    protected function invoiceNumber(): string
    {
        return $this->invoice->number ?: $this->invoice->public_id;
    }

    protected function companyName(): string
    {
        $company = $this->company();

        return $company?->name ?: 'Your service provider';
    }

    protected function companyLogoUrl(): ?string
    {
        return $this->company()?->logo_url;
    }

    protected function orderLabel(): ?string
    {
        $order = $this->invoice->order;
        if (!$order) {
            return null;
        }

        return $order->tracking_number
            ?? $order->trackingNumber?->tracking_number
            ?? $order->public_id
            ?? $order->uuid;
    }

    protected function lineItems(): array
    {
        if (!$this->invoice->items || $this->invoice->items->isEmpty()) {
            return [];
        }

        return $this->invoice->items
            ->map(function ($item) {
                $currency  = strtoupper((string) ($this->invoice->currency ?: 'USD'));
                $quantity  = (float) ($item->quantity ?: 1);
                $unitPrice = $item->unit_price ?? ($quantity > 0 ? ((int) $item->amount / $quantity) : $item->amount);

                return [
                    'description' => Str::limit((string) ($item->description ?: 'Invoice item'), 140),
                    'quantity'    => $this->formatQuantity($quantity),
                    'unitPrice'   => $this->formatMoney($unitPrice, $currency),
                    'amount'      => $this->formatMoney($item->amount, $currency),
                ];
            })
            ->values()
            ->all();
    }

    protected function formatMoney($amount, string $currency): string
    {
        return $currency . ' ' . number_format(((int) $amount) / 100, 2);
    }

    protected function invoiceUrl(): string
    {
        return Utils::consoleUrl('~/invoice', [
            'id' => $this->invoice->public_id,
        ]);
    }

    protected function customerName(): ?string
    {
        $customer = $this->invoice->customer;

        return $customer?->name
            ?? $customer?->display_name
            ?? $customer?->email
            ?? null;
    }

    protected function customerEmail(): ?string
    {
        $customer = $this->invoice->customer;

        return $customer?->email
            ?? $customer?->contact_email
            ?? null;
    }

    protected function company(): ?Company
    {
        if ($this->company === null) {
            $this->company = Company::where('uuid', $this->invoice->company_uuid)->first();
        }

        return $this->company;
    }

    protected function formatDate($date): ?string
    {
        if (!$date) {
            return null;
        }

        return Carbon::parse($date)->format('M j, Y');
    }

    protected function formatQuantity(float $quantity): string
    {
        return fmod($quantity, 1.0) === 0.0 ? (string) (int) $quantity : rtrim(rtrim(number_format($quantity, 2), '0'), '.');
    }
}
