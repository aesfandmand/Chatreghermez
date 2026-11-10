<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Sms;

/** Iranian mobile numbers -> canonical "09xxxxxxxxx" (accepts Persian/Arabic digits, +98, 0098, 98, spaces, dashes). */
final class PhoneNormalizer
{
    public static function normalize(string $raw): ?string
    {
        $s = strtr($raw, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $s = preg_replace('/[\s\-\(\)\.]/u', '', $s) ?? '';
        $s = preg_replace('/^(\+98|0098|98)/', '0', $s) ?? '';
        if (preg_match('/^9\d{9}$/', $s)) {
            $s = '0' . $s;
        }
        return preg_match('/^09\d{9}$/', $s) ? $s : null;
    }
}
