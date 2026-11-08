<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\FileImporter;

/**
 * Which file hashes this owner already imported with the same importer (and did not undo).
 *
 * @internal
 */
final class FileDuplicateCheck
{
    /**
     * @param  list<string>  $hashes
     * @return array<string, true>
     */
    public static function earlier(FileImporter $importer, Model $owner, array $hashes, int|string $exceptSession): array
    {
        $check = $importer->duplicateCheck();

        if ($hashes === [] || $check->strategy === 'none') {
            return [];
        }

        $found = [];

        if ($check->strategy === 'using' && $check->callback !== null) {
            foreach (array_unique($hashes) as $hash) {
                if (($check->callback)($hash, $owner) !== null) {
                    $found[$hash] = true;
                }
            }

            return $found;
        }

        $sessions = ImportModels::session()::query()->withTrashed()->select('id')->where('importer', $importer->key())->whereKeyNot($exceptSession);

        foreach (array_chunk(array_values(array_unique($hashes)), 500) as $chunk) {
            foreach (ImportModels::record()::query()
                ->where('owner_digest', Digests::owner($owner))
                ->whereIn('content_hash', $chunk)
                ->whereNull('rolled_back_at')
                ->whereIn('session_id', $sessions)
                ->pluck('content_hash') as $hash) {
                if (is_string($hash)) {
                    $found[$hash] = true;
                }
            }
        }

        return $found;
    }
}
