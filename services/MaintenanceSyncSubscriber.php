<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Per-request hook for the automatic sync (see SyncScheduler), on YesWiki versions whose
 * Performer dispatches performable events.
 *
 * Core's maintenance dispatches nothing, so there is no event meaning "core just did its
 * housekeeping" to subscribe to. What is needed instead is the cheapest possible "a request
 * is being served" signal on which to ask the scheduler whether anything is due, and
 * "performable.before" is it: it fires before every action and handler, so it fires on any
 * page view whatever that page contains.
 *
 * On doryphore (4.x), where this event doesn't exist, handlers/page/__show.php does the same
 * job with that version's own hook convention. The scheduler ignores the second call, so a
 * version supporting both would still sync once.
 */
class MaintenanceSyncSubscriber implements EventSubscriberInterface
{
    private $services;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'performable.before' => 'onRequest',
        ];
    }

    public function onRequest($event = null): void
    {
        try {
            $this->services->get(SyncScheduler::class)->triggerAfterResponse();
        } catch (\Throwable $ex) {
            // an importer's scheduling must never break the page it was noticed from
        }
    }
}
