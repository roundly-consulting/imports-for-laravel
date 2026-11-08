<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Imports\Http\Controllers\BatchesController;
use RoundlyConsulting\Imports\Http\Controllers\ImportersController;
use RoundlyConsulting\Imports\Http\Controllers\PreviewController;
use RoundlyConsulting\Imports\Http\Controllers\SessionsController;
use RoundlyConsulting\Imports\Support\ImportsConfig;

/*
 * The opt-in JSON API (`imports.routes.enabled`). Every endpoint resolves the owner per request
 * (`imports.routes.owner_resolver`) and goes through `Imports::for($owner)`, so another owner's
 * session or batch is a 404; the importer's authorize() decides the rest.
 */
Route::prefix(ImportsConfig::routesPrefix())
    ->middleware(ImportsConfig::routesMiddleware())
    ->name('imports.')
    ->group(static function (): void {
        Route::get('importers', [ImportersController::class, 'index'])->name('importers.index');
        Route::get('importers/{importer}/template', [ImportersController::class, 'template'])->name('importers.template');
        Route::get('importers/{importer}/mappings', [ImportersController::class, 'mappings'])->name('importers.mappings');
        Route::delete('importers/{importer}/mappings/{name}', [ImportersController::class, 'deleteMapping'])->name('importers.mappings.destroy');
        Route::post('importers/{importer}/sessions', [SessionsController::class, 'store'])->middleware('throttle:imports-upload')->name('sessions.store');
        Route::post('importers/{importer}/file-sessions', [SessionsController::class, 'storeFiles'])->middleware('throttle:imports-upload')->name('sessions.files');

        Route::get('sessions/{session}', [SessionsController::class, 'show'])->name('sessions.show');
        Route::patch('sessions/{session}/source', [SessionsController::class, 'source'])->name('sessions.source');
        Route::delete('sessions/{session}', [SessionsController::class, 'destroy'])->name('sessions.destroy');
        Route::get('sessions/{session}/mapping', [SessionsController::class, 'suggestion'])->name('sessions.mapping');
        Route::put('sessions/{session}/mapping', [SessionsController::class, 'map'])->name('sessions.map');
        Route::post('sessions/{session}/mapping/save', [SessionsController::class, 'saveMapping'])->name('sessions.mapping.save');

        Route::put('sessions/{session}/mode', [PreviewController::class, 'mode'])->name('sessions.mode');
        Route::post('sessions/{session}/preview', [PreviewController::class, 'run'])->name('sessions.preview.run');
        Route::get('sessions/{session}/preview', [PreviewController::class, 'show'])->name('sessions.preview');
        Route::get('sessions/{session}/rows', [PreviewController::class, 'rows'])->name('sessions.rows');
        Route::get('sessions/{session}/error-report', [PreviewController::class, 'errorReport'])->name('sessions.error-report');
        Route::post('sessions/{session}/commit', [PreviewController::class, 'commit'])->name('sessions.commit');

        Route::get('batches/{batch}', [BatchesController::class, 'show'])->name('batches.show');
        Route::post('batches/{batch}/cancel', [BatchesController::class, 'cancel'])->name('batches.cancel');
        Route::post('batches/{batch}/resume', [BatchesController::class, 'resume'])->name('batches.resume');
        Route::post('batches/{batch}/rollback', [BatchesController::class, 'rollback'])->name('batches.rollback');
        Route::get('batches/{batch}/records', [BatchesController::class, 'records'])->name('batches.records');
    });
