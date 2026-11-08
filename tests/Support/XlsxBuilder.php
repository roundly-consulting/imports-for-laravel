<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Support;

use ZipArchive;

/**
 * Hand-built XLSX workbooks for the shapes the writer never produces: Excel-style shared strings
 * (rich text, phonetic runs, `_xHHHH_` escapes), styles with custom date formats, strict OOXML,
 * absolute and case-different part names, missing cell references, formulas, error and `d` cells,
 * hidden and chart sheets, a DOCTYPE. Every part is plain XML in the test that uses it.
 */
final class XlsxBuilder
{
    public const string MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public const string REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public const string STRICT_MAIN = 'http://purl.oclc.org/ooxml/spreadsheetml/main';

    public const string STRICT_REL = 'http://purl.oclc.org/ooxml/officeDocument/relationships';

    /**
     * @param  array<string, string|array{xml: string, state?: string, chart?: bool}>  $sheets  name => sheetData inner XML
     * @param  list<string>|string|null  $sharedStrings  plain strings, or raw `<si>` XML
     * @param  array<string, string>  $extraParts  part name => content
     */
    public static function make(
        array $sheets,
        array|string|null $sharedStrings = null,
        ?string $styles = null,
        bool $date1904 = false,
        bool $strict = false,
        bool $absoluteTargets = false,
        bool $upperCaseParts = false,
        string $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        array $extraParts = [],
        ?string $doctype = null,
    ): string {
        $main = $strict ? self::STRICT_MAIN : self::MAIN;
        $rel = $strict ? self::STRICT_REL : self::REL;
        $relType = static fn (string $type): string => ($strict ? 'http://purl.oclc.org/ooxml/officeDocument/relationships/' : 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/').$type;
        $sheetPath = static fn (int $i): string => $upperCaseParts ? 'xl/Worksheets/Sheet'.$i.'.XML' : 'xl/worksheets/sheet'.$i.'.xml';

        $parts = [];
        $overrides = '';
        $sheetList = '';
        $workbookRels = '';
        $index = 0;

        foreach ($sheets as $name => $sheet) {
            $index++;
            $spec = is_string($sheet) ? ['xml' => $sheet] : $sheet;
            $chart = $spec['chart'] ?? false;
            $path = $chart ? 'xl/chartsheets/sheet'.$index.'.xml' : $sheetPath($index);
            $target = $absoluteTargets ? '/'.$path : substr($path, 3);
            $parts[$path] = $chart
                ? '<?xml version="1.0" encoding="UTF-8"?><chartsheet xmlns="'.$main.'"/>'
                : '<?xml version="1.0" encoding="UTF-8"?>'.($doctype ?? '').'<worksheet xmlns="'.$main.'" xmlns:r="'.$rel.'"><sheetData>'.$spec['xml'].'</sheetData></worksheet>';
            $overrides .= '<Override PartName="/'.$path.'" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.'.($chart ? 'chartsheet' : 'worksheet').'+xml"/>';
            $sheetList .= '<sheet name="'.htmlspecialchars($name).'" sheetId="'.$index.'"'.(isset($spec['state']) ? ' state="'.$spec['state'].'"' : '').' r:id="rId'.$index.'"/>';
            $workbookRels .= '<Relationship Id="rId'.$index.'" Type="'.$relType($chart ? 'chartsheet' : 'worksheet').'" Target="'.$target.'"/>';
        }

        if ($sharedStrings !== null) {
            $items = is_string($sharedStrings) ? $sharedStrings : implode('', array_map(static fn (string $s): string => '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1).'</t></si>', $sharedStrings));
            $parts['xl/sharedStrings.xml'] = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="'.$main.'">'.$items.'</sst>';
            $workbookRels .= '<Relationship Id="rIdS" Type="'.$relType('sharedStrings').'" Target="'.($absoluteTargets ? '/xl/sharedStrings.xml' : 'sharedStrings.xml').'"/>';
        }

        if ($styles !== null) {
            $parts['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="'.$main.'">'.$styles.'</styleSheet>';
            $workbookRels .= '<Relationship Id="rIdT" Type="'.$relType('styles').'" Target="styles.xml"/>';
        }

        $workbookPath = $upperCaseParts ? 'xl/Workbook.xml' : 'xl/workbook.xml';
        $parts['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="'.$contentType.'"/>'.$overrides.'</Types>';
        $parts['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.$relType('officeDocument').'" Target="'.($absoluteTargets ? '/' : '').'xl/workbook.xml"/></Relationships>';
        $parts[$workbookPath] = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="'.$main.'" xmlns:r="'.$rel.'"><workbookPr'.($date1904 ? ' date1904="1"' : '').'/><sheets>'.$sheetList.'</sheets></workbook>';
        $parts['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$workbookRels.'</Relationships>';

        return self::zip([...$parts, ...$extraParts]);
    }

    /**
     * @param  array<string, string>  $entries
     */
    public static function zip(array $entries, ?callable $configure = null): string
    {
        $path = self::path('xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        if ($configure !== null) {
            $configure($zip);
        }

        $zip->close();

        return $path;
    }

    public static function path(string $extension): string
    {
        $directory = sys_get_temp_dir().'/roundly-imports-fixtures';

        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        return $directory.'/'.bin2hex(random_bytes(8)).'.'.$extension;
    }

    /**
     * A row of inline-string / number cells, A1 references included.
     *
     * @param  list<string|int|float|null>  $values
     */
    public static function row(int $number, array $values): string
    {
        $cells = '';

        foreach ($values as $index => $value) {
            if ($value === null) {
                continue;
            }

            $ref = chr(65 + $index).$number;
            $cells .= is_string($value)
                ? '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>'
                : '<c r="'.$ref.'"><v>'.$value.'</v></c>';
        }

        return '<row r="'.$number.'">'.$cells.'</row>';
    }
}
