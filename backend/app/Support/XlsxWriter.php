<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * "Anything missed... reporting in export in excel or pdf" — real .xlsx
 * export, not just CSV-renamed-to-xlsx. Deliberately NOT built on
 * PhpSpreadsheet/maatwebsite-excel: this server's outbound network policy
 * blocks Packagist (repo.packagist.org answers every Composer request
 * with a 403), so pulling in a new Composer package isn't something that
 * can be built and verified here. The .xlsx format itself is just a zip
 * of a handful of small XML parts (OOXML SpreadsheetML) — small enough to
 * hand-write the exact subset this app needs (one or more named sheets,
 * a bold header row, string/number cells) using nothing beyond PHP's own
 * ext-zip, which is already effectively required (barryvdh/laravel-
 * dompdf and most Laravel hosts enable it) rather than add a dependency
 * at all.
 *
 * Every call site builds rows the same way streamBookingsCsv()/
 * streamPaymentsCsv() already do for CSV — plain PHP arrays — the only
 * difference being an int/float value becomes a real numeric Excel cell
 * (so SUM() works on it) instead of a text column. The first row of each
 * sheet is always rendered bold, on the assumption it's a header row —
 * exactly how every call site here uses it.
 */
class XlsxWriter
{
    /**
     * @param  array<int, array{name: string, rows: iterable<int, array<int, mixed>>}>  $sheets
     */
    public static function download(string $filename, array $sheets): BinaryFileResponse
    {
        $path = self::build($sheets);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Builds the .xlsx file to a temp path and returns it — split out from
     * download() so a standalone script (or a test) can build and inspect
     * a file without needing the full Laravel container.
     *
     * @param  array<int, array{name: string, rows: iterable<int, array<int, mixed>>}>  $sheets
     */
    public static function build(array $sheets): string
    {
        $sheets = array_values($sheets);
        $path = tempnam(sys_get_temp_dir(), 'xlsx_');

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml(count($sheets)));
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml(count($sheets)));
        $zip->addFromString('xl/styles.xml', self::stylesXml());

        foreach ($sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', self::sheetXml($sheet['rows']));
        }

        $zip->close();

        return $path;
    }

    protected static function contentTypesXml(int $sheetCount): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides
            .'</Types>';
    }

    protected static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /** @param array<int, array{name: string, rows: iterable<int, array<int, mixed>>}> $sheets */
    protected static function workbookXml(array $sheets): string
    {
        $sheetTags = '';

        foreach ($sheets as $i => $sheet) {
            $sheetId = $i + 1;
            $name = self::escapeXml(substr($sheet['name'], 0, 31));
            $sheetTags .= '<sheet name="'.$name.'" sheetId="'.$sheetId.'" r:id="rId'.$sheetId.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetTags.'</sheets>'
            .'</workbook>';
    }

    protected static function workbookRelsXml(int $sheetCount): string
    {
        $rels = '';

        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        // styles.xml gets the relationship id immediately after every sheet.
        $stylesId = $sheetCount + 1;
        $rels .= '<Relationship Id="rId'.$stylesId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    /** Two cell formats only: 0 = plain, 1 = bold (the header row every sheet starts with). */
    protected static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="10"/><name val="Calibri"/></font><font><sz val="10"/><name val="Calibri"/><b/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /** @param iterable<int, array<int, mixed>> $rows */
    protected static function sheetXml(iterable $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $rowIndex = 0;

        foreach ($rows as $row) {
            $r = $rowIndex + 1;
            $styleIdx = $rowIndex === 0 ? 1 : 0;
            $xml .= '<row r="'.$r.'">';

            foreach (array_values($row) as $colIndex => $value) {
                $ref = self::columnLetter($colIndex).$r;

                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$ref.'" s="'.$styleIdx.'"><v>'.self::escapeXml((string) $value).'</v></c>';
                } elseif ($value === null || $value === '') {
                    $xml .= '<c r="'.$ref.'" s="'.$styleIdx.'"/>';
                } else {
                    $xml .= '<c r="'.$ref.'" s="'.$styleIdx.'" t="inlineStr"><is><t xml:space="preserve">'.self::escapeXml((string) $value).'</t></is></c>';
                }
            }

            $xml .= '</row>';
            $rowIndex++;
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    /** 0 -> A, 25 -> Z, 26 -> AA, ... (bijective base-26, 0-indexed column). */
    protected static function columnLetter(int $index): string
    {
        $index++;
        $letter = '';

        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $letter = chr(65 + $rem).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    protected static function escapeXml(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        // Strip control characters XML 1.0 has no valid encoding for at all
        // (anything below 0x20 except tab/newline) — a stray remark/note
        // field could otherwise contain one and produce a corrupt file.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
