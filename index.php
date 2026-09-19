<?php
// index.php — эталонный ADR-каркас с вынесенными View и поддержкой JSON.
// PHP 8.0+
//
// Запуск (dev):
//   php -S localhost:8000 index.php
//   HTML: http://localhost:8000/?action=catalog
//   JSON: http://localhost:8000/?action=catalog&format=json
//
// Тесты:
//   CLI:  php index.php --run-ci
//   Web:  http://localhost:8000/?run_ci=1   (только при APP_ENV=dev)

declare(strict_types=1);

define('APP_ENV', getenv('APP_ENV') ?: 'dev');
define('IS_CLI', php_sapi_name() === 'cli');

if (!IS_CLI && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =========================================================================
// 0. PREFLIGHT: все шаблоны на месте?
// =========================================================================
(static function (): void {
    $required = ['_layout', 'catalog', 'login', 'add_good', 'error', 'good_details', 'good_edit', 'profile', 'search', 'categories', 'category_detail', 'collections', 'collection', 'collection_edit', 'cart'];
    $missing  = [];
    foreach ($required as $name) {
        $path = __DIR__ . '/views/' . $name . '.phtml';
        if (!is_file($path)) {
            $missing[] = 'views/' . $name . '.phtml';
        }
    }
    if ($missing) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "Не найдены файлы шаблонов:\n  - " . implode("\n  - ", $missing);
        echo "\n\nОжидаемая папка: " . __DIR__ . '/views' . "\n";
        exit(1);
    }
})();

// =========================================================================
// 1. ИНФРАСТРУКТУРА
// =========================================================================

class Db
{
    public array $goods = [
        'good-1' => ['id' => 'good-1', 'seller_id' => 'seller_123', 'title' => 'Telegram Premium', 'price' => 299, 'description' => 'Премиум версия мессенджера', 'category_id' => 'cat-1', 'sales' => 150, 'stock' => 100],
    ];

    public array $sellers = [];

    public array $categories = [
        'cat-1' => ['id' => 'cat-1', 'name' => 'Мессенджеры', 'slug' => 'messengers'],
        'cat-2' => ['id' => 'cat-2', 'name' => 'Игры', 'slug' => 'games'],
        'cat-3' => ['id' => 'cat-3', 'name' => 'Продуктивность', 'slug' => 'productivity'],
    ];

    public array $collections = [
        'col-1' => ['id' => 'col-1', 'name' => 'Популярные товары', 'description' => 'Самые продаваемые товары недели'],
        'col-2' => ['id' => 'col-2', 'name' => 'Новинки', 'description' => 'Недавно добавленные товары'],
        'col-3' => ['id' => 'col-3', 'name' => 'Для работы', 'description' => 'Товары для повышения продуктивности'],
    ];

    // Связь многие-ко-многим: коллекции <-> товары
    // collection_good_ids хранит массив ID товаров, входящих в коллекцию
    public array $collectionGoodIds = [
        'col-1' => ['good-1'],
        'col-2' => [],
        'col-3' => [],
    ];

    public array $users = [];

    // Корзина пользователя: массив items[good_id] => ['quantity' => int, 'added_at' => timestamp]
    public array $cart = [];

    public function __construct()
    {
        $this->users['seller@store.com'] = [
            'id'            => 'seller_123',
            'name'          => 'Алексей',
            'password_hash' => password_hash('123', PASSWORD_DEFAULT),
        ];
        
        // Инициализируем селлера
        $this->sellers['seller_123'] = [
            'id' => 'seller_123',
            'user_id' => 'seller_123',
            'name' => 'Магазин Алексея',
            'description' => 'Официальный магазин селлера',
            'rating' => 4.8,
            'verified' => true,
        ];
    }
}

/**
 * Изолированный рендерер файловых шаблонов.
 *  - EXTR_SKIP защищает служебные переменные от перезаписи.
 *  - try/finally + ob_get_level() гарантируют очистку буферов.
 */
final class Engine
{
    public static function view(string $templateName, array $data = []): string
    {
        $__path = __DIR__ . '/views/' . $templateName . '.phtml';
        if (!is_file($__path)) {
            throw new RuntimeException(
                "View template not found: {$__path}\n" .
                "Ожидается файл 'views/{$templateName}.phtml' рядом с index.php."
            );
        }

        $__level = ob_get_level();
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $__path;
            return (string)ob_get_clean();
        } finally {
            while (ob_get_level() > $__level) {
                ob_end_clean();
            }
        }
    }
}

/**
 * Хелпер для API-ответов. Не шаблонизирует, а сериализует.
 * Всегда возвращает строку, как и Engine::view().
 */
final class Json
{
    public static function render(mixed $data, int $status = 200): string
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        return (string)json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public static function error(string $message, int $status = 400): string
    {
        return self::render(['error' => $message], $status);
    }
}

final class DomainResult
{
    private function __construct(
        private bool $success,
        private mixed $data = null,
        private string $error = ''
    ) {}

    public static function success(mixed $data = null): self { return new self(true, $data); }
    public static function failure(string $error): self { return new self(false, null, $error); }

    public function isSuccess(): bool { return $this->success; }
    public function isFailure(): bool { return !$this->success; }
    public function getData(): mixed { return $this->data; }
    public function getError(): string { return $this->error; }
}

final class Auth
{
    private static ?array $mockSession = null;

    public static function setMockSession(?array $data): void { self::$mockSession = $data; }

    public static function login(array $user): void
    {
        if (self::$mockSession !== null) { self::$mockSession['user'] = $user; return; }
        $_SESSION['user'] = $user;
    }

    public static function logout(): void
    {
        if (self::$mockSession !== null) { self::$mockSession['user'] = null; return; }
        unset($_SESSION['user']);
    }

    public static function user(): ?array
    {
        if (self::$mockSession !== null) { return self::$mockSession['user'] ?? null; }
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool { return self::user() !== null; }
}

final class Csrf
{
    private static ?string $mockToken = null;

    public static function setMockToken(?string $token): void { self::$mockToken = $token; }

    public static function token(): string
    {
        if (self::$mockToken !== null) { return self::$mockToken; }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verify(?string $token): bool
    {
        if (self::$mockToken !== null) {
            return $token !== null && hash_equals(self::$mockToken, $token);
        }
        if (empty($_SESSION['csrf_token'])) { return false; }
        return $token !== null && hash_equals($_SESSION['csrf_token'], $token);
    }
}

/**
 * Обёртка контента в общий каркас + единая точка рендеринга ошибок.
 * В случае отсутствия/падения шаблона — отдаём безопасный fallback.
 */
final class Layout
{
    public static function render(string $title, string $contentHtml): string
    {
        try {
            return Engine::view('_layout', [
                'title'       => $title,
                'contentHtml' => $contentHtml,
                'user'        => Auth::user(),
                'csrf'        => Csrf::token(),
            ]);
        } catch (Throwable $e) {
            return self::fallback($title, $contentHtml);
        }
    }

    public static function error(int $statusCode, string $title, string $message): string
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
        }

        try {
            $inner = Engine::view('error', ['title' => $title, 'message' => $message]);
        } catch (Throwable $e) {
            return self::fallback(
                "Ошибка {$statusCode}",
                '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
            );
        }

        return self::render("Ошибка {$statusCode}", $inner);
    }

    /** Последняя линия обороны: HTML без внешних шаблонов. */
    private static function fallback(string $title, string $contentHtml): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES);
        return "<!DOCTYPE html><html lang='ru'><head><meta charset='utf-8'><title>{$t}</title>"
             . "<style>body{font-family:sans-serif;padding:24px;max-width:640px;margin:0 auto;}"
             . ".error{color:#b00020;background:#ffe5e5;padding:12px;border-radius:6px;}</style>"
             . "</head><body><div class='error'><h1>{$t}</h1>{$contentHtml}</div></body></html>";
    }
}

// =========================================================================
// 2. АБСТРАКТНЫЙ КОНВЕЙЕР ADR
// =========================================================================
abstract class BaseAdrSlice
{
    /** Префикс маркера редиректа в TEST_MODE. */
    public const TEST_REDIRECT_PREFIX = '__REDIRECT__:';

    final public function __invoke(Db $db, array $request): string
    {
        try {
            if (($request['METHOD'] ?? 'GET') === 'POST') {
                if (!Csrf::verify($request['POST']['csrf_token'] ?? null)) {
                    return $this->response(
                        DomainResult::failure('Ошибка безопасности: неверный или отсутствующий CSRF-токен.'),
                        $request
                    );
                }
            }
            return $this->response($this->domain($db, $request), $request);
        } catch (Throwable $e) {
            $message = APP_ENV === 'dev'
                ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
                : 'Внутренняя ошибка. Попробуйте позже.';

            if (self::wantsJson($request)) {
                return Json::error($message, 500);
            }
            return Layout::error(500, 'Критический сбой', $message);
        }
    }

    abstract public function domain(Db $db, array $request): DomainResult;
    abstract public function response(DomainResult $result, array $request): string;
    abstract public function runTests(Db $db): void;

    protected static function wantsJson(array $request): bool
    {
        return ($request['GET']['format'] ?? '') === 'json';
    }

    /**
     * Редирект, безопасный для тестов.
     *  - production: отправляет header('Location') и завершает процесс.
     *  - TEST_MODE: возвращает строку-маркер, не завершая работу.
     *
     * Это позволяет тестировать response() целиком через __invoke,
     * не убивая тест-раннер вызовом exit.
     */
    final protected function redirect(string $url): string
    {
        if (defined('TEST_MODE') && TEST_MODE) {
            return self::TEST_REDIRECT_PREFIX . $url;
        }
        if (!headers_sent()) {
            header("Location: {$url}");
        }
        exit;
    }
}

// =========================================================================
// 3. РЕЕСТР ВЕРТИКАЛЬНЫХ СРЕЗОВ
// =========================================================================
$features = [

    // --- CATALOG: read-only, HTML + JSON -----------------------------------
    'catalog' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            $goods = $db->goods;

            return DomainResult::success($goods);
        }

        public function response(DomainResult $result, array $request): string
        {
            $goods = $result->getData();

            if (self::wantsJson($request)) {
                if ($result->isFailure()) {
                    return Json::error($result->getError(), 400);
                }
                return Json::render(['goods' => array_values($goods)]);
            }

            $content = Engine::view('catalog', ['goods' => $goods, 'csrf' => Csrf::token()]);
            return Layout::render('Каталог', $content);
        }

        public function runTests(Db $db): void
        {
            $testDb = clone $db;
            $testDb->goods['t-1'] = ['id' => 't-1', 'seller_id' => 'x', 'title' => 'Test', 'sales' => 0];

            $res = $this->domain($testDb, ['METHOD' => 'GET']);
            if ($res->isFailure() || !isset($res->getData()['t-1'])) {
                throw new RuntimeException('Catalog domain test failed.');
            }

            $json = $this->response($res, ['GET' => ['format' => 'json'], 'METHOD' => 'GET']);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['goods'])) {
                throw new RuntimeException('Catalog JSON response is not valid.');
            }
            $ids = array_column($decoded['goods'], 'id');
            if (!in_array('t-1', $ids, true)) {
                throw new RuntimeException('Catalog JSON missing expected good.');
            }

            echo "[PASS] catalog\n";
        }
    },

    // --- LOGIN: HTML only (редиректы не имеют JSON-смысла) -----------------
    'login' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            if (Auth::check()) {
                return DomainResult::success(['status' => 'already_logged_in']);
            }
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::success(['status' => 'show_form']);
            }

            $email    = trim((string)($request['POST']['email'] ?? ''));
            $password = (string)($request['POST']['password'] ?? '');

            if (!isset($db->users[$email]) || !password_verify($password, $db->users[$email]['password_hash'])) {
                return DomainResult::failure('Неверная пара email/пароль.');
            }

            Auth::login($db->users[$email]);
            return DomainResult::success(['status' => 'logged_in']);
        }

        public function response(DomainResult $result, array $request): string
        {
            if ($result->isSuccess()) {
                $status = $result->getData()['status'] ?? '';
                if ($status === 'logged_in' || $status === 'already_logged_in') {
                    return $this->redirect('?action=add_good');
                }
            }

            $content = Engine::view('login', [
                'error' => $result->isFailure() ? $result->getError() : null,
                'isDev' => APP_ENV === 'dev',
                'csrf'  => Csrf::token(),
            ]);
            return Layout::render('Вход', $content);
        }

        public function runTests(Db $db): void
        {
            Auth::setMockSession([]);
            try {
                $testDb = clone $db;

                $fail = $this->domain($testDb, [
                    'METHOD' => 'POST',
                    'POST'   => ['email' => 'seller@store.com', 'password' => 'wrong'],
                ]);
                if ($fail->isSuccess()) {
                    throw new RuntimeException('Login: bad password accepted.');
                }

                $ok = $this->domain($testDb, [
                    'METHOD' => 'POST',
                    'POST'   => ['email' => 'seller@store.com', 'password' => '123'],
                ]);
                if ($ok->isFailure() || !Auth::check()) {
                    throw new RuntimeException('Login: valid credentials rejected.');
                }

                // response() с успехом должен вернуть маркер редиректа, а не exit
                $out = $this->response($ok, ['METHOD' => 'POST']);
                if (strpos($out, BaseAdrSlice::TEST_REDIRECT_PREFIX . '?action=add_good') === false) {
                    throw new RuntimeException('Login: success did not redirect to publish.');
                }
            } finally {
                Auth::setMockSession(null);
            }
            echo "[PASS] login\n";
        }
    },

    // --- PUBLISH: HTML + JSON ----------------------------------------------
    'add_good' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            if (!Auth::check()) {
                return DomainResult::failure('unauthorized');
            }
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::success(['status' => 'show_form']);
            }

            $title = trim((string)($request['POST']['good_title'] ?? ''));
            if (mb_strlen($title) < 3) {
                return DomainResult::failure('Название товара должно содержать минимум 3 символа.');
            }

            $newId = 'good-' . (count($db->goods) + 1);
            $db->goods[$newId] = [
                'id'          => $newId,
                'seller_id'      => Auth::user()['id'],
                'title'       => $title,
                'description' => $request['POST']['description'] ?? null,
                'price' => $request['POST']['price'] ?? null,
                'sales'   => 0,
                'stock' => $request['POST']['stock'] ?? null,
                'category_id' => $request['POST']['category_id'] ?? null,
            ];

            return DomainResult::success(['status' => 'created', 'id' => $newId]);
        }

        public function response(DomainResult $result, array $request): string
        {
            global $db;
            $json = self::wantsJson($request);

            if ($json) {
                if ($result->isFailure()) {
                    $code = $result->getError() === 'unauthorized' ? 403 : 400;
                    return Json::error($result->getError(), $code);
                }
                return Json::render($result->getData() ?? ['status' => 'ok']);
            }

            if ($result->isFailure() && $result->getError() === 'unauthorized') {
                return Layout::error(403, 'Доступ запрещён', 'Войдите, чтобы публиковать товара.');
            }

            if ($result->isSuccess() && ($result->getData()['status'] ?? '') === 'created') {
                return $this->redirect('?action=catalog');
            }

            $content = Engine::view('add_good', [
                'error' => $result->isFailure() ? $result->getError() : null,
                'csrf'  => Csrf::token(),
                'categories' => $db->categories,
            ]);
            return Layout::render('Добавить товар', $content);
        }

        public function runTests(Db $db): void
        {
            Auth::setMockSession(['user' => ['id' => 'seller_123', 'name' => 'Tester']]);
            Csrf::setMockToken('valid_token');
            try {
                $testDb = clone $db;

                // CSRF-мидлварь отклоняет неверный токен
                $bad = $this($testDb, [
                    'METHOD' => 'POST',
                    'GET'    => [],
                    'POST'   => ['good_title' => 'Valid Title', 'csrf_token' => 'ATTACK'],
                ]);
                if (strpos($bad, 'CSRF') === false) {
                    throw new RuntimeException('Publish: CSRF middleware broken.');
                }

                // Успешный домен + маркер редиректа (HTML)
                $before = count($testDb->goods);
                $res = $this->domain($testDb, [
                    'METHOD' => 'POST',
                    'POST'   => ['good_title' => 'New Awesome Good', 'csrf_token' => 'valid_token'],
                ]);
                if ($res->isFailure() || count($testDb->goods) !== $before + 1) {
                    throw new RuntimeException('Publish: domain logic failed.');
                }
                if (($res->getData()['id'] ?? null) === null) {
                    throw new RuntimeException('Publish: created good has no id.');
                }

                $htmlOut = $this->response($res, ['GET' => [], 'METHOD' => 'POST']);
                if (strpos($htmlOut, BaseAdrSlice::TEST_REDIRECT_PREFIX . '?action=catalog') === false) {
                    throw new RuntimeException('Publish: success did not redirect to catalog.');
                }

                // JSON-ветка
                $json = $this->response($res, ['GET' => ['format' => 'json'], 'METHOD' => 'POST']);
                $decoded = json_decode($json, true);
                if (($decoded['status'] ?? null) !== 'created') {
                    throw new RuntimeException('Publish: JSON success response broken.');
                }

                // JSON-ошибка при неавторизованном доступе
                Auth::setMockSession([]);
                $unauthRes = $this->domain($testDb, ['METHOD' => 'POST', 'POST' => []]);
                $jsonErr = $this->response($unauthRes, ['GET' => ['format' => 'json'], 'METHOD' => 'POST']);
                $decodedErr = json_decode($jsonErr, true);
                if (($decodedErr['error'] ?? null) !== 'unauthorized') {
                    throw new RuntimeException('Publish: JSON unauthorized response broken.');
                }
            } finally {
                Auth::setMockSession(null);
                Csrf::setMockToken(null);
            }
            echo "[PASS] publish\n";
        }
    },

    // --- LOGOUT: только POST + CSRF, HTML-only -----------------------------
    'logout' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Метод не поддерживается. Используйте POST.');
            }
            Auth::logout();
            return DomainResult::success();
        }

        public function response(DomainResult $result, array $request): string
        {
            if ($result->isFailure()) {
                $error = $result->getError();
                if (strpos($error, 'CSRF') !== false) {
                    return Layout::error(403, 'Доступ запрещён', $error);
                }
                return Layout::error(405, 'Метод не поддерживается', $error);
            }
            return $this->redirect('?action=catalog');
        }

        public function runTests(Db $db): void
        {
            Csrf::setMockToken('token');
            try {
                $testDb = clone $db;

                // 1. Неверный CSRF → __invoke возвращает Layout::error (без exit)
                Auth::setMockSession(['user' => ['id' => '1', 'name' => 'X']]);
                $bad = $this($testDb, [
                    'METHOD' => 'POST',
                    'GET'    => [],
                    'POST'   => ['csrf_token' => 'WRONG'],
                ]);
                if (strpos($bad, 'CSRF') === false && strpos($bad, 'безопасности') === false) {
                    throw new RuntimeException('Logout: CSRF middleware broken.');
                }
                if (!Auth::check()) {
                    throw new RuntimeException('Logout: session cleared despite bad CSRF.');
                }

                // 2. Валидный CSRF + POST → __invoke возвращает маркер редиректа,
                //    сессия очищена. Здесь __invoke безопасен благодаря TEST_MODE.
                $ok = $this($testDb, [
                    'METHOD' => 'POST',
                    'GET'    => [],
                    'POST'   => ['csrf_token' => 'token'],
                ]);
                if (strpos($ok, BaseAdrSlice::TEST_REDIRECT_PREFIX . '?action=catalog') === false) {
                    throw new RuntimeException('Logout: no redirect marker after success.');
                }
                if (Auth::check()) {
                    throw new RuntimeException('Logout: session not cleared.');
                }

                // 3. GET-запрос (без POST) → 405
                Auth::setMockSession(['user' => ['id' => '1', 'name' => 'X']]);
                $res = $this->domain($testDb, ['METHOD' => 'GET']);
                if ($res->isSuccess()) {
                    throw new RuntimeException('Logout: GET was accepted.');
                }
            } finally {
                Auth::setMockSession(null);
                Csrf::setMockToken(null);
            }
            echo "[PASS] logout\n";
        }
    },

    // --- DELETE_APP: удаление товара (только владелец) -----------------
    'delete_good' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            if (!Auth::check()) {
                return DomainResult::failure('unauthorized');
            }
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Метод не поддерживается. Используйте POST.');
            }

            $goodId = trim((string)($request['POST']['good_id'] ?? ''));
            if ($goodId === '') {
                return DomainResult::failure('Не указан ID товара.');
            }

            if (!isset($db->goods[$goodId])) {
                return DomainResult::failure('Товар не найден.');
            }

            $currentUserId = Auth::user()['id'];
            if ($db->goods[$goodId]['seller_id'] !== $currentUserId) {
                return DomainResult::failure('Только владелец может удалить товар.');
            }

            unset($db->goods[$goodId]);
            return DomainResult::success(['status' => 'deleted', 'id' => $goodId]);
        }

        public function response(DomainResult $result, array $request): string
        {
            $json = self::wantsJson($request);

            if ($json) {
                if ($result->isFailure()) {
                    $error = $result->getError();
                    $code = match ($error) {
                        'unauthorized' => 403,
                        'Товар не найден.', 'Только владелец может удалить товар.' => 404,
                        default => 400,
                    };
                    return Json::error($result->getError(), $code);
                }
                return Json::render($result->getData() ?? ['status' => 'ok']);
            }

            if ($result->isFailure()) {
                $error = $result->getError();
                if ($error === 'unauthorized') {
                    return Layout::error(403, 'Доступ запрещён', 'Войдите, чтобы удалять товара.');
                }
                if ($error === 'Товар не найден.' || $error === 'Только владелец может удалить товар.') {
                    return Layout::error(404, 'Товар не найден', $error);
                }
                return Layout::error(400, 'Ошибка удаления', $error);
            }

            return $this->redirect('?action=catalog');
        }

        public function runTests(Db $db): void
        {
            Auth::setMockSession(['user' => ['id' => 'seller_123', 'name' => 'Tester']]);
            Csrf::setMockToken('valid_token');
            try {
                $testDb = clone $db;
                $testDb->goods['good-to-delete'] = [
                    'id' => 'good-to-delete',
                    'seller_id' => 'seller_123',
                    'title' => 'ToDelete',
                    'sales' => 0,
                ];

                // CSRF-мидлварь отклоняет неверный токен
                $bad = $this($testDb, [
                    'METHOD' => 'POST',
                    'GET'    => [],
                    'POST'   => ['good_id' => 'good-to-delete', 'csrf_token' => 'ATTACK'],
                ]);
                if (strpos($bad, 'CSRF') === false) {
                    throw new RuntimeException('DeleteApp: CSRF middleware broken.');
                }

                // Успешное удаление
                $before = count($testDb->goods);
                $res = $this->domain($testDb, [
                    'METHOD' => 'POST',
                    'POST'   => ['good_id' => 'good-to-delete', 'csrf_token' => 'valid_token'],
                ]);
                if ($res->isFailure()) {
                    throw new RuntimeException('DeleteApp: domain logic failed: ' . $res->getError());
                }
                if (count($testDb->goods) !== $before - 1) {
                    throw new RuntimeException('DeleteApp: good not removed from DB.');
                }
                if (isset($testDb->goods['good-to-delete'])) {
                    throw new RuntimeException('DeleteApp: good still exists in DB.');
                }

                $htmlOut = $this->response($res, ['GET' => [], 'METHOD' => 'POST']);
                if (strpos($htmlOut, BaseAdrSlice::TEST_REDIRECT_PREFIX . '?action=catalog') === false) {
                    throw new RuntimeException('DeleteApp: success did not redirect to catalog.');
                }

                // JSON-ветка
                $testDb2 = clone $db;
                $testDb2->goods['good-to-delete2'] = [
                    'id' => 'good-to-delete2',
                    'seller_id' => 'seller_123',
                    'title' => 'ToDelete2',
                    'sales' => 0,
                ];
                $res2 = $this->domain($testDb2, [
                    'METHOD' => 'POST',
                    'POST'   => ['good_id' => 'good-to-delete2', 'csrf_token' => 'valid_token'],
                ]);
                $json = $this->response($res2, ['GET' => ['format' => 'json'], 'METHOD' => 'POST']);
                $decoded = json_decode($json, true);
                if (($decoded['status'] ?? null) !== 'deleted') {
                    throw new RuntimeException('DeleteApp: JSON success response broken.');
                }

                // JSON-ошибка при неавторизованном доступе
                Auth::setMockSession([]);
                $unauthRes = $this->domain($testDb, ['METHOD' => 'POST', 'POST' => ['good_id' => 'good-1']]);
                $jsonErr = $this->response($unauthRes, ['GET' => ['format' => 'json'], 'METHOD' => 'POST']);
                $decodedErr = json_decode($jsonErr, true);
                if (($decodedErr['error'] ?? null) !== 'unauthorized') {
                    throw new RuntimeException('DeleteApp: JSON unauthorized response broken.');
                }

                // Ошибка: приложение не найдено
                Auth::setMockSession(['user' => ['id' => 'seller_123', 'name' => 'Tester']]);
                $notFoundRes = $this->domain($testDb, ['METHOD' => 'POST', 'POST' => ['good_id' => 'nonexistent']]);
                if ($notFoundRes->isSuccess()) {
                    throw new RuntimeException('DeleteApp: nonexistent good should fail.');
                }

                // Ошибка: не владелец
                Auth::setMockSession(['user' => ['id' => 'other_dev', 'name' => 'Other']]);
                $testDb->goods['good-other'] = [
                    'id' => 'good-other',
                    'seller_id' => 'seller_123',
                    'title' => 'OtherGood',
                    'sales' => 0,
                ];
                $notOwnerRes = $this->domain($testDb, ['METHOD' => 'POST', 'POST' => ['good_id' => 'good-other']]);
                if ($notOwnerRes->isSuccess()) {
                    throw new RuntimeException('DeleteApp: non-owner should not delete.');
                }
            } finally {
                Auth::setMockSession(null);
                Csrf::setMockToken(null);
            }
            echo "[PASS] delete_good\n";
        }
    },

    // --- APP_DETAILS: детальная информация о приложении (read-only) --------
    'good_details' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            $goodId = trim((string)($request['GET']['good_id'] ?? ''));
            if ($goodId === '') {
                return DomainResult::failure('Не указан ID товара.');
            }

            if (!isset($db->goods[$goodId])) {
                return DomainResult::failure('Товар не найден.');
            }

            $good = $db->goods[$goodId];
            $currentUser = Auth::user();
            
            // Проверяем, может ли текущий пользователь редактировать это приложение
            $canEdit = false;
            if ($currentUser !== null && $good['seller_id'] === $currentUser['id']) {
                $canEdit = true;
            }

            // Добавляем информацию о категории
            $category = null;
            if (isset($good['category_id']) && isset($db->categories[$good['category_id']])) {
                $category = $db->categories[$good['category_id']];
            }

            return DomainResult::success(['good' => $good, 'canEdit' => $canEdit, 'category' => $category]);
        }

        public function response(DomainResult $result, array $request): string
        {
            $json = self::wantsJson($request);

            if ($json) {
                if ($result->isFailure()) {
                    return Json::error($result->getError(), 404);
                }
                $data = $result->getData();
                $good = $data['good'] ?? $data;
                return Json::render(['good' => $good, 'category' => $data['category'] ?? null]);
            }

            if ($result->isFailure()) {
                return Layout::error(404, 'Товар не найден', $result->getError());
            }

            $data = $result->getData();
            $good = $data['good'] ?? $data;
            $canEdit = $data['canEdit'] ?? false;
            $category = $data['category'] ?? null;
            
            $content = Engine::view('good_details', ['good' => $good, 'canEdit' => $canEdit, 'category' => $category, 'csrf' => Csrf::token()]);
            return Layout::render(htmlspecialchars($good['title'] ?? 'Товар', ENT_QUOTES), $content);
        }

        public function runTests(Db $db): void
        {
            $testDb = clone $db;
            $testDb->goods['t-details'] = [
                'id' => 't-details',
                'seller_id' => 'seller_123',
                'title' => 'Test Details Good',
                'sales' => 42,
            ];

            // domain() без good_id должен вернуть ошибку
            $noId = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => []]);
            if ($noId->isSuccess()) {
                throw new RuntimeException('AppDetails: missing good_id should fail.');
            }

            // domain() с несуществующим good_id должен вернуть ошибку
            $notFound = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['good_id' => 'nonexistent']]);
            if ($notFound->isSuccess()) {
                throw new RuntimeException('AppDetails: nonexistent good should fail.');
            }

            // domain() с существующим good_id должен вернуть данные
            $ok = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['good_id' => 't-details']]);
            if ($ok->isFailure()) {
                throw new RuntimeException('AppDetails: valid good_id failed: ' . $ok->getError());
            }
            $data = $ok->getData();
            if (($data['good']['id'] ?? null) !== 't-details' || ($data['good']['title'] ?? null) !== 'Test Details Good') {
                throw new RuntimeException('AppDetails: returned data mismatch.');
            }

            // HTML response
            $html = $this->response($ok, ['METHOD' => 'GET', 'GET' => ['good_id' => 't-details']]);
            if (strpos($html, 'Test Details Good') === false) {
                throw new RuntimeException('AppDetails: HTML response missing good title.');
            }

            // JSON response
            $json = $this->response($ok, ['METHOD' => 'GET', 'GET' => ['good_id' => 't-details', 'format' => 'json']]);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['good'])) {
                throw new RuntimeException('AppDetails: JSON response missing good key.');
            }
            if (($decoded['good']['id'] ?? null) !== 't-details') {
                throw new RuntimeException('AppDetails: JSON response has wrong good id.');
            }

            // JSON error response
            $jsonErr = $this->response($notFound, ['METHOD' => 'GET', 'GET' => ['good_id' => 'nonexistent', 'format' => 'json']]);
            $decodedErr = json_decode($jsonErr, true);
            if (($decodedErr['error'] ?? null) === null) {
                throw new RuntimeException('AppDetails: JSON error response broken.');
            }

            echo "[PASS] good_details\n";
        }
    },

    // --- APP_EDIT: редактирование товара (HTML only) ---------------------
    'good_edit' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            if (!Auth::check()) {
                return DomainResult::failure('Требуется авторизация.');
            }

            $goodId = trim((string)($request['GET']['good_id'] ?? $request['POST']['good_id'] ?? ''));
            if ($goodId === '') {
                return DomainResult::failure('Не указан ID товара.');
            }

            if (!isset($db->goods[$goodId])) {
                return DomainResult::failure('Товар не найден.');
            }

            $good = $db->goods[$goodId];
            $currentUser = Auth::user();

            // Проверка прав: только селлер может редактировать своё приложение
            if ($good['seller_id'] !== $currentUser['id']) {
                return DomainResult::failure('У вас нет прав на редактирование этого товара.');
            }

            // GET запрос - показываем форму
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::success(['good' => $good, 'show_form' => true]);
            }

            // POST запрос - обрабатываем сохранение
            $title = trim((string)($request['POST']['title'] ?? ''));
            if (mb_strlen($title) < 3) {
                return DomainResult::failure('Название товара должно содержать минимум 3 символа.');
            }

            // Обновляем данные товара
            $db->goods[$goodId]['title'] = $title;
            $db->goods[$goodId]['category_id'] = $request['POST']['category_id'] ?? null;

            return DomainResult::success(['good' => $db->goods[$goodId], 'updated' => true]);
        }

        public function response(DomainResult $result, array $request): string
        {
            global $db;
            $json = self::wantsJson($request);

            if ($json) {
                if ($result->isFailure()) {
                    return Json::error($result->getError(), 400);
                }
                return Json::render($result->getData());
            }

            if ($result->isFailure()) {
                return Layout::error(400, 'Ошибка', $result->getError());
            }

            $data = $result->getData();
            $good = $data['good'] ?? null;
            $error = $result->getError() ?: null;
            $success = $data['updated'] ?? false;

            // Получаем информацию о категории, если она указана
            $category = null;
            if ($good && isset($good['category_id']) && isset($db->categories[$good['category_id']])) {
                $category = $db->categories[$good['category_id']];
            }

            $content = Engine::view('good_edit', [
                'good' => $good,
                'error' => $error,
                'success' => $success,
                'csrf' => Csrf::token(),
                'category' => $category,
                'categories' => $db->categories,
            ]);

            return Layout::render('Редактирование товара', $content);
        }

        public function runTests(Db $db): void
        {
            Auth::setMockSession(['user' => ['id' => 'seller_123', 'name' => 'Test User']]);
            Csrf::setMockToken('test');

            try {
                $testDb = clone $db;
                $testDb->goods['t-edit'] = [
                    'id' => 't-edit',
                    'seller_id' => 'seller_123',
                    'title' => 'Original Title',
                    'sales' => 10,
                ];

                // Тест: отсутствие авторизации
                Auth::setMockSession(null);
                $noAuth = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['good_id' => 't-edit']]);
                if ($noAuth->isSuccess()) {
                    throw new RuntimeException('AppEdit: unauthorized access should fail.');
                }
                Auth::setMockSession(['user' => ['id' => 'seller_123', 'name' => 'Test User']]);

                // Тест: отсутствие good_id
                $noId = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => []]);
                if ($noId->isSuccess()) {
                    throw new RuntimeException('AppEdit: missing good_id should fail.');
                }

                // Тест: несуществующее приложение
                $notFound = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['good_id' => 'nonexistent']]);
                if ($notFound->isSuccess()) {
                    throw new RuntimeException('AppEdit: nonexistent good should fail.');
                }

                // Тест: GET запрос должен вернуть форму
                $ok = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['good_id' => 't-edit']]);
                if ($ok->isFailure()) {
                    throw new RuntimeException('AppEdit: valid request failed: ' . $ok->getError());
                }
                $data = $ok->getData();
                if (!isset($data['good']) || !isset($data['show_form'])) {
                    throw new RuntimeException('AppEdit: should return good and show_form.');
                }

                // Тест: POST с валидными данными
                $postOk = $this->domain($testDb, [
                    'METHOD' => 'POST',
                    'GET' => ['good_id' => 't-edit'],
                    'POST' => ['good_id' => 't-edit', 'title' => 'Updated Title', 'csrf_token' => 'test'],
                ]);
                if ($postOk->isFailure()) {
                    throw new RuntimeException('AppEdit: valid POST failed: ' . $postOk->getError());
                }
                if (($testDb->goods['t-edit']['title'] ?? '') !== 'Updated Title') {
                    throw new RuntimeException('AppEdit: title was not updated.');
                }

                // Тест: POST с коротким названием
                $shortTitle = $this->domain($testDb, [
                    'METHOD' => 'POST',
                    'GET' => ['good_id' => 't-edit'],
                    'POST' => ['good_id' => 't-edit', 'title' => 'AB', 'csrf_token' => 'test'],
                ]);
                if ($shortTitle->isSuccess()) {
                    throw new RuntimeException('AppEdit: short title should fail.');
                }

                // Тест: HTML response
                $html = $this->response($ok, ['METHOD' => 'GET', 'GET' => ['good_id' => 't-edit']]);
                if (strpos($html, 'Original Title') === false) {
                    throw new RuntimeException('AppEdit: HTML response missing good title.');
                }

                echo "[PASS] good_edit\n";
            } finally {
                Auth::setMockSession(null);
                Csrf::setMockToken(null);
            }
        }
    },

    // --- PROFILE: страница профиля текущего пользователя (HTML + JSON) ------
    'profile' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            // Если указан user_id в запросе, показываем профиль этого пользователя
            $requestedUserId = $request['GET']['user_id'] ?? null;
            
            if ($requestedUserId !== null) {
                // Поиск пользователя по ID
                foreach ($db->users as $userEmail => $userData) {
                    if ($userData['id'] === $requestedUserId) {
                        return DomainResult::success([
                            'user' => $userData,
                            'email' => $userEmail,
                        ]);
                    }
                }
                return DomainResult::failure('Товар не найден.');
            }
            
            // Если user_id не указан, показываем профиль текущего авторизованного пользователя
            if (!Auth::check()) {
                return DomainResult::failure('Требуется авторизация.');
            }

            $user = Auth::user();
            
            // Находим email пользователя по ID
            $email = null;
            foreach ($db->users as $userEmail => $userData) {
                if ($userData['id'] === $user['id']) {
                    $email = $userEmail;
                    break;
                }
            }

            return DomainResult::success([
                'user' => $user,
                'email' => $email,
            ]);
        }

        public function response(DomainResult $result, array $request): string
        {
            $json = self::wantsJson($request);

            if ($json) {
                if ($result->isFailure()) {
                    return Json::error($result->getError(), 401);
                }
                return Json::render($result->getData());
            }

            if ($result->isFailure()) {
                return Layout::error(401, 'Требуется авторизация', $result->getError());
            }

            $data = $result->getData();
            $content = Engine::view('profile', [
                'user' => $data['user'],
                'email' => $data['email'],
            ]);
            return Layout::render('Профиль пользователя', $content);
        }

        public function runTests(Db $db): void
        {
            Auth::setMockSession([]);
            try {
                $testDb = clone $db;

                // domain() без авторизации должен вернуть ошибку
                $noAuth = $this->domain($testDb, ['METHOD' => 'GET']);
                if ($noAuth->isSuccess()) {
                    throw new RuntimeException('Profile: unauthorized access should fail.');
                }

                // domain() с авторизацией должен вернуть данные
                Auth::login($testDb->users['seller@store.com']);
                $ok = $this->domain($testDb, ['METHOD' => 'GET']);
                if ($ok->isFailure()) {
                    throw new RuntimeException('Profile: authorized access failed: ' . $ok->getError());
                }
                $data = $ok->getData();
                if (!isset($data['user']['id']) || !isset($data['email'])) {
                    throw new RuntimeException('Profile: returned data missing user or email.');
                }
                if ($data['email'] !== 'seller@store.com') {
                    throw new RuntimeException('Profile: email mismatch.');
                }

                // HTML response
                $html = $this->response($ok, ['METHOD' => 'GET']);
                if (strpos($html, 'Профиль пользователя') === false) {
                    throw new RuntimeException('Profile: HTML response missing title.');
                }

                // JSON response
                $json = $this->response($ok, ['METHOD' => 'GET', 'GET' => ['format' => 'json']]);
                $decoded = json_decode($json, true);
                if (!is_array($decoded) || !isset($decoded['user']) || !isset($decoded['email'])) {
                    throw new RuntimeException('Profile: JSON response missing required keys.');
                }
                if ($decoded['email'] !== 'seller@store.com') {
                    throw new RuntimeException('Profile: JSON email mismatch.');
                }

                // JSON error response for unauthorized
                $jsonErr = $this->response($noAuth, ['METHOD' => 'GET', 'GET' => ['format' => 'json']]);
                $decodedErr = json_decode($jsonErr, true);
                if (($decodedErr['error'] ?? null) === null) {
                    throw new RuntimeException('Profile: JSON error response broken.');
                }
            } finally {
                Auth::setMockSession(null);
            }
            echo "[PASS] profile\n";
        }
    },

    // --- SEARCH: поиск товаров по названию (read-only, HTML + JSON) ------
    'search' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            $query = trim((string)($request['GET']['q'] ?? ''));
            
            // Если запрос пустой, возвращаем пустой результат
            if ($query === '') {
                return DomainResult::success(['goods' => [], 'query' => '']);
            }

            // Фильтрация товаров по названию (case-insensitive поиск)
            $matchingGoods = [];
            foreach ($db->goods as $good) {
                if (mb_stripos($good['title'], $query) !== false) {
                    $matchingGoods[] = $good;
                }
            }

            return DomainResult::success([
                'goods' => $matchingGoods,
                'query' => $query,
            ]);
        }

        public function response(DomainResult $result, array $request): string
        {
            $json = self::wantsJson($request);
            $data = $result->getData();
            $query = $data['query'] ?? '';
            $goods = $data['goods'] ?? [];

            if ($json) {
                if ($result->isFailure()) {
                    return Json::error($result->getError(), 400);
                }
                return Json::render([
                    'goods' => array_values($goods),
                    'query' => $query,
                    'count' => count($goods),
                ]);
            }

            $content = Engine::view('search', [
                'goods' => $goods,
                'query' => $query,
                'csrf' => Csrf::token(),
            ]);
            return Layout::render('Поиск товаров', $content);
        }

        public function runTests(Db $db): void
        {
            $testDb = clone $db;
            $testDb->goods['search-test-1'] = [
                'id' => 'search-test-1',
                'seller_id' => 'seller_123',
                'title' => 'Telegram Messenger',
                'sales' => 500,
            ];
            $testDb->goods['search-test-2'] = [
                'id' => 'search-test-2',
                'seller_id' => 'seller_123',
                'title' => 'Photo Editor Pro',
                'sales' => 200,
            ];
            $testDb->goods['search-test-3'] = [
                'id' => 'search-test-3',
                'seller_id' => 'seller_123',
                'title' => 'Game of Thrones',
                'sales' => 1000,
            ];

            // domain() с пустым запросом должен вернуть пустой результат
            $emptyQuery = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['q' => '']]);
            if ($emptyQuery->isFailure()) {
                throw new RuntimeException('Search: empty query should not fail.');
            }
            if (count($emptyQuery->getData()['goods']) !== 0) {
                throw new RuntimeException('Search: empty query should return empty goods list.');
            }

            // domain() с запросом "telegram" должен найти товар
            $telegramResult = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['q' => 'telegram']]);
            if ($telegramResult->isFailure()) {
                throw new RuntimeException('Search: valid query failed: ' . $telegramResult->getError());
            }
            $telegramGoods = $telegramResult->getData()['goods'];
            // Ожидаем как минимум search-test-1 (также может быть good-1 "Telegram Dev")
            $found = false;
            foreach ($telegramGoods as $good) {
                if ($good['id'] === 'search-test-1') {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new RuntimeException('Search: telegram query did not find expected good.');
            }

            // domain() с запросом "game" должен найти одно приложение
            $gameResult = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['q' => 'game']]);
            if ($gameResult->isFailure()) {
                throw new RuntimeException('Search: game query failed.');
            }
            $gameGoods = $gameResult->getData()['goods'];
            if (count($gameGoods) !== 1 || $gameGoods[0]['id'] !== 'search-test-3') {
                throw new RuntimeException('Search: game query did not find expected good.');
            }

            // domain() с запросом "notfound" должен вернуть пустой список
            $notFoundResult = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['q' => 'notfound']]);
            if ($notFoundResult->isFailure()) {
                throw new RuntimeException('Search: notfound query should not fail.');
            }
            if (count($notFoundResult->getData()['goods']) !== 0) {
                throw new RuntimeException('Search: notfound query should return empty list.');
            }

            // HTML response
            $html = $this->response($telegramResult, ['METHOD' => 'GET', 'GET' => ['q' => 'telegram']]);
            if (strpos($html, 'Поиск товаров') === false) {
                throw new RuntimeException('Search: HTML response missing title.');
            }
            if (strpos($html, 'Telegram Messenger') === false) {
                throw new RuntimeException('Search: HTML response missing found good.');
            }

            // JSON response
            $json = $this->response($telegramResult, ['METHOD' => 'GET', 'GET' => ['q' => 'telegram', 'format' => 'json']]);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['goods']) || !isset($decoded['query']) || !isset($decoded['count'])) {
                throw new RuntimeException('Search: JSON response missing required keys.');
            }
            if ($decoded['query'] !== 'telegram') {
                throw new RuntimeException('Search: JSON response has wrong query.');
            }
            // Проверяем что найдено хотя бы одно приложение и search-test-1 среди них
            if ($decoded['count'] < 1) {
                throw new RuntimeException('Search: JSON response count should be >= 1.');
            }
            $foundInJson = false;
            foreach ($decoded['goods'] as $good) {
                if ($good['id'] === 'search-test-1') {
                    $foundInJson = true;
                    break;
                }
            }
            if (!$foundInJson) {
                throw new RuntimeException('Search: JSON response has wrong goods.');
            }

            echo "[PASS] search\n";
        }
    },

    // --- CATEGORIES: read-only, HTML + JSON --------------------------------
    'categories' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            return DomainResult::success($db->categories);
        }

        public function response(DomainResult $result, array $request): string
        {
            $categories = $result->getData();

            if (self::wantsJson($request)) {
                if ($result->isFailure()) {
                    return Json::error($result->getError(), 400);
                }
                return Json::render(['categories' => array_values($categories)]);
            }

            $content = Engine::view('categories', ['categories' => $categories]);
            return Layout::render('Категории', $content);
        }

        public function runTests(Db $db): void
        {
            $testDb = clone $db;
            $testDb->categories['cat-test'] = ['id' => 'cat-test', 'name' => 'Тестовая', 'slug' => 'test'];

            $res = $this->domain($testDb, ['METHOD' => 'GET']);
            if ($res->isFailure() || !isset($res->getData()['cat-test'])) {
                throw new RuntimeException('Categories domain test failed.');
            }

            $json = $this->response($res, ['GET' => ['format' => 'json'], 'METHOD' => 'GET']);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['categories'])) {
                throw new RuntimeException('Categories JSON response is not valid.');
            }
            $ids = array_column($decoded['categories'], 'id');
            if (!in_array('cat-test', $ids, true)) {
                throw new RuntimeException('Categories JSON missing expected category.');
            }

            $html = $this->response($res, ['METHOD' => 'GET']);
            if (strpos($html, 'Категории') === false) {
                throw new RuntimeException('Categories HTML response missing title.');
            }
            if (strpos($html, 'Тестовая') === false) {
                throw new RuntimeException('Categories HTML response missing category name.');
            }

            echo "[PASS] categories\n";
        }
    },

    // --- COLLECTIONS: список коллекций товаров --------------------------
    'collections' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            return DomainResult::success($db->collections);
        }

        public function response(DomainResult $result, array $request): string
        {
            $collections = $result->getData();

            if (self::wantsJson($request)) {
                if ($result->isFailure()) {
                    return Json::error($result->getError(), 400);
                }
                return Json::render(['collections' => array_values($collections)]);
            }

            $content = Engine::view('collections', ['collections' => $collections]);
            return Layout::render('Коллекции', $content);
        }

        public function runTests(Db $db): void
        {
            $testDb = clone $db;
            $testDb->collections['col-test'] = ['id' => 'col-test', 'name' => 'Тестовая коллекция', 'description' => 'Описание'];

            $res = $this->domain($testDb, ['METHOD' => 'GET']);
            if ($res->isFailure() || !isset($res->getData()['col-test'])) {
                throw new RuntimeException('Collections domain test failed.');
            }

            $json = $this->response($res, ['GET' => ['format' => 'json'], 'METHOD' => 'GET']);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['collections'])) {
                throw new RuntimeException('Collections JSON response is not valid.');
            }
            $ids = array_column($decoded['collections'], 'id');
            if (!in_array('col-test', $ids, true)) {
                throw new RuntimeException('Collections JSON missing expected collection.');
            }

            $html = $this->response($res, ['METHOD' => 'GET']);
            if (strpos($html, 'Коллекции') === false) {
                throw new RuntimeException('Collections HTML response missing title.');
            }
            if (strpos($html, 'Тестовая коллекция') === false) {
                throw new RuntimeException('Collections HTML response missing collection name.');
            }

            echo "[PASS] collections\n";
        }
    },

    // --- COLLECTION: конкретная коллекция ----------------------------------
    'collection' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            $collectionId = $request['GET']['collection_id'] ?? null;
            
            if ($collectionId === null || $collectionId === '') {
                return DomainResult::failure('Не указан ID коллекции.');
            }

            // Находим коллекцию по ID
            $collection = null;
            foreach ($db->collections as $col) {
                if (($col['id'] ?? '') === $collectionId) {
                    $collection = $col;
                    break;
                }
            }

            if ($collection === null) {
                return DomainResult::failure('Коллекция не найдена.');
            }

            // Получаем ID товаров, входящих в эту коллекцию (связь многие-ко-многим)
            $collectionGoodIds = $db->collectionGoodIds[$collectionId] ?? [];
            
            // Фильтруем товара, входящие в коллекцию
            $goods = [];
            foreach ($db->goods as $good) {
                if (in_array($good['id'], $collectionGoodIds, true)) {
                    $goods[] = $good;
                }
            }

            return DomainResult::success(['collection' => $collection, 'goods' => $goods, 'collectionGoodIds' => $collectionGoodIds]);
        }

        public function response(DomainResult $result, array $request): string
        {
            if ($result->isFailure()) {
                if (self::wantsJson($request)) {
                    return Json::error($result->getError(), 404);
                }
                return Layout::error(404, 'Коллекция не найдена', $result->getError());
            }

            $data = $result->getData();
            $collection = $data['collection'];
            $goods = $data['goods'];

            if (self::wantsJson($request)) {
                return Json::render(['collection' => $collection, 'goods' => array_values($goods)]);
            }

            $content = Engine::view('collection', ['collection' => $collection, 'goods' => $goods, 'csrf' => Csrf::token()]);
            return Layout::render($collection['name'] ?? 'Коллекция', $content);
        }

        public function runTests(Db $db): void
        {
            $testDb = clone $db;
            $testDb->goods['t-1'] = ['id' => 't-1', 'seller_id' => 'x', 'title' => 'Test Good', 'sales' => 10, 'category_id' => 'cat-1'];
            $testDb->collectionGoodIds['col-1'] = ['t-1'];

            // Тест: коллекция существует
            $res = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['collection_id' => 'col-1']]);
            if ($res->isFailure()) {
                throw new RuntimeException('Collection detail domain test failed: collection not found.');
            }
            $data = $res->getData();
            if (!isset($data['collection']) || !isset($data['goods'])) {
                throw new RuntimeException('Collection detail domain test failed: missing data.');
            }

            // Тест: JSON ответ
            $json = $this->response($res, ['GET' => ['format' => 'json', 'collection_id' => 'col-1'], 'METHOD' => 'GET']);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['collection']) || !isset($decoded['goods'])) {
                throw new RuntimeException('Collection detail JSON response is not valid.');
            }

            // Тест: HTML ответ
            $html = $this->response($res, ['GET' => ['collection_id' => 'col-1'], 'METHOD' => 'GET']);
            if (strpos($html, 'Популярные товары') === false) {
                throw new RuntimeException('Collection detail HTML response missing collection name.');
            }

            echo "[PASS] collection\n";
        }
    },

    // --- COLLECTION_EDIT: редактирование коллекции -------------------------
    'collection_edit' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            // Проверяем авторизацию
            if (!Auth::check()) {
                return DomainResult::failure('unauthorized');
            }

            $collectionId = $request['GET']['collection_id'] ?? null;

            if ($collectionId === null || $collectionId === '') {
                return DomainResult::failure('Не указан ID коллекции.');
            }

            // Находим коллекцию по ID
            $collection = null;
            foreach ($db->collections as $col) {
                if (($col['id'] ?? '') === $collectionId) {
                    $collection = $col;
                    break;
                }
            }

            if ($collection === null) {
                return DomainResult::failure('Коллекция не найдена.');
            }

            // Если это POST-запрос, сохраняем изменения
            if (($request['METHOD'] ?? 'GET') === 'POST') {
                $name = trim((string)($request['POST']['name'] ?? ''));
                $description = trim((string)($request['POST']['description'] ?? ''));
                $goodIds = (array)($request['POST']['good_ids'] ?? []);

                if ($name === '') {
                    return DomainResult::failure('Название коллекции не может быть пустым.');
                }

                // Обновляем данные коллекции
                $db->collections[$collectionId]['name'] = $name;
                $db->collections[$collectionId]['description'] = $description;
                
                // Обновляем связь многие-ко-многим
                $db->collectionGoodIds[$collectionId] = $goodIds;

                return DomainResult::success(['status' => 'updated', 'collection' => $db->collections[$collectionId]]);
            }

            // Для GET-запроса возвращаем текущие данные
            $collectionGoodIds = $db->collectionGoodIds[$collectionId] ?? [];
            
            return DomainResult::success([
                'collection' => $collection,
                'goods' => $db->goods,
                'collectionGoodIds' => $collectionGoodIds,
            ]);
        }

        public function response(DomainResult $result, array $request): string
        {
            if ($result->isFailure()) {
                $error = $result->getError();
                if ($error === 'unauthorized') {
                    return $this->redirect('?action=login');
                }
                if (self::wantsJson($request)) {
                    return Json::error($result->getError(), 400);
                }
                return Layout::error(400, 'Ошибка', $result->getError());
            }

            $data = $result->getData();
            
            // Если успешно обновлено - редирект на страницу коллекции
            if (($data['status'] ?? '') === 'updated') {
                $collection = $data['collection'];
                return $this->redirect('?action=collection&collection_id=' . urlencode($collection['id']));
            }

            $collection = $data['collection'];
            $goods = $data['goods'];
            $collectionGoodIds = $data['collectionGoodIds'] ?? [];

            if (self::wantsJson($request)) {
                return Json::render([
                    'collection' => $collection,
                    'goods' => array_values($goods),
                    'collectionGoodIds' => $collectionGoodIds,
                ]);
            }

            $content = Engine::view('collection_edit', [
                'collection' => $collection,
                'goods' => $goods,
                'collectionGoodIds' => $collectionGoodIds,
                'csrf' => Csrf::token(),
            ]);
            return Layout::render('Редактирование: ' . ($collection['name'] ?? 'Коллекция'), $content);
        }

        public function runTests(Db $db): void
        {
            Auth::setMockSession(['user' => ['id' => 'seller_123', 'name' => 'Test Dev']]);
            try {
                $testDb = clone $db;
                $testDb->goods['t-1'] = ['id' => 't-1', 'seller_id' => 'x', 'title' => 'Test Good', 'sales' => 10, 'category_id' => 'cat-1'];
                $testDb->goods['t-2'] = ['id' => 't-2', 'seller_id' => 'x', 'title' => 'Another Good', 'sales' => 5, 'category_id' => 'cat-1'];

                // Тест: GET запрос для отображения формы
                $res = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['collection_id' => 'col-1']]);
                if ($res->isFailure()) {
                    throw new RuntimeException('Collection edit GET domain test failed.');
                }
                $data = $res->getData();
                if (!isset($data['collection']) || !isset($data['goods'])) {
                    throw new RuntimeException('Collection edit GET domain test failed: missing data.');
                }

                // Тест: POST запрос для обновления
                $postRes = $this->domain($testDb, [
                    'METHOD' => 'POST',
                    'GET' => ['collection_id' => 'col-1'],
                    'POST' => [
                        'csrf_token' => Csrf::token(),
                        'name' => 'Обновлённая коллекция',
                        'description' => 'Новое описание',
                        'good_ids' => ['t-1', 't-2'],
                    ],
                ]);
                if ($postRes->isFailure()) {
                    throw new RuntimeException('Collection edit POST domain test failed.');
                }
                $postData = $postRes->getData();
                if (($postData['status'] ?? '') !== 'updated') {
                    throw new RuntimeException('Collection edit POST did not return updated status.');
                }
                if ($testDb->collections['col-1']['name'] !== 'Обновлённая коллекция') {
                    throw new RuntimeException('Collection edit POST did not update name.');
                }
                if ($testDb->collectionGoodIds['col-1'] !== ['t-1', 't-2']) {
                    throw new RuntimeException('Collection edit POST did not update good associations.');
                }

                // Тест: HTML ответ с формой
                $html = $this->response($res, ['GET' => ['collection_id' => 'col-1'], 'METHOD' => 'GET']);
                if (strpos($html, 'Редактирование коллекции') === false) {
                    throw new RuntimeException('Collection edit HTML response missing title.');
                }

                echo "[PASS] collection_edit\n";
            } finally {
                Auth::setMockSession(null);
            }
        }
    },

    // --- CATEGORY_DETAIL: детальная информация по категории -----------------
    'category_detail' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            $categorySlug = $request['GET']['slug'] ?? null;
            
            if ($categorySlug === null || $categorySlug === '') {
                return DomainResult::failure('Не указан slug категории.');
            }

            // Находим категорию по slug
            $category = null;
            foreach ($db->categories as $cat) {
                if (($cat['slug'] ?? '') === $categorySlug) {
                    $category = $cat;
                    break;
                }
            }

            if ($category === null) {
                return DomainResult::failure('Категория не найдена.');
            }

            // Фильтруем товара по category_id
            $goods = [];
            foreach ($db->goods as $good) {
                if (($good['category_id'] ?? '') === $category['id']) {
                    $goods[$good['id']] = $good;
                }
            }

            return DomainResult::success(['category' => $category, 'goods' => $goods]);
        }

        public function response(DomainResult $result, array $request): string
        {
            if ($result->isFailure()) {
                if (self::wantsJson($request)) {
                    return Json::error($result->getError(), 404);
                }
                return Layout::error(404, 'Категория не найдена', $result->getError());
            }

            $data = $result->getData();
            $category = $data['category'];
            $goods = $data['goods'];

            if (self::wantsJson($request)) {
                return Json::render(['category' => $category, 'goods' => array_values($goods)]);
            }

            $content = Engine::view('category_detail', ['category' => $category, 'goods' => $goods, 'csrf' => Csrf::token()]);
            return Layout::render($category['name'] ?? 'Категория', $content);
        }

        public function runTests(Db $db): void
        {
            $testDb = clone $db;
            $testDb->goods['t-1'] = ['id' => 't-1', 'seller_id' => 'x', 'title' => 'Test Good', 'sales' => 10, 'category_id' => 'cat-1'];

            // Тест: категория существует
            $res = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['slug' => 'messengers']]);
            if ($res->isFailure()) {
                throw new RuntimeException('Category detail domain test failed: category not found.');
            }
            $data = $res->getData();
            if (!isset($data['category']) || !isset($data['goods'])) {
                throw new RuntimeException('Category detail domain test failed: missing data.');
            }

            // Тест: JSON ответ
            $json = $this->response($res, ['GET' => ['format' => 'json', 'slug' => 'messengers'], 'METHOD' => 'GET']);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['category']) || !isset($decoded['goods'])) {
                throw new RuntimeException('Category detail JSON response is not valid.');
            }

            // Тест: HTML ответ
            $html = $this->response($res, ['GET' => ['slug' => 'messengers'], 'METHOD' => 'GET']);
            if (strpos($html, 'Мессенджеры') === false) {
                throw new RuntimeException('Category detail HTML response missing category name.');
            }

            echo "[PASS] category_detail\n";
        }
    },

    // --- CART: корзина пользователя -----------------------------------------
    'cart' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            // Получаем корзину из сессии или используем пустую
            $cart = $_SESSION['cart'] ?? [];
            
            // Собираем полную информацию о товарах в корзине
            $cartItems = [];
            foreach ($cart as $goodId => $itemData) {
                if (isset($db->goods[$goodId])) {
                    $good = $db->goods[$goodId];
                    $cartItems[$goodId] = [
                        'id' => $goodId,
                        'title' => $good['title'],
                        'price' => $good['price'],
                        'quantity' => $itemData['quantity'] ?? 1,
                        'added_at' => $itemData['added_at'] ?? time(),
                    ];
                }
            }
            
            return DomainResult::success(['cartItems' => $cartItems]);
        }

        public function response(DomainResult $result, array $request): string
        {
            if (self::wantsJson($request)) {
                if ($result->isFailure()) {
                    return Json::error($result->getError(), 400);
                }
                return Json::render($result->getData());
            }

            $data = $result->getData();
            $content = Engine::view('cart', [
                'cartItems' => $data['cartItems'] ?? [],
                'csrf' => Csrf::token(),
            ]);
            return Layout::render('Корзина', $content);
        }

        public function runTests(Db $db): void
        {
            // Тест: пустая корзина
            $_SESSION['cart'] = [];
            $res = $this->domain($db, ['METHOD' => 'GET']);
            if ($res->isFailure()) {
                throw new RuntimeException('Cart domain test failed for empty cart.');
            }
            $data = $res->getData();
            if (!empty($data['cartItems'])) {
                throw new RuntimeException('Cart should be empty initially.');
            }

            // Тест: корзина с товаром
            $_SESSION['cart'] = [
                'good-1' => ['quantity' => 2, 'added_at' => time()],
            ];
            $res = $this->domain($db, ['METHOD' => 'GET']);
            if ($res->isFailure()) {
                throw new RuntimeException('Cart domain test failed for non-empty cart.');
            }
            $data = $res->getData();
            if (!isset($data['cartItems']['good-1'])) {
                throw new RuntimeException('Cart should contain good-1.');
            }
            if ($data['cartItems']['good-1']['quantity'] !== 2) {
                throw new RuntimeException('Cart quantity mismatch.');
            }

            // Тест: JSON ответ
            $json = $this->response($res, ['GET' => ['format' => 'json'], 'METHOD' => 'GET']);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['cartItems'])) {
                throw new RuntimeException('Cart JSON response is not valid.');
            }

            echo "[PASS] cart\n";
        }
    },

    // --- CART_ADD: добавление товара в корзину ------------------------------
    'cart_add' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Метод должен быть POST.');
            }

            $goodId = (string)($request['POST']['good_id'] ?? '');
            $quantity = max(1, (int)($request['POST']['quantity'] ?? 1));

            if (!isset($db->goods[$goodId])) {
                return DomainResult::failure('Товар не найден.');
            }

            // Инициализируем корзину в сессии если нужно
            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }

            // Добавляем или обновляем товар в корзине
            if (isset($_SESSION['cart'][$goodId])) {
                $_SESSION['cart'][$goodId]['quantity'] += $quantity;
            } else {
                $_SESSION['cart'][$goodId] = [
                    'quantity' => $quantity,
                    'added_at' => time(),
                ];
            }

            return DomainResult::success(['status' => 'added', 'good_id' => $goodId]);
        }

        public function response(DomainResult $result, array $request): string
        {
            if ($result->isSuccess()) {
                return $this->redirect('?action=cart');
            }
            
            // В случае ошибки - возвращаемся на каталог
            return $this->redirect('?action=catalog');
        }

        public function runTests(Db $db): void
        {
            // Тест: добавление товара
            $_SESSION['cart'] = [];
            $res = $this->domain($db, [
                'METHOD' => 'POST',
                'POST' => ['good_id' => 'good-1', 'quantity' => 1, 'csrf_token' => Csrf::token()],
            ]);
            if ($res->isFailure()) {
                throw new RuntimeException('Cart add domain test failed.');
            }
            if (!isset($_SESSION['cart']['good-1'])) {
                throw new RuntimeException('Cart should contain good-1 after add.');
            }
            if ($_SESSION['cart']['good-1']['quantity'] !== 1) {
                throw new RuntimeException('Cart quantity should be 1.');
            }

            // Тест: добавление того же товара ещё раз
            $res = $this->domain($db, [
                'METHOD' => 'POST',
                'POST' => ['good_id' => 'good-1', 'quantity' => 2, 'csrf_token' => Csrf::token()],
            ]);
            if ($_SESSION['cart']['good-1']['quantity'] !== 3) {
                throw new RuntimeException('Cart quantity should be 3 after second add.');
            }

            // Тест: добавление несуществующего товара
            $res = $this->domain($db, [
                'METHOD' => 'POST',
                'POST' => ['good_id' => 'nonexistent', 'quantity' => 1, 'csrf_token' => Csrf::token()],
            ]);
            if ($res->isSuccess()) {
                throw new RuntimeException('Adding nonexistent good should fail.');
            }

            echo "[PASS] cart_add\n";
        }
    },

    // --- CART_REMOVE: удаление товара из корзины ----------------------------
    'cart_remove' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Метод должен быть POST.');
            }

            $itemId = (string)($request['POST']['item_id'] ?? '');

            if (!isset($_SESSION['cart'][$itemId])) {
                return DomainResult::failure('Товар не найден в корзине.');
            }

            // Удаляем товар из корзины
            unset($_SESSION['cart'][$itemId]);

            return DomainResult::success(['status' => 'removed', 'item_id' => $itemId]);
        }

        public function response(DomainResult $result, array $request): string
        {
            return $this->redirect('?action=cart');
        }

        public function runTests(Db $db): void
        {
            // Тест: удаление товара
            $_SESSION['cart'] = [
                'good-1' => ['quantity' => 2, 'added_at' => time()],
            ];
            $res = $this->domain($db, [
                'METHOD' => 'POST',
                'POST' => ['item_id' => 'good-1', 'csrf_token' => Csrf::token()],
            ]);
            if ($res->isFailure()) {
                throw new RuntimeException('Cart remove domain test failed.');
            }
            if (isset($_SESSION['cart']['good-1'])) {
                throw new RuntimeException('Cart should not contain good-1 after remove.');
            }

            // Тест: удаление несуществующего товара
            $res = $this->domain($db, [
                'METHOD' => 'POST',
                'POST' => ['item_id' => 'nonexistent', 'csrf_token' => Csrf::token()],
            ]);
            if ($res->isSuccess()) {
                throw new RuntimeException('Removing nonexistent item should fail.');
            }

            echo "[PASS] cart_remove\n";
        }
    },
];

// =========================================================================
// 4. РАНТАЙМ
// =========================================================================

if (!IS_CLI) {
    if (!isset($_SESSION['mock_db'])) {
        $_SESSION['mock_db'] = new Db();
    }
    $db = $_SESSION['mock_db'];
} else {
    $db = new Db();
}

// --- CI-режим ---
$runCi = (IS_CLI && in_array('--run-ci', $argv ?? [], true))
      || (!IS_CLI && isset($_GET['run_ci']));

if ($runCi) {
    if (!IS_CLI && APP_ENV !== 'dev') {
        http_response_code(403);
        exit('Тесты доступны только в APP_ENV=dev.');
    }

    // TEST_MODE заставляет BaseAdrSlice::redirect() возвращать маркер,
    // а не вызывать exit. Без этого тест-раннер умирает на первом редиректе.
    if (!defined('TEST_MODE')) {
        define('TEST_MODE', true);
    }

    if (!IS_CLI) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "=== CI: ЗАПУСК ВСТРОЕННЫХ ТЕСТОВ ===\n";
    $failed = 0;
    foreach ($features as $name => $feature) {
        try {
            $feature->runTests($db);
        } catch (Throwable $e) {
            $failed++;
            echo "[FAIL] {$name}: " . $e->getMessage() . "\n";
        }
    }
    echo $failed === 0
        ? "=== ВСЕ ТЕСТЫ ПРОЙДЕНЫ ===\n"
        : "=== ПРОВАЛЕНО: {$failed} ===\n";
    exit($failed === 0 ? 0 : 1);
}

// --- CLI без --run-ci ---
if (IS_CLI) {
    fwrite(STDERR, "CLI: используйте флаг --run-ci для запуска тестов.\n");
    exit(1);
}

// =========================================================================
// 4. ОБРАБОТКА СТАТИЧЕСКИХ ФАЙЛОВ
// =========================================================================
$staticDir = __DIR__ . '/public';
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

// Если запрос начинается с /public/, отдаём статический файл
if (str_starts_with($requestUri, '/public/')) {
    $relativePath = substr($requestUri, strlen('/public/'));
    
    // Защита от выхода за пределы директории (path traversal)
    if (strpos($relativePath, '..') !== false || strpos($relativePath, '/') !== false) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Доступ запрещён';
        exit;
    }
    
    $filePath = $staticDir . '/' . $relativePath;
    
    if (is_file($filePath)) {
        // Определение MIME-типа по расширению
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'js'   => 'application/javascript; charset=utf-8',
            'css'  => 'text/css; charset=utf-8',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
            'ttf'  => 'font/ttf',
            'eot'  => 'application/vnd.ms-fontobject',
            'txt'  => 'text/plain; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
        ];
        
        $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
        
        // Заголовки для кеширования статики
        header('Content-Type: ' . $mimeType);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($filePath));
        
        // Отдаём файл
        readfile($filePath);
        exit;
    } else {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Файл не найден';
        exit;
    }
}

// --- HTTP-роутинг ---
$action = $_GET['action'] ?? 'catalog';

if (isset($features[$action])) {
    $requestPayload = [
        'METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'GET'    => $_GET,
        'POST'   => $_POST,
    ];
    echo $features[$action]($db, $requestPayload);
} else {
    $wantsJson = ($_GET['format'] ?? '') === 'json';
    if ($wantsJson) {
        echo Json::error('Страница не найдена', 404);
    } else {
        echo Layout::error(404, 'Страница не найдена', 'Проверьте адрес или вернитесь в каталог.');
    }
}