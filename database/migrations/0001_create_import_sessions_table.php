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

        Schema::create(ImportsConfig::table('sessions'), function (Blueprint $blueprint) use ($primary): void {
            $table = $blueprint->getTable();

            match ($primary) {
                KeyType::BigInt => $blueprint->id(),
                KeyType::Uuid => $blueprint->uuid('id')->primary(),
                KeyType::Ulid => $blueprint->ulid('id')->primary(),
            };

            $blueprint->string('importer', 64);
            $blueprint->string('kind', 8);
            $blueprint->morphKey('owner', ImportsConfig::keyType(), false);
            $blueprint->morphKey('actor', ImportsConfig::keyType(), true);
            $blueprint->string('status', 24);
            $blueprint->string('format', 8)->nullable();
            $blueprint->string('file_disk', 64);
            $blueprint->string('file_path');
            $blueprint->string('file_name', 255);
            $blueprint->unsignedBigInteger('file_size')->default(0);
            $blueprint->char('file_sha256', 64)->nullable();
            $blueprint->string('encoding', 16)->nullable();
            $blueprint->string('delimiter', 4)->nullable();
            $blueprint->string('delimiter_source', 8)->nullable();
            $blueprint->string('sheet', 31)->nullable();
            $blueprint->json('sheets')->nullable();
            $blueprint->boolean('date1904')->default(false);
            $blueprint->unsignedInteger('header_row')->default(1);
            $blueprint->json('headers');
            $blueprint->json('mapping')->nullable();
            $blueprint->string('mapping_source', 16)->nullable();
            $blueprint->string('mode', 16);
            $blueprint->string('locale', 16);
            $blueprint->string('timezone', 64);
            $blueprint->unsignedInteger('data_rows')->default(0);
            $blueprint->json('counts');
            $blueprint->unsignedInteger('processed_rows')->default(0);
            $blueprint->unsignedSmallInteger('progress')->default(0);
            $blueprint->unsignedInteger('preview_version')->default(0);
            $blueprint->unsignedInteger('chunk_size')->nullable();
            $blueprint->json('chunk_offsets')->nullable();
            $blueprint->string('working_copy_path')->nullable();
            // The lock: one running commit or rollback per owner and importer. NULLs are distinct
            // on every engine, so the unique index is a portable, durable, atomic lock.
            $blueprint->char('lock_key', 64)->nullable()->unique($table.'_lock_key_unique');
            $blueprint->json('failure')->nullable();
            $blueprint->dateTime('previewed_at')->nullable();
            $blueprint->dateTime('last_progress_at')->nullable();
            $blueprint->dateTime('expires_at')->nullable();
            $blueprint->dateTime('file_pruned_at')->nullable();
            $blueprint->datetimes();
            $blueprint->softDeletesDatetime();

            $blueprint->index(['owner_type', 'owner_id', 'importer', 'status'], $table.'_owner_importer_status_idx');
            $blueprint->index(['status', 'expires_at'], $table.'_status_expires_idx');
        });
    }
};
