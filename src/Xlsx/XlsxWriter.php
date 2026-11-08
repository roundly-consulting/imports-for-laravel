<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

use Carbon\CarbonInterface;
use DateTimeInterface;
use RuntimeException;
use XMLWriter;
use ZipArchive;

/**
 * A minimal, valid XLSX writer on ext-xmlwriter + ext-zip — for templates and error reports only
 * (no formulas, merges or protection). Strings are inline by default, so the output carries no
 * formula and needs no shared-strings table; sheet XML streams to a temp file row by row.
 *
 * @internal
 */
final class XlsxWriter
{
    private const int STYLE_BOLD = 1;

    private const int STYLE_TEXT = 2;

    private const int STYLE_DATE = 3;

    private const int STYLE_DATETIME = 4;

    /** @var list<array{name: string, file: string, hidden: bool}> */
    private array $sheets = [];

    /** @var array<string, string> defined name => range */
    private array $definedNames = [];

    /** @var array<int, int> decimal scale => style index */
    private array $decimalStyles = [];

    /** @var array<string, int> */
    private array $strings = [];

    public function __construct(
        private readonly string $path,
        private readonly string $workDirectory,
        private readonly bool $sharedStrings = false,
        private readonly bool $date1904 = false,
    ) {}

    public function defineName(string $name, string $range): void
    {
        $this->definedNames[$name] = $range;
    }

    /**
     * @param  iterable<list<string|int|float|bool|DateTimeInterface|null>>  $rows
     */
    public function addSheet(string $name, iterable $rows, SheetLayout $layout = new SheetLayout): void
    {
        $name = $this->uniqueName($name);
        $file = $this->workDirectory.DIRECTORY_SEPARATOR.'sheet'.(count($this->sheets) + 1).'.xml';
        $xml = new XMLWriter;

        if (! $xml->openUri($file)) {
            throw new RuntimeException('Cannot write the worksheet.');
        }

        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('worksheet');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->writeAttribute('xmlns:r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        if ($layout->freezeHeader) {
            $xml->startElement('sheetViews');
            $xml->startElement('sheetView');
            $xml->writeAttribute('workbookViewId', '0');
            $xml->startElement('pane');
            $xml->writeAttribute('ySplit', '1');
            $xml->writeAttribute('topLeftCell', 'A2');
            $xml->writeAttribute('activePane', 'bottomLeft');
            $xml->writeAttribute('state', 'frozen');
            $xml->endElement();
            $xml->endElement();
            $xml->endElement();
        }

        $columns = array_unique([...array_keys($layout->widths), ...array_keys($layout->formats)]);
        sort($columns);

        if ($columns !== []) {
            $xml->startElement('cols');

            foreach ($columns as $column) {
                $xml->startElement('col');
                $xml->writeAttribute('min', (string) ($column + 1));
                $xml->writeAttribute('max', (string) ($column + 1));
                $xml->writeAttribute('width', (string) round($layout->widths[$column] ?? 12, 2));
                $xml->writeAttribute('customWidth', '1');

                if (isset($layout->formats[$column])) {
                    $xml->writeAttribute('style', (string) $this->formatStyle($layout->formats[$column]));
                }

                $xml->endElement();
            }

            $xml->endElement();
        }

        $xml->startElement('sheetData');
        $number = 0;

        foreach ($rows as $cells) {
            $number++;
            $xml->startElement('row');
            $xml->writeAttribute('r', (string) $number);

            foreach ($cells as $index => $value) {
                $this->cell($xml, CellReference::letters($index).$number, $value, $number === 1 && $layout->boldHeader ? self::STYLE_BOLD : $this->columnStyle($layout, $index, $value));
            }

            $xml->endElement();

            if ($number % 1000 === 0) {
                $xml->flush();
            }
        }

        $xml->endElement();
        $this->validations($xml, $layout);
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();

        $this->sheets[] = ['name' => $name, 'file' => $file, 'hidden' => $layout->hidden];
    }

    public function close(): void
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write the workbook.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Roundly Imports</Application></Properties>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>Roundly Imports</dc:creator></cp:coreProperties>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());

        if ($this->sharedStrings) {
            $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml());
        }

        foreach ($this->sheets as $position => $sheet) {
            $zip->addFile($sheet['file'], 'xl/worksheets/sheet'.($position + 1).'.xml');
        }

        if (! $zip->close()) {
            throw new RuntimeException('Cannot write the workbook.');
        }
    }

    public static function serial(DateTimeInterface $date, bool $date1904 = false): float
    {
        $epoch = $date1904 ? gmmktime(0, 0, 0, 1, 1, 1904) : gmmktime(0, 0, 0, 12, 30, 1899);
        $wall = gmmktime((int) $date->format('H'), (int) $date->format('i'), (int) $date->format('s'), (int) $date->format('n'), (int) $date->format('j'), (int) $date->format('Y'));

        return round(($wall - $epoch) / 86400, 8);
    }

    private function cell(XMLWriter $xml, string $reference, string|int|float|bool|DateTimeInterface|null $value, int $style): void
    {
        if ($value === null || $value === '') {
            if ($style > 0) {
                $xml->startElement('c');
                $xml->writeAttribute('r', $reference);
                $xml->writeAttribute('s', (string) $style);
                $xml->endElement();
            }

            return;
        }

        $xml->startElement('c');
        $xml->writeAttribute('r', $reference);

        if ($style > 0) {
            $xml->writeAttribute('s', (string) $style);
        }

        if (is_string($value)) {
            $value = self::clean($value);

            if ($this->sharedStrings) {
                $xml->writeAttribute('t', 's');
                $xml->writeElement('v', (string) ($this->strings[$value] ??= count($this->strings)));
            } else {
                $xml->writeAttribute('t', 'inlineStr');
                $xml->startElement('is');
                $xml->startElement('t');
                $xml->writeAttribute('xml:space', 'preserve');
                $xml->text($value);
                $xml->endElement();
                $xml->endElement();
            }
        } elseif (is_bool($value)) {
            $xml->writeAttribute('t', 'b');
            $xml->writeElement('v', $value ? '1' : '0');
        } elseif ($value instanceof DateTimeInterface) {
            $xml->writeElement('v', (string) self::serial($value, $this->date1904));
        } else {
            $xml->writeElement('v', is_float($value) ? rtrim(rtrim(sprintf('%.15F', $value), '0'), '.') : (string) $value);
        }

        $xml->endElement();
    }

    private function columnStyle(SheetLayout $layout, int $column, mixed $value): int
    {
        if ($value instanceof DateTimeInterface) {
            $format = $layout->formats[$column] ?? (($value instanceof CarbonInterface ? $value->format('His') : $value->format('His')) === '000000' ? 'date' : 'datetime');

            return $this->formatStyle($format === 'text' ? 'date' : $format);
        }

        return isset($layout->formats[$column]) ? $this->formatStyle($layout->formats[$column]) : 0;
    }

    private function formatStyle(string $format): int
    {
        if (str_starts_with($format, 'decimal:')) {
            $scale = max(0, min(10, (int) substr($format, 8)));

            return $this->decimalStyles[$scale] ??= 5 + count($this->decimalStyles);
        }

        return match ($format) {
            'text' => self::STYLE_TEXT,
            'date' => self::STYLE_DATE,
            'datetime' => self::STYLE_DATETIME,
            default => 0,
        };
    }

    private function validations(XMLWriter $xml, SheetLayout $layout): void
    {
        $columns = array_unique([...array_keys($layout->lists), ...array_keys($layout->prompts)]);

        if ($columns === [] || $layout->validationRows < 1) {
            return;
        }

        sort($columns);
        $xml->startElement('dataValidations');
        $xml->writeAttribute('count', (string) count($columns));

        foreach ($columns as $column) {
            $letters = CellReference::letters($column);
            $xml->startElement('dataValidation');

            if (isset($layout->lists[$column])) {
                $xml->writeAttribute('type', 'list');
                $xml->writeAttribute('showErrorMessage', '1');
            }

            $xml->writeAttribute('allowBlank', '1');

            if (isset($layout->prompts[$column])) {
                $xml->writeAttribute('showInputMessage', '1');
                $xml->writeAttribute('promptTitle', mb_substr(self::clean($layout->prompts[$column]->title), 0, 32));
                $xml->writeAttribute('prompt', mb_substr(self::clean($layout->prompts[$column]->prompt), 0, 255));
            }

            $xml->writeAttribute('sqref', $letters.'2:'.$letters.($layout->validationRows + 1));

            if (isset($layout->lists[$column])) {
                $xml->writeElement('formula1', $layout->lists[$column]);
            }

            $xml->endElement();
        }

        $xml->endElement();
    }

    private function uniqueName(string $name): string
    {
        $name = trim((string) preg_replace('/[\[\]:*?\/\\\\]/', '_', self::clean($name)), "' ");
        $name = mb_substr($name === '' ? 'Sheet' : $name, 0, 31);
        $taken = array_map(static fn (array $sheet): string => mb_strtolower($sheet['name']), $this->sheets);
        $candidate = $name;

        for ($i = 2; in_array(mb_strtolower($candidate), $taken, true); $i++) {
            $candidate = mb_substr($name, 0, 31 - strlen((string) $i) - 1).'_'.$i;
        }

        return $candidate;
    }

    /**
     * Strip the control characters XML 1.0 cannot carry.
     */
    private static function clean(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');

        if (strlen($value) > 32767 * 4 || mb_strlen($value) > 32767) {
            throw new RuntimeException('A cell holds more than 32,767 characters.');
        }

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    }

    private function contentTypes(): string
    {
        $sheets = '';

        foreach (array_keys($this->sheets) as $position) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($position + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .($this->sharedStrings ? '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' : '')
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .$sheets.'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('workbook');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->writeAttribute('xmlns:r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $xml->startElement('workbookPr');

        if ($this->date1904) {
            $xml->writeAttribute('date1904', '1');
        }

        $xml->endElement();
        $xml->startElement('sheets');

        foreach ($this->sheets as $position => $sheet) {
            $xml->startElement('sheet');
            $xml->writeAttribute('name', $sheet['name']);
            $xml->writeAttribute('sheetId', (string) ($position + 1));

            if ($sheet['hidden']) {
                $xml->writeAttribute('state', 'hidden');
            }

            $xml->writeAttribute('r:id', 'rId'.($position + 1));
            $xml->endElement();
        }

        $xml->endElement();

        if ($this->definedNames !== []) {
            $xml->startElement('definedNames');

            foreach ($this->definedNames as $name => $range) {
                $xml->startElement('definedName');
                $xml->writeAttribute('name', $name);
                $xml->text($range);
                $xml->endElement();
            }

            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function workbookRelationships(): string
    {
        $relationships = '';
        $count = count($this->sheets);

        foreach (array_keys($this->sheets) as $position) {
            $relationships .= '<Relationship Id="rId'.($position + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($position + 1).'.xml"/>';
        }

        $relationships .= '<Relationship Id="rId'.($count + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        if ($this->sharedStrings) {
            $relationships .= '<Relationship Id="rId'.($count + 2).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>';
    }

    private function styles(): string
    {
        $numFmts = '<numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd hh:mm"/>';
        $xfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
        $styles = $this->decimalStyles;
        asort($styles);

        foreach (array_keys($styles) as $position => $scale) {
            $id = 166 + $position;
            $numFmts .= '<numFmt numFmtId="'.$id.'" formatCode="'.($scale === 0 ? '0' : '0.'.str_repeat('0', $scale)).'"/>';
            $xfs .= '<xf numFmtId="'.$id.'" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="'.(2 + count($styles)).'">'.$numFmts.'</numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.(5 + count($styles)).'">'.$xfs.'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function sharedStringsXml(): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('sst');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->writeAttribute('count', (string) count($this->strings));
        $xml->writeAttribute('uniqueCount', (string) count($this->strings));

        foreach (array_keys($this->strings) as $string) {
            $xml->startElement('si');
            $xml->startElement('t');
            $xml->writeAttribute('xml:space', 'preserve');
            $xml->text((string) $string);
            $xml->endElement();
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
