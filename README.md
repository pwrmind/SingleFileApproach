# PHP ADR Monolith

> Однофайловый каркас на PHP 8.0+ для **LLM-Driven Development**: Vertical Slices, паттерн ADR (Action–Domain–Response), встроенный CI, вынесенные View и опциональный JSON API.

---

## Содержание

- [Зачем это нужно](#зачем-это-нужно)
- [Философия](#философия)
- [Архитектура](#архитектура)
- [Структура проекта](#структура-проекта)
- [Быстрый старт](#быстрый-старт)
- [Как это работает](#как-это-работает)
- [Добавление новой фичи](#добавление-новой-фичи)
- [Встроенное тестирование](#встроенное-тестирование)
- [JSON API](#json-api)
- [Безопасность](#безопасность)
- [Ограничения](#ограничения)
- [Лицензия](#лицензия)

---

## Зачем это нужно

Классические архитектуры (Clean, Onion, Layered, MVC) плохо уживаются с LLM-Driven Development:

- LLM теряет контекст, когда логика размазана по десяткам файлов.
- Модель галлюцинирует, когда нет жёсткого шаблона для генерации.
- Human-in-the-loop становится обязательным, но проверять удобно только то, что читается целиком.

Этот каркас решает три задачи одновременно:

1. **Весь код приложения — в одном `index.php`.** LLM может прочитать его целиком в одном контекстном окне.
2. **Жёсткий каркас ADR** через абстрактный класс `BaseAdrSlice`. Модель физически не может «слить» логику в один метод или перепутать слои.
3. **Верстка вынесена в `views/*.phtml`.** Человеку удобно править HTML, а не собирать его из строк в PHP.

---

## Философия

**Vertical Slices.** Код делится не по техническим слоям (`Controllers/`, `Services/`, `Repositories/`), а по бизнес-фичам (`catalog`, `login`, `publish`, `logout`). Каждая фича — независимый анонимный класс в общем массиве `$features`.

**ADR (Action–Domain–Response).** Внутри каждой фичи:

| Слой | Ответственность |
|---|---|
| **Action** | Принимает HTTP-запрос, проверяет CSRF, оркестрирует вызовы |
| **Domain** | Чистая бизнес-логика. Не знает про HTTP, сессии, HTML |
| **Response** | Решает, что вернуть: HTML, JSON или редирект |

**Жёсткий каркас.** `BaseAdrSlice::__invoke()` объявлен `final`. Модель обязана реализовать `domain()`, `response()` и `runTests()` — иначе PHP не скомпилирует класс.

**Тесты внутри фичи.** `runTests()` живёт рядом с логикой, использует `clone $db` и mock-сессии, работает в изоляции.

---

## Архитектура

```
                     ┌──────────────────────────────────┐
                     │         HTTP / CLI               │
                     └────────────────┬─────────────────┘
                                      │
                     ┌────────────────▼─────────────────┐
                     │       Runtime (index.php)        │
                     │  роутинг, CI-режим, $_SESSION    │
                     └────────────────┬─────────────────┘
                                      │
                     ┌────────────────▼─────────────────┐
                     │   BaseAdrSlice::__invoke()       │  ← final, не переопределить
                     │   CSRF → domain() → response()   │
                     └────────────────┬─────────────────┘
                                      │
        ┌─────────────────────────────┼─────────────────────────────┐
        │                             │                             │
        ▼                             ▼                             ▼
   ┌─────────┐                  ┌─────────┐                  ┌─────────┐
   │ catalog │                  │  login  │                  │ publish │
   │ domain  │                  │ domain  │                  │ domain  │
   │ response│                  │ response│                  │ response│
   │ tests   │                  │ tests   │                  │ tests   │
   └────┬────┘                  └────┬────┘                  └────┬────┘
        │                             │                             │
        ▼                             ▼                             ▼
   ┌─────────┐                  ┌─────────┐                  ┌─────────┐
   │ catalog │                  │  login  │                  │ publish │
   │ .phtml  │                  │ .phtml  │                  │ .phtml  │
   └─────────┘                  └─────────┘                  └─────────┘
```

---

## Структура проекта

```
.
├── index.php                # Инфраструктура + ADR-конвейер + все фичи
└── views/
    ├── _layout.phtml        # Общий каркас сайта (nav, стили)
    ├── catalog.phtml        # Верстка каталога
    ├── login.phtml          # Форма входа
    ├── publish.phtml        # Форма публикации
    └── error.phtml          # Страница ошибок (403 / 404 / 405 / 500)
```

Подчёркивание в `_layout.phtml` — соглашение: служебные шаблоны сортируются вверху списка.

---

## Быстрый старт

**Требования:** PHP 8.0+, расширения `json`, `mbstring`, `session`.

```bash
git clone <repo-url> php-adr-monolith
cd php-adr-monolith

# Тесты
php index.php --run-ci

# Веб-сервер
php -S localhost:8000 index.php
```

Открыть в браузере:

- Каталог: <http://localhost:8000/?action=catalog>
- Вход: <http://localhost:8000/?action=login> — `dev@store.com` / `123`
- Публикация: <http://localhost:8000/?action=publish>
- JSON: <http://localhost:8000/?action=catalog&format=json>
- CI: <http://localhost:8000/?run_ci=1> (только при `APP_ENV=dev`)

---

## Как это работает

### 1. Роутинг

```php
$action = $_GET['action'] ?? 'catalog';

if (isset($features[$action])) {
    $requestPayload = [
        'METHOD' => $_SERVER['REQUEST_METHOD'],
        'GET'    => $_GET,
        'POST'   => $_POST,
    ];
    echo $features[$action]($db, $requestPayload);
}
```

Каждая фича — анонимный класс в массиве `$features`. Ключ — значение `?action=...`.

### 2. Конвейер ADR

```php
final public function __invoke(Db $db, array $request): string
{
    // 1. CSRF-мидлварь для всех POST
    if (($request['METHOD'] ?? 'GET') === 'POST') {
        if (!Csrf::verify($request['POST']['csrf_token'] ?? null)) {
            return $this->response(DomainResult::failure('...'), $request);
        }
    }
    // 2. Domain → Response
    return $this->response($this->domain($db, $request), $request);
}
```

`final` гарантирует: любая новая фича пройдёт через одинаковый пайплайн.

### 3. Единый контракт данных

`DomainResult` возвращает либо `success($data)`, либо `failure($error)`. Никаких строковых статусов, никаких опечаток.

### 4. Рендеринг

```php
Engine::view('catalog', ['apps' => $apps]);
// → подставляет $apps в views/catalog.phtml через include + ob_start
```

`EXTR_SKIP` защищает служебные переменные. `try/finally` + `ob_get_level()` гарантируют очистку буферов даже при исключении.

### 5. Обёртка в layout

```php
Layout::render('Каталог', $content);
// → вставляет $content в views/_layout.phtml
```

Если `_layout.phtml` упадёт — вернётся безопасный fallback-HTML, а не фатальная ошибка.

---

## Добавление новой фичи

Допустим, нужно добавить фичу `delete_app` — удаление приложения.

### Шаг 1. Добавьте анонимный класс в `$features`

```php
'delete_app' => new class extends BaseAdrSlice {
    public function domain(Db $db, array $request): DomainResult
    {
        if (!Auth::check()) {
            return DomainResult::failure('unauthorized');
        }
        if (($request['METHOD'] ?? 'GET') !== 'POST') {
            return DomainResult::success(['status' => 'show_form']);
        }

        $id = (string)($request['POST']['app_id'] ?? '');
        if (!isset($db->apps[$id])) {
            return DomainResult::failure('Приложение не найдено.');
        }
        if ($db->apps[$id]['dev_id'] !== Auth::user()['id']) {
            return DomainResult::failure('Вы не владелец этого приложения.');
        }

        unset($db->apps[$id]);
        return DomainResult::success(['status' => 'deleted']);
    }

    public function response(DomainResult $result, array $request): string
    {
        if ($result->isFailure() && $result->getError() === 'unauthorized') {
            return Layout::error(403, 'Доступ запрещён', 'Войдите.');
        }
        if ($result->isSuccess() && ($result->getData()['status'] ?? '') === 'deleted') {
            return $this->redirect('?action=catalog');
        }

        $content = Engine::view('delete_app', [
            'error' => $result->isFailure() ? $result->getError() : null,
            'csrf'  => Csrf::token(),
        ]);
        return Layout::render('Удаление', $content);
    }

    public function runTests(Db $db): void
    {
        Auth::setMockSession(['user' => ['id' => 'dev_123', 'name' => 'Tester']]);
        Csrf::setMockToken('token');
        try {
            $testDb = clone $db;
            $testDb->apps['own']    = ['id' => 'own',    'dev_id' => 'dev_123', 'title' => 'Mine', 'downloads' => 0];
            $testDb->apps['foe']    = ['id' => 'foe',    'dev_id' => 'other',   'title' => 'Not mine', 'downloads' => 0];

            // Нельзя удалить чужое
            $res = $this->domain($testDb, ['METHOD' => 'POST', 'POST' => ['app_id' => 'foe']]);
            if ($res->isSuccess() || isset($testDb->apps['foe']) === false) {
                throw new RuntimeException('DeleteApp: allowed to delete other developer\'s app.');
            }

            // Можно удалить своё
            $res = $this->domain($testDb, ['METHOD' => 'POST', 'POST' => ['app_id' => 'own']]);
            if ($res->isFailure() || isset($testDb->apps['own'])) {
                throw new RuntimeException('DeleteApp: could not delete own app.');
            }
        } finally {
            Auth::setMockSession(null);
            Csrf::setMockToken(null);
        }
        echo "[PASS] delete_app\n";
    }
},
```

### Шаг 2. Создайте `views/delete_app.phtml`

```php
<h1>🗑 Удаление приложения</h1>

<?php if (!empty($error)): ?>
    <div class="error">⚠️ <?= htmlspecialchars($error, ENT_QUOTES) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" action="?action=delete_app">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES) ?>">
        <label>ID приложения</label>
        <input type="text" name="app_id" required>
        <button type="submit" class="btn">Удалить</button>
    </form>
</div>
```

### Шаг 3. Добавьте `delete_app` в preflight-проверку

```php
$required = ['_layout', 'catalog', 'login', 'publish', 'error', 'delete_app'];
```

### Шаг 4. Запустите тесты

```bash
php index.php --run-ci
# Ожидаем: [PASS] delete_app
```

**Больше ничего делать не нужно.** Роутинг, CSRF, `try/finally` в тестах, mock-сессии — уже работают.

---

## Встроенное тестирование

### Запуск

```bash
# CLI
php index.php --run-ci

# Web (только при APP_ENV=dev)
http://localhost:8000/?run_ci=1
```

### Что изолируется

- **База данных:** `clone $db` перед каждым тестом — реальный `$_SESSION['mock_db']` не портится.
- **Сессия:** `Auth::setMockSession([...])` подменяет `$_SESSION['user']` виртуальным массивом.
- **CSRF:** `Csrf::setMockToken(...)` подменяет токен.
- **Редиректы:** `TEST_MODE` превращает `header('Location: ...'); exit;` в возврат строки-маркера `__REDIRECT__:?action=...`.

Сброс состояния — через `try/finally`, работает даже при исключении.

### Формат вывода

```
=== CI: ЗАПУСК ВСТРОЕННЫХ ТЕСТОВ ===
[PASS] catalog
[PASS] login
[PASS] publish
[PASS] logout
=== ВСЕ ТЕСТЫ ПРОЙДЕНЫ ===
```

Exit code: `0` при успехе, `1` при любом провале.

---

## JSON API

Фичи `catalog` и `publish` поддерживают JSON-режим через параметр `?format=json`.

```bash
# Каталог
curl "http://localhost:8000/?action=catalog&format=json"
# {"apps":[{"id":"app-1","dev_id":"dev_123","title":"Telegram Dev","downloads":150}]}

# Публикация (нужна сессия и CSRF)
curl -X POST "http://localhost:8000/?action=publish&format=json" \
     -d "app_title=My App" -d "csrf_token=<token>"
# {"status":"created","id":"app-2"}

# Ошибка — 403
curl "http://localhost:8000/?action=publish&format=json"
# {"error":"unauthorized"}
```

**Почему `?format=json`, а не `Accept: application/json`?** Для LLM-first явный параметр удобнее: виден в логах, легко воспроизводится через `curl`, не требует content negotiation.

**Почему не все фичи поддерживают JSON?** `login` и `logout` делают редирект, который для JSON-клиента бессмысленен. Двуязычие создало бы путаницу без пользы.

---

## Безопасность

Что уже защищено:

| Угроза | Защита |
|---|---|
| **CSRF** | Токен в `$_SESSION`, проверка для всех POST в `BaseAdrSlice::__invoke` |
| **XSS** | `htmlspecialchars(..., ENT_QUOTES)` во всех шаблонах |
| **SQL-инъекции** | Не применимо — данных в реальной БД нет |
| **Session fixation** | `session_start()` без явной регенерации; в продакшене — `session_regenerate_id(true)` при логине |
| **Пароли** | `password_hash()` / `password_verify()` с bcrypt |
| **Отладочная информация** | Детали исключений только при `APP_ENV=dev` |
| **CI-эндпоинт** | `?run_ci=1` заблокирован при `APP_ENV!=dev` |
| **Path traversal в Engine** | Имя шаблона — это литерал в коде, не пользовательский ввод |

Что **не** защищено (и не должно быть в эталонном каркасе):

- **Rate limiting** — добавьте на уровне reverse proxy.
- **2FA** — расширение `login`.
- **HTTPS** — на уровне веб-сервера.

### Настройка окружения

```bash
# Dev (по умолчанию)
APP_ENV=dev php -S localhost:8000 index.php

# Prod (CI-эндпоинт заблокирован, детали ошибок скрыты)
APP_ENV=prod php -S localhost:8000 index.php
```

---

## Ограничения

Каркас сознательно остаётся минимальным. Что не входит:

- **Реальная БД.** `Db` — имитация в `$_SESSION['mock_db']`. Каждый браузер получает свою копию. Для продакшена замените на PDO/SQLite/PostgreSQL.
- **Аутентификация в промышленном смысле.** Один пользователь `dev@store.com` / `123`. Нет регистрации, восстановления пароля, 2FA.
- **Роли и права.** `Auth::user()` не имеет `role`. Добавляется за 5 строк, если нужно.
- **Пагинация, поиск, сортировка.** Каталог отдаёт всё разом. Для больших данных — расширение `catalog::domain()`.
- **Логирование.** Нет PSR-3. Если нужно — `error_log()` или внешний логгер.
- **Миграции.** Изменение схемы данных — перезапуск с новой `Db`.

Каркас — это **стартовая точка**, а не финальный продукт. Он специально оставляет пространство для расширений, но не додумывает за вас.

---

## Лицензия

MIT. Используйте, форкайте, адаптируйте.

---

## См. также

- [Action–Domain–Responder (ADR) — Paul M. Jones](https://pmjones.io/adr/)
- [Vertical Slice Architecture — Jimmy Bogard](https://jimmybogard.com/vertical-slice-architecture/)
- [PHP: password_hash](https://www.php.net/manual/en/function.password-hash.php)
- [PHP: Output Buffering](https://www.php.net/manual/en/book.outcontrol.php)
