<?php

namespace Tests\Git\Service;

use Git\Controller\RepositoryController;
use Git\Model\CommitInfo;
use Git\Service\Git2Service;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AccessDecision;

/**
 * The commit graph, against a real repository built with the git binary:
 *
 *     A - B ------- M - D   main (HEAD), v2 on M
 *      \         /
 *       F1 - F2             feature (merged), v1 on F1 (annotated)
 *            \
 *             W             wip (not merged)
 *
 * and a remote branch origin/main left on B.
 */
class Git2GraphTest extends TestCase
{
    private static string $dir;
    /** @var array<string, string> a commit's subject -> its sha */
    private static array $sha = [];

    public static function setUpBeforeClass(): void
    {
        if (!\extension_loaded('git2')) {
            self::markTestSkipped('The php-git2 extension is not loaded.');
        }

        self::$dir = sys_get_temp_dir().'/git-bundle-graph-'.bin2hex(random_bytes(4));
        mkdir(self::$dir, 0777, true);

        // Each commit a minute after the one before: the order by date is theirs.
        $clock = 1_700_000_000;
        $git = function (string $args) use (&$clock): string {
            $clock += 60;
            $env = sprintf('GIT_AUTHOR_NAME=Test GIT_AUTHOR_EMAIL=t@example.org GIT_COMMITTER_NAME=Test GIT_COMMITTER_EMAIL=t@example.org GIT_AUTHOR_DATE="%1$d +0000" GIT_COMMITTER_DATE="%1$d +0000"', $clock);

            return (string) shell_exec(sprintf('cd %s && %s git %s 2>&1', escapeshellarg(self::$dir), $env, $args));
        };
        $commit = function (string $subject) use ($git): void {
            file_put_contents(self::$dir.'/'.$subject.'.txt', $subject."\n");
            $git('add -A');
            $git('commit -q -m '.escapeshellarg($subject));
            self::$sha[$subject] = trim($git('rev-parse HEAD'));
        };

        $git('init -q -b main');
        $commit('A');
        $git('checkout -q -b feature');
        $commit('F1');
        $git('tag -a v1 -m "First"');
        $commit('F2');
        $git('checkout -q -b wip');
        $commit('W');
        $git('checkout -q main');
        $commit('B');
        $git('update-ref refs/remotes/origin/main HEAD');
        $git('symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/main');
        $git('merge -q --no-ff -m M feature');
        self::$sha['M'] = trim($git('rev-parse HEAD'));
        $git('tag v2');
        $commit('D');
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$dir) && is_dir(self::$dir)) {
            shell_exec('rm -rf '.escapeshellarg(self::$dir));
        }
    }

    private function service(): Git2Service
    {
        return new Git2Service(['fixture' => ['path' => self::$dir, 'label' => 'Fixture', 'description' => null, 'default_branch' => 'main']]);
    }

    public function testEveryBranchIsWalkedChildrenBeforeParents(): void
    {
        $graph = $this->service()->getCommitGraph('fixture');
        $subjects = array_map(fn (CommitInfo $commit) => $commit->subject, $graph);

        self::assertEqualsCanonicalizing(['A', 'B', 'D', 'F1', 'F2', 'M', 'W'], $subjects, 'the unmerged branch too, each commit once');
        $at = array_flip(array_map(fn (CommitInfo $commit) => $commit->sha, $graph));
        foreach ($graph as $commit) {
            foreach ($commit->parentShas as $parent) {
                self::assertLessThan($at[$parent], $at[$commit->sha], sprintf('%s comes before its parent', $commit->subject));
            }
        }
        self::assertSame('A', end($subjects), 'the root last');

        $merge = $graph[$at[self::$sha['M']]];
        self::assertSame([self::$sha['B'], self::$sha['F2']], $merge->parentShas, 'a merge has its two parents, the branch merged into first');
        self::assertSame([self::$sha['F2']], $graph[$at[self::$sha['W']]]->parentShas);
    }

    public function testEachCommitCarriesTheReferencesPointingAtIt(): void
    {
        $refs = [];
        foreach ($this->service()->getCommitGraph('fixture') as $commit) {
            $list = $commit->refs;
            usort($list, fn (array $a, array $b) => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);
            $refs[$commit->subject] = $list;
        }

        self::assertSame([['type' => 'branch', 'name' => 'main', 'head' => true]], $refs['D'], 'the checked-out branch says so');
        self::assertSame([['type' => 'tag', 'name' => 'v2']], $refs['M'], 'a lightweight tag');
        self::assertSame([['type' => 'branch', 'name' => 'feature', 'head' => false]], $refs['F2']);
        self::assertSame([['type' => 'tag', 'name' => 'v1']], $refs['F1'], 'an annotated tag, on the commit it tags');
        self::assertSame([['type' => 'branch', 'name' => 'wip', 'head' => false]], $refs['W']);
        self::assertSame([['type' => 'remote', 'name' => 'origin/main']], $refs['B'], 'a remote branch - not origin/HEAD, which only names the default one');
        self::assertSame([], $refs['A']);

        self::assertSame($refs['D'], $this->service()->getReferencesByCommit('fixture')[self::$sha['D']]);
    }

    public function testItIsReadPageByPage(): void
    {
        $all = array_map(fn (CommitInfo $commit) => $commit->sha, $this->service()->getCommitGraph('fixture'));

        $first = array_map(fn (CommitInfo $commit) => $commit->sha, $this->service()->getCommitGraph('fixture', 3));
        $then = array_map(fn (CommitInfo $commit) => $commit->sha, $this->service()->getCommitGraph('fixture', 3, 3));
        $last = array_map(fn (CommitInfo $commit) => $commit->sha, $this->service()->getCommitGraph('fixture', 3, 6));

        self::assertSame($all, array_merge($first, $then, $last));
        self::assertCount(1, $last);
        self::assertSame([], $this->service()->getCommitGraph('fixture', 3, 7));
    }

    public function testTheRouteAnswersTheGraphAsJson(): void
    {
        // AbstractController's access check is symfony/security-core's: in a host application, not in a bare checkout.
        if (!class_exists(AccessDecision::class)) {
            self::markTestSkipped('Needs symfony/security-core (a host application).');
        }

        // The controller alone, behind an authorization checker that grants everything.
        $container = new Container();
        $container->set('security.authorization_checker', new class {
            public array $asked = [];

            public function isGranted(mixed $attribute, mixed $subject = null, mixed $decision = null): bool
            {
                $this->asked[] = [$attribute, $subject];

                return true;
            }
        });
        $controller = new RepositoryController($this->service(), 'ROLE_USER', 'GIT_VIEW');
        $controller->setContainer($container);

        $response = $controller->graph(new Request(['limit' => '4']), 'fixture');
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame([['ROLE_USER', null], ['GIT_VIEW', 'fixture']], $container->get('security.authorization_checker')->asked, 'the same access rules as the pages');
        self::assertSame('fixture', $data['repository']);
        self::assertSame('main', $data['default_branch']);
        self::assertCount(4, $data['commits']);
        self::assertSame(4, $data['next'], 'a full page: there may be more');

        $head = $data['commits'][0];
        self::assertSame(['sha', 'short', 'subject', 'author', 'date', 'parents', 'refs'], array_keys($head));
        self::assertSame(self::$sha['D'], $head['sha']);
        self::assertSame(substr(self::$sha['D'], 0, 8), $head['short']);
        self::assertSame('D', $head['subject']);
        self::assertSame(['name' => 'Test', 'email' => 't@example.org'], $head['author']);
        self::assertSame([self::$sha['M']], $head['parents']);
        self::assertSame([['type' => 'branch', 'name' => 'main', 'head' => true]], $head['refs']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $head['date']));

        $rest = json_decode((string) $controller->graph(new Request(['limit' => '4', 'offset' => '4']), 'fixture')->getContent(), true);
        self::assertCount(3, $rest['commits']);
        self::assertNull($rest['next'], 'the last page');
        self::assertCount(7, array_unique(array_merge(array_column($data['commits'], 'sha'), array_column($rest['commits'], 'sha'))));

        // A limit out of bounds is brought back within them.
        self::assertCount(1, json_decode((string) $controller->graph(new Request(['limit' => '0']), 'fixture')->getContent(), true)['commits']);
    }
}
