<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Imports\DataTransferObjects\FieldDescriptor;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\SourceFormat;
use RoundlyConsulting\Imports\ImportsManager;

/**
 * @internal
 */
final class ImportersCommand extends Command
{
    protected $signature = 'imports:importers';

    protected $description = 'List the registered importers';

    public function handle(ImportsManager $imports): int
    {
        $rows = [];

        foreach ($imports->importers()->all() as $definition) {
            $descriptor = $imports->importers()->describe($definition->key());
            $rows[] = [
                $descriptor->key,
                $descriptor->label,
                $descriptor->kind->value,
                implode(', ', array_map(static fn (FieldDescriptor $f): string => $f->name.($f->required ? '*' : ''), $descriptor->fields)) ?: implode(', ', $descriptor->extensions),
                implode(', ', array_map(static fn (DuplicateMode $m): string => $m->value, $descriptor->modes)),
                implode(', ', array_map(static fn (SourceFormat $f): string => $f->value, $descriptor->formats)),
            ];
        }

        $this->table(['Key', 'Label', 'Kind', 'Fields', 'Modes', 'Formats'], $rows);

        return self::SUCCESS;
    }
}
