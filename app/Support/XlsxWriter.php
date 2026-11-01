<?php

namespace App\Support;

/**
 * Минимальный генератор настоящего .xlsx (Office Open XML) без внешних пакетов.
 *
 * Раньше экспорт отдавал HTML-таблицу с расширением .xls: Excel 2013
 * предупреждал «формат не соответствует расширению», а телефоны такой файл
 * не открывали вовсе. Один лист, жирная шапка, числа — числами.
 */
class XlsxWriter
{
    /** @return string бинарное содержимое .xlsx */
    public static function build(array $headings, array $rows, string $sheetName = 'Лист1'): string
    {
        $allRows = array_merge([$headings], $rows);

        $sheetRows = '';
        foreach ($allRows as $r => $row) {
            $rowNum = $r + 1;
            $cells  = '';
            foreach (array_values($row) as $c => $value) {
                $ref   = self::column($c) . $rowNum;
                $style = $r === 0 ? ' s="1"' : '';
                if ($value === null || $value === '') {
                    continue;
                }
                if ($r > 0 && is_numeric($value) && ! self::looksLikeCode($value)) {
                    $cells .= '<c r="' . $ref . '"' . $style . '><v>' . (0 + $value) . '</v></c>';
                } else {
                    $cells .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                        . self::xml((string) $value) . '</t></is></c>';
                }
            }
            $sheetRows .= '<row r="' . $rowNum . '">' . $cells . '</row>';
        }

        $cols = '';
        foreach (self::columnWidths($allRows) as $i => $width) {
            $n     = $i + 1;
            $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . $width . '" customWidth="1"/>';
        }

        $lastCol = self::column(max(count($headings), 1) - 1);
        $sheet   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . ($cols ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $sheetRows . '</sheetData>'
            . '<autoFilter ref="A1:' . $lastCol . max(count($allRows), 1) . '"/>'
            . '</worksheet>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . self::xml(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets>'
                . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . self::xml(str_replace("'", "''", mb_substr($sheetName, 0, 31))) . '\'!$A$1:$' . $lastCol . '$' . max(count($allRows), 1) . '</definedName></definedNames>'
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            // Стиль 0 — обычный, 1 — жирная шапка с серой заливкой и рамкой
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill></fills>'
                . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
                . '<border><left/><right/><top/><bottom style="thin"><color rgb="FF999999"/></bottom><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/></cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                . '</styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $binary = file_get_contents($tmp);
        @unlink($tmp);

        return $binary;
    }

    // 0 → A, 25 → Z, 26 → AA
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }
        return $name;
    }

    // Артикулы/телефоны вида «00123» или длиннее 15 цифр оставляем текстом,
    // иначе Excel съест ведущие нули или округлит
    private static function looksLikeCode($value): bool
    {
        $s = (string) $value;
        return (strlen($s) > 1 && $s[0] === '0' && $s[1] !== '.') || strlen(preg_replace('/\D/', '', $s)) > 15;
    }

    private static function columnWidths(array $rows): array
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $value) {
                $len         = mb_strlen((string) $value);
                $widths[$i]  = max($widths[$i] ?? 8, min($len + 2, 60));
            }
        }
        return $widths;
    }

    private static function xml(string $s): string
    {
        // Управляющие символы недопустимы в XML — Excel счёл бы файл повреждённым
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
