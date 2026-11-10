<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Billing;

/** Cheque data rules: 16-digit Sayad ID, valid Iranian national code, due-date cap on services invoices (default 45 days). */
final class ChequePolicy
{
    public const SERVICES_MAX_DAYS = 45;

    public static function validSayad(string $id): bool
    {
        return (bool) preg_match('/^\d{16}$/', $id);
    }

    public static function validNationalCode(string $code): bool
    {
        if (!preg_match('/^\d{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }
        $r = $sum % 11;
        $check = (int) $code[9];
        return $r < 2 ? $check === $r : $check === 11 - $r;
    }

    /** @return list<string> error codes (empty = ok) */
    public static function validate(array $c, string $invoiceKind, int $now, int $maxServicesDays = self::SERVICES_MAX_DAYS): array
    {
        $e = [];
        if (!self::validSayad((string) ($c['sayad_id'] ?? ''))) {
            $e[] = 'sayad_invalid';
        }
        if (!self::validNationalCode((string) ($c['payee_national_code'] ?? ''))) {
            $e[] = 'national_code_invalid';
        }
        if ((int) ($c['amount_rials'] ?? 0) <= 0) {
            $e[] = 'amount_invalid';
        }
        $due = (int) ($c['due_at'] ?? 0);
        if ($due <= 0) {
            $e[] = 'due_invalid';
        } elseif ($invoiceKind === 'services' && $due > $now + $maxServicesDays * 86400) {
            $e[] = 'due_too_far';
        }
        return $e;
    }
}
