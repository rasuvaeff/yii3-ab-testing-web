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
- `rasuvaeff/yii3-ab-testing` ^1.6 (`AssignmentResolver` и configuration ids)
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
| Удерживать вариант между запросами | `StickyAssignmentMiddleware` |

У залогиненного пользователя уже есть стабильный id (`userId`) — установите его
как атрибут запроса выше по pipeline, и middleware его не тронет.

## Middleware идентификации субъекта

Добавьте `SubjectIdMiddleware` в PSR-15 стек. Он вычисляет subject id и
предоставляет его через `SubjectIdRequestAccessor` (исторический строковый
атрибут `ab.subjectId` сохраняется для совместимости):

1. если атрибут уже установлен (upstream auth-middleware положил туда `userId`)
   — он сохраняется, cookie не выставляется;
2. иначе переиспользуется cookie `ab_id` — только если её значение признаёт
   своим `SubjectIdGeneratorInterface` (по умолчанию — 32 шестнадцатеричных
   символа в нижнем регистре); подделанное или слишком длинное значение
   отбрасывается и генерируется заново;
3. иначе генерируется новый непрозрачный id; долговечная cookie с флагами
   `HttpOnly`, `SameSite=Lax` выставляется только когда consent policy разрешает
   постоянное хранение.

```php
use Rasuvaeff\Yii3AbTestingWeb\CallbackConsentPolicy;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdRequestAccessor;

$consent = new CallbackConsentPolicy(
    static fn ($request): bool => $request->getAttribute('analyticsConsent') === true,
);
$middleware = new SubjectIdMiddleware(consentPolicy: $consent);

// in your action/handler:
$subjectId = (new SubjectIdRequestAccessor())->require($request);
$assignment = $ab->resolve(experiment: 'checkout-button', subjectId: $subjectId->value);
```

До согласия входящие identity- и assignment-cookie игнорируются, свежий id с
`SubjectIdSource::Ephemeral` живёт только в запросе, cookie не записываются.
`AllowAllConsentPolicy` остаётся default для обратной совместимости; приложения,
где требуется согласие, передают одну policy в оба middleware.

### Переход от anonymous к authenticated identity

Настройте `identityTransition`, когда auth-middleware передал `ab.subjectId`, а
в браузере уже есть anonymous-cookie `ab_id`:

| Стратегия | Subject id после входа | Существующие browser assignments |
|---|---|---|
| `MigrateAssignments` (default) | authenticated id | сохраняются |
| `UseAuthenticatedId` | authenticated id | отбрасываются и создаются заново |
| `KeepAnonymousId` | anonymous cookie id | сохраняются |

```php
use Rasuvaeff\Yii3AbTestingWeb\AnonymousToAuthenticatedStrategy;

$middleware = new SubjectIdMiddleware(
    consentPolicy: $consent,
    identityTransition: AnonymousToAuthenticatedStrategy::UseAuthenticatedId,
);
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

Используйте готовый PSR-15 `StickyAssignmentMiddleware` после
`SubjectIdMiddleware`. Он создаёт request-scoped `CookieAssignmentStore`,
предоставляет `AssignmentResolver` и применяет изменённые назначения к response:

```php
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentMiddleware;
use Yiisoft\Cookies\CookieSigner;

$identityMiddleware = new SubjectIdMiddleware(consentPolicy: $consent);
$stickyMiddleware = new StickyAssignmentMiddleware(
    resolver: $ab,
    signer: new CookieSigner($secretKey),
    consentPolicy: $consent,
    maxEntries: 50,
    maxCookieBytes: 3800,
);
```

Затем в действии:

```php
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentRequestAccessor;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdRequestAccessor;

$subjectId = (new SubjectIdRequestAccessor())->require($request);
$resolver = (new StickyAssignmentRequestAccessor())->resolver($request);

$assignment = $resolver->resolve(
    experiment: 'checkout-button',
    subjectId: $subjectId->value,
);
```

Подписанная cookie ограничена одновременно `maxEntries` и фактическим размером
заголовка `Set-Cookie` (`maxCookieBytes`). Eviction детерминированный FIFO;
обновление эксперимента делает его самым новым. Слишком большие входящие cookie
отвергаются до JSON decode. Записи несут core `configurationId`, поэтому новая
конфигурация эксперимента инвалидирует старое sticky-назначение. Формат v1 со
строковой map по-прежнему читается.

`StickyAssignmentResolver` реализует core `AssignmentResolver`. Fallback, forced
и targeting-решения возвращаются до обращения к store; отключённый эксперимент
остаётся kill switch. `AbTesting::assign()` остаётся чистым.

## API reference

| Класс | Описание |
|---|---|
| `SubjectIdMiddleware` | PSR-15 middleware; стабильный subject id + cookie `ab_id` |
| `SubjectId`, `SubjectIdSource` | типизированная identity и её anonymous/authenticated/ephemeral источник |
| `SubjectIdRequestAccessor` | типизированный доступ с совместимостью через `ab.subjectId` |
| `ConsentPolicyInterface` | решение о persistence; `CallbackConsentPolicy` адаптирует application consent |
| `AnonymousToAuthenticatedStrategy` | сохранить anonymous, использовать authenticated или мигрировать assignments |
| `SubjectIdGeneratorInterface` | `generate()` + `isValid()`: формат id и проверка, принимающая его обратно |
| `HexSubjectIdGenerator` | по умолчанию: 32 hex-символа в нижнем регистре |
| `CookieAssignmentStore` | `AssignmentStore` поверх одной подписанной cookie; `fromRequest()` / `applyToResponse()` |
| `StickyAssignmentMiddleware` | готовая PSR-15 интеграция request-scoped store и sticky resolver |
| `StickyAssignmentRequestAccessor` | типизированный доступ к resolver и store в запросе |
| `StickyAssignmentResolver` | декоратор `AssignmentResolver` поверх любого core resolver и store |

## Безопасность и приватность

- Subject id — это непрозрачный 128-битный токен (`random_bytes`, не UUID), он
  не содержит персональных данных, но является постоянным идентификатором.
  Отказ consent policy запрещает чтение и запись identity- и sticky-cookie.
- Sticky-cookie подписана (`yiisoft/cookies` `CookieSigner`); отсутствующая,
  неподписанная, подделанная или некорректная cookie игнорируется и даёт пустой
  store — никогда частичный или контролируемый злоумышленником variant map.
  Используйте сильный ключ подписи.
- По умолчанию cookie имеют атрибуты `HttpOnly`, `SameSite=Lax` и `Secure`.
- Sticky-cookie привязана к браузеру: аргумент `$subjectId` у store игнорируется.
  Выберите auth-transition явно согласно требованиям продукта.

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
