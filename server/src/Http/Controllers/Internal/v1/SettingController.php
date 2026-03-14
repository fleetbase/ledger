<?php

namespace Fleetbase\Ledger\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Ledger\Models\Gateway;
use Fleetbase\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SettingController.
 *
 * Handles reading and writing of per-organisation Ledger settings.
 * All settings are stored in the core-api `settings` table using the
 * `Setting::configureCompany()` / `Setting::lookupCompany()` helpers which
 * automatically scope every key to `company.<company_uuid>.<key>`.
 *
 * Setting keys used:
 *   ledger.invoice-settings
 *   ledger.payment-settings
 *   ledger.accounting-settings
 */
class SettingController extends Controller
{
    // =========================================================================
    // Invoice Settings
    // =========================================================================

    /**
     * Return the current invoice settings for the authenticated organisation.
     */
    public function getInvoiceSettings(): JsonResponse
    {
        $defaults = [
            'invoice_prefix'          => 'INV',
            'default_currency'        => 'USD',
            'payment_terms_days'      => 30,
            'due_date_offset_days'    => 30,
            'default_notes'           => '',
            'default_terms'           => '',
            'auto_send_on_creation'   => false,
        ];

        $settings = Setting::lookupCompany('ledger.invoice-settings', $defaults);

        // Merge with defaults so new keys are always present
        if (is_array($settings)) {
            $settings = array_merge($defaults, $settings);
        } else {
            $settings = $defaults;
        }

        return response()->json(['invoiceSettings' => $settings]);
    }

    /**
     * Save invoice settings for the authenticated organisation.
     */
    public function saveInvoiceSettings(Request $request): JsonResponse
    {
        $request->validate([
            'invoiceSettings'                        => 'required|array',
            'invoiceSettings.invoice_prefix'         => 'nullable|string|max:20',
            'invoiceSettings.default_currency'       => 'nullable|string|size:3',
            'invoiceSettings.payment_terms_days'     => 'nullable|integer|min:0|max:365',
            'invoiceSettings.due_date_offset_days'   => 'nullable|integer|min:0|max:365',
            'invoiceSettings.default_notes'          => 'nullable|string|max:2000',
            'invoiceSettings.default_terms'          => 'nullable|string|max:2000',
            'invoiceSettings.auto_send_on_creation'  => 'nullable|boolean',
        ]);

        $invoiceSettings = $request->input('invoiceSettings', []);

        // Merge with existing settings to preserve any keys not sent in this request
        $current = Setting::lookupCompany('ledger.invoice-settings', []);
        if (!is_array($current)) {
            $current = [];
        }
        $merged = array_merge($current, $invoiceSettings);

        Setting::configureCompany('ledger.invoice-settings', $merged);

        return response()->json([
            'status'          => 'ok',
            'message'         => 'Invoice settings saved.',
            'invoiceSettings' => $merged,
        ]);
    }

    // =========================================================================
    // Payment Settings
    // =========================================================================

    /**
     * Return the current payment settings for the authenticated organisation.
     * Includes the full gateway resource for the default gateway so the
     * frontend can display its name without a separate lookup.
     */
    public function getPaymentSettings(): JsonResponse
    {
        $defaults = [
            'default_gateway_uuid'      => null,
            'allow_partial_payments'    => false,
            'auto_apply_wallet_credit'  => false,
            'send_payment_receipt'      => true,
        ];

        $settings = Setting::lookupCompany('ledger.payment-settings', $defaults);

        if (is_array($settings)) {
            $settings = array_merge($defaults, $settings);
        } else {
            $settings = $defaults;
        }

        // Attach the gateway name/public_id for display purposes
        $settings['default_gateway'] = null;
        if (!empty($settings['default_gateway_uuid'])) {
            $gateway = Gateway::where('company_uuid', session('company'))
                ->where(function ($q) use ($settings) {
                    $q->where('uuid', $settings['default_gateway_uuid'])
                      ->orWhere('public_id', $settings['default_gateway_uuid']);
                })
                ->select(['uuid', 'public_id', 'name', 'driver', 'environment', 'status'])
                ->first();

            if ($gateway) {
                $settings['default_gateway'] = [
                    'uuid'        => $gateway->uuid,
                    'public_id'   => $gateway->public_id,
                    'name'        => $gateway->name,
                    'driver'      => $gateway->driver,
                    'environment' => $gateway->environment,
                    'status'      => $gateway->status,
                ];
            }
        }

        return response()->json(['paymentSettings' => $settings]);
    }

    /**
     * Save payment settings for the authenticated organisation.
     */
    public function savePaymentSettings(Request $request): JsonResponse
    {
        $request->validate([
            'paymentSettings'                            => 'required|array',
            'paymentSettings.default_gateway_uuid'       => 'nullable|string',
            'paymentSettings.allow_partial_payments'     => 'nullable|boolean',
            'paymentSettings.auto_apply_wallet_credit'   => 'nullable|boolean',
            'paymentSettings.send_payment_receipt'       => 'nullable|boolean',
        ]);

        $paymentSettings = $request->input('paymentSettings', []);

        // Validate that the gateway UUID belongs to this company (if provided)
        if (!empty($paymentSettings['default_gateway_uuid'])) {
            $gatewayExists = Gateway::where('company_uuid', session('company'))
                ->where(function ($q) use ($paymentSettings) {
                    $q->where('uuid', $paymentSettings['default_gateway_uuid'])
                      ->orWhere('public_id', $paymentSettings['default_gateway_uuid']);
                })
                ->exists();

            if (!$gatewayExists) {
                return response()->json(['error' => 'The specified default gateway was not found.'], 422);
            }

            // Normalise to UUID
            $gateway = Gateway::where('company_uuid', session('company'))
                ->where(function ($q) use ($paymentSettings) {
                    $q->where('uuid', $paymentSettings['default_gateway_uuid'])
                      ->orWhere('public_id', $paymentSettings['default_gateway_uuid']);
                })
                ->first();
            $paymentSettings['default_gateway_uuid'] = $gateway->uuid;
        }

        $current = Setting::lookupCompany('ledger.payment-settings', []);
        if (!is_array($current)) {
            $current = [];
        }
        $merged = array_merge($current, $paymentSettings);

        Setting::configureCompany('ledger.payment-settings', $merged);

        return response()->json([
            'status'          => 'ok',
            'message'         => 'Payment settings saved.',
            'paymentSettings' => $merged,
        ]);
    }

    // =========================================================================
    // Accounting Settings
    // =========================================================================

    /**
     * Return the current accounting settings for the authenticated organisation.
     */
    public function getAccountingSettings(): JsonResponse
    {
        $defaults = [
            'base_currency'              => 'USD',
            'fiscal_year_start_month'    => 1,   // January
            'auto_post_journal_entries'  => false,
            'default_ar_account_uuid'    => null,
            'default_revenue_account_uuid' => null,
            'default_expense_account_uuid' => null,
        ];

        $settings = Setting::lookupCompany('ledger.accounting-settings', $defaults);

        if (is_array($settings)) {
            $settings = array_merge($defaults, $settings);
        } else {
            $settings = $defaults;
        }

        return response()->json(['accountingSettings' => $settings]);
    }

    /**
     * Save accounting settings for the authenticated organisation.
     */
    public function saveAccountingSettings(Request $request): JsonResponse
    {
        $request->validate([
            'accountingSettings'                                => 'required|array',
            'accountingSettings.base_currency'                  => 'nullable|string|size:3',
            'accountingSettings.fiscal_year_start_month'        => 'nullable|integer|min:1|max:12',
            'accountingSettings.auto_post_journal_entries'      => 'nullable|boolean',
            'accountingSettings.default_ar_account_uuid'        => 'nullable|string',
            'accountingSettings.default_revenue_account_uuid'   => 'nullable|string',
            'accountingSettings.default_expense_account_uuid'   => 'nullable|string',
        ]);

        $accountingSettings = $request->input('accountingSettings', []);

        $current = Setting::lookupCompany('ledger.accounting-settings', []);
        if (!is_array($current)) {
            $current = [];
        }
        $merged = array_merge($current, $accountingSettings);

        Setting::configureCompany('ledger.accounting-settings', $merged);

        return response()->json([
            'status'             => 'ok',
            'message'            => 'Accounting settings saved.',
            'accountingSettings' => $merged,
        ]);
    }
}
