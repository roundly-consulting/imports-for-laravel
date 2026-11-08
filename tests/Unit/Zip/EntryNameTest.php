<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Zip\EntryName;

test('unsafe_entry_names_are_refused', function (string $name): void {
    expect(EntryName::isSafe($name))->toBeFalse();
})->with([
    'parent' => ['..'],
    'parent prefix' => ['../x'],
    'nested parent' => ['a/../../x'],
    'backslash parent' => ['a\..\x'],
    'dot then parent' => ['./../x'],
    'absolute' => ['/x'],
    'backslash absolute' => ['\x'],
    'drive relative' => ['C:x'],
    'drive absolute' => ['C:\x'],
    'unc' => ['\\\\server\share\x'],
    'nul' => ["a\0b"],
    'del' => ["a\x7Fb"],
    'too long' => [str_repeat('a', 256)],
    'empty' => [''],
]);

test('safe_entry_names_are_kept', function (string $name): void {
    expect(EntryName::isSafe($name))->toBeTrue();
})->with([
    'dot segment' => ['a/./b'],
    'diacritics' => ['ž.pdf'],
    'dots in a name' => ['..foo'],
    'longest' => [str_repeat('a', 255)],
]);
