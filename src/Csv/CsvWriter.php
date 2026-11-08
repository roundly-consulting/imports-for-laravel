<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Csv;

use RuntimeException;

/**
 * Every CSV the package writes — templates and error reports, which echo uploaded values — goes
 * through here: a cell starting with `= + - @`, a tab or a CR gets a leading `'` so a spreadsheet
 * never runs it as a formula (CSV/DDE injection). A plain number (`-5`, `1,5`) is left alone.
 *
 * @internal
 */
final class CsvWriter
{
    /** @var resource */
    private $handle;

    public function __construct(string $path, private readonly string $delimiter = ',', bool $bom = true)
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Cannot write the CSV file.');
        }

        $this->handle = $handle;

        if ($bom) {
            fwrite($this->handle, "\xEF\xBB\xBF");
        }
    }

    /**
     * @param  list<string|int|float|bool|null>  $cells
     */
    public function row(array $cells): void
    {
        fputcsv($this->handle, array_map(self::neutralise(...), $cells), $this->delimiter, '"', '', "\r\n");
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public static function neutralise(string|int|float|bool|null $value): string
    {
        $value = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            default => (string) $value,
        };

        if ($value === '' || preg_match('/^-?\d+([.,]\d+)?$/', $value) === 1) {
            return $value;
        }

        return strpbrk($value[0], "=+-@\t\r") !== false ? "'".$value : $value;
    }
}
