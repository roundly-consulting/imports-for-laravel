<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Testing;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportSession;

/**
 * Installed by `Imports::fake()`. Records every operation — through the facade, an injected
 * manager, the handles and the model conveniences — and passes it through, so handles keep
 * returning real sessions and batches (combine with `Bus::fake()` to keep commits from running).
 * `withoutRunning()` switches to record-only for the operations that return nothing a test needs:
 * cancel, discard, resume, rollback, mapping deletes, prune and sweep.
 */
final class ImportsFake extends ImportsManager
{
    private const array RETURNLESS = ['cancel', 'discard', 'resume', 'rollback', 'deleteMapping', 'prune', 'sweep'];

    /** @var list<RecordedOperation> */
    private array $recorded = [];

    private bool $running = true;

    public function withoutRunning(bool $withoutRunning = true): self
    {
        $this->running = ! $withoutRunning;

        return $this;
    }

    /**
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  array<string, mixed>  $context
     * @return TResult|null
     */
    public function perform(string $operation, string $action, Closure $execute, array $context = []): mixed
    {
        $result = $this->running || ! in_array($operation, self::RETURNLESS, true)
            ? parent::perform($operation, $action, $execute, $context)
            : null;

        $this->recorded[] = new RecordedOperation($operation, $context, $result, $action);

        return $result;
    }

    /**
     * @return list<RecordedOperation>
     */
    public function recorded(?string $operation = null): array
    {
        return array_values(array_filter($this->recorded, static fn (RecordedOperation $r): bool => $operation === null || $r->operation === $operation));
    }

    /**
     * @param  (Closure(ImportSession): bool)|null  $callback
     */
    public function assertUploaded(?string $importer = null, ?Closure $callback = null): void
    {
        $this->assertSessions('upload', $importer, $callback, 'uploaded');
    }

    public function assertNothingUploaded(): void
    {
        $this->assertNone('upload', 'uploaded');
    }

    /**
     * @param  (Closure(ImportSession): bool)|null  $callback
     */
    public function assertFilesUploaded(?string $importer = null, ?Closure $callback = null): void
    {
        $this->assertSessions('uploadFiles', $importer, $callback, 'uploaded as files');
    }

    /**
     * @param  (Closure(ImportSession): bool)|null  $callback
     */
    public function assertMapped(?Closure $callback = null): void
    {
        $this->assertSessions('map', null, $callback, 'mapped');
    }

    public function assertNothingMapped(): void
    {
        $this->assertNone('map', 'mapped');
    }

    public function assertMappingSaved(?string $name = null): void
    {
        $matching = array_filter($this->recorded('saveMapping'), static fn (RecordedOperation $r): bool => $name === null || ($r->context['name'] ?? null) === $name);

        PHPUnit::assertNotEmpty($matching, $name === null ? 'Expected a mapping to be saved, but none was.' : "Expected a mapping named [{$name}] to be saved, but none was.");
    }

    public function assertNoMappingSaved(): void
    {
        $this->assertNone('saveMapping', 'saved as a mapping');
    }

    /**
     * @param  (Closure(ImportSession): bool)|null  $callback
     */
    public function assertPreviewed(?Closure $callback = null): void
    {
        $this->assertSessions('preview', null, $callback, 'previewed');
    }

    public function assertNothingPreviewed(): void
    {
        $this->assertNone('preview', 'previewed');
    }

    /**
     * @param  (Closure(ImportBatch): bool)|null  $callback
     */
    public function assertCommitted(?string $importer = null, ?Closure $callback = null): void
    {
        $matching = array_filter($this->recorded('commit'), static function (RecordedOperation $r) use ($importer, $callback): bool {
            if ($importer !== null && ($r->context['importer'] ?? null) !== $importer) {
                return false;
            }

            return $callback === null || ($r->result instanceof ImportBatch && $callback($r->result));
        });

        PHPUnit::assertNotEmpty($matching, $importer === null && $callback === null
            ? 'Expected an import to be committed, but none was.'
            : 'Expected a matching import to be committed, but no commit matched.');
    }

    public function assertNothingCommitted(): void
    {
        $this->assertNone('commit', 'committed');
    }

    /**
     * @param  (Closure(ImportBatch): bool)|null  $callback
     */
    public function assertCancelled(?Closure $callback = null): void
    {
        $this->assertBatches('cancel', $callback, 'cancelled');
    }

    public function assertNothingCancelled(): void
    {
        $this->assertNone('cancel', 'cancelled');
    }

    /**
     * @param  (Closure(ImportBatch): bool)|null  $callback
     */
    public function assertResumed(?Closure $callback = null): void
    {
        $this->assertBatches('resume', $callback, 'resumed');
    }

    public function assertNothingResumed(): void
    {
        $this->assertNone('resume', 'resumed');
    }

    /**
     * @param  (Closure(ImportBatch): bool)|null  $callback
     */
    public function assertRolledBack(?Closure $callback = null): void
    {
        $this->assertBatches('rollback', $callback, 'rolled back');
    }

    public function assertNothingRolledBack(): void
    {
        $this->assertNone('rollback', 'rolled back');
    }

    /**
     * @param  (Closure(ImportSession): bool)|null  $callback
     */
    public function assertDiscarded(?Closure $callback = null): void
    {
        $this->assertSessions('discard', null, $callback, 'discarded');
    }

    public function assertNothingDiscarded(): void
    {
        $this->assertNone('discard', 'discarded');
    }

    public function assertTemplateBuilt(?string $importer = null, ?TemplateFormat $format = null, ?string $locale = null): void
    {
        $matching = array_filter($this->recorded('template'), static fn (RecordedOperation $r): bool => ($importer === null || ($r->context['importer'] ?? null) === $importer)
            && ($format === null || ($r->context['format'] ?? null) === $format)
            && ($locale === null || ($r->context['locale'] ?? null) === $locale));

        PHPUnit::assertNotEmpty($matching, 'Expected a matching template to be built, but none was.');
    }

    public function assertNoTemplateBuilt(): void
    {
        $this->assertNone('template', 'built as a template');
    }

    /**
     * @param  (Closure(ImportSession): bool)|null  $callback
     */
    public function assertErrorReportBuilt(?Closure $callback = null): void
    {
        $this->assertSessions('errorReport', null, $callback, 'turned into an error report');
    }

    public function assertNoErrorReportBuilt(): void
    {
        $this->assertNone('errorReport', 'turned into an error report');
    }

    public function assertPruned(): void
    {
        PHPUnit::assertNotEmpty($this->recorded('prune'), 'Expected imports to be pruned, but they were not.');
    }

    public function assertNotPruned(): void
    {
        $this->assertNone('prune', 'pruned');
    }

    public function assertSwept(): void
    {
        PHPUnit::assertNotEmpty($this->recorded('sweep'), 'Expected imports to be swept, but they were not.');
    }

    public function assertNotSwept(): void
    {
        $this->assertNone('sweep', 'swept');
    }

    public function assertNothingHappened(): void
    {
        PHPUnit::assertEmpty($this->recorded, sprintf(
            'Expected no import operation, but %d happened: %s.',
            count($this->recorded),
            implode(', ', array_map(static fn (RecordedOperation $r): string => $r->operation, $this->recorded)),
        ));
    }

    /**
     * @param  (Closure(ImportSession): bool)|null  $callback
     */
    private function assertSessions(string $operation, ?string $importer, ?Closure $callback, string $verb): void
    {
        $matching = array_filter($this->recorded($operation), static function (RecordedOperation $r) use ($importer, $callback): bool {
            $session = $r->result instanceof ImportSession ? $r->result : ($r->context['session'] ?? null);

            if (! $session instanceof ImportSession) {
                return $importer === null && $callback === null;
            }

            return ($importer === null || $session->importer === $importer) && ($callback === null || $callback($session));
        });

        PHPUnit::assertNotEmpty($matching, $importer === null && $callback === null
            ? "Expected an import to be {$verb}, but none was."
            : "Expected a matching import to be {$verb}, but none matched.");
    }

    /**
     * @param  (Closure(ImportBatch): bool)|null  $callback
     */
    private function assertBatches(string $operation, ?Closure $callback, string $verb): void
    {
        $matching = array_filter($this->recorded($operation), static function (RecordedOperation $r) use ($callback): bool {
            $batch = $r->context['batch'] ?? null;

            return $callback === null || ($batch instanceof ImportBatch && $callback($batch));
        });

        PHPUnit::assertNotEmpty($matching, $callback === null ? "Expected an import to be {$verb}, but none was." : "Expected a matching import to be {$verb}, but none matched.");
    }

    private function assertNone(string $operation, string $verb): void
    {
        $count = count($this->recorded($operation));

        PHPUnit::assertSame(0, $count, "Expected nothing to be {$verb}, but {$count} import(s) were.");
    }
}
