# Git Bundle

A GitHub-like, **read-only git repository viewer** embedded in your Symfony
application — browse trees, blobs, commit history, diffs, branches and tags of
any local repository from your own site, behind your own security layer.

## Philosophy

- **In-process, powered by libgit2.** All repository reads go through
  [libgit2](https://libgit2.org) via the [php-git2](https://github.com/RogerGee/php-git2)
  PHP extension. Requests never shell out to the `git` binary and never talk to
  an external service (no gitweb/cgit/GitLab needed): a repository is treated
  as a plain data source, opened and object-read directly by PHP.
- **Read-only by construction.** The bundle exposes lookups only (trees, blobs,
  commits, refs). Nothing writes to the repositories.
- **Safe by object addressing.** Paths in URLs are resolved through git tree
  *objects*, never through the filesystem — path traversal outside the
  repository is impossible by design. Every route is additionally gated by a
  configurable role.
- **Repositories are declarative.** You list repositories in config; anything
  declared with a `url` whose `path` does not exist yet is **cloned
  automatically** during `cache:warmup`, and fetched (`--all --prune`) on later
  warmups. Deploying a new viewer is: add 4 lines of YAML, warm the cache.

## Try it in one command

A self-contained demo (bare Symfony skeleton + this bundle, browsing the
libgit2 repository itself) ships in [example/Dockerfile](example/Dockerfile):

```bash
docker build -t git-bundle-demo -f example/Dockerfile .
docker run --rm -p 8000:8000 git-bundle-demo
# → http://localhost:8000/git   (libgit2 is auto-cloned at startup by the warmer)
```

## Requirements

- PHP ≥ 8.1
- The **php-git2 extension** (PHP bindings for the libgit2 C library). It is
  not bundled with PHP; install libgit2 then build the extension:

  ```bash
  apt install libgit2-dev            # or brew install libgit2
  git clone https://github.com/RogerGee/php-git2 && cd php-git2
  phpize && ./configure --with-libgit2=/usr && make && make install
  docker-php-ext-enable git2        # or add "extension=git2.so" to php.ini
  ```

## Installation

```bash
composer require git/git-bundle:^1.0
```

Enable it (Flex usually does this) in `config/bundles.php`:

```php
Git\GitBundle::class => ['all' => true],
```

## Configuration

`config/packages/git.yaml`:

```yaml
git:
    route_prefix: /git          # URL prefix for all viewer routes
    access_role: ROLE_ADMIN     # role required to browse (PUBLIC_ACCESS to open up)
    repositories:
        hellogitworld:
            url:  'https://github.com/githubtraining/hellogitworld'   # auto-cloned at cache:warmup
            path: '%kernel.project_dir%/var/repos/hellogitworld.git'
            label: 'Hello Git World'
            description: 'Demo repository'
            default_branch: master
        app:
            path: '%kernel.project_dir%'    # any existing local repo works too
            label: 'This application'
            default_branch: HEAD
```

Routing: on Symfony ≥ 7.3 the controller's attribute routes are registered
automatically (the URL prefix comes from `git.route_prefix` via a class-level
`#[Route]`). On older versions import them once in `config/routes/git.yaml`:

```yaml
git:
    resource: '@GitBundle/Resources/config/routes.php'
```

Do **not** add an import `prefix:` — the routes are already prefixed.

## Routes

| URL | View |
|-----|------|
| `/git` | repository index |
| `/git/{repo}` | redirect to the default branch tree |
| `/git/{repo}/tree/{ref}/{path}` | directory listing |
| `/git/{repo}/blob/{ref}/{path}` | file contents |
| `/git/{repo}/log/{ref}` | commit history (paginated) |
| `/git/{repo}/commit/{sha}` | commit details + diff |
| `/git/{repo}/branches`, `/git/{repo}/tags` | refs |
| `/git/{repo}/graph.json` | the commit graph, as JSON (see below) |

### The commit graph

`/git/{repo}/graph.json` (route `git_graph`) answers the history of every
branch at once, for a page that draws it: the commits reachable from any
branch, tag or remote branch, children before their parents, each with its
parents and the references pointing at it.

```json
{
    "repository": "app",
    "default_branch": "main",
    "commits": [
        {
            "sha": "5d3c…", "short": "5d3c1f0a", "subject": "Merge feature",
            "author": {"name": "Ada", "email": "ada@example.org"}, "date": "2026-10-05T14:02:11+00:00",
            "parents": ["91ab…", "c07e…"],
            "refs": [{"type": "branch", "name": "main", "head": true}, {"type": "tag", "name": "v1.2.0"}]
        }
    ],
    "next": null
}
```

`?limit=` (200 by default, 500 at most) and `?offset=` page through it; `next`
is the offset of the following page, `null` on the last. A reference's `type`
is `branch`, `remote`, `tag` or `head` (a detached HEAD); the checked-out
branch carries `head: true`. The same access rules apply as to the pages.

In PHP: `Git2Service::getCommitGraph($repo, $limit, $offset)` returns the
`CommitInfo`s, their `refs` filled; `getReferencesByCommit($repo)` the
references alone, by commit.

## Repositories from your application

Beyond `git.repositories`, any service implementing
`Git\Repository\RepositoryProviderInterface` adds repositories. The interface
is autoconfigured with the `git.repository_provider` tag. A typical provider
returns the repositories of an application's own entities: its clients'
projects, the software it publishes. Each entry has the same shape as a
configured one:

```php
final class ProjectRepositories implements RepositoryProviderInterface
{
    public function getRepositories(): array
    {
        return ['acme-shop' => ['path' => '/srv/repos/acme-shop.git', 'url' => 'git@…', 'label' => 'Acme shop']];
    }
}
```

When a name exists in both, the configured repository wins. Providers are
queried lazily, once per request. The warmer tolerates a provider that fails
(no database while an image builds).

`bin/console git:sync [name…]` clones or fetches the repositories that have a
`url`, which is what the warmer does, but on demand. It is worth a cron line
when repositories come from a provider.

## One repository at a time

`access_role` lets a user into the viewer. Setting `repository_attribute`
adds a check on each repository, `isGranted(<attribute>, <repository name>)`,
so a voter can grant each user their own repositories:

```yaml
git:
    access_role: ROLE_USER
    repository_attribute: GIT_VIEW
```

The repository index lists only the granted ones.

## Service API

`Git\Service\Git2Service` is public and autowirable:

- `getCommitLog()`, `getCommit()`, `getTree()`, `getBlob()`, `getBranches()`, `getTags()`: the viewer's reads
- `getTag($repo, $name)`: the commit a tag points at, plus the message and date of an annotated tag
- `walkTree($repo, $ref, $path = '')`: every file of a tree, as a generator `path => [content, filemode]`, read from the object database. Good for building an archive of a release.

An unknown ref or tag is a `NotFoundHttpException` (404).

## Tests

```bash
vendor/bin/phpunit   # needs php-git2 and the git binary (fixtures are built with it)
```

## Notes

- The auto-clone/fetch happens in a **cache warmer** (`RepositoryWarmer`,
  optional): a slow remote can slow down `cache:warmup`, so prefer bare
  mirrors on fast storage for large repositories.
- Licensed LGPL-3.0-or-later (see `COPYING` / `COPYING.LESSER`).
