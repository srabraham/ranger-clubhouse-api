<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => env('RANGER_DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
        ],

        'sqlite_testing' => [
            'driver' => 'sqlite',
            'database' => env('RANGER_DB_DATABASE_NAME', database_path('testdb.sqlite')),
            'prefix' => '',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'host' => env('RANGER_DB_HOST_NAME', 'localhost'),
            'port' => env('RANGER_DB_PORT', '3306'),
            'database' => env('RANGER_DB_DATABASE_NAME', 'rangers'),
            'username' => env('RANGER_DB_USER_NAME', 'rangers'),
            'password' => env('RANGER_DB_PASSWORD', 'donothing'),
            'unix_socket' => env('RANGER_DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'timezone' => '-07:00',
            // Turn on persistent connections.
            'options' => [
                PDO::ATTR_PERSISTENT => true
            ]
        ],

        'mysql_clone_from' => [
            'driver' => 'mysql',
            'host' => env('RANGER_DB_CLONE_FROM_HOST_NAME', 'localhost'),
            'port' => env('RANGER_DB_CLONE_FROM_PORT', '3306'),
            'database' => env('RANGER_DB_CLONE_FROM_DATABASE_NAME', 'rangers-ghd'),
            'username' => env('RANGER_DB_CLONE_FROM_USER_NAME', 'rangers'),
            'password' => env('RANGER_DB_CLONE_FROM_PASSWORD', 'donothing'),
            'unix_socket' => env('RANGER_DB_CLONE_FROM_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'timezone' => '-07:00',
            // Turn on persistent connections.
            'options' => [
                PDO::ATTR_PERSISTENT => true
            ]
        ],

        'mysql_testing' => [
            'driver' => 'mysql',
            'host' => env('RANGER_DB_HOST_NAME', 'localhost'),
            'port' => env('RANGER_DB_PORT', '3306'),
            'database' => env('RANGER_DB_DATABASE_NAME', 'rangers-test'),
            'username' => env('RANGER_DB_USER_NAME', 'rangers'),
            'password' => env('RANGER_DB_PASSWORD', 'donothing'),
            'unix_socket' => env('RANGER_DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'timezone' => '-07:00',
            // Turn on persistent connections.
            'options' => [
                PDO::ATTR_PERSISTENT => true
            ]
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer set of commands than a typical key-value systems
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    'redis' => [
        'client' => 'phpredis',

        'cache_connection' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', ''),
            'port' => env('REDIS_PORT', 6379),
            'prefix' => 'cache',
            'database' => 0,
        ],

        'lock_connection' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', ''),
            'port' => env('REDIS_PORT', 6379),
            'prefix' => 'lock',
            'database' => 0,
        ],

    ],

];
