<?php

namespace Tests\Git\Service;

use Git\Engine\Git;
use Git\Service\CliService;
use Git\Service\RepositoryViewerInterface;

/** The commit graph and its JSON route - read by the git binary (the default backend). */
final class CliGraphTest extends GraphTestCase
{
    protected static function viewer(array $repositories): RepositoryViewerInterface
    {
        return new CliService($repositories, new Git('git'));
    }
}
