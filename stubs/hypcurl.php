<?php

/*
 * HypCurl project file — read by `vendor/bin/hypcurl`.
 * In your own code, pass the same options to Hyphun\HypCurl\HypCurl::boot().
 */
return [
    // CLI name => your ApiClient subclass (must be autoloadable by your composer.json).
    // `vendor/bin/hypcurl make:api [Name]` generates a class and adds it here.
    'services' => [
    ],

    // Folder with default.env, {env}.env and local.env (secrets, git-ignored)
    'config_dir' => __DIR__ . '/config/hypcurl',

    // Values that override everything else (optional)
    // 'values' => ['HYPCURL_TIMEOUT' => 15],

    // Include the demo services (httpbin, jsonplaceholder)
    'examples' => true,
];
