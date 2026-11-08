<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Zip\ByteBudget;
use RoundlyConsulting\Imports\Zip\GuardedStream;
use RoundlyConsulting\Imports\Zip\ZipGuard;
use XMLReader;
use ZipArchive;

/**
 * The XLSX container: parts are found by OPC name (case-insensitively) and parsed straight from
 * the ZIP stream through the byte guard — never extracted to disk. Every XML part is refused the
 * moment it shows a DOCTYPE, before an entity could be declared or used (XXE, billion laughs).
 *
 * @internal
 */
final class Package
{
    /** @var array<string, string> lower-cased part name => real entry name */
    private array $names = [];

    public readonly ByteBudget $budget;

    private bool $closed = false;

    private function __construct(private readonly ZipArchive $zip, int $maxPartSize, int $maxInflated)
    {
        $this->budget = new ByteBudget($maxPartSize, $maxInflated);

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (is_string($name)) {
                $this->names[strtolower(ltrim($name, '/'))] = $name;
            }
        }
    }

    public static function open(string $path, int $maxEntries, int $maxPartSize, int $maxInflated): self
    {
        $zip = ZipGuard::open($path, RejectionReason::MalformedSpreadsheet);
        ZipGuard::inspectWorkbook($zip, $maxEntries, $maxPartSize);

        return new self($zip, $maxPartSize, $maxInflated);
    }

    public function has(string $part): bool
    {
        return isset($this->names[strtolower(ltrim($part, '/'))]);
    }

    /**
     * A streaming XML reader over one part, or null when the part does not exist.
     */
    public function xml(string $part): ?XMLReader
    {
        $name = $this->names[strtolower(ltrim($part, '/'))] ?? null;

        if ($name === null) {
            return null;
        }

        $stat = $this->zip->statName($name);
        $stream = $this->zip->getStream($name);

        if ($stream === false || $stat === false) {
            throw ImportFileRejectedException::because(RejectionReason::MalformedSpreadsheet);
        }

        GuardedStream::attach($stream, $this->budget, $stat['comp_size']);
        libxml_use_internal_errors(true);
        $reader = XMLReader::fromStream($stream, null, LIBXML_NONET | LIBXML_COMPACT);

        return $reader;
    }

    /**
     * Advance the reader one node, refusing a DOCTYPE and turning a parse error or a crossed byte
     * cap into a rejection.
     */
    public function read(XMLReader $reader): bool
    {
        $read = @$reader->read();

        if ($read && $reader->nodeType === XMLReader::DOC_TYPE) {
            throw ImportFileRejectedException::because(RejectionReason::MalformedSpreadsheet);
        }

        if (! $read) {
            $this->assertHealthy();
        }

        return $read;
    }

    public function assertHealthy(): void
    {
        if ($this->budget->exceeded) {
            throw ImportFileRejectedException::because(RejectionReason::ZipTooLarge);
        }

        $errors = libxml_get_errors();
        libxml_clear_errors();

        foreach ($errors as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                throw ImportFileRejectedException::because(RejectionReason::MalformedSpreadsheet);
            }
        }
    }

    public function close(): void
    {
        if (! $this->closed) {
            $this->closed = true;
            $this->zip->close();
        }
    }
}
