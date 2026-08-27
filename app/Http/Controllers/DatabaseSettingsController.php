<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DatabaseSettingsController extends Controller
{
    public function index()
    {
        try {
            $databaseName = config('database.connections.mysql.database');

            $tables = DB::select('SHOW TABLES');

            $tableNames = array_map(function ($table) use ($databaseName) {
                $key = 'Tables_in_' . $databaseName;
                return $table->$key ?? reset($table);
            }, $tables);

            return response()->json([
                'success' => true,
                'database' => $databaseName,
                'count' => count($tableNames),
                'tables' => $tableNames
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des tables : ' . $e->getMessage()
            ], 500);
        }
    }
}
