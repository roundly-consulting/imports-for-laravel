<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\Pipeline\CellNormalizer;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Support\Numbers;

// claude.ai chat review 2026-11-08 — the value-pipeline findings (C-1, C-4, C-5, C-7, C-8, V-1).

function chatCell(Field $field, Cell $cell, string $timezone = 'UTC'): mixed
{
    $result = CellNormalizer::forSession('en', $timezone)->normalize($field, $cell);

    return $result->failed() ? 'error:'.$result->error : $result->value;
}

/**
 * The error codes of the first invalid row of a previewed session.
 *
 * @return list<string>
 */
function chatErrorCodes(SessionHandle $session): array
{
    $row = $session->rows(RowFilter::invalid())->paginate()->items()[0] ?? null;

    return $row === null ? [] : array_map(static fn ($error): string => $error->code, $row->errors);
}

final class ChatReviewDatetimeInvite extends Model
{
    protected $table = 'invitations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }
}

it('C-1: a number with a huge exponent is refused instead of being expanded', function (): void {
    expect(Numbers::plain('1e5000'))->toBeNull()
        ->and(Numbers::plain('1e-5000'))->toBeNull()
        ->and(Numbers::plain('1e999999999'))->toBeNull()
        ->and(Numbers::plain('-1e9999999999999999999999'))->toBeNull()
        ->and(Numbers::parse('1e5000', 'en'))->toBeNull()
        // Everything a float can print still expands.
        ->and(Numbers::plain('1.5E+3'))->toBe('1500')
        ->and(Numbers::plain('1.79769313486232E+308'))->toStartWith('179769313486232')
        ->and(Numbers::plain('4.94065645841247E-324'))->toStartWith('0.000');
});

it('C-1: a preview of a cell like 1e9999999999 finishes and reports the cell', function (): void {
    $team = team();
    $rows = assetRows(1);
    $rows[0][3] = '1e9999999999';   // status, a choice
    $rows[0][5] = '1e9999999999';   // price, a decimal

    $session = uploadAssets($team, admin($team), assetsCsv($rows))->preview();

    expect($session->counts()->invalid)->toBe(1)
        ->and(chatErrorCodes($session))->toContain('invalid_number')->toContain('invalid_choice');
});

it('C-4: a choice value is matched as typed before it is read as a number', function (): void {
    $region = Field::choice('region')->options(['01' => 'Bratislava', '02' => 'Trnava']);
    $size = Field::choice('size')->options(['1.50' => ['en' => 'Large']]);
    $code = Field::choice('code')->options(['1' => ['en' => 'One']]);

    expect(chatCell($region, Cell::text('01')))->toBe('01')
        ->and(chatCell($region, Cell::text('Bratislava')))->toBe('01')
        ->and(chatCell($size, Cell::text('1.50')))->toBe('1.50')
        ->and(chatCell($code, Cell::number('1')))->toBe('1')
        ->and(chatCell($code, Cell::number('1.0')))->toBe('1')
        ->and(chatCell($code, Cell::text('1.0')))->toBe('1')
        ->and(chatCell($region, Cell::text('03')))->toBe('error:invalid_choice');
});

it('V-1: a numeric value => label map keeps its codes; a list stays a list', function (): void {
    $gender = Field::choice('gender')->options([1 => 'Male', 2 => 'Female']);
    $state = Field::choice('state')->options(['active', 'retired']);

    expect(array_keys($gender->options))->toBe([1, 2])
        ->and(chatCell($gender, Cell::text('1')))->toBe('1')
        ->and(chatCell($gender, Cell::text('Male')))->toBe('1')
        ->and(chatCell($gender, Cell::text('female')))->toBe('2')
        ->and(array_keys($state->options))->toBe(['active', 'retired'])
        ->and(chatCell($state, Cell::text('Retired')))->toBe('retired');
});

it('C-5: a date-time value reaches the importer in the app timezone; a date stays a date', function (): void {
    config(['app.timezone' => 'UTC']);

    $at = chatCell(Field::datetime('accepted_at'), Cell::text('2026-01-15 09:30'), 'Europe/Bratislava');
    $serial = chatCell(Field::datetime('accepted_at'), Cell::number('46037.375'), 'Europe/Bratislava');   // 2026-01-15 09:00
    $date = chatCell(Field::date('on'), Cell::text('2026-01-15'), 'Europe/Bratislava');

    expect($at->format('Y-m-d H:i:s e'))->toBe('2026-01-15 08:30:00 UTC')
        ->and($serial->format('Y-m-d H:i e'))->toBe('2026-01-15 08:00 UTC')
        ->and($date->format('Y-m-d H:i'))->toBe('2026-01-15 00:00');

    $invite = ChatReviewDatetimeInvite::query()->create(['team_id' => team()->id, 'email' => 'a@example.com', 'role' => 'member', 'accepted_at' => $at]);

    expect(DB::table('invitations')->where('id', $invite->id)->value('accepted_at'))->toBe('2026-01-15 08:30:00');
});
