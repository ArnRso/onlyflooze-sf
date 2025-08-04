<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'csv-import' => [
        'path' => './assets/csv-import.js',
        'entrypoint' => true,
    ],
    'transaction-pagination' => [
        'path' => './assets/transaction-pagination.js',
        'entrypoint' => true,
    ],
    'csv-upload' => [
        'path' => './assets/csv-upload.js',
        'entrypoint' => true,
    ],
    'csv-preview' => [
        'path' => './assets/csv-preview.js',
        'entrypoint' => true,
    ],
    'transaction-management' => [
        'path' => './assets/transaction-management.js',
        'entrypoint' => true,
    ],
    'bootstrap' => [
        'version' => '5.3.7',
    ],
    '@popperjs/core' => [
        'version' => '2.11.8',
    ],
    'bootstrap/dist/css/bootstrap.min.css' => [
        'version' => '5.3.7',
        'type' => 'css',
    ],
    '@fortawesome/fontawesome-free' => [
        'version' => '7.0.0',
    ],
    '@fortawesome/fontawesome-free/css/fontawesome.min.css' => [
        'version' => '7.0.0',
        'type' => 'css',
    ],
    '@fortawesome/fontawesome-free/css/solid.min.css' => [
        'version' => '7.0.0',
        'type' => 'css',
    ],
];
