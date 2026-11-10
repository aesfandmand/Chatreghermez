<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Payments;

/**
 * Zibal adapter. ENDPOINTS AND FIELDS WRITTEN FROM MEMORY — MUST BE CHECKED AGAINST CURRENT ZIBAL DOCS BEFORE USE.
 * Merchant id comes from secure settings (never the repo); "zibal" is Zibal's public sandbox merchant.
 */
final class ZibalGateway implements GatewayAdapter
{
    private const BASE = 'https://gateway.zibal.ir';

    public function __construct(private string $merchant) {}

    public function id(): string { return 'zibal'; }

    public function initiate(int $amountRials, string $orderNo, string $callbackUrl): array
    {
        $r = $this->post('/v1/request', ['merchant' => $this->merchant, 'amount' => $amountRials, 'callbackUrl' => $callbackUrl, 'orderId' => $orderNo]);
        if ((int) ($r['result'] ?? 0) !== 100 || empty($r['trackId'])) {
            throw new \RuntimeException('gateway_initiate_failed');
        }
        $ref = (string) $r['trackId'];
        return ['ref' => $ref, 'redirect_url' => self::BASE . '/start/' . $ref];
    }

    public function verify(string $ref): array
    {
        $r = $this->post('/v1/verify', ['merchant' => $this->merchant, 'trackId' => (int) $ref]);
        $ok = in_array((int) ($r['result'] ?? 0), [100, 201], true) && (int) ($r['status'] ?? 0) === 1; // 201 = already verified
        return ['paid' => $ok, 'amount_rials' => (int) ($r['amount'] ?? 0), 'ref' => $ref, 'receipt' => (string) ($r['refNumber'] ?? '')];
    }

    /** @return array<string,mixed> */
    private function post(string $path, array $body): array
    {
        $res = wp_remote_post(self::BASE . $path, ['timeout' => 15, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($body)]);
        if (is_wp_error($res)) {
            throw new \RuntimeException('gateway_unreachable');
        }
        return (array) json_decode((string) wp_remote_retrieve_body($res), true);
    }
}
