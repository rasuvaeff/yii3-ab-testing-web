<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingWeb\Tests;

use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3AbTestingWeb\SubjectId;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdRequestAccessor;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdSource;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SubjectIdRequestAccessor::class)]
final class SubjectIdRequestAccessorTest
{
    public function writesTypedAndLegacyRepresentations(): void
    {
        $accessor = new SubjectIdRequestAccessor();
        $subjectId = new SubjectId(value: 'anon-1', source: SubjectIdSource::Anonymous);
        $request = $accessor->with(new ServerRequest('GET', '/'), $subjectId);

        Assert::same($accessor->require($request), $subjectId);
        Assert::same($request->getAttribute('ab.subjectId'), 'anon-1');
    }

    public function readsLegacyAttributeAsAuthenticatedIdentity(): void
    {
        $request = (new ServerRequest('GET', '/'))->withAttribute('subject', 'user-7');
        $subjectId = (new SubjectIdRequestAccessor(attribute: 'subject'))->require($request);

        Assert::same($subjectId->value, 'user-7');
        Assert::same($subjectId->source, SubjectIdSource::Authenticated);
    }

    public function getReturnsNullWhenMissing(): void
    {
        Assert::null((new SubjectIdRequestAccessor())->get(new ServerRequest('GET', '/')));
    }

    public function requireThrowsWhenMissing(): void
    {
        Expect::exception(RuntimeException::class)->withMessage('Subject id is not available on the request');

        (new SubjectIdRequestAccessor())->require(new ServerRequest('GET', '/'));
    }
}
