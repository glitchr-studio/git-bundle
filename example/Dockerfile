# syntax=docker/dockerfile:1
#
# Standalone, runnable demo of git/git-bundle — a bare Symfony skeleton (no
# theme, no app code) serving the bundle's own flat templates. It demonstrates
# the two ways to declare a repository:
#
#   * url + path  → cloned by git:sync (libgit2/libgit2 here)
#   * path only   → any existing local repository (this very checkout)
#
# Build FROM THE REPOSITORY ROOT (the local checkout is what gets installed —
# no network fetch of the bundle itself):
#
#   docker build -t git-bundle-demo -f example/Dockerfile .
#   docker run --rm -p 8000:8000 git-bundle-demo
#   → http://localhost:8000/git
#
# libgit2 is cloned on container start — watch git:sync stream its progress.

FROM php:8.4-cli

# --- system deps + php-git2 (libgit2 bindings, RogerGee/php-git2) -----------
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libgit2-dev \
    && rm -rf /var/lib/apt/lists/*

RUN git clone https://github.com/RogerGee/php-git2.git /usr/src/php-git2 \
    && cd /usr/src/php-git2 \
    && phpize \
    && ./configure --with-libgit2=/usr \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable git2 \
    && rm -rf /usr/src/php-git2

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# --- this checkout becomes the installed package (and a browsable repo) -----
COPY . /srv/git-bundle
RUN git config --global --add safe.directory '*'

# --- bare Symfony skeleton + the bundle from the local path -----------------
WORKDIR /srv/demo
RUN composer create-project symfony/skeleton . --no-interaction --no-progress \
    && composer require symfony/twig-bundle symfony/security-bundle --no-interaction --no-progress \
    && composer config repositories.git-bundle '{"type": "path", "url": "/srv/git-bundle", "options": {"symlink": false}}' \
    && composer require "git/git-bundle:*@dev" --no-interaction --no-progress

# --- wiring: declare repositories and import the routes ---------------------
# (Flex auto-registers the bundle in config/bundles.php on composer require.)
COPY <<'EOF' config/packages/git.yaml
git:
    route_prefix: /git
    access_role: PUBLIC_ACCESS     # demo only — use ROLE_ADMIN in real apps
    repositories:
        libgit2:
            url: 'https://github.com/libgit2/libgit2'
            path: '%kernel.project_dir%/var/repos/libgit2.git'
            label: 'libgit2/libgit2'
            description: 'The C library powering this very viewer (cloned at startup by git:sync)'
            default_branch: main
        git-bundle:
            path: '/srv/git-bundle'
            label: 'git/git-bundle'
            description: 'This bundle, browsing itself'
            default_branch: HEAD
EOF

# (No route import needed: Symfony 8 auto-registers the controller's
#  attribute routes, already prefixed via git.route_prefix.)

# composer's recipe scripts compiled the container BEFORE the config files
# above were copied — drop that stale cache so first warmup starts clean.
# Session upload progress off: php.net's mitigation for CVE-2026-109619.
RUN rm -rf var/cache \
    && echo 'session.upload_progress.enabled = 0' > /usr/local/etc/php/conf.d/zz-example.ini

EXPOSE 8000

# git:sync clones libgit2 (cache:warmup does not touch the network), then a
# plain PHP dev server serves the skeleton.
CMD ["sh", "-c", "php bin/console cache:warmup && php bin/console git:sync && php -S 0.0.0.0:8000 -t public"]
