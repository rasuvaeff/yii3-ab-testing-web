<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use Rasuvaeff\Yii3AbTesting\AssignmentStore;

/**
 * Optional extension for stores that invalidate sticky variants when an
 * experiment configuration changes.
 *
 * @api
 */
interface ConfigurationAwareAssignmentStore extends AssignmentStore
{
    public function getForConfiguration(
        string $experiment,
        string $subjectId,
        ?string $configurationId,
    ): ?string;

    public function putForConfiguration(
        string $experiment,
        string $subjectId,
        string $variant,
        ?string $configurationId,
    ): void;
}
