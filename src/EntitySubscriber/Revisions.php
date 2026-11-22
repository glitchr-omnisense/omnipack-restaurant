<?php

namespace Base\Restaurant\EntitySubscriber;

use Base\Contract\Lifecycle\OnFlush;
use Base\Contract\Lifecycle\PostFlush;
use Base\Contract\Schedule;
use Base\Restaurant\Service\Revision;

/**
 * Anything of the restaurant written: the revision the pass watches grows. A listener of omnibase's neutral
 * lifecycle (an invokable of an EntitySubscriber folder, tagged by omnibridge/symfony's LifecycleListenersPass): the
 * same on every persistence - omnibridge/doctrine relays Doctrine's onFlush and postFlush to it, omnibridge/eloquent
 * dispatches its own.
 */
final class Revisions
{
    private bool $changed = false;

    public function __construct(private readonly Revision $revision)
    {
    }

    public function __invoke(object $event): void
    {
        if ($event instanceof OnFlush && $event->unitOfWork instanceof Schedule) {
            $this->changed = $this->changed || self::touches($event->unitOfWork);
        } elseif ($event instanceof PostFlush && $this->changed) {
            $this->changed = false;
            $this->revision->bump();
        }
    }

    private static function touches(Schedule $schedule): bool
    {
        foreach ([$schedule->scheduledInsertions(), $schedule->scheduledUpdates(), $schedule->scheduledDeletions()] as $entities) {
            foreach ($entities as $entity) {
                if (self::isRestaurants($entity)) {
                    return true;
                }
            }
        }
        foreach ($schedule->scheduledCollectionUpdates() as $collection) {
            if (str_starts_with($collection['entity']::class, 'Base\\Restaurant\\')) {
                return true;
            }
        }

        return false;
    }

    private static function isRestaurants(object $entity): bool
    {
        return str_starts_with($entity::class, 'Base\\Restaurant\\') || str_contains($entity::class, '\\Restaurant\\');
    }
}
