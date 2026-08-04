<?php

/**
 * Per-request hook for the automatic sync (see SyncScheduler), on doryphore (YesWiki 4.x).
 *
 * That version has no event fired on an ordinary page view - its extensions reach into core
 * with the "__name.php" before-callback convention instead, which Performer runs before the
 * handler of the same name. "show" is the handler every page view goes through, so this is
 * the 4.x equivalent of the "performable.before" subscriber (recent core ignores this file:
 * its Performer only loads handlers defined as "NameHandler" classes).
 *
 * Runs with $this bound to the Wiki, and must print nothing.
 */

use YesWiki\Importer\Service\SyncScheduler;

try {
    $this->services->get(SyncScheduler::class)->triggerAfterResponse();
} catch (Throwable $ex) {
    // an importer's scheduling must never break the page it was noticed from
}
