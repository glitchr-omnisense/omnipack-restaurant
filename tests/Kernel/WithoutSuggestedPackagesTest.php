<?php

namespace Base\Restaurant\Tests\Kernel;

use Base\Contract\Lifecycle\OnFlush;
use Base\Contract\Lifecycle\PostFlush;
use Base\Contract\Schedule;
use Base\Contract\UnitOfWork;
use Base\Restaurant\EntitySubscriber\Revisions;
use Base\Restaurant\Service\Revision;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * omnipack/restaurant 3.0 presumes no persistence: omnibridge/doctrine is suggested, the bundle names no Doctrine
 * class - the revision grows on omnibase's neutral lifecycle, a platform's repeated event is caught as the core's
 * UniqueConstraintViolation. omnipack/marketplace, the family it is built on, stays required.
 */
final class WithoutSuggestedPackagesTest extends TestCase
{
    private static function composer(): array
    {
        return json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testThePersistenceIsSuggestedNotRequired(): void
    {
        $composer = self::composer();
        self::assertArrayNotHasKey('omnibridge/doctrine', $composer['require'], 'not required');
        self::assertArrayHasKey('omnibridge/doctrine', $composer['suggest'], 'suggested');
        self::assertArrayHasKey('omnibridge/doctrine', $composer['require-dev'], 'there for the bundle\'s own suite');
        self::assertArrayHasKey('omnipack/marketplace', $composer['require'], 'the family it is built on stays');
    }

    public function testTheSourceNamesNoPersistence(): void
    {
        $named = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('php' === $file->getExtension() && preg_match_all('/^use (Doctrine\\\\(?:ORM|DBAL|Persistence|Bundle)\\\\|Omnibridge\\\\Doctrine\\\\)\S+;/m', (string) file_get_contents($file->getPathname()), $m)) {
                $named[] = $file->getFilename().': '.implode(', ', $m[0]);
            }
        }
        self::assertSame([], $named);
    }

    public function testTheRevisionGrowsWhenAnythingOfTheRestaurantIsWritten(): void
    {
        $revision = new Revision(new ArrayAdapter());
        $before = $revision->current();
        $listener = new Revisions($revision);

        $other = $this->schedule(insertions: [new \stdClass()]);
        $listener(new OnFlush($other));
        $listener(new PostFlush($other));
        self::assertSame($before, $revision->current(), 'nothing of the restaurant');

        $floor = $this->schedule(collections: [['entity' => new \Base\Restaurant\Entity\Table(), 'field' => 'x', 'added' => [], 'removed' => []]]);
        $listener(new OnFlush($floor));
        self::assertSame($before, $revision->current(), 'grown after the flush only');
        usleep(2000);
        $listener(new PostFlush($floor));
        self::assertGreaterThan($before, $revision->current());
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheBundleBootsWithItsCoreOnly(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Needs a host application (its kernel).');
        }
        $kernel = new WithoutSuggestedPackagesKernel('test', true);
        ob_start(); // omnibase's cache warmers say what they warm
        try {
            $kernel->boot();
        } finally {
            ob_end_clean();
        }
        try {
            $container = $kernel->getContainer();
            if (!$container->hasParameter('restaurant.staff_role')) {
                self::markTestSkipped('The host application does not load omnipack/restaurant.');
            }
            self::assertInstanceOf(Revisions::class, $container->get('test.service_container')->get(Revisions::class));
        } finally {
            $kernel->shutdown();
            (new \Symfony\Component\Filesystem\Filesystem())->remove($kernel->getCacheDir());
        }
    }

    private function schedule(array $insertions = [], array $collections = []): UnitOfWork&Schedule
    {
        $schedule = $this->createStubForIntersectionOfInterfaces([UnitOfWork::class, Schedule::class]);
        $schedule->method('scheduledInsertions')->willReturn($insertions);
        $schedule->method('scheduledUpdates')->willReturn([]);
        $schedule->method('scheduledDeletions')->willReturn([]);
        $schedule->method('scheduledCollectionUpdates')->willReturn($collections);

        return $schedule;
    }
}
