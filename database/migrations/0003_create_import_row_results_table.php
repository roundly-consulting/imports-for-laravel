<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        $primary = ImportsConfig::primaryKeyType();

        Schema::create(ImportsConfig::table('row_results'), function (Blueprint $blueprint) use ($primary): void {
            $table = $blueprint->getTable();

            match ($primary) {
                KeyType::BigInt => $blueprint->id(),
                KeyType::Uuid => $blueprint->uuid('id')->primary(),
                KeyType::Ulid => $blueprint->ulid('id')->primary(),
            };

            $blueprint->ownerKey('session_id', $primary, false, false);
            $blueprint->foreign('session_id', $table.'_session_fk')->references('id')->on(ImportsConfig::table('sessions'));
            $blueprint->unsignedInteger('row_number');
            $blueprint->string('status', 24);
            $blueprint->string('outcome', 16)->nullable();
            $blueprint->json('errors')->nullable();
            $blueprint->string('match_key', 64)->nullable();
            $blueprint->unsignedSmallInteger('match_candidate')->nullable();
            $blueprint->ownerKey('matched_record_id', ImportsConfig::recordKeyType(), true, false);
            $blueprint->string('label', 255)->nullable();
            $blueprint->unsignedBigInteger('file_size')->nullable();
            $blueprint->char('content_hash', 64)->nullable();
            $blueprint->string('stored_path')->nullable();
            $blueprint->datetimes();

            // Exceptions only: a clean file stores no rows. One result per spreadsheet row.
            $blueprint->unique(['session_id', 'row_number'], $table.'_row_unique');
            $blueprint->index(['session_id', 'status'], $table.'_status_idx');
        });
    }
};
