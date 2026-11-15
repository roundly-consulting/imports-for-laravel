<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\DataTransferObjects\RowResult;
use RoundlyConsulting\Imports\Definition\FileKey;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Exceptions\InvalidImporterDefinitionException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\InvitationsImporter;
use Workbench\App\Models\Invitation;
use Workbench\App\Models\Team;
use Workbench\Database\Factories\InvitationFactory;

/*
 * A file-only uniqueness rule (`uniqueInFile()`): the same key twice in one file is a duplicate
 * within the file, while existing records — active or soft-deleted — are never looked up.
 */

beforeEach(function (): void {
    InvitationsImporter::reset();
    Imports::register(InvitationsImporter::class);
});

/**
 * @param  list<list<string>>  $rows  email, first name
 */
function uploadInvitations(Team $team, array $rows): SessionHandle
{
    return Imports::for($team)->by(admin($team))->upload('invitations', SpreadsheetFactory::csv(['Email', 'First name'], $rows));
}

/**
 * @return list<array{int, string, string|null, list<string>}>
 */
function invitationRows(SessionHandle $session, ?RowFilter $filter = null): array
{
    return array_map(
        static fn (RowResult $row): array => [$row->rowNumber, $row->status->value, $row->label, array_map(static fn ($error): string => $error->message, $row->errors)],
        iterator_to_array($session->rows($filter)->cursor(), false),
    );
}

test('a_later_row_with_the_same_key_in_another_case_is_a_duplicate_within_the_file', function (): void {
    $team = team();

    $session = uploadInvitations($team, [
        ['jane@example.com', 'Jane'],
        ['Jane@Example.com ', 'Janet'],
        ['joe@example.com', 'Joe'],
    ])->preview();

    expect($session->counts()->valid)->toBe(2)
        ->and($session->counts()->duplicatesInFile)->toBe(1)
        ->and($session->counts()->matched)->toBe(0)
        ->and(invitationRows($session))->toBe([
            [2, 'valid', 'jane@example.com', []],
            [3, 'duplicate_in_file', 'Jane@Example.com', ['Same as row 2.']],
            [4, 'valid', 'joe@example.com', []],
        ]);

    $duplicate = $session->rows(RowFilter::duplicates())->paginate()->items()[0];

    expect($duplicate->errors[0]->code)->toBe('duplicate_in_file');

    $batch = $session->commit();

    expect($batch->summary()->created)->toBe(2)
        ->and($batch->summary()->duplicates)->toBe(1)
        ->and(Invitation::query()->where('team_id', $team->id)->pluck('first_name')->sort()->values()->all())->toBe(['Jane', 'Joe']);
});

test('the_message_names_the_earlier_row_in_slovak_too', function (): void {
    $team = team();

    $session = Imports::for($team)->by(admin($team))
        ->upload('invitations', SpreadsheetFactory::csv(['Email', 'First name'], [['a@example.com', 'A'], ['a@example.com', 'B']]), locale: 'sk')
        ->preview();

    expect(invitationRows($session, RowFilter::duplicates())[0][3])->toBe(['Rovnaké ako riadok 2.']);
});

test('a_duplicate_is_found_across_a_chunk_boundary', function (): void {
    $team = team();
    $rows = [['first@example.com', 'First']];

    for ($i = 2; $i <= 1000; $i++) {
        $rows[] = ["person{$i}@example.com", "P{$i}"];
    }

    $rows[] = ['FIRST@example.com', 'Again'];   // data row 1001, sheet row 1002

    $session = uploadInvitations($team, $rows)->preview(queue: false);

    expect($session->counts()->valid)->toBe(1000)
        ->and($session->counts()->duplicates)->toBe(1)
        ->and(invitationRows($session, RowFilter::duplicates()))->toBe([[1002, 'duplicate_in_file', 'FIRST@example.com', ['Same as row 2.']]]);

    $batch = $session->commit();

    expect($batch->summary()->created)->toBe(1000)
        ->and($batch->summary()->duplicates)->toBe(1)
        ->and(Invitation::query()->where('email', 'first@example.com')->count())->toBe(1);
});

test('existing_records_never_match_active_or_soft_deleted', function (): void {
    $team = team();
    InvitationFactory::new()->create(['team_id' => $team->id, 'email' => 'jane@example.com', 'status' => 'accepted']);
    InvitationFactory::new()->create(['team_id' => $team->id, 'email' => 'joe@example.com'])->delete();

    $session = uploadInvitations($team, [['jane@example.com', 'Jane'], ['joe@example.com', 'Joe']])->preview();

    expect($session->counts()->valid)->toBe(2)
        ->and($session->counts()->matched)->toBe(0)
        ->and($session->counts()->invalid)->toBe(0);

    $session->commit();

    expect(Invitation::query()->where('team_id', $team->id)->count())->toBe(3)
        ->and(Invitation::withTrashed()->where('team_id', $team->id)->count())->toBe(4);
});

test('the_importers_own_validation_still_refuses_the_first_row', function (): void {
    $team = team();

    $session = uploadInvitations($team, [['not-an-email', 'A'], ['not-an-email', 'B']])->preview();

    // The first row fails validation, so the second has no earlier holder of the key: invalid too, not a duplicate.
    expect($session->counts()->invalid)->toBe(2)
        ->and($session->counts()->duplicates)->toBe(0);
});

test('a_natural_key_and_a_file_key_work_together', function (): void {
    $team = team();
    InvitationsImporter::$naturalKey = NaturalKey::by('first_name');
    InvitationsImporter::$fileKey = FileKey::by('email')->caseInsensitive();
    $existing = InvitationFactory::new()->create(['team_id' => $team->id, 'email' => 'old@example.com', 'first_name' => 'Ada']);

    $session = uploadInvitations($team, [
        ['ada@example.com', 'Ada'],      // natural key: matches the existing record
        ['grace@example.com', 'Grace'],  // new
        ['GRACE@example.com', 'Hopper'], // file key: same email as row 3
        ['other@example.com', 'Grace'],  // natural key: same first name as row 3 — today's duplicate
    ])->preview();

    expect($session->counts()->matched)->toBe(1)
        ->and($session->counts()->valid)->toBe(1)
        ->and($session->counts()->duplicatesInFile)->toBe(2)
        // `duplicates` counts the matched row too, as in 1.1.
        ->and($session->counts()->duplicates)->toBe(3)
        ->and(invitationRows($session))->toBe([
            [2, 'matched', 'Ada', []],
            [3, 'valid', 'Grace', []],
            [4, 'duplicate_in_file', 'Hopper', ['Same as row 3.']],
            [5, 'duplicate_in_file', 'Grace', ['An earlier row of the file has the same identifier.']],
        ])
        ->and($session->rows(RowFilter::status(RowStatus::Matched))->paginate()->items()[0]->match?->id)->toBe($existing->id);

    $session->mode(DuplicateMode::Skip);
    $batch = $session->commit();

    expect($batch->summary()->created)->toBe(1)
        ->and($batch->summary()->skipped)->toBe(1)
        ->and($batch->summary()->duplicates)->toBe(2);
});

test('blank_key_cells_are_not_duplicates_of_each_other', function (): void {
    $team = team();

    $session = uploadInvitations($team, [['', 'A'], ['', 'B'], [' ', 'C']])->preview();

    expect($session->counts()->valid)->toBe(3)
        ->and($session->counts()->duplicates)->toBe(0);
});

test('every_candidate_counts_and_a_blank_part_skips_its_candidate', function (): void {
    $team = team();
    InvitationsImporter::$fileKey = FileKey::by('email')->then('first_name', 'role');

    $session = uploadInvitations($team, [
        ['a@example.com', 'Ann'],
        ['b@example.com', 'Ann'],   // second candidate: same first name + default role
        ['', 'Bob'],
        ['', 'Cid'],
    ])->preview();

    expect(array_column(invitationRows($session), 1))->toBe(['valid', 'duplicate_in_file', 'valid', 'valid']);
});

test('labels_fall_back_to_the_file_key', function (): void {
    $team = team();
    InvitationsImporter::$labelField = null;

    $session = uploadInvitations($team, [['x@example.com', 'X']])->preview();

    expect(invitationRows($session)[0][2])->toBe('x@example.com');
});

test('a_file_key_naming_an_unknown_field_is_refused_at_registration', function (): void {
    InvitationsImporter::$fileKey = FileKey::by('phone');

    Imports::register(InvitationsImporter::class);

    expect(fn () => Imports::importer('invitations'))
        ->toThrow(InvalidImporterDefinitionException::class, 'uniqueInFile() key field [phone] is not a field');
});

test('without_a_file_key_the_same_email_twice_stays_valid_as_in_1_1', function (): void {
    $team = team();
    InvitationsImporter::$fileKey = null;

    $session = uploadInvitations($team, [['jane@example.com', 'Jane'], ['jane@example.com', 'Janet']])->preview();

    expect($session->counts()->valid)->toBe(2)
        ->and($session->counts()->duplicates)->toBe(0);
});

test('a_file_key_lists_every_field_it_uses_once', function (): void {
    $key = FileKey::by('email')->then('first_name', 'email')->caseInsensitive();

    expect($key->fields())->toBe(['email', 'first_name'])
        ->and($key->caseInsensitive)->toBeTrue()
        ->and($key->candidates)->toBe([['email'], ['first_name', 'email']]);
});
