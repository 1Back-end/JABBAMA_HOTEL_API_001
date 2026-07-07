<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL') . '/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'browser_shot' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL'),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'exportsuppliers' => [
            'driver' => 'local',
            'root' => storage_path('app/public/export-suppliers'),
            'url'    => env('APP_URL') . '/storage/export-suppliers',
            'visibility' => 'public',
        ],

        'exportorders' => [
            'driver' => 'local',
            'root' => storage_path('app/public/export-orders'),
            'url'    => env('APP_URL') . '/storage/export-orders',
            'visibility' => 'public',
        ],

        'exportsupply' => [
            'driver' => 'local',
            'root'   => storage_path('app/public/export-supply'),
            'url'    => env('APP_URL') . '/storage/export-supply',
            'visibility' => 'public',
        ],
        'exportinventory' => [
            'driver' => 'local',
            'root'   => storage_path('app/public/export-inventory'),
            'url'    => env('APP_URL') . '/storage/export-inventory',
            'visibility' => 'public',
        ],
        'exportwarehouse' => [
            'driver' => 'local',
            'root'   => storage_path('app/public/export-warehouse'),
            'url'    => env('APP_URL') . '/storage/export-warehouse',
            'visibility' => 'public',
        ],
        'exportwarehouseall' => [
            'driver' => 'local',
            'root'   => storage_path('app/public/export-warehouse-all'),
            'url'    => env('APP_URL') . '/storage/export-warehouse-all',
            'visibility' => 'public',
        ],
        'stock_adjustment' => [
            'driver' => 'local',
            'root'   => storage_path('app/public/export-stock_adjustment'),
            'url'    => env('APP_URL') . '/storage/export-stock_adjustment',
            'visibility' => 'public',
        ],
        'passations_stocks' => [
            'driver' => 'local',
            'root'   => storage_path('app/public/export-passations_stocks'),
            'url'    => env('APP_URL') . '/storage/export-passations_stocks',
            'visibility' => 'public',
        ],
        'stocks_deductions' => [
            'driver' => 'local',
            'root'   => storage_path('app/public/export-stocks_deductions'),
            'url'    => env('APP_URL') . '/storage/export-stocks_deductions',
            'visibility' => 'public',
        ],

        'orders_menus_restaurants' => [
            'driver' => 'local',
            'root'   => storage_path('app/public/export-orders-menus-restaurant'),
            'url'    => env('APP_URL') . '/storage/export-orders-menus-restaurant',
            'visibility' => 'public',
        ],



        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
