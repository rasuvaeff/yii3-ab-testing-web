<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Reads and writes the typed subject id without scattering request attribute
 * keys through application code.
 *
 * The historical string attribute is written as well, preserving compatibility
 * with handlers that read `ab.subjectId` directly.
 *
 * @api
 */
final readonly class SubjectIdRequestAccessor
{
    private string $typedAttribute;

    public function __construct(
        private string $attribute = 'ab.subjectId',
    ) {
        $this->typedAttribute = $attribute . '.typed';
    }

    public function get(ServerRequestInterface $request): ?SubjectId
    {
        /** @var mixed $typed */
        $typed = $request->getAttribute($this->typedAttribute);

        if ($typed instanceof SubjectId) {
            return $typed;
        }

        /** @var mixed $legacy */
        $legacy = $request->getAttribute($this->attribute);

        return \is_string($legacy) && trim($legacy) !== ''
            ? new SubjectId(value: $legacy, source: SubjectIdSource::Authenticated)
            : null;
    }

    public function require(ServerRequestInterface $request): SubjectId
    {
        return $this->get($request) ?? throw new RuntimeException('Subject id is not available on the request');
    }

    public function with(ServerRequestInterface $request, SubjectId $subjectId): ServerRequestInterface
    {
        return $request
            ->withAttribute($this->typedAttribute, $subjectId)
            ->withAttribute($this->attribute, $subjectId->value);
    }
}
