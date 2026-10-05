<?php

namespace Git\Controller;

use Git\Model\CommitInfo;
use Git\Service\Git2Service;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

// The class-level prefix makes every registration path (Symfony 8's automatic
// controller-service attribute routes, or an explicit routes.php import on
// 6.x/7.x) produce identical, correctly-prefixed routes — import order stops
// mattering. The container parameter is resolved by the Router at runtime.
#[Route('%git.route_prefix%')]
class RepositoryController extends AbstractController
{
    public function __construct(
        private readonly Git2Service $git,
        private readonly string $accessRole = 'ROLE_ADMIN',
        private readonly ?string $repositoryAttribute = null,
    ) {}

    /**
     * The role first; then, when `git.repository_attribute` is set, the
     * repository itself - isGranted(<attribute>, <repository name>), so an
     * application's voter can open each client their own repositories only.
     */
    private function checkAccess(?string $repo = null): void
    {
        $this->denyAccessUnlessGranted($this->accessRole);
        if ($repo !== null && $this->repositoryAttribute !== null) {
            $this->denyAccessUnlessGranted($this->repositoryAttribute, $repo);
        }
    }

    #[Route('', name: 'git_repositories')]
    public function repositories(): Response
    {
        $this->checkAccess();
        $repos = $this->git->listRepositories();
        if ($this->repositoryAttribute !== null) {
            $repos = array_filter($repos, fn (string $name) => $this->isGranted($this->repositoryAttribute, $name), ARRAY_FILTER_USE_KEY);
        }
        return $this->render('@Git/repositories.html.twig', [
            'repos' => $repos,
        ]);
    }

    #[Route('/{repo}', name: 'git_repo_default', requirements: ['repo' => '[^/]+'])]
    public function repoDefault(string $repo): Response
    {
        $this->checkAccess($repo);
        $config = $this->git->getRepositoryConfig($repo);
        return $this->redirectToRoute('git_tree', [
            'repo' => $repo,
            'ref'  => $config['default_branch'],
            'path' => '',
        ]);
    }

    #[Route('/{repo}/tree/{ref}/{path}', name: 'git_tree', requirements: ['repo' => '[^/]+', 'ref' => '[^/]+', 'path' => '.*'], defaults: ['path' => ''])]
    public function tree(string $repo, string $ref, string $path): Response
    {
        $this->checkAccess($repo);
        $entries = $this->git->getTree($repo, $ref, $path);
        $config  = $this->git->getRepositoryConfig($repo);
        return $this->render('@Git/tree.html.twig', [
            'repo'        => $repo,
            'config'      => $config,
            'ref'         => $ref,
            'path'        => $path,
            'entries'     => $entries,
            'breadcrumbs' => $this->git->pathBreadcrumbs($path),
        ]);
    }

    #[Route('/{repo}/blob/{ref}/{path}', name: 'git_blob', requirements: ['repo' => '[^/]+', 'ref' => '[^/]+', 'path' => '.+'])]
    public function blob(string $repo, string $ref, string $path): Response
    {
        $this->checkAccess($repo);
        $blob   = $this->git->getBlob($repo, $ref, $path);
        $config = $this->git->getRepositoryConfig($repo);
        return $this->render('@Git/blob.html.twig', [
            'repo'        => $repo,
            'config'      => $config,
            'ref'         => $ref,
            'path'        => $path,
            'blob'        => $blob,
            'filename'    => basename($path),
            'breadcrumbs' => $this->git->pathBreadcrumbs($path),
        ]);
    }

    #[Route('/{repo}/log/{ref}', name: 'git_log', requirements: ['repo' => '[^/]+', 'ref' => '[^/]+'])]
    public function log(Request $request, string $repo, string $ref): Response
    {
        $this->checkAccess($repo);
        $page    = max(1, (int) $request->query->get('page', 1));
        $limit   = 30;
        $commits = $this->git->getCommitLog($repo, $ref, $limit, ($page - 1) * $limit);
        $config  = $this->git->getRepositoryConfig($repo);
        return $this->render('@Git/log.html.twig', [
            'repo'    => $repo,
            'config'  => $config,
            'ref'     => $ref,
            'commits' => $commits,
            'page'    => $page,
            'has_more'=> count($commits) === $limit,
        ]);
    }

    /**
     * The commit graph as data: every branch's history, children before their
     * parents, each commit with its parents and the references pointing at it
     * (Git2Service::getCommitGraph()) - what a page draws a graph from.
     * ?limit= (200, at most 500) and ?offset= page through it; `next` is the
     * offset of the following page, null on the last.
     */
    #[Route('/{repo}/graph.json', name: 'git_graph', requirements: ['repo' => '[^/]+'], methods: ['GET'])]
    public function graph(Request $request, string $repo): JsonResponse
    {
        $this->checkAccess($repo);
        $limit   = max(1, min(500, (int) $request->query->get('limit', 200)));
        $offset  = max(0, (int) $request->query->get('offset', 0));
        $config  = $this->git->getRepositoryConfig($repo);
        $commits = $this->git->getCommitGraph($repo, $limit, $offset);

        return new JsonResponse([
            'repository'     => $repo,
            'default_branch' => $config['default_branch'],
            'commits'        => array_map(static fn (CommitInfo $commit): array => [
                'sha'     => $commit->sha,
                'short'   => $commit->shortSha,
                'subject' => $commit->subject,
                'author'  => ['name' => $commit->authorName, 'email' => $commit->authorEmail],
                'date'    => $commit->authorDate->format(\DATE_ATOM),
                'parents' => $commit->parentShas,
                'refs'    => $commit->refs,
            ], $commits),
            'next'           => count($commits) === $limit ? $offset + $limit : null,
        ]);
    }

    #[Route('/{repo}/commit/{sha}', name: 'git_commit', requirements: ['repo' => '[^/]+', 'sha' => '[0-9a-f]{7,40}'])]
    public function commit(string $repo, string $sha): Response
    {
        $this->checkAccess($repo);
        $commit = $this->git->getCommit($repo, $sha);
        $config = $this->git->getRepositoryConfig($repo);
        return $this->render('@Git/commit.html.twig', [
            'repo'   => $repo,
            'config' => $config,
            'commit' => $commit,
        ]);
    }

    #[Route('/{repo}/branches', name: 'git_branches', requirements: ['repo' => '[^/]+'])]
    public function branches(string $repo): Response
    {
        $this->checkAccess($repo);
        $branches = $this->git->getBranches($repo);
        $config   = $this->git->getRepositoryConfig($repo);
        return $this->render('@Git/refs.html.twig', [
            'repo'     => $repo,
            'config'   => $config,
            'branches' => $branches,
            'tags'     => [],
            'active'   => 'branches',
        ]);
    }

    #[Route('/{repo}/tags', name: 'git_tags', requirements: ['repo' => '[^/]+'])]
    public function tags(string $repo): Response
    {
        $this->checkAccess($repo);
        $tags   = $this->git->getTags($repo);
        $config = $this->git->getRepositoryConfig($repo);
        return $this->render('@Git/refs.html.twig', [
            'repo'     => $repo,
            'config'   => $config,
            'branches' => [],
            'tags'     => $tags,
            'active'   => 'tags',
        ]);
    }
}
