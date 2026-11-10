<?php

// A forced command in miniature, for SshBackendTest: git runs it as its "ssh"
// (GIT_SSH_COMMAND, GIT_SSH_VARIANT=simple: `<this> <host> <command>`), so the
// last argument is what sshd would put in SSH_ORIGINAL_COMMAND. The path
// "<key>" names <GIT_ENGINE_TEST_ROOT>/<key>.git. No access control here - an
// application checks who may read or write between the parse and the serve.
foreach ([__DIR__.'/../../../vendor/autoload.php', __DIR__.'/../../../../../autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;
        $loader->addPsr4('Git\\', __DIR__.'/../../../src', true);
        break;
    }
}

use Git\Engine\Git;
use Git\Engine\GitException;
use Git\Engine\RepositoryStore;
use Git\Engine\SshBackend;
use Git\Engine\SshCommand;

$original = (string) (getenv('GIT_ENGINE_TEST_COMMAND') ?: $argv[\count($argv) - 1]);
try {
    $command = SshCommand::parse($original);
    $git = new Git('git');
    $backend = new SshBackend($git, new RepositoryStore($git, (string) getenv('GIT_ENGINE_TEST_ROOT')), 60.0);
    exit($backend->serve($command, $command->path, \STDIN, \STDOUT, \STDERR, getenv('GIT_PROTOCOL') ?: null, (int) (getenv('GIT_ENGINE_TEST_MAX_PUSH') ?: 0) ?: null));
} catch (GitException $e) {
    fwrite(\STDERR, 'fatal: '.$e->getMessage()."\n");
    exit(128);
}
