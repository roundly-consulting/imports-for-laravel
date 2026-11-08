<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\ImportsManager;

/**
 * @internal
 */
final class TemplateCommand extends Command
{
    protected $signature = 'imports:template {importer} {--format=xlsx : xlsx or csv} {--locale= : the header language} {--output= : where to write it}';

    protected $description = "Write an importer's template file";

    public function handle(ImportsManager $imports): int
    {
        $format = TemplateFormat::tryFrom((string) $this->option('format'));

        if ($format === null) {
            $this->components->error('The format must be xlsx or csv.');

            return self::FAILURE;
        }

        $locale = $this->option('locale');
        $template = $imports->template((string) $this->argument('importer'), $format, is_string($locale) && $locale !== '' ? $locale : null);
        $output = $this->option('output');
        $target = is_string($output) && $output !== '' ? $output : getcwd().DIRECTORY_SEPARATOR.$template->filename;
        $path = $template->toTemporaryFile($target);

        $this->components->info('Template written to '.$path.'.');

        return self::SUCCESS;
    }
}
