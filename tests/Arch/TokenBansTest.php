<?php

declare(strict_types=1);

/**
 * Token scans for what no arch preset expresses. Each ban was proven red once with a throwaway
 * mutation (a `DB::table()` call, a two-argument `fgetcsv()`, `LIBXML_NOENT`, a stray
 * `Asset::query()`, `ini_set('auto_detect_line_endings', …)`, `$this->imports->session(` in a
 * controller).
 *
 * @return array<string, string> path => source
 */
function importsSources(string $dir = 'src'): array
{
    $sources = [];
    $root = dirname(__DIR__, 2).'/'.$dir;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    expect($sources)->not->toBeEmpty();

    return $sources;
}

it('never uses the DB facade', function (): void {
    foreach (importsSources() as $path => $source) {
        expect($source)->not->toMatch('/\bDB::|Facades\\\\DB\b/', $path);
    }
});

it('always passes an explicit escape argument to fgetcsv', function (): void {
    foreach (importsSources() as $path => $source) {
        preg_match_all('/fgetcsv\((\$[^;]*)\)/', $source, $calls);

        foreach ($calls[1] as $arguments) {
            expect(substr_count($arguments, ','))->toBeGreaterThanOrEqual(4, $path);
        }
    }
});

it('never parses XML with entity expansion, DTD loading or huge-document mode', function (): void {
    foreach (importsSources() as $path => $source) {
        expect($source)->not->toMatch('/LIBXML_(NOENT|DTDLOAD|DTDATTR|PARSEHUGE)/', $path);
    }
});

it('never touches the deprecated line-ending switch', function (): void {
    foreach (importsSources() as $path => $source) {
        expect($source)->not->toContain('auto_detect_line_endings', $path);
    }
});

it('starts host-model queries only in the owner-scoped helper', function (): void {
    foreach (importsSources() as $path => $source) {
        if ($path === 'Pipeline/Scoped.php') {
            continue;
        }

        // `$model::query()` / `$class::query()` on a class-string variable is a host model.
        expect($source)->not->toMatch('/\$(model|class|target)::query\(\)/', $path);
    }
});

it('never uses the owner-less trusted path from HTTP code', function (): void {
    foreach (importsSources('src/Http') as $path => $source) {
        expect($source)->not->toMatch('/(Imports::|imports->|ImportsManager::)(session|batch)\(/', $path);
    }
});
