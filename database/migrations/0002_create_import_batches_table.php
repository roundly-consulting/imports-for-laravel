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

        Schema::create(ImportsConfig::table('batches'), function (Blueprint $blueprint) use ($primary): void {
            $table = $blueprint->getTable();

            match ($primary) {
                KeyType::BigInt => $blueprint->id(),
                KeyType::Uuid => $blueprint->uuid('id')->primary(),
                KeyType::Ulid => $blueprint->ulid('id')->primary(),
            };

            $blueprint->ownerKey('session_id', $primary, false, false)->unique($table.'_session_unique');
            $blueprint->foreign('session_id', $table.'_session_fk')->references('id')->on(ImportsConfig::table('sessions'));
            $blueprint->morphKey('actor', ImportsConfig::keyType(), true);
            $blueprint->morphKey('rollback_actor', ImportsConfig::keyType(), true);
            $blueprint->string('laravel_batch_id', 36)->nullable();
            $blueprint->string('rollback_batch_id', 36)->nullable();
            $blueprint->string('status', 24);
            $blueprint->unsignedInteger('chunk_size');
            $blueprint->unsignedInteger('total_chunks');
            $blueprint->unsignedInteger('chunks_completed')->default(0);
            $blueprint->json('counts');
            $blueprint->string('rollback_status', 24)->nullable();
            $blueprint->json('rollback_counts')->nullable();
            $blueprint->json('failure')->nullable();
            $blueprint->dateTime('started_at')->nullable();
            $blueprint->dateTime('finished_at')->nullable();
            $blueprint->dateTime('undo_until')->nullable();
            $blueprint->dateTime('rolled_back_at')->nullable();
            $blueprint->datetimes();

            $blueprint->index(['status', 'undo_until'], $table.'_status_undo_idx');
        });
    }
};
