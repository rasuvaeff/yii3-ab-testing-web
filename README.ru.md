# rasuvaeff/yii3-ab-testing-web

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-web.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-web)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-web.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-web)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-web/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-web/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-web/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-web/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-web/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-web)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-web.svg)](LICENSE.md)
[English version](README.md)

Слой веб-идентификации и sticky-вариантов для A/B-тестирования в Yii3. Даёт
каждому посетителю стабильный subject id (чтобы детерминированное назначение
сохранялось между визитами), а при необходимости фиксирует субъекта на варианте
через подписанную cookie — даже при изменении весов.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник,
> которым можно поделиться с моделью.

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.2 (добавляет `AssignmentStore` и `Assignment::isSticky`)
- `yiisoft/cookies` ^1.2
- реализация PSR-7 (например `nyholm/psr7`) и PSR-15 стек

## Установка

```bash
composer require rasuvaeff/yii3-ab-testing-web
```

## Идентичность vs sticky-привязка

Назначение детерминировано по `subjectId` (`sha256(salt:subjectId)`), поэтому
одного лишь стабильного id достаточно, чтобы посетитель оставался в том же
варианте между визитами — никакой вариант хранить не нужно. Две разные cookie
решают две разные задачи:

| Задача | Использовать |
|---|---|
| Стабильный id для анонимных посетителей | `SubjectIdMiddleware` (cookie `ab_id`) |
| Удерживать вариант даже после изменения весов/набора вариантов | `CookieAssignmentStore` + `StickyAssignmentResolver` |

У залогиненного пользователя уже есть стабильный id (`userId`) — установите его
как атрибут запроса выше по pipeline, и middleware его не тронет.

## Middleware идентификации субъекта

Добавьте `SubjectIdMiddleware` в PSR-15 стек. Он вычисляет subject id и
предоставляет его как атрибут запроса (по умолчанию `ab.subjectId`):

1. если атрибут уже установлен (upstream auth-middleware положил туда `userId`)
   — он сохраняется, cookie не выставляется;
2. иначе переиспользуется cookie `ab_id` — только если её значение признаёт
   своим `SubjectIdGeneratorInterface` (по умолчанию — 32 шестнадцатеричных
   символа в нижнем регистре); подделанное или слишком длинное значение
   отбрасывается и генерируется заново;
3. иначе генерируется новый непрозрачный id и выставляется долговечной cookie с
   флагами `HttpOnly`, `SameSite=Lax`.

```php
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware;

$middleware = new SubjectIdMiddleware(); // defaults: cookie 'ab_id', attribute 'ab.subjectId'

// in your action/handler:
$subjectId = $request->getAttribute('ab.subjectId');
$assignment = $ab->assign(experiment: 'checkout-button', subjectId: $subjectId);
```

### Свой формат subject id

Формат id и проверка, по которой он принимается обратно из cookie, — это один
контракт, `SubjectIdGeneratorInterface`. `HexSubjectIdGenerator` по умолчанию
выдаёт 32 hex-символа в нижнем регистре и не принимает ничего другого:

```php
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdGeneratorInterface;

final readonly class PrefixedSubjectIdGenerator implements SubjectIdGeneratorInterface
{
    public function generate(): string
    {
        return 'sub_' . bin2hex(random_bytes(8));
    }

    public function isValid(string $id): bool
    {
        return preg_match('/^sub_[0-9a-f]{16}\z/', $id) === 1;
    }
}

$middleware = new SubjectIdMiddleware(idGenerator: new PrefixedSubjectIdGenerator());
```

Реализуйте обе половины, иначе middleware будет отвергать собственную cookie на
следующем же запросе, каждый раз выдавать новый id и — поскольку назначение
детерминированно зависит от subject id — перекидывать посетителя между
вариантами на каждой странице.

`isValid()` — граница безопасности, а не формальность: cookie контролируется
клиентом, и всё, что пройдёт проверку, станет subject id в ваших логах и
аналитике. Якорьте паттерн через `\z`, а не `$`: в PCRE `$` совпадает и перед
завершающим переводом строки.

Чтобы привязать id к залогиненному пользователю, генератор не нужен: upstream
middleware, выставивший атрибут `ab.subjectId`, побеждает и cookie, и генератор
(правило 1 выше) — так один человек сохраняет один вариант на всех устройствах.

Для большинства экспериментов этого достаточно.

## Sticky-варианты

Изменение весов или набора вариантов сдвигает границы бакетов и перетасовывает
субъектов. Чтобы зафиксировать субъекта при таких изменениях, резолвите через
`CookieAssignmentStore` (подписанная cookie `{experiment: variant}`) и
`StickyAssignmentResolver`. Поскольку store имеет request-scope lifetime,
оберните его в тонкое middleware, которое читает cookie, предоставляет store и
пишет её обратно:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3AbTestingWeb\CookieAssignmentStore;
use Yiisoft\Cookies\CookieSigner;

final class StickyCookieMiddleware implements MiddlewareInterface
{
    public function __construct(private CookieSigner $signer) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $store = CookieAssignmentStore::fromRequest($request, $this->signer);
        $response = $handler->handle($request->withAttribute('ab.store', $store));

        return $store->applyToResponse($response);
    }
}
```

Затем в действии:

```php
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentResolver;

$store = $request->getAttribute('ab.store');                 // CookieAssignmentStore
$resolver = new StickyAssignmentResolver($ab, $store);

$assignment = $resolver->resolve(
    experiment: 'checkout-button',
    subjectId: $request->getAttribute('ab.subjectId'),
);
// first time: assigned and stored; later: the stored variant is returned
```

`StickyAssignmentResolver` сохраняет чистоту `AbTesting::assign()`: форсированный
вариант обходит store, отключённый эксперимент возвращает fallback (kill switch
всегда побеждает и ничего не сохраняется), а сохранённый вариант, который больше
не входит в набор эксперимента, назначается заново.

## API reference

| Класс | Описание |
|---|---|
| `SubjectIdMiddleware` | PSR-15 middleware; стабильный subject id + cookie `ab_id` |
| `SubjectIdGeneratorInterface` | `generate()` + `isValid()`: формат id и проверка, принимающая его обратно |
| `HexSubjectIdGenerator` | по умолчанию: 32 hex-символа в нижнем регистре |
| `CookieAssignmentStore` | `AssignmentStore` поверх одной подписанной cookie; `fromRequest()` / `applyToResponse()` |
| `StickyAssignmentResolver` | get-or-assign поверх `AbTesting` + любого `AssignmentStore` |

## Безопасность и приватность

- Subject id — это непрозрачный 128-битный токен (`random_bytes`, не UUID), он
  не содержит персональных данных, но является постоянным идентификатором.
  Выставляйте cookie только после consent там, где этого требует закон.
- Sticky-cookie подписана (`yiisoft/cookies` `CookieSigner`); отсутствующая,
  неподписанная, подделанная или некорректная cookie игнорируется и даёт пустой
  store — никогда частичный или контролируемый злоумышленником variant map.
  Используйте сильный ключ подписи.
- По умолчанию cookie имеют атрибуты `HttpOnly`, `SameSite=Lax` и `Secure`.
- Cookie привязана к браузеру: аргумент `$subjectId` у store игнорируется.
  Посетитель, который был анонимным, а затем залогинился, сохраняет варианты,
  назначенные его анонимной идентичностью.

## Примеры

См. [examples/](examples/) — запускаемый скрипт (сервер не требуется).

## Разработка

```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run tests
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
