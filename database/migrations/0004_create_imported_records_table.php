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

        Schema::create(ImportsConfig::table('records'), function (Blueprint $blueprint) use ($primary): void {
            $table = $blueprint->getTable();

            match ($primary) {
                KeyType::BigInt => $blueprint->id(),
                KeyType::Uuid => $blueprint->uuid('id')->primary(),
                KeyType::Ulid => $blueprint->ulid('id')->primary(),
            };

            $blueprint->ownerKey('batch_id', $primary, false, false);
            $blueprint->foreign('batch_id', $table.'_batch_fk')->references('id')->on(ImportsConfig::table('batches'));
            $blueprint->ownerKey('session_id', $primary, false, true);
            $blueprint->foreign('session_id', $table.'_session_fk')->references('id')->on(ImportsConfig::table('sessions'));
            $blueprint->unsignedInteger('row_number');
            $blueprint->morphKey('record', ImportsConfig::recordKeyType(), false);
            $blueprint->string('action', 8);
            $blueprint->char('checksum', 64)->nullable();
            $blueprint->dateTime('record_updated_at')->nullable();
            $blueprint->char('owner_digest', 64);
            $blueprint->char('content_hash', 64)->nullable();
            $blueprint->dateTime('rolled_back_at')->nullable();
            $blueprint->string('rollback_outcome', 16)->nullable();
            $blueprint->string('rollback_reason', 255)->nullable();
            $blueprint->dateTime('created_at')->nullable();

            // The idempotency key: a retried chunk can never ledger a row twice.
            $blueprint->unique(['batch_id', 'row_number'], $table.'_row_unique');
            $blueprint->index(['owner_digest', 'content_hash'], $table.'_content_idx');
        });
    }
};
