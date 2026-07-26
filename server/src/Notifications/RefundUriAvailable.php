<?php

namespace Fleetbase\Ledger\Notifications;

use Fleetbase\Ledger\Models\GatewayTransaction;
use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Models\Company;
use Fleetbase\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class RefundUriAvailable extends Notification implements ShouldQueue
{
    use Queueable;

    protected ?Company $company = null;

    public function __construct(
        protected Invoice $invoice,
        protected GatewayTransaction $refund,
        protected string $refundUri,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $this->invoice->loadMissing(['customer', 'order.trackingNumber']);
        $companyName = $this->companyName();

        return (new MailMessage())
            ->from(config('mail.from.address'), $companyName)
            ->subject('Refund available for invoice ' . $this->invoiceNumber())
            ->view('ledger::mail.refund-uri-available', [
                'companyName'    => $companyName,
                'companyLogoUrl' => $this->companyLogoUrl(),
                'customerName'   => $this->customerName(),
                'invoiceNumber'  => $this->invoiceNumber(),
                'orderLabel'     => $this->orderLabel(),
                'refundAmount'   => $this->formatMoney($this->refund->amount, strtoupper((string) ($this->refund->currency ?: $this->invoice->currency ?: 'USD'))),
                'refundUrl'      => $this->refundUrl(),
                'refundUri'      => $this->refundUri,
                'invoiceUrl'     => $this->invoiceUrl(),
                'issuedAt'       => $this->formatDate($this->refund->created_at),
            ]);
    }

    protected function invoiceNumber(): string
    {
        return $this->invoice->number ?: $this->invoice->public_id;
    }

    protected function companyName(): string
    {
        return $this->company()?->name ?: 'Your service provider';
    }

    protected function companyLogoUrl(): ?string
    {
        return $this->company()?->logo_url;
    }

    protected function customerName(): ?string
    {
        $customer = $this->invoice->customer;

        return $customer?->name
            ?? $customer?->display_name
            ?? $customer?->email
            ?? null;
    }

    protected function orderLabel(): ?string
    {
        $order = $this->invoice->order;

        return $order?->tracking_number
            ?? $order?->trackingNumber?->tracking_number
            ?? $order?->public_id
            ?? $order?->uuid;
    }

    protected function invoiceUrl(): string
    {
        return Utils::consoleUrl('~/invoice', [
            'id' => $this->invoice->public_id,
        ]);
    }

    protected function refundUrl(): string
    {
        return Utils::consoleUrl('~/taler-refund', [
            'id' => $this->refund->public_id ?? $this->refund->uuid,
        ]);
    }

    protected function formatMoney($amount, string $currency): string
    {
        return $currency . ' ' . number_format(((int) $amount) / 100, 2);
    }

    protected function formatDate($date): ?string
    {
        if (!$date) {
            return null;
        }

        return Carbon::parse($date)->format('M j, Y H:i');
    }

    protected function company(): ?Company
    {
        if ($this->company === null) {
            $this->company = Company::where('uuid', $this->invoice->company_uuid)->first();
        }

        return $this->company;
    }
}
