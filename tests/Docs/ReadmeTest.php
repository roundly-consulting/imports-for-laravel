<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Testing\ImportsFake;

/**
 * Truth, never completeness: what the README names must exist. Completeness is the docs' job.
 *
 * @return list<string>
 */
function readmePhpBlocks(): array
{
    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents(dirname(__DIR__, 2).'/README.md'), $blocks);

    return $blocks[1];
}

/**
 * Every public method of every package class, plus what the example calls on the host's models,
 * request and user.
 *
 * @return array<string, true>
 */
function knownMethods(): array
{
    $methods = [];
    $root = dirname(__DIR__, 2).'/src';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $class = 'RoundlyConsulting\\Imports\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));

        if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $methods[$method->getName()] = true;
            }
        }
    }

    foreach ([Model::class, Request::class, Authorizable::class] as $host) {
        foreach ((new ReflectionClass($host))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methods[$method->getName()] = true;
        }
    }

    return $methods;
}

test('every_readme_php_block_parses', function (): void {
    expect(readmePhpBlocks())->toHaveCount(2);

    foreach (readmePhpBlocks() as $block) {
        token_get_all('<?php '.$block, TOKEN_PARSE);
    }
});

test('every_class_and_method_the_readme_names_exists', function (): void {
    $known = knownMethods();
    $classes = [];

    foreach (readmePhpBlocks() as $block) {
        preg_match_all('/^use (RoundlyConsulting\\\\Imports\\\\[\w\\\\]*?)(?:\{([^}]+)\}|(\w+));$/m', $block, $uses, PREG_SET_ORDER);

        foreach ($uses as $use) {
            foreach (($use[2] ?? '') !== '' ? array_map('trim', explode(',', $use[2])) : [$use[3]] as $name) {
                $classes[] = rtrim($use[1], '\\').'\\'.$name;
            }
        }

        preg_match_all('/(?:->|::)(\w+)\(/', $block, $calls);

        foreach ($calls[1] as $method) {
            expect(isset($known[$method]) || method_exists(ImportsManager::class, $method) || method_exists(ImportsFake::class, $method))
                ->toBeTrue("The README calls [{$method}()], which no package class has.");
        }
    }

    expect($classes)->toHaveCount(9);

    foreach ($classes as $class) {
        expect(class_exists($class) || enum_exists($class))->toBeTrue("The README imports [{$class}], which does not exist.");
    }
});

test('every_publish_tag_the_readme_names_exists', function (): void {
    preg_match_all('/--tag="?([\w-]+)"?/', (string) file_get_contents(dirname(__DIR__, 2).'/README.md'), $tags);

    expect($tags[1])->toBe(['options-migrations', 'imports-migrations']);

    foreach ($tags[1] as $tag) {
        expect(ServiceProvider::pathsToPublish(null, $tag))->not->toBeEmpty("No provider publishes [{$tag}].");
    }
});
