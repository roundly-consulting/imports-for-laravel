<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ulid_teams', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('uuid_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->ulid('team_id');
            $table->string('code');
            $table->string('name');
            $table->timestamps();
        });
    }
};
