<?php

namespace Git\Warmer;

use Git\Repository\RepositorySync;

/**
 * @deprecated since 1.x, use Git\Repository\RepositorySync (`git:sync`). This
 * was a cache warmer that cloned and fetched the repositories during
 * `cache:warmup`, which then needed the network (and fetched every remote at
 * each deploy); it is no cache warmer any more, only the former name of
 * RepositorySync.
 */
class RepositoryWarmer extends RepositorySync
{
}
