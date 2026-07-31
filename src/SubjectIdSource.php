<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

/**
 * @api
 */
enum SubjectIdSource
{
    case Anonymous;
    case Authenticated;
    case Ephemeral;
}
