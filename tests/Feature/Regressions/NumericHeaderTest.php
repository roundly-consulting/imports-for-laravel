<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\DataTransferObjects\ColumnMapping;
use RoundlyConsulting\Imports\Exceptions\InvalidMappingException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;

// Dual review 2026-11-08, O-3 = F-3: PHP turns the array key '2024' into int 2024, so a header
// whose text is a number was taken as a column index — refused ('2024'), or silently the wrong
// column ('1' mapped column B instead of the column headed "1").

it('O-3: a numeric header that is no column index maps by its text', function (): void {
    $team = team();
    $session = Imports::for($team)->by(admin($team))->upload('assets', SpreadsheetFactory::csv(['Asset tag', '2024'], [['A-1', 'Laptop']]));

    $session->map(['Asset tag' => 'asset_tag', '2024' => 'name']);

    expect($session->model()->refresh()->mapping)->toBe([0 => 'asset_tag', 1 => 'name']);
});

it('O-3: a number that is a header and another column\'s index is refused, never guessed', function (): void {
    $team = team();
    $session = Imports::for($team)->by(admin($team))->upload('assets', SpreadsheetFactory::csv(['Asset tag', 'Name', '1'], [['A-1', 'Laptop', 'x']]));

    try {
        $session->map(['Asset tag' => 'asset_tag', '1' => 'name']);
        $this->fail('The ambiguous mapping was applied.');
    } catch (InvalidMappingException $exception) {
        expect($exception->errors)->toHaveKey('columns.1')
            ->and($exception->errors['columns.1'][0])->toContain('column number');
    }

    // ColumnMapping is the explicit index form; a header reading its own index is not ambiguous.
    $session->map(ColumnMapping::fromArray([0 => 'asset_tag', 2 => 'name']));
    expect($session->model()->refresh()->mapping)->toBe([0 => 'asset_tag', 2 => 'name']);

    $own = Imports::for($team)->by(admin($team))->upload('assets', SpreadsheetFactory::csv(['Asset tag', '1'], [['A-1', 'Laptop']]));
    $own->map(['Asset tag' => 'asset_tag', 1 => 'name']);
    expect($own->model()->refresh()->mapping)->toBe([0 => 'asset_tag', 1 => 'name']);
});
