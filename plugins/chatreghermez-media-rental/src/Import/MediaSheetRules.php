<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Import;

/**
 * Rules for the media availability Excel sheets (sample: Mobarakeh). Pure and conservative: anything unclear is flagged
 * for the admin preview instead of guessed. Verify column mapping against the real sheet in the staging checklist.
 */
final class MediaSheetRules
{
    /** @return array{status:string,brand:string} status: free|blocked|reserved */
    public static function cell($value): array
    {
        $s = self::clean((string) $value);
        if ($s === '') {
            return ['status' => 'free', 'brand' => ''];
        }
        if (in_array($s, ['-', '_', '−', '–', '—', 'ـ'], true)) {
            return ['status' => 'blocked', 'brand' => ''];
        }
        return ['status' => 'reserved', 'brand' => self::brandKey($s)];
    }

    /** Header/cell cleanup: Arabic→Persian letters, ZWNJ/NBSP/trailing spaces, collapsed whitespace. */
    public static function clean(string $s): string
    {
        $s = strtr($s, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', "\u{00A0}" => ' ', "\u{200F}" => '', "\u{200E}" => '']);
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';
        return trim($s);
    }

    /** Brand dedupe key: «پرسفید» and «پر سفید» collapse to the same key (spaces and ZWNJ ignored). */
    public static function dedupeKey(string $brand): string
    {
        return mb_strtolower(preg_replace('/[\s\x{200C}]+/u', '', self::clean($brand)) ?? '');
    }

    private static function brandKey(string $s): string
    {
        return $s;
    }

    /** System code: exactly 6 digits (Persian digits accepted). */
    public static function systemCode($value): ?string
    {
        $s = strtr(self::clean((string) $value), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
        return preg_match('/^\d{6}$/', $s) ? $s : null;
    }

    /**
     * Location text carries the media type and size, e.g. "بیلبورد 6×3 ..." / "تابلو 3*2". Returns best guess for the preview.
     * @return array{type:string,width_m:?float,height_m:?float,orientation:string,lit:bool,confident:bool}
     */
    public static function parseLocation(string $text): array
    {
        $t = strtr(self::clean($text), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.']);
        $w = $h = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:[x×*])\s*(\d+(?:\.\d+)?)/iu', $t, $m)) {
            $w = (float) $m[1];
            $h = (float) $m[2];
        }
        if ($w !== null && $h !== null && $w > 30 && $h > 30) { // written in centimetres (e.g. 180*120)
            $w /= 100;
            $h /= 100;
        }
        $type = '';
        foreach (['لایت باکس', 'لایت', 'بیلبورد', 'استرابورد', 'پرتابل', 'پل عابر', 'پل', 'استند', 'المان', 'تابلو', 'بنر', 'دیجیتال', 'ال ای دی'] as $kw) {
            if (mb_strpos($t, $kw) !== false) {
                $type = $kw;
                break;
            }
        }
        $orientation = mb_strpos($t, 'افقی') !== false ? 'landscape' : (mb_strpos($t, 'عمودی') !== false ? 'portrait' : '');
        return ['type' => $type, 'width_m' => $w, 'height_m' => $h, 'orientation' => $orientation,
            'lit' => mb_strpos($type, 'لایت') !== false, 'confident' => $type !== '' && $w !== null];
    }
}
