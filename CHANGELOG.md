# Changelog

## Unreleased

## [1.0.0] - 2026-11-13

The first stable release. Until now the package was installed from its `1.x` branch (`1.x-dev`); `^1.0` now
resolves at the default stability. Tags are `vX.Y.Z`.

### What 1.0.0 holds

- The engine (`Git\Engine\`, framework-free): bare repositories created, read, written and merged through the `git`
  binary (symfony/process, an argument array, bounded time and output), every ref, revision and path checked by
  `Names`; merges inside a per-repository lock (symfony/lock); git's smart HTTP served through `git http-backend`
  (`HttpBackend`) and git over SSH from a forced command (`SshCommand`, `SshBackend`).
- The viewer (a Symfony bundle): trees, blobs, history, diffs, branches, tags and the commit graph (`/{repo}/graph`,
  `/{repo}/graph.json`) of the repositories configured under `git.repositories` or added by a
  `RepositoryProviderInterface`, read through `RepositoryViewerInterface` with the git binary (`CliService`, the
  default) or libgit2 (`Git2Service`, the php-git2 extension); access by `git.access_role` and, optionally, a
  per-repository attribute.
- `bin/console git:sync` clones or fetches the repositories that have a `url`; `cache:warmup` never talks to a remote
  (`RepositoryWarmer` remains as a deprecated name of `RepositorySync`).
- MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.

### Changed

- Symfony `^6.4|^7.0|^8.0` for every component (some said `^6.0`, but the controller's
  `Symfony\Component\Routing\Attribute\Route` and the `attribute` route type need 6.2 or later); PHP `^8.1` unchanged.
- `symfony/console` (`git:sync`) and `symfony/http-foundation` (the controller) are required instead of reached
  through other packages; `symfony/security-bundle` is suggested: the viewer's routes check access through it.
- `extra.branch-alias`: `dev-1.x` is `1.x-dev`.

### Added

- CI on glitchr-studio/ci's shared workflows (`ci.yml`: PHPUnit, PHPStan at level max against
  `phpstan-baseline.neon`, a consumer install; `release.yml` on a `v*` tag).
- `symfony/security-core` for development: the controller's tests (the graph as JSON and as a page) run instead of
  being skipped.
