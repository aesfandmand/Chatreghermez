<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Payments;

/** In-memory gateway for tests and staging dry runs. */
final class FakeGateway implements GatewayAdapter
{
    /** @var array<string,int> ref => amount */
    private array $initiated = [];
    /** @var array<string,bool> */
    private array $paid = [];

    public function id(): string { return 'fake'; }

    public function initiate(int $amountRials, string $orderNo, string $callbackUrl): array
    {
        $ref = 'F' . (count($this->initiated) + 1);
        $this->initiated[$ref] = $amountRials;
        return ['ref' => $ref, 'redirect_url' => $callbackUrl . '?ref=' . $ref];
    }

    public function markPaid(string $ref): void { $this->paid[$ref] = true; }

    public function verify(string $ref): array
    {
        $ok = isset($this->initiated[$ref]) && !empty($this->paid[$ref]);
        return ['paid' => $ok, 'amount_rials' => $this->initiated[$ref] ?? 0, 'ref' => $ref, 'receipt' => $ok ? 'R-' . $ref : ''];
    }
}
