<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('database_tables_metadata', \App\Http\Controllers\DatabaseTableMetadataController::class);
