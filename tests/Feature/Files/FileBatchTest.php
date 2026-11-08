<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\ZipCrafter;
use RoundlyConsulting\Imports\Zip\ByteBudget;
use RoundlyConsulting\Imports\Zip\ZipGuard;
use Workbench\App\Models\Document;
use Workbench\App\Models\Team;

/**
 * @param  list<UploadedFile>|UploadedFile  $files
 */
function uploadDocuments(Team $team, array|UploadedFile $files): SessionHandle
{
    return Imports::for($team)->by(admin($team))->uploadFiles('documents', $files);
}

function zipUpload(string $path, string $name = 'archive.zip'): UploadedFile
{
    return SpreadsheetFactory::file($name, (string) file_get_contents($path), 'application/zip');
}

function rejectedArchive(Team $team, string $path): RejectionReason
{
    try {
        uploadDocuments($team, zipUpload($path));
    } catch (ImportFileRejectedException $exception) {
        expect(ImportSession::query()->withTrashed()->count())->toBe(0)
            ->and(Storage::disk('imports')->allFiles())->toBe([]);

        return $exception->reason;
    }

    throw new RuntimeException('Expected the archive to be rejected.');
}

test('a_file_batch_runs_preview_commit_and_undo', function (): void {
    $team = team();
    $session = uploadDocuments($team, [
        SpreadsheetFactory::file('a.pdf', '%PDF-1 a'),
        SpreadsheetFactory::file('notes.txt', 'hello'),
        SpreadsheetFactory::file('virus.exe', 'MZ'),
        SpreadsheetFactory::file('empty.pdf', ''),
        zipUpload(ZipCrafter::make(['inside/b.pdf' => '%PDF-1 b', 'inside/c.docx' => 'PK docx'])),
    ]);

    expect($session->status())->toBe(SessionStatus::Mapped)->and($session->model()->data_rows)->toBe(6);

    $session->preview();
    $counts = $session->counts();

    expect([$counts->valid, $counts->invalid])->toBe([4, 2])
        ->and(array_map(static fn ($r): string => $r->errors[0]->code, $session->rows(RowFilter::invalid())->paginate()->items()))->toBe(['extension_not_allowed', 'empty_file']);

    $batch = $session->commit();

    expect($batch->summary()->created)->toBe(4)
        ->and(Document::query()->orderBy('id')->pluck('name')->all())->toBe(['a.pdf', 'notes.txt', 'b.pdf', 'c.docx'])
        ->and(Storage::disk('documents')->allFiles('imported'))->toHaveCount(4);

    $batch->rollback();

    expect(Document::query()->count())->toBe(0)->and($batch->rollbackReport()?->deleted)->toBe(4);
});

test('identical_files_in_one_batch_are_duplicates', function (): void {
    $team = team();
    $session = uploadDocuments($team, [SpreadsheetFactory::file('a.pdf', 'same'), SpreadsheetFactory::file('copy.pdf', 'same')])->preview();

    expect($session->counts()->duplicatesInFile)->toBe(1)
        ->and(ImportRowResult::query()->where('status', RowStatus::DuplicateInFile)->value('label'))->toBe('copy.pdf');

    expect($session->commit()->summary()->created)->toBe(1);
});

test('a_file_imported_earlier_is_a_duplicate', function (): void {
    $team = team();
    uploadDocuments($team, SpreadsheetFactory::file('a.pdf', 'content'))->preview()->commit();

    $again = uploadDocuments($team, [SpreadsheetFactory::file('renamed.pdf', 'content'), SpreadsheetFactory::file('new.pdf', 'new')])->preview();

    expect($again->counts()->matched)->toBe(1)->and($again->counts()->valid)->toBe(1)
        ->and($again->rows(RowFilter::duplicates())->paginate()->items()[0]->errors[0]->code)->toBe('file_imported_earlier');

    // Another owner's identical file is not a duplicate.
    $other = team();
    expect(uploadDocuments($other, SpreadsheetFactory::file('a.pdf', 'content'))->preview()->counts()->valid)->toBe(1);
});

test('a_rolled_back_file_can_be_imported_again', function (): void {
    $team = team();
    uploadDocuments($team, SpreadsheetFactory::file('a.pdf', 'content'))->preview()->commit()->rollback();

    expect(uploadDocuments($team, SpreadsheetFactory::file('a.pdf', 'content'))->preview()->counts()->valid)->toBe(1);
});

test('a_file_imported_between_preview_and_commit_is_skipped', function (): void {
    $team = team();
    $later = uploadDocuments($team, SpreadsheetFactory::file('a.pdf', 'content'))->preview();
    uploadDocuments($team, SpreadsheetFactory::file('a.pdf', 'content'))->preview()->commit();

    $batch = $later->commit();

    expect($batch->summary()->created)->toBe(0)->and($batch->summary()->duplicates)->toBe(1)->and(Document::query()->count())->toBe(1);
});

test('a_zip_with_501_files_is_rejected', function (): void {
    $entries = [];

    for ($i = 1; $i <= 501; $i++) {
        $entries['f'.$i.'.txt'] = (string) $i;
    }

    expect(rejectedArchive(team(), ZipCrafter::make($entries)))->toBe(RejectionReason::TooManyFiles);
});

test('more_than_max_files_across_uploads_is_rejected', function (): void {
    config()->set('imports.files.max_files', 2);
    $team = team();

    expect(fn () => uploadDocuments($team, [SpreadsheetFactory::file('a.txt', 'a'), SpreadsheetFactory::file('b.txt', 'b'), SpreadsheetFactory::file('c.txt', 'c')]))
        ->toThrow(fn (ImportFileRejectedException $e) => expect($e->reason)->toBe(RejectionReason::TooManyFiles));
});

test('a_zip_over_the_uncompressed_limit_is_rejected', function (): void {
    config()->set('imports.files.max_uncompressed', 2048);

    expect(rejectedArchive(team(), ZipCrafter::make(['a.txt' => random_bytes(1500), 'b.txt' => random_bytes(1500)])))->toBe(RejectionReason::ZipTooLarge);
});

test('an_archive_over_the_size_limit_is_rejected', function (): void {
    config()->set('imports.files.max_archive_size', 1);

    expect(rejectedArchive(team(), ZipCrafter::make(['a.txt' => random_bytes(4096)])))->toBe(RejectionReason::ArchiveTooLarge);
});

test('a_lying_entry_size_is_stopped_mid_stream', function (): void {
    // Declares 10 bytes in both headers (CHECKCONS passes), inflates to 3 MB of incompressible
    // bytes (> the importer's 2 MB per-file cap, ratio ≈ 1): only the streaming count can tell.
    $path = ZipCrafter::lyingSize('big.txt', random_bytes(3 * 1024 * 1024), 10);

    expect(rejectedArchive(team(), $path))->toBe(RejectionReason::ZipEntryTooLarge);
});

test('the_streaming_budget_is_what_stops_a_lying_entry', function (): void {
    $zip = ZipGuard::open(ZipCrafter::lyingSize('big.txt', random_bytes(3 * 1024 * 1024), 10), RejectionReason::ZipInconsistent);
    [$entry] = ZipGuard::inspectArchive($zip, 500, PHP_INT_MAX, 2 * 1024 * 1024, 100, 1024 * 1024, []);
    $budget = new ByteBudget(2 * 1024 * 1024, PHP_INT_MAX, 100, 1024 * 1024);
    $target = tempnam(sys_get_temp_dir(), 'imports-lying-');

    expect($entry->size)->toBe(10)
        ->and(fn () => ZipGuard::extract($zip, $entry, (string) $target, $budget))
        ->toThrow(fn (ImportFileRejectedException $e) => expect($e->reason)->toBe(RejectionReason::ZipEntryTooLarge))
        ->and($budget->exceeded)->toBeTrue()
        ->and($budget->part)->toBeGreaterThan(2 * 1024 * 1024);

    $zip->close();
    @unlink((string) $target);
});

test('a_zip_bomb_with_honest_small_entries_is_stopped_by_the_running_total', function (): void {
    config()->set('imports.files.max_uncompressed', 3 * 1024 * 1024);
    $entries = [];

    foreach (range(1, 5) as $i) {
        $entries['part'.$i.'.txt'] = str_repeat("\0", 900 * 1024);   // each under the per-file cap and the ratio minimum
    }

    expect(rejectedArchive(team(), ZipCrafter::make($entries)))->toBe(RejectionReason::ZipTooLarge);
});

test('a_symlink_named_like_a_directory_is_rejected', function (): void {
    expect(rejectedArchive(team(), ZipCrafter::symlink('docs/', '/etc')))->toBe(RejectionReason::ZipSymlink);
});

test('nothing_from_a_rejected_archive_remains_on_the_disk', function (Closure $archive, RejectionReason $reason): void {
    // rejectedArchive() asserts the disk is empty and no session exists after the rejection.
    expect(rejectedArchive(team(), $archive()))->toBe($reason);
})->with([
    'unsafe path after a good file' => [fn () => ZipCrafter::make(['ok.txt' => 'fine', '../evil.txt' => 'x']), RejectionReason::ZipUnsafePath],
    'symlink after a good file' => [fn () => ZipCrafter::symlink('link.txt', '/etc/passwd'), RejectionReason::ZipSymlink],
    'lying size' => [fn () => ZipCrafter::lyingSize('big.txt', random_bytes(3 * 1024 * 1024), 10), RejectionReason::ZipEntryTooLarge],
    'encrypted' => [fn () => ZipCrafter::encrypted(), RejectionReason::ZipEncrypted],
    'running total' => [function () {
        config()->set('imports.files.max_uncompressed', 4096);

        return ZipCrafter::make(['a.txt' => random_bytes(3000), 'b.txt' => random_bytes(3000)]);
    }, RejectionReason::ZipTooLarge],
]);

test('an_entry_name_in_cp437_is_stored_as_valid_utf8', function (): void {
    $team = team();
    // A Windows-era archive: no UTF-8 flag, the name in code page 437 (0x81 is ü).
    $path = ZipCrafter::make([], static function (ZipArchive $zip): void {
        $zip->addFromString("Gr\x81n.pdf", '%PDF-1.4 x', ZipArchive::FL_ENC_CP437);
    });
    $session = uploadDocuments($team, zipUpload($path));

    expect(ImportRowResult::query()->where('session_id', $session->id())->value('label'))->toBe('Grün.pdf');
});

test('an_absolute_entry_path_is_rejected', function (string $name): void {
    expect(rejectedArchive(team(), ZipCrafter::make([$name => 'x'])))->toBe(RejectionReason::ZipUnsafePath);
})->with(['/etc/passwd', 'C:/Windows/evil.txt']);

test('a_backslash_traversal_is_rejected', function (): void {
    expect(rejectedArchive(team(), ZipCrafter::make(['docs\\..\\..\\evil.txt' => 'x'])))->toBe(RejectionReason::ZipUnsafePath);
});

test('a_symlink_entry_is_rejected', function (): void {
    expect(rejectedArchive(team(), ZipCrafter::symlink('link.txt', '/etc/passwd')))->toBe(RejectionReason::ZipSymlink);
});

test('an_encrypted_entry_is_rejected', function (): void {
    expect(rejectedArchive(team(), ZipCrafter::encrypted()))->toBe(RejectionReason::ZipEncrypted);
});

test('a_nested_zip_is_not_extracted', function (): void {
    $inner = (string) file_get_contents(ZipCrafter::make(['deep.pdf' => 'deep']));
    $team = team();

    $session = uploadDocuments($team, zipUpload(ZipCrafter::make(['inner.zip' => $inner, 'top.pdf' => 'top'])))->preview();

    expect(ImportRowResult::query()->orderBy('row_number')->pluck('label')->all())->toBe(['inner.zip', 'top.pdf'])
        ->and($session->counts()->valid)->toBe(2);
});

test('macos_metadata_is_skipped', function (): void {
    $team = team();
    $session = uploadDocuments($team, zipUpload(ZipCrafter::make([
        'doc.pdf' => 'x',
        '__MACOSX/._doc.pdf' => 'meta',
        'folder/.DS_Store' => 'meta',
        'Thumbs.db' => 'meta',
        'folder/' => '',
    ])));

    expect(ImportRowResult::query()->pluck('label')->all())->toBe(['doc.pdf']);
});

test('entries_are_stored_under_generated_names', function (): void {
    $team = team();
    $session = uploadDocuments($team, zipUpload(ZipCrafter::make(['My Report (final).pdf' => 'x', 'sub/dir/Ž.pdf' => 'y'])));

    $files = Storage::disk('imports')->allFiles();

    expect($files)->toHaveCount(2)
        ->and(array_map('basename', $files))->toEqualCanonicalizing(['1', '2'])
        ->and(implode(' ', $files))->not->toContain('Report')->not->toContain('sub/dir')
        ->and(ImportRowResult::query()->orderBy('row_number')->pluck('label')->all())->toBe(['My Report (final).pdf', 'Ž.pdf']);
});

test('a_file_too_large_for_the_importer_is_invalid', function (): void {
    $team = team();
    $session = uploadDocuments($team, SpreadsheetFactory::file('big.pdf', str_repeat('x', 2049 * 1024)))->preview();

    expect($session->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0]->code)->toBe('file_too_large');
});

test('a_non_zip_named_zip_is_a_plain_file', function (): void {
    $team = team();
    $session = uploadDocuments($team, SpreadsheetFactory::file('fake.zip', 'not a zip'))->preview();

    expect($session->counts()->valid)->toBe(1);
});
