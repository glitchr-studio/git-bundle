<?php

// The smart HTTP bridge behind PHP's own server, for HttpBackendTest: every
// request is /<key>.git/<service>, served from GIT_ENGINE_TEST_ROOT. No access
// control here - an application puts its own in front (who may read, write).
foreach ([__DIR__.'/../../../vendor/autoload.php', __DIR__.'/../../../../../autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;
        $loader->addPsr4('Git\\', __DIR__.'/../../../src', true);
        break;
    }
}

use Git\Engine\Git;
use Git\Engine\HttpBackend;
use Git\Engine\RepositoryStore;

$path = (string) parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH);
if (!preg_match('#^/([a-z0-9-]+)\.git/(info/refs|git-upload-pack|git-receive-pack)$#', $path, $m)) {
    http_response_code(404);

    return;
}
$git = new Git('git');
$backend = new HttpBackend($git, new RepositoryStore($git, (string) getenv('GIT_ENGINE_TEST_ROOT')));
$answer = $backend->serve(
    $m[1],
    $m[2],
    $_SERVER['REQUEST_METHOD'],
    (string) ($_SERVER['QUERY_STRING'] ?? ''),
    $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? null,
    $_SERVER['HTTP_CONTENT_ENCODING'] ?? null,
    isset($_SERVER['CONTENT_LENGTH']) && '' !== $_SERVER['CONTENT_LENGTH'] ? (int) $_SERVER['CONTENT_LENGTH'] : null,
    'POST' === $_SERVER['REQUEST_METHOD'] ? fopen('php://input', 'r') : null,
    'tester',
    $_SERVER['HTTP_GIT_PROTOCOL'] ?? null,
    maxPushBytes: (int) (getenv('GIT_ENGINE_TEST_MAX_PUSH') ?: 0) ?: null,
);
http_response_code($answer->status);
foreach ($answer->headers as $name => $value) {
    header($name.': '.$value);
}
foreach ($answer->body as $chunk) {
    echo $chunk;
}
