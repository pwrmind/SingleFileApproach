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
    $required = ['_layout', 'catalog', 'login', 'publish', 'error', 'app_details'];
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
    public array $apps = [
        'app-1' => ['id' => 'app-1', 'dev_id' => 'dev_123', 'title' => 'Telegram Dev', 'downloads' => 150],
    ];

    public array $users = [];

    public function __construct()
    {
        $this->users['dev@store.com'] = [
            'id'            => 'dev_123',
            'name'          => 'Алексей',
            'password_hash' => password_hash('123', PASSWORD_DEFAULT),
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
            return DomainResult::success($db->apps);
        }

        public function response(DomainResult $result, array $request): string
        {
            $apps = $result->getData();

            if (self::wantsJson($request)) {
                if ($result->isFailure()) {
                    return Json::error($result->getError(), 400);
                }
                return Json::render(['apps' => array_values($apps)]);
            }

            $content = Engine::view('catalog', ['apps' => $apps]);
            return Layout::render('Каталог', $content);
        }

        public function runTests(Db $db): void
        {
            $testDb = clone $db;
            $testDb->apps['t-1'] = ['id' => 't-1', 'dev_id' => 'x', 'title' => 'Test', 'downloads' => 0];

            $res = $this->domain($testDb, ['METHOD' => 'GET']);
            if ($res->isFailure() || !isset($res->getData()['t-1'])) {
                throw new RuntimeException('Catalog domain test failed.');
            }

            $json = $this->response($res, ['GET' => ['format' => 'json'], 'METHOD' => 'GET']);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['apps'])) {
                throw new RuntimeException('Catalog JSON response is not valid.');
            }
            $ids = array_column($decoded['apps'], 'id');
            if (!in_array('t-1', $ids, true)) {
                throw new RuntimeException('Catalog JSON missing expected app.');
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
                    return $this->redirect('?action=publish');
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
                    'POST'   => ['email' => 'dev@store.com', 'password' => 'wrong'],
                ]);
                if ($fail->isSuccess()) {
                    throw new RuntimeException('Login: bad password accepted.');
                }

                $ok = $this->domain($testDb, [
                    'METHOD' => 'POST',
                    'POST'   => ['email' => 'dev@store.com', 'password' => '123'],
                ]);
                if ($ok->isFailure() || !Auth::check()) {
                    throw new RuntimeException('Login: valid credentials rejected.');
                }

                // response() с успехом должен вернуть маркер редиректа, а не exit
                $out = $this->response($ok, ['METHOD' => 'POST']);
                if (strpos($out, BaseAdrSlice::TEST_REDIRECT_PREFIX . '?action=publish') === false) {
                    throw new RuntimeException('Login: success did not redirect to publish.');
                }
            } finally {
                Auth::setMockSession(null);
            }
            echo "[PASS] login\n";
        }
    },

    // --- PUBLISH: HTML + JSON ----------------------------------------------
    'publish' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            if (!Auth::check()) {
                return DomainResult::failure('unauthorized');
            }
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::success(['status' => 'show_form']);
            }

            $title = trim((string)($request['POST']['app_title'] ?? ''));
            if (mb_strlen($title) < 3) {
                return DomainResult::failure('Название приложения должно содержать минимум 3 символа.');
            }

            $newId = 'app-' . (count($db->apps) + 1);
            $db->apps[$newId] = [
                'id'        => $newId,
                'dev_id'    => Auth::user()['id'],
                'title'     => $title,
                'downloads' => 0,
            ];

            return DomainResult::success(['status' => 'created', 'id' => $newId]);
        }

        public function response(DomainResult $result, array $request): string
        {
            $json = self::wantsJson($request);

            if ($json) {
                if ($result->isFailure()) {
                    $code = $result->getError() === 'unauthorized' ? 403 : 400;
                    return Json::error($result->getError(), $code);
                }
                return Json::render($result->getData() ?? ['status' => 'ok']);
            }

            if ($result->isFailure() && $result->getError() === 'unauthorized') {
                return Layout::error(403, 'Доступ запрещён', 'Войдите, чтобы публиковать приложения.');
            }

            if ($result->isSuccess() && ($result->getData()['status'] ?? '') === 'created') {
                return $this->redirect('?action=catalog');
            }

            $content = Engine::view('publish', [
                'error' => $result->isFailure() ? $result->getError() : null,
                'csrf'  => Csrf::token(),
            ]);
            return Layout::render('Публикация', $content);
        }

        public function runTests(Db $db): void
        {
            Auth::setMockSession(['user' => ['id' => 'dev_123', 'name' => 'Tester']]);
            Csrf::setMockToken('valid_token');
            try {
                $testDb = clone $db;

                // CSRF-мидлварь отклоняет неверный токен
                $bad = $this($testDb, [
                    'METHOD' => 'POST',
                    'GET'    => [],
                    'POST'   => ['app_title' => 'Valid Title', 'csrf_token' => 'ATTACK'],
                ]);
                if (strpos($bad, 'CSRF') === false) {
                    throw new RuntimeException('Publish: CSRF middleware broken.');
                }

                // Успешный домен + маркер редиректа (HTML)
                $before = count($testDb->apps);
                $res = $this->domain($testDb, [
                    'METHOD' => 'POST',
                    'POST'   => ['app_title' => 'New Awesome App', 'csrf_token' => 'valid_token'],
                ]);
                if ($res->isFailure() || count($testDb->apps) !== $before + 1) {
                    throw new RuntimeException('Publish: domain logic failed.');
                }
                if (($res->getData()['id'] ?? null) === null) {
                    throw new RuntimeException('Publish: created app has no id.');
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

    // --- DELETE_APP: удаление приложения (только владелец) -----------------
    'delete_app' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            if (!Auth::check()) {
                return DomainResult::failure('unauthorized');
            }
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Метод не поддерживается. Используйте POST.');
            }

            $appId = trim((string)($request['POST']['app_id'] ?? ''));
            if ($appId === '') {
                return DomainResult::failure('Не указан ID приложения.');
            }

            if (!isset($db->apps[$appId])) {
                return DomainResult::failure('Приложение не найдено.');
            }

            $currentUserId = Auth::user()['id'];
            if ($db->apps[$appId]['dev_id'] !== $currentUserId) {
                return DomainResult::failure('Только владелец может удалить приложение.');
            }

            unset($db->apps[$appId]);
            return DomainResult::success(['status' => 'deleted', 'id' => $appId]);
        }

        public function response(DomainResult $result, array $request): string
        {
            $json = self::wantsJson($request);

            if ($json) {
                if ($result->isFailure()) {
                    $error = $result->getError();
                    $code = match ($error) {
                        'unauthorized' => 403,
                        'Приложение не найдено.', 'Только владелец может удалить приложение.' => 404,
                        default => 400,
                    };
                    return Json::error($result->getError(), $code);
                }
                return Json::render($result->getData() ?? ['status' => 'ok']);
            }

            if ($result->isFailure()) {
                $error = $result->getError();
                if ($error === 'unauthorized') {
                    return Layout::error(403, 'Доступ запрещён', 'Войдите, чтобы удалять приложения.');
                }
                if ($error === 'Приложение не найдено.' || $error === 'Только владелец может удалить приложение.') {
                    return Layout::error(404, 'Приложение не найдено', $error);
                }
                return Layout::error(400, 'Ошибка удаления', $error);
            }

            return $this->redirect('?action=catalog');
        }

        public function runTests(Db $db): void
        {
            Auth::setMockSession(['user' => ['id' => 'dev_123', 'name' => 'Tester']]);
            Csrf::setMockToken('valid_token');
            try {
                $testDb = clone $db;
                $testDb->apps['app-to-delete'] = [
                    'id' => 'app-to-delete',
                    'dev_id' => 'dev_123',
                    'title' => 'ToDelete',
                    'downloads' => 0,
                ];

                // CSRF-мидлварь отклоняет неверный токен
                $bad = $this($testDb, [
                    'METHOD' => 'POST',
                    'GET'    => [],
                    'POST'   => ['app_id' => 'app-to-delete', 'csrf_token' => 'ATTACK'],
                ]);
                if (strpos($bad, 'CSRF') === false) {
                    throw new RuntimeException('DeleteApp: CSRF middleware broken.');
                }

                // Успешное удаление
                $before = count($testDb->apps);
                $res = $this->domain($testDb, [
                    'METHOD' => 'POST',
                    'POST'   => ['app_id' => 'app-to-delete', 'csrf_token' => 'valid_token'],
                ]);
                if ($res->isFailure()) {
                    throw new RuntimeException('DeleteApp: domain logic failed: ' . $res->getError());
                }
                if (count($testDb->apps) !== $before - 1) {
                    throw new RuntimeException('DeleteApp: app not removed from DB.');
                }
                if (isset($testDb->apps['app-to-delete'])) {
                    throw new RuntimeException('DeleteApp: app still exists in DB.');
                }

                $htmlOut = $this->response($res, ['GET' => [], 'METHOD' => 'POST']);
                if (strpos($htmlOut, BaseAdrSlice::TEST_REDIRECT_PREFIX . '?action=catalog') === false) {
                    throw new RuntimeException('DeleteApp: success did not redirect to catalog.');
                }

                // JSON-ветка
                $testDb2 = clone $db;
                $testDb2->apps['app-to-delete2'] = [
                    'id' => 'app-to-delete2',
                    'dev_id' => 'dev_123',
                    'title' => 'ToDelete2',
                    'downloads' => 0,
                ];
                $res2 = $this->domain($testDb2, [
                    'METHOD' => 'POST',
                    'POST'   => ['app_id' => 'app-to-delete2', 'csrf_token' => 'valid_token'],
                ]);
                $json = $this->response($res2, ['GET' => ['format' => 'json'], 'METHOD' => 'POST']);
                $decoded = json_decode($json, true);
                if (($decoded['status'] ?? null) !== 'deleted') {
                    throw new RuntimeException('DeleteApp: JSON success response broken.');
                }

                // JSON-ошибка при неавторизованном доступе
                Auth::setMockSession([]);
                $unauthRes = $this->domain($testDb, ['METHOD' => 'POST', 'POST' => ['app_id' => 'app-1']]);
                $jsonErr = $this->response($unauthRes, ['GET' => ['format' => 'json'], 'METHOD' => 'POST']);
                $decodedErr = json_decode($jsonErr, true);
                if (($decodedErr['error'] ?? null) !== 'unauthorized') {
                    throw new RuntimeException('DeleteApp: JSON unauthorized response broken.');
                }

                // Ошибка: приложение не найдено
                Auth::setMockSession(['user' => ['id' => 'dev_123', 'name' => 'Tester']]);
                $notFoundRes = $this->domain($testDb, ['METHOD' => 'POST', 'POST' => ['app_id' => 'nonexistent']]);
                if ($notFoundRes->isSuccess()) {
                    throw new RuntimeException('DeleteApp: nonexistent app should fail.');
                }

                // Ошибка: не владелец
                Auth::setMockSession(['user' => ['id' => 'other_dev', 'name' => 'Other']]);
                $testDb->apps['app-other'] = [
                    'id' => 'app-other',
                    'dev_id' => 'dev_123',
                    'title' => 'OtherApp',
                    'downloads' => 0,
                ];
                $notOwnerRes = $this->domain($testDb, ['METHOD' => 'POST', 'POST' => ['app_id' => 'app-other']]);
                if ($notOwnerRes->isSuccess()) {
                    throw new RuntimeException('DeleteApp: non-owner should not delete.');
                }
            } finally {
                Auth::setMockSession(null);
                Csrf::setMockToken(null);
            }
            echo "[PASS] delete_app\n";
        }
    },

    // --- APP_DETAILS: детальная информация о приложении (read-only) --------
    'app_details' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $request): DomainResult
        {
            $appId = trim((string)($request['GET']['app_id'] ?? ''));
            if ($appId === '') {
                return DomainResult::failure('Не указан ID приложения.');
            }

            if (!isset($db->apps[$appId])) {
                return DomainResult::failure('Приложение не найдено.');
            }

            return DomainResult::success($db->apps[$appId]);
        }

        public function response(DomainResult $result, array $request): string
        {
            $json = self::wantsJson($request);

            if ($json) {
                if ($result->isFailure()) {
                    return Json::error($result->getError(), 404);
                }
                return Json::render(['app' => $result->getData()]);
            }

            if ($result->isFailure()) {
                return Layout::error(404, 'Приложение не найдено', $result->getError());
            }

            $app = $result->getData();
            $content = Engine::view('app_details', ['app' => $app]);
            return Layout::render(htmlspecialchars($app['title'] ?? 'Приложение', ENT_QUOTES), $content);
        }

        public function runTests(Db $db): void
        {
            $testDb = clone $db;
            $testDb->apps['t-details'] = [
                'id' => 't-details',
                'dev_id' => 'dev_123',
                'title' => 'Test Details App',
                'downloads' => 42,
            ];

            // domain() без app_id должен вернуть ошибку
            $noId = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => []]);
            if ($noId->isSuccess()) {
                throw new RuntimeException('AppDetails: missing app_id should fail.');
            }

            // domain() с несуществующим app_id должен вернуть ошибку
            $notFound = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['app_id' => 'nonexistent']]);
            if ($notFound->isSuccess()) {
                throw new RuntimeException('AppDetails: nonexistent app should fail.');
            }

            // domain() с существующим app_id должен вернуть данные
            $ok = $this->domain($testDb, ['METHOD' => 'GET', 'GET' => ['app_id' => 't-details']]);
            if ($ok->isFailure()) {
                throw new RuntimeException('AppDetails: valid app_id failed: ' . $ok->getError());
            }
            $data = $ok->getData();
            if (($data['id'] ?? null) !== 't-details' || ($data['title'] ?? null) !== 'Test Details App') {
                throw new RuntimeException('AppDetails: returned data mismatch.');
            }

            // HTML response
            $html = $this->response($ok, ['METHOD' => 'GET', 'GET' => ['app_id' => 't-details']]);
            if (strpos($html, 'Test Details App') === false) {
                throw new RuntimeException('AppDetails: HTML response missing app title.');
            }

            // JSON response
            $json = $this->response($ok, ['METHOD' => 'GET', 'GET' => ['app_id' => 't-details', 'format' => 'json']]);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['app'])) {
                throw new RuntimeException('AppDetails: JSON response missing app key.');
            }
            if (($decoded['app']['id'] ?? null) !== 't-details') {
                throw new RuntimeException('AppDetails: JSON response has wrong app id.');
            }

            // JSON error response
            $jsonErr = $this->response($notFound, ['METHOD' => 'GET', 'GET' => ['app_id' => 'nonexistent', 'format' => 'json']]);
            $decodedErr = json_decode($jsonErr, true);
            if (($decodedErr['error'] ?? null) === null) {
                throw new RuntimeException('AppDetails: JSON error response broken.');
            }

            echo "[PASS] app_details\n";
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