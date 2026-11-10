<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Import;

/** Minimal .xlsx reader (first sheet, text values) using ZipArchive + SimpleXML — no Composer needed on the host. */
final class XlsxReader
{
    /** @return list<list<string>> rows of strings ('' for empty cells), columns aligned by letter */
    public static function read(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('xlsx_unreadable');
        }
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $x = simplexml_load_string($ss, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOENT & 0);
            foreach ($x->si ?? [] as $si) {
                $t = '';
                if (isset($si->t)) {
                    $t = (string) $si->t;
                } else {
                    foreach ($si->r ?? [] as $r) {
                        $t .= (string) $r->t;
                    }
                }
                $shared[] = $t;
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) {
            throw new \RuntimeException('xlsx_no_sheet');
        }
        $x = simplexml_load_string($sheetXml, \SimpleXMLElement::class, LIBXML_NONET);
        $rows = [];
        foreach ($x->sheetData->row ?? [] as $row) {
            $line = [];
            foreach ($row->c as $c) {
                $col = self::colIndex((string) $c['r']);
                $type = (string) $c['t'];
                $v = $type === 'inlineStr' ? (string) $c->is->t : (string) $c->v;
                if ($type === 's') {
                    $v = $shared[(int) $v] ?? '';
                }
                $line[$col] = $v;
            }
            $max = $line ? max(array_keys($line)) : -1;
            $out = [];
            for ($i = 0; $i <= $max; $i++) {
                $out[] = $line[$i] ?? '';
            }
            $rows[(int) $row['r']] = $out;
        }
        ksort($rows);
        return $rows; // keyed by the sheet's real row number
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/', $ref, $m);
        $n = 0;
        foreach (str_split($m[0] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}
