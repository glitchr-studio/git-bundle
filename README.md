# Git Bundle

A **git repository engine and viewer** for PHP. Two parts, one package:

- **The engine** (`Git\Engine\`, framework-free): bare repositories read,
  written and merged through the `git` binary, git's smart HTTP protocol
  served through `git http-backend`, git over SSH served from a forced
  command. It needs symfony/process,
  symfony/filesystem and symfony/lock, nothing else - no framework, no
  database, no notion of accounts.
- **The viewer** (`Git\Service\`, a Symfony bundle on top): browse trees,
  blobs, history, diffs, branches, tags and the commit graph of named
  repositories from your own site, behind your own security layer. It reads
  through one interface, `RepositoryViewerInterface`, with two backends: the
  git binary (`CliService`, the default) or libgit2 through the php-git2
  extension (`Git2Service`).

## Philosophy

- **The git binary, held at arm's length.** Every command runs through
  symfony/process with an argument array - never a shell string - a bounded
  time and a bounded output, no system or global configuration, no prompt
  (`Git\Engine\Git`). Every ref, revision and path a caller hands over is
  checked first (`Git\Engine\Names`: no leading `-`, no `..`, git's own ref
  rules); paths are only resolved through git's objects (`rev:path`), never
  through the filesystem.
- **Access control is yours.** The engine decides nothing about who may read
  or push: the application puts its firewall and its voters in front of
  `HttpBackend`, `SshBackend` and the writes. The viewer's routes are gated by a role and,
  optionally, a per-repository attribute.
- **libgit2 as an alternative.** `git.backend: libgit2` reads through php-git2
  in-process instead; the answers are the same (the tests run the same
  contract against both).
- **Repositories are declarative** for the viewer. You list repositories in
  config; anything declared with a `url` is cloned (a bare mirror) when its
  `path` does not exist yet, and fetched (`--all --prune`) otherwise, by
  `bin/console git:sync`. `cache:warmup` never talks to a remote.

## The engine

```php
use Git\Engine\Git;
use Git\Engine\HttpBackend;
use Git\Engine\Model\Person;
use Git\Engine\RepositoryLocks;
use Git\Engine\RepositoryStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

$git = new Git('/usr/bin/git', timeout: 30.0);
$store = new RepositoryStore($git, '/srv/repositories');   // <root>/<key>.git, the key an id of yours

$repository = $store->create('42');                        // git init --bare, main
$repository->commitFiles('main', ['README.md' => "# Hello\n"], 'First', new Person('Ada', 'ada@example.org', new DateTimeImmutable()));

$repository->tree('main', 'src');          // list<TreeEntry>, folders first
$repository->blob('main', 'README.md');    // Blob: content cut at a limit, binary or not
$repository->log('main', limit: 30);       // list<Commit>
$repository->commitDiff($id);              // Diff: files, hunks, numbered lines
$repository->diff('main', 'topic');        // what topic brings (three dots)
$repository->blame('main', 'src/a.php');   // list<BlameLine>
$repository->grep('main', 'needle');       // list<GrepMatch>, fixed string, case-insensitive

// A merge made on the server: merge-tree (git's own "ort" merge), commit-tree,
// then update-ref with the old value - inside the repository's lock.
$locks = new RepositoryLocks(new LockFactory(new FlockStore()));
$result = $locks->merge($repository, '42', 'main', 'refs/heads/topic', "Merge topic\n", $by);
$result->isMerged() ? $result->commit : $result->conflicts;   // the files in conflict, nothing moved
```

Smart HTTP (`git clone|fetch|push https://host/<owner>/<name>.git`): your
controller decides who may read (upload-pack) or write (receive-pack), then
hands the request to the bridge, which runs `git http-backend` as a CGI
program and gives the headers first, the body as it comes:

```php
$answer = (new HttpBackend($git, $store))->serve(
    key: '42', service: 'git-receive-pack',      // or info/refs, git-upload-pack
    method: 'POST', query: '', contentType: $type, contentEncoding: $encoding,
    contentLength: $length, input: fopen('php://input', 'r'),
    remoteUser: 'ada', gitProtocol: $gitProtocolHeader,
    maxPushBytes: 50_000_000,                    // git's own receive.maxInputSize
);
// $answer->status, $answer->headers, foreach ($answer->body as $chunk) echo $chunk;
```

Only the smart protocol is served. `tests/Engine/Fixtures/http-router.php` is
a complete (access-free) example behind PHP's own server.

Over SSH (`git clone git@host:<owner>/<name>.git`): the SSH server finds
the account a key belongs to and forces a command for it (OpenSSH's
`AuthorizedKeysCommand` printing `command="<your program> <key id>",restrict
<key>`). That program reads what the client asked for from
`SSH_ORIGINAL_COMMAND`, decides who may read or write, then hands its own
standard streams to the bridge, which runs `git upload-pack`, `receive-pack`
or `upload-archive` on the repository (an argument array, no shell; the pack
goes straight between git and sshd) and returns git's exit code:

```php
use Git\Engine\GitException;
use Git\Engine\SshBackend;
use Git\Engine\SshCommand;

try {
    // git-upload-pack 'owner/name.git' -> service + "owner/name"; anything else throws:
    // another program, a second argument, "..", a segment starting with "-", a quote...
    $command = SshCommand::parse((string) getenv('SSH_ORIGINAL_COMMAND'));
} catch (GitException) {
    fwrite(STDERR, "fatal: only git is served here\n");
    exit(128);
}
[$owner, $name] = $command->segments();       // yours to map, check and refuse
$command->isWrite();                          // git-receive-pack: a push

exit((new SshBackend($git, $store))->serve(
    $command, key: '42', input: STDIN, output: STDOUT, error: STDERR,
    gitProtocol: getenv('GIT_PROTOCOL') ?: null, // protocol v2 (sshd: AcceptEnv GIT_PROTOCOL)
    maxPushBytes: 50_000_000,                    // git's own receive.maxInputSize
));
```

When a web server and the SSH server's user both write the repositories,
create them group-shared: `new RepositoryStore($git, $root, shared: 'group')`
(`git init --shared=group`). `tests/Engine/Fixtures/ssh-serve.php` is a
complete (access-free) forced command, run by the git client as its `ssh`.

## Try it in one command

A self-contained demo of the viewer (bare Symfony skeleton + this bundle,
browsing the libgit2 repository itself) ships in [example/Dockerfile](example/Dockerfile):

```bash
docker build -t git-bundle-demo -f example/Dockerfile .
docker run --rm -p 8000:8000 git-bundle-demo
# → http://localhost:8000/git   (libgit2 is cloned at startup by git:sync)
```

## Requirements

- PHP ≥ 8.1 and the `git` binary (2.38 or later: merges use `merge-tree --write-tree`).
- Optionally, for `git.backend: libgit2`, the **php-git2 extension** (PHP
  bindings for the libgit2 C library). It is not bundled with PHP; install
  libgit2 then build the extension:

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
    backend: cli                # the viewer reads with the git binary (cli) or libgit2 (php-git2)
    engine:
        binary: git             # the git binary of the engine and of the cli backend
        timeout: 30             # seconds a command may take
    route_prefix: /git          # URL prefix for all viewer routes
    access_role: ROLE_ADMIN     # role required to browse (PUBLIC_ACCESS to open up)
    repositories:
        hellogitworld:
            url:  'https://github.com/githubtraining/hellogitworld'   # cloned by git:sync
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
queried lazily, once per request.

`bin/console git:sync [name…]` clones or fetches the repositories that have a
`url` - all of them, or those named. It is the only command of the bundle that
talks to a remote: run it at a deploy, and from a cron line to keep the mirrors
fresh and to clone the repositories a provider adds.

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

`Git\Service\RepositoryViewerInterface` (service `git.viewer`, the backend
`git.backend` chose) is public and autowirable; `Git\Service\CliService` and
`Git\Service\Git2Service` are its two implementations, and `Git\Engine\Git`
(service `git.engine`) the configured binary:

- `getCommitLog()`, `getCommit()`, `getTree()`, `getBlob()`, `getBranches()`, `getTags()`: the viewer's reads
- `getTag($repo, $name)`: the commit a tag points at, plus the message and date of an annotated tag
- `walkTree($repo, $ref, $path = '')`: every file of a tree, as a generator `path => [content, filemode]`, read from the object database. Good for building an archive of a release.

An unknown ref or tag is a `NotFoundHttpException` (404).

## Tests

```bash
vendor/bin/phpunit   # needs the git binary; the libgit2 contract runs when php-git2 is loaded, skipped otherwise
```

## Notes

- Cloning and fetching happen in `git:sync` (`Git\Repository\RepositorySync`),
  never in `cache:warmup`: until 2026-10 a cache warmer (`RepositoryWarmer`)
  did it, so every warmup needed the network and fetched every remote. The
  class remains as a deprecated name of `RepositorySync`, no longer a warmer.
  A large remote takes a while to mirror: prefer bare mirrors on fast storage.
- Licensed MIT (`LICENSE`) since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
