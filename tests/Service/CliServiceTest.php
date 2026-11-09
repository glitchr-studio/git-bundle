<?php

namespace Tests\Git\Service;

use Git\Engine\Git;
use Git\Service\CliService;
use Git\Service\RepositoryViewerInterface;

/** The viewer's contract (tags, tree walk, refs) - read by the git binary (the default backend). */
final class CliServiceTest extends ViewerTestCase
{
    protected static function viewer(array $repositories): RepositoryViewerInterface
    {
        return new CliService($repositories, new Git('git'));
    }
}
