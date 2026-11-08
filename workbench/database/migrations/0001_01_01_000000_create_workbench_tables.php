<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The workbench app's tables (teams, users, vendors, assets, invitations, documents) and Laravel's
 * own queue tables — job batches, jobs and failed jobs — which a host creates with
 * `make:queue-batches-table` / `make:queue-table`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('is_admin')->default(true);
            $table->timestamps();
        });

        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id');
            $table->string('name');
            $table->string('vat_id')->nullable();
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id');
            $table->string('asset_tag')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('name', 120);
            $table->string('status', 16)->default('active');
            $table->date('purchased_on')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->boolean('insured')->default(false);
            $table->foreignId('vendor_id')->nullable();
            $table->foreignId('assignee_id')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'serial_number']);
        });

        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id');
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role', 16);
            $table->string('status', 16)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id');
            $table->string('name');
            $table->string('path');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }
};
