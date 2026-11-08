<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Preview;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Exceptions\InvalidMappingException;
use RoundlyConsulting\Imports\FileImporter;
use RoundlyConsulting\Imports\Jobs\ValidatePreviewJob;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Keys;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Support\Queues;
use RoundlyConsulting\Imports\Support\SessionState;

/**
 * Validate every row. Up to `preview.sync_threshold` data rows (500) this runs in the request;
 * larger files validate on the queue (status `validating`, poll `progress()`). Nothing is saved to
 * host tables either way.
 */
final readonly class PreviewImportAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private Container $container,
        private Dispatcher $bus,
        private Repository $cache,
    ) {}

    public function execute(ImportSession $session, ?Model $actor, ?bool $queue = null): ImportSession
    {
        $session->refresh();
        $definition = $this->registry->find($session->importer);
        Access::check($definition, $actor, Audits::owner($session), ImportOperation::Upload);

        if ($definition instanceof FileImporter) {
            if (! in_array($session->status, [SessionStatus::Mapped, SessionStatus::Previewed, SessionStatus::PreviewFailed], true)) {
                throw ImportStateException::wrongStatus($session->status->value);
            }

            $this->container->make(PreviewFilesAction::class)->execute($session, $definition);

            return $session->refresh();
        }

        if ($session->status === SessionStatus::NeedsMapping || $session->mapping === null || $session->mapping === []) {
            throw InvalidMappingException::withErrors(['mapping' => [Locales::line('imports::exceptions.mapping.incomplete', [], $session->locale)]]);
        }

        if (! in_array($session->status, [SessionStatus::Mapped, SessionStatus::Validating, SessionStatus::Previewed, SessionStatus::PreviewFailed], true)) {
            throw ImportStateException::wrongStatus($session->status->value);
        }

        SessionState::resetPreview($session);
        // last_progress_at starts the clock imports:sweep reads to release a lost validation.
        $session->forceFill(['status' => SessionStatus::Validating, 'last_progress_at' => Clock::now()])->save();
        $version = $session->preview_version;

        $inline = $queue === false
            || ! ImportsConfig::queueEnabled()
            || ($queue === null && $session->data_rows <= ImportsConfig::syncThreshold());

        if ($inline) {
            $this->container->make(ValidateRowsAction::class)->execute(Keys::of($session), $version);
        } else {
            $job = (new ValidatePreviewJob(Keys::of($session), $version))
                ->onConnection(Queues::connection())
                ->onQueue(Queues::queue());

            // The bus dispatcher does not take a unique job's lock (only dispatch() helpers do).
            // It is taken here: imports:sweep reads it to tell a queued validation from a lost one.
            if ((new UniqueLock($this->cache))->acquire($job)) {
                $this->bus->dispatch($job);
            }
        }

        return $session->refresh();
    }
}
