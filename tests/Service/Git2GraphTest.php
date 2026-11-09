<?php

namespace Tests\Git\Service;

use Git\Service\Git2Service;
use Git\Service\RepositoryViewerInterface;

/** The commit graph and its JSON route - read by libgit2 (php-git2), when the extension is there. */
final class Git2GraphTest extends GraphTestCase
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
