<?php

use Fleetbase\Ledger\Console\Commands\VerifyTalerRefunds;
use Fleetbase\Ledger\Services\TalerRefundVerificationService;
use Illuminate\Container\Container;
use Symfony\Component\Console\Tester\CommandTester;

class VerifyTalerRefundsServiceSpy extends TalerRefundVerificationService
{
    public array $calls   = [];
    public array $summary = [];

    public function __construct()
    {
    }

    public function verifyPending(array $filters = []): array
    {
        $this->calls[] = $filters;

        return $this->summary;
    }
}

function verifyTalerRefundsTester(VerifyTalerRefundsServiceSpy $verifier): CommandTester
{
    $container = Container::getInstance();
    $container->instance(TalerRefundVerificationService::class, $verifier);
    $command = new VerifyTalerRefunds();
    $command->setLaravel($container);

    return new CommandTester($command);
}

test('refund verification command forwards filters and reports successful result details', function () {
    $verifier          = new VerifyTalerRefundsServiceSpy();
    $verifier->summary = [
        'checked'  => 2,
        'accepted' => 1,
        'pending'  => 1,
        'errors'   => 0,
        'results'  => [
            ['id' => 'refund-1', 'status' => 'accepted', 'message' => 'Wallet obtained refund'],
            ['status' => 'pending'],
        ],
    ];
    $tester = verifyTalerRefundsTester($verifier);

    expect($tester->execute([
        '--company' => 'company-1',
        '--gateway' => 'gateway-1',
        '--refund'  => 'refund-1',
        '--limit'   => 25,
    ]))->toBe(0)
        ->and($verifier->calls)->toBe([[
            'company' => 'company-1',
            'gateway' => 'gateway-1',
            'refund'  => 'refund-1',
            'limit'   => 25,
        ]])
        ->and($tester->getDisplay())
        ->toContain('Checked 2; accepted 1; pending 1; errors 0.')
        ->toContain('- refund-1: accepted - Wallet obtained refund')
        ->toContain('- refund: pending');
});

test('refund verification command returns failure when any provider check errors', function () {
    $verifier          = new VerifyTalerRefundsServiceSpy();
    $verifier->summary = [
        'checked'  => 1,
        'accepted' => 0,
        'pending'  => 0,
        'errors'   => 1,
        'results'  => [
            ['id' => 'refund-error', 'status' => 'error', 'message' => 'Backend unavailable'],
        ],
    ];

    expect(verifyTalerRefundsTester($verifier)->execute([]))->toBe(1)
        ->and($verifier->calls[0])->toBe([
            'company' => null,
            'gateway' => null,
            'refund'  => null,
            'limit'   => 100,
        ]);
});
