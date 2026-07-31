<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

/**
 * Controls identity and sticky-assignment handling when an anonymous visitor
 * becomes authenticated.
 *
 * @api
 */
enum AnonymousToAuthenticatedStrategy
{
    /** Use the authenticated id and start browser sticky assignments afresh. */
    case UseAuthenticatedId;

    /** Keep using the anonymous browser id after authentication. */
    case KeepAnonymousId;

    /** Use the authenticated id while retaining existing browser assignments. */
    case MigrateAssignments;
}
