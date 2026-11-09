<?php

namespace Tests\Git\Service;

use Git\Service\Git2Service;
use Git\Service\RepositoryViewerInterface;

/** The viewer's contract (tags, tree walk, refs) - read by libgit2 (php-git2), when the extension is there. */
final class Git2ServiceTest extends ViewerTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\extension_loaded('git2')) {
            self::markTestSkipped('The php-git2 extension is not loaded.');
        }
        parent::setUpBeforeClass();
    }

    protected static function viewer(array $repositories): RepositoryViewerInterface
    {
        return new Git2Service($repositories);
    }
}
