# rasuvaeff/yii3-ab-testing-web
[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-web.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-web)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-web.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-web)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-web/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-web/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-web/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-web/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-web/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-web)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-web.svg)](LICENSE.md)
Веб-идентичность и слой липкого варианта для A/B-тестирования Yii3. Предоставляет каждому посетителю
 стабильный идентификатор субъекта (поэтому детерминированное назначение сохраняется для всех посещений) и, когда он вам
 нужен, привязывает тему к варианту при изменении веса с помощью подписанного файла cookie.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которую вы можете использовать в контексте приглашения. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `rasuvaeff/yii3-ab-testing` ^1.2 (добавляет `AssignmentStore` и `Assignment::isSticky`)
 - `yiisoft/cookies` ^1.2
 - реализация PSR-7 (например, `nyholm/psr7`) и стек PSR-15

## Установка
```bash
composer require rasuvaeff/yii3-ab-testing-web
```
## Идентичность против липкости
Назначение является детерминированным в `subjectId` (`sha256(salt:subjectId)`), поэтому только стабильный идентификатор
 сохраняет посетителя в одном и том же варианте при каждом посещении — ни один вариант не сохраняется.
 Две роли файлов cookie решают две разные проблемы:

 | Нужна | Использование |
 |---|---|
 | Стабильный идентификатор для анонимных посетителей | `SubjectIdMiddleware` (файл cookie `ab_id`) |
 | Сохранять вариант даже после изменения весов/вариантов | `CookieAssignmentStore` + `StickyAssignmentResolver` |

 Вошедший в систему пользователь уже имеет стабильный идентификатор (`userId`) — установите его в качестве атрибута запроса
 вверх по течению, и промежуточное программное обеспечение оставит его в покое. @@ЛИНИЯ@@
## Промежуточное программное обеспечение для идентификации субъекта
Добавьте SubjectIdMiddleware в ваш стек PSR-15. Он разрешает идентификатор субъекта, и
 предоставляет его как атрибут запроса (по умолчанию `ab.subjectId`):

 1. если атрибут уже установлен (вышестоящее промежуточное программное обеспечение аутентификации помещает туда `userId`)
 он сохраняется — cookie нет;
 2. в противном случае файл cookie `ab_id` используется повторно — только если его значение соответствует сгенерированному формату (32 строчных шестнадцатеричных символа); подделанное или слишком большое значение отбрасывается и восстанавливается;
 3. в противном случае генерируется новый непрозрачный идентификатор и устанавливается долгоживущий файл cookie `HttpOnly`,
 `SameSite=Lax`. @@ЛИНИЯ@@
```php
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware;

$middleware = new SubjectIdMiddleware(); // defaults: cookie 'ab_id', attribute 'ab.subjectId'

// in your action/handler:
$subjectId = $request->getAttribute('ab.subjectId');
$assignment = $ab->assign(experiment: 'checkout-button', subjectId: $subjectId);
```
Для большинства экспериментов это все, что вам нужно. @@ЛИНИЯ@@
## Прикрепленные варианты
Изменение весов или набора вариантов смещает границы сегментов и перетасовывает субъекты
. Чтобы закрепить тему за такими изменениями, разрешите ее с помощью
 `CookieAssignmentStore` (подписанный файл cookie `{experiment:variant}`) и
 `StickyAssignmentResolver`. Поскольку хранилище ограничено запросами, подключите его к тонкому промежуточному программному обеспечению
, которое считывает файл cookie, предоставляет доступ к хранилищу и записывает его обратно:

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
Тогда в вашем действии:

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
`StickyAssignmentResolver` сохраняет `AbTesting::assign()` чистым: принудительный вариант
 обходит хранилище, отключенный эксперимент возвращает свой запасной вариант (выключатель уничтожения
 всегда побеждает и ничего не сохраняется), а сохраненный вариант, который больше не является частью
 эксперимента, переназначается. @@ЛИНИЯ@@
## Справочник по API
| Класс | Описание |
 |---|---|
 | `SubjectIdMiddleware` | промежуточное программное обеспечение PSR-15; стабильный идентификатор субъекта + файл cookie `ab_id` |
 | `CookieAssignmentStore` | `AssignmentStore` для одного подписанного файла cookie; `fromRequest()` / `applyToResponse()` |
 | `StickyAssignmentResolver` | получить или назначить через AbTesting + любой AssignmentStore | @@ЛИНИЯ@@
## Безопасность и конфиденциальность
- Идентификатор субъекта представляет собой непрозрачный 128-битный токен («random_bytes»), а не UUID, и
 не содержит личных данных, но является постоянным идентификатором. Устанавливайте файл cookie
 только после согласия, если этого требует закон.
 - Прикрепленный файл cookie подписан (`yiisoft/cookies` `CookieSigner`); отсутствующий
 неподписанный, подделанный или неправильно сформированный файл cookie игнорируется и оставляет пустое хранилище —
 никогда не является частичной или контролируемой злоумышленником вариантной картой. Предоставьте надежный ключ подписи.
 — по умолчанию файлы cookie имеют типы «HttpOnly», «SameSite=Lax» и «Secure».
 - Файл cookie распространяется на браузер: аргумент хранилища `$subjectId` игнорируется.
 Посетитель, который был анонимным и затем вошел в систему, сохраняет варианты своей анонимной личности
. @@ЛИНИЯ@@
## Примеры
См. [examples/](examples/) для работоспособного сценария (сервер не требуется). @@ЛИНИЯ@@
## Разработка
```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run tests
```
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
