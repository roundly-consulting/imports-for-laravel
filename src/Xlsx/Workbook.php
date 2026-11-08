<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

use RoundlyConsulting\Imports\DataTransferObjects\SheetInfo;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Exceptions\SheetNotFoundException;
use XMLReader;

/**
 * `workbook.xml` and its relationships: the worksheets in order (chart and dialog sheets are not
 * worksheets), their visibility and part names, the date system, and the styles and
 * shared-strings parts.
 *
 * @internal
 */
final class Workbook
{
    /**
     * @param  list<array{name: string, visible: bool, part: string}>  $sheets
     */
    private function __construct(
        public readonly array $sheets,
        public readonly bool $date1904,
        public readonly ?string $stylesPart,
        public readonly ?string $sharedStringsPart,
    ) {}

    public static function read(Package $package): self
    {
        $root = Relationships::for($package, '');
        $workbookPart = $root->firstOfType('officedocument');

        if ($workbookPart === null || ! $package->has($workbookPart)) {
            throw ImportFileRejectedException::because(RejectionReason::MalformedSpreadsheet);
        }

        $relationships = Relationships::for($package, $workbookPart);
        $reader = $package->xml($workbookPart);

        if ($reader === null) {
            throw ImportFileRejectedException::because(RejectionReason::MalformedSpreadsheet);
        }

        $sheets = [];
        $date1904 = false;

        while ($package->read($reader)) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->localName === 'workbookPr') {
                $value = strtolower((string) $reader->getAttribute('date1904'));
                $date1904 = $value === '1' || $value === 'true';
            } elseif ($reader->localName === 'sheet') {
                $name = (string) $reader->getAttribute('name');
                $state = (string) $reader->getAttribute('state');
                $id = self::relationshipId($reader);

                if ($id === null || $relationships->type($id) !== 'worksheet') {
                    continue;
                }

                $part = $relationships->target($id);

                if ($part !== null && $package->has($part)) {
                    $sheets[] = ['name' => $name, 'visible' => $state === '' || $state === 'visible', 'part' => $part];
                }
            }
        }

        $reader->close();
        $package->assertHealthy();

        if ($sheets === []) {
            throw ImportFileRejectedException::because(RejectionReason::MalformedSpreadsheet);
        }

        return new self($sheets, $date1904, $relationships->firstOfType('styles'), $relationships->firstOfType('sharedstrings'));
    }

    /**
     * @return list<SheetInfo>
     */
    public function sheetInfos(): array
    {
        $infos = [];
        $visible = 0;

        foreach ($this->sheets as $sheet) {
            $infos[] = new SheetInfo($sheet['name'], $sheet['visible'] ? ++$visible : null, $sheet['visible']);
        }

        return $infos;
    }

    /**
     * Default = the first visible worksheet; a name matches exactly, then case-insensitively (any
     * state, hidden included); a number is the 1-based position among visible worksheets.
     *
     * @return array{name: string, visible: bool, part: string}
     */
    public function select(string|int|null $sheet): array
    {
        $visible = array_values(array_filter($this->sheets, static fn (array $s): bool => $s['visible']));

        if ($sheet === null) {
            return $visible[0] ?? $this->sheets[0];
        }

        if (is_int($sheet)) {
            return $visible[$sheet - 1] ?? throw SheetNotFoundException::named($sheet, $this->names());
        }

        foreach ($this->sheets as $candidate) {
            if ($candidate['name'] === $sheet) {
                return $candidate;
            }
        }

        foreach ($this->sheets as $candidate) {
            if (mb_strtolower($candidate['name']) === mb_strtolower($sheet)) {
                return $candidate;
            }
        }

        throw SheetNotFoundException::named($sheet, $this->names());
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (array $sheet): string => $sheet['name'], $this->sheets);
    }

    private static function relationshipId(XMLReader $reader): ?string
    {
        if (! $reader->hasAttributes) {
            return null;
        }

        $id = null;

        while ($reader->moveToNextAttribute()) {
            if ($reader->localName === 'id' && str_ends_with($reader->namespaceURI, '/relationships')) {
                $id = $reader->value;
            }
        }

        $reader->moveToElement();

        return $id;
    }
}
