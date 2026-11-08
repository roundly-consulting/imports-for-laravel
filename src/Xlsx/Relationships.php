<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

use XMLReader;

/**
 * An OPC relationships part: id → (type, target), the target resolved to a package part name
 * relative to the source part's folder (or absolute `/xl/...`). Types are compared by their last
 * segment, so transitional and strict OOXML namespaces both work.
 *
 * @internal
 */
final class Relationships
{
    /**
     * @param  array<string, array{type: string, target: string}>  $relationships
     */
    private function __construct(private readonly array $relationships) {}

    public static function for(Package $package, string $sourcePart): self
    {
        $folder = str_contains($sourcePart, '/') ? substr($sourcePart, 0, (int) strrpos($sourcePart, '/')) : '';
        $file = str_contains($sourcePart, '/') ? substr($sourcePart, (int) strrpos($sourcePart, '/') + 1) : $sourcePart;
        $relsPart = ($folder === '' ? '' : $folder.'/').'_rels/'.$file.'.rels';

        $reader = $package->xml($relsPart);
        $relationships = [];

        if ($reader === null) {
            return new self([]);
        }

        while ($package->read($reader)) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Relationship') {
                continue;
            }

            $id = $reader->getAttribute('Id');
            $type = $reader->getAttribute('Type');
            $target = $reader->getAttribute('Target');

            if ($id === null || $type === null || $target === null || $reader->getAttribute('TargetMode') === 'External') {
                continue;
            }

            $relationships[$id] = ['type' => self::kind($type), 'target' => self::resolve($folder, $target)];
        }

        $reader->close();
        $package->assertHealthy();

        return new self($relationships);
    }

    public function target(string $id): ?string
    {
        return $this->relationships[$id]['target'] ?? null;
    }

    public function type(string $id): ?string
    {
        return $this->relationships[$id]['type'] ?? null;
    }

    public function firstOfType(string $kind): ?string
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship['type'] === $kind) {
                return $relationship['target'];
            }
        }

        return null;
    }

    private static function kind(string $type): string
    {
        $position = strrpos($type, '/');

        return strtolower($position === false ? $type : substr($type, $position + 1));
    }

    private static function resolve(string $folder, string $target): string
    {
        $path = str_starts_with($target, '/') ? ltrim($target, '/') : ($folder === '' ? $target : $folder.'/'.$target);
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return implode('/', $parts);
    }
}
