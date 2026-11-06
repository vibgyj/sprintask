<?php

declare(strict_types=1);

namespace App;

use App\Mail\Mailer;
use App\Services\AuthService;
use App\Services\ReportService;
use App\Services\WorkService;

final class Application
{
    private Csrf $csrf;
    private Session $session;
    private AuthService $auth;
    private WorkService $work;
    private ReportService $reports;
    private string $basePath;
    private string $rememberCookieName;
    private bool $currentUserResolved = false;
    private ?array $currentUserCache = null;

    public function __construct(
        private readonly array $config,
        private readonly Database $database,
        Mailer $mailer,
        private readonly string $root
    ) {
        $this->csrf = new Csrf($config['security']['csrf_key']);
        $this->session = new Session($config['session']['name'] ?? 'sprintask_session');
        $this->auth = new AuthService($database, $mailer, $config['security']);
        $this->work = new WorkService($database);
        $this->reports = new ReportService($database);
        $this->basePath = UrlPath::resolveBasePath($_SERVER, (string) ($config['app']['base_url'] ?? ''));
        $this->rememberCookieName = (string) ($config['session']['remember_cookie'] ?? 'sprintask_remember');
    }

    public function run(): void
    {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $requestPath = is_string($requestPath) ? $requestPath : '/';
        $path = UrlPath::stripBasePath($requestPath, $this->basePath);
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $this->attemptRememberLogin();

        try {
            $this->route($path, $method);
        } catch (\Throwable $e) {
            http_response_code(500);
            $this->render('error.php', ['message' => 'Unexpected error: ' . $e->getMessage()]);
        }
    }

    private function route(string $path, string $method): void
    {
        if ($path === '/' && !$this->currentUser()) {
            $this->redirect('/auth/login');
        }

        if ($path === '/auth/register' && $method === 'GET') {
            $this->render('auth_request.php', ['purpose' => 'register']);
            return;
        }
        if ($path === '/auth/register' && $method === 'POST') {
            $this->requireCsrf();
            $email = (string) ($_POST['email'] ?? '');
            $name = trim((string) ($_POST['name'] ?? ''));
            $result = $this->auth->requestOtp('register', $email, $name, $this->ip(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            if ($result['ok']) {
                $_SESSION['otp_register_name'] = $name;
                $_SESSION['otp_register_email'] = $email;
                $this->flash('success', 'Verification code sent.');
                $this->redirect('/auth/register/verify');
            }
            $this->flash('error', (string) $result['error']);
            $this->redirect('/auth/register');
        }
        if ($path === '/auth/register/verify' && $method === 'GET') {
            $this->render('auth_verify.php', [
                'purpose' => 'register',
                'email' => (string) ($_SESSION['otp_register_email'] ?? ''),
            ]);
            return;
        }
        if ($path === '/auth/register/verify' && $method === 'POST') {
            $this->requireCsrf();
            $email = (string) ($_SESSION['otp_register_email'] ?? ($_POST['email'] ?? ''));
            $name = (string) ($_SESSION['otp_register_name'] ?? '');
            $code = (string) ($_POST['code'] ?? '');
            $result = $this->auth->verifyOtp('register', $email, $code, $name);
            if ($result['ok']) {
                $userId = (int) $result['user_id'];
                $this->session->regenerate();
                $_SESSION['user_id'] = $userId;
                $_SESSION['session_version'] = $this->auth->sessionVersion($userId);
                unset($_SESSION['otp_register_name'], $_SESSION['otp_register_email']);
                $this->flash('success', 'Welcome! Registration complete.');
                $this->redirect('/');
            }
            $this->flash('error', (string) $result['error']);
            $this->redirect('/auth/register/verify');
        }

        if ($path === '/auth/login' && $method === 'GET') {
            $this->render('auth_request.php', ['purpose' => 'login']);
            return;
        }
        if ($path === '/auth/login' && $method === 'POST') {
            $this->requireCsrf();
            $email = (string) ($_POST['email'] ?? '');
            $result = $this->auth->requestOtp('login', $email, null, $this->ip(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            if ($result['ok']) {
                $_SESSION['otp_login_email'] = $email;
                $this->flash('success', 'Verification code sent.');
                $this->redirect('/auth/login/verify');
            }
            $this->flash('error', (string) $result['error']);
            $this->redirect('/auth/login');
        }
        if ($path === '/auth/login/verify' && $method === 'GET') {
            $this->render('auth_verify.php', [
                'purpose' => 'login',
                'email' => (string) ($_SESSION['otp_login_email'] ?? ''),
            ]);
            return;
        }
        if ($path === '/auth/login/verify' && $method === 'POST') {
            $this->requireCsrf();
            $email = (string) ($_SESSION['otp_login_email'] ?? ($_POST['email'] ?? ''));
            $code = (string) ($_POST['code'] ?? '');
            $result = $this->auth->verifyOtp('login', $email, $code);
            if ($result['ok']) {
                $userId = (int) $result['user_id'];
                $rememberMe = !empty($_POST['remember_me']);
                $logoutOtherDevices = !empty($_POST['logout_other_devices']);
                $login = $this->auth->completeLogin($userId, $rememberMe, $logoutOtherDevices, $this->ip(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

                $this->session->regenerate();
                $_SESSION['user_id'] = $userId;
                $_SESSION['session_version'] = $login['session_version'];
                unset($_SESSION['otp_login_email']);

                if ($login['remember'] !== null) {
                    $this->setRememberCookie($login['remember']['selector'], $login['remember']['validator'], $login['remember']['expires_at']);
                }

                $this->flash('success', $logoutOtherDevices ? 'Logged in. Other devices have been signed out.' : 'Logged in.');
                $this->redirect('/');
            }
            $this->flash('error', (string) $result['error']);
            $this->redirect('/auth/login/verify');
        }

        if ($path === '/logout' && $method === 'POST') {
            $this->requireCsrf();
            $this->revokeRememberCookie();
            $_SESSION = [];
            session_destroy();
            $this->redirect('/auth/login');
        }

        $user = $this->requireUser();

        if ($path === '/' && $method === 'GET') {
            $this->renderDashboard($user);
            return;
        }

        $settingsActions = [
            '/categories/create' => fn() => $this->work->createCategory((int) $user['id'], (string) ($_POST['name'] ?? '')),
            '/categories/update' => fn() => $this->work->renameCategory((int) $user['id'], (int) ($_POST['category_id'] ?? 0), (string) ($_POST['name'] ?? '')),
            '/categories/delete' => fn() => $this->work->deleteCategory((int) $user['id'], (int) ($_POST['category_id'] ?? 0)),
            '/tasks/create' => fn() => $this->work->createTask((int) $user['id'], (int) ($_POST['category_id'] ?? 0), (string) ($_POST['name'] ?? '')),
            '/tasks/update' => fn() => $this->work->renameTask((int) $user['id'], (int) ($_POST['task_id'] ?? 0), (string) ($_POST['name'] ?? '')),
            '/tasks/delete' => fn() => $this->work->deleteTask((int) $user['id'], (int) ($_POST['task_id'] ?? 0)),
            '/settings/rows/update' => fn() => $this->work->updateCategoryAndTask(
                (int) $user['id'],
                (int) ($_POST['category_id'] ?? 0),
                (string) ($_POST['category_name'] ?? ''),
                isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int) $_POST['task_id'] : null,
                isset($_POST['task_name']) ? (string) $_POST['task_name'] : null
            ),
        ];
        if (isset($settingsActions[$path]) && $method === 'POST') {
            $this->requireCsrf();
            try {
                $settingsActions[$path]();
            } catch (\RuntimeException $e) {
                $this->flash('error', $e->getMessage());
            }
            $this->redirect($this->settingsReturnPath($_POST));
        }

        if ($path === '/timer/start' && $method === 'POST') {
            $this->requireCsrf();
            try {
                $this->work->resolveOrCreateAndStartTimer(
                    (int) $user['id'],
                    (string) ($_POST['category_name'] ?? ''),
                    (string) ($_POST['task_name'] ?? ''),
                    isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int) $_POST['category_id'] : null,
                    isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int) $_POST['task_id'] : null
                );
            } catch (\RuntimeException $e) {
                $this->flash('error', $e->getMessage());
            }
            $this->redirect('/');
        }
        if ($path === '/timer/resume' && $method === 'POST') {
            $this->requireCsrf();
            try {
                $this->work->resumeTask((int) $user['id'], (int) ($_POST['task_id'] ?? 0));
            } catch (\RuntimeException $e) {
                $this->flash('error', $e->getMessage());
            }
            $this->redirect($this->dashboardReturnPath($_POST));
        }
        if ($path === '/timer/stop' && $method === 'POST') {
            $this->requireCsrf();
            $this->work->stopTimer((int) $user['id']);
            $this->redirect('/');
        }

        if ($path === '/entries/create' && $method === 'POST') {
            $this->requireCsrf();
            $this->work->createManualEntry((int) $user['id'], (int) ($_POST['task_id'] ?? 0), (string) ($_POST['started_at'] ?? ''), (string) ($_POST['ended_at'] ?? ''), (string) $user['timezone']);
            $this->redirect('/');
        }
        if ($path === '/entries/update' && $method === 'POST') {
            $this->requireCsrf();
            try {
                $this->work->updateEntry((int) $user['id'], (int) ($_POST['entry_id'] ?? 0), (string) ($_POST['started_at'] ?? ''), (string) ($_POST['ended_at'] ?? ''), (string) $user['timezone']);
            } catch (\RuntimeException $e) {
                $this->flash('error', $e->getMessage());
            }
            $this->redirect($this->dashboardReturnPath($_POST));
        }
        if ($path === '/entries/delete' && $method === 'POST') {
            $this->requireCsrf();
            $this->work->deleteEntry((int) $user['id'], (int) ($_POST['entry_id'] ?? 0));
            $this->redirect($this->dashboardReturnPath($_POST));
        }

        if ($path === '/reports' && $method === 'GET') {
            $taskId = (int) ($_GET['task_id'] ?? 0);
            $report = $this->reports->report(
                (int) $user['id'],
                (string) $user['timezone'],
                (string) ($_GET['range'] ?? '7d'),
                (string) ($_GET['aggregate'] ?? '24h'),
                (string) ($_GET['start'] ?? ''),
                $taskId > 0 ? $taskId : null
            );
            $this->render('reports.php', [
                'report' => $report,
                'user' => $user,
                'task_options' => $this->work->timerComposerOptions((int) $user['id'])['tasks'],
            ]);
            return;
        }

        if ($path === '/settings' && $method === 'GET') {
            $grid = $this->work->settingsRows(
                (int) $user['id'],
                (string) ($_GET['sort'] ?? 'category'),
                (string) ($_GET['dir'] ?? 'asc'),
                (int) ($_GET['page'] ?? 1),
                (int) ($_GET['per'] ?? 10)
            );
            $this->render('settings.php', [
                'user' => $user,
                'categories' => $this->work->categoriesWithTasks((int) $user['id']),
                'grid' => $grid,
                'timezone_options' => Util::timezoneOptions(),
            ]);
            return;
        }
        if ($path === '/settings' && $method === 'POST') {
            $this->requireCsrf();
            $theme = (string) ($_POST['theme'] ?? 'system');
            if (!in_array($theme, ['system', 'light', 'dark'], true)) {
                $theme = 'system';
            }
            $timezone = (string) ($_POST['timezone'] ?? 'UTC');
            if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                $timezone = 'UTC';
            }
            $stmt = $this->database->pdo()->prepare('UPDATE users SET theme = :theme, timezone = :timezone WHERE id = :id');
            $stmt->execute([':theme' => $theme, ':timezone' => $timezone, ':id' => $user['id']]);
            $this->redirect('/settings');
        }

        http_response_code(404);
        $this->render('error.php', ['message' => 'Page not found.']);
    }

    private function renderDashboard(array $user): void
    {
        $categories = $this->work->categoriesWithTasks((int) $user['id']);
        $composer = $this->work->timerComposerOptions((int) $user['id']);
        $running = $this->work->runningEntry((int) $user['id']);
        $history = $this->reports->history((int) $user['id'], ReportService::normalizeRange($_GET['range'] ?? null));
        $this->render('dashboard.php', [
            'user' => $user,
            'categories' => $categories,
            'composer' => $composer,
            'running' => $running,
            'history' => $history,
            'server_now_utc' => time(),
        ]);
    }

    /** Dashboard URL that keeps the history range filter after an entry POST. */
    private function dashboardReturnPath(array $input): string
    {
        $range = ReportService::normalizeRange($input['range'] ?? null);
        return $range === '7d' ? '/' : '/?range=' . rawurlencode($range);
    }

    /** Settings URL that keeps the grid's sort/page/size after a POST. */
    private function settingsReturnPath(array $input): string
    {
        $query = [];
        foreach (['sort' => ['category', 'task'], 'dir' => ['asc', 'desc'], 'per' => ['10', '50', '100']] as $key => $allowed) {
            $value = (string) ($input['grid_' . $key] ?? '');
            if (in_array($value, $allowed, true)) {
                $query[$key] = $value;
            }
        }
        $page = (int) ($input['grid_page'] ?? 0);
        if ($page > 1) {
            $query['page'] = (string) $page;
        }
        return '/settings' . ($query ? '?' . http_build_query($query) : '');
    }

    private function render(string $template, array $data = []): void
    {
        $data['template'] = $template;
        $data['csrf_token'] = $this->csrf->token();
        $data['flash'] = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        $data['current_user'] = $this->currentUser();
        $data['base_path'] = $this->basePath;
        $data['url'] = fn(string $path = '/'): string => UrlPath::appPath($path, $this->basePath);

        extract($data, EXTR_SKIP);
        require $this->root . '/templates/layout.php';
    }

    private function requireUser(): array
    {
        $user = $this->currentUser();
        if (!$user) {
            $this->redirect('/auth/login');
        }
        return $user;
    }

    private function currentUser(): ?array
    {
        if ($this->currentUserResolved) {
            return $this->currentUserCache;
        }
        $this->currentUserResolved = true;

        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            return $this->currentUserCache = null;
        }

        $user = $this->auth->findUserById($id);
        if (!$user) {
            unset($_SESSION['user_id'], $_SESSION['session_version']);
            return $this->currentUserCache = null;
        }

        if ((int) ($_SESSION['session_version'] ?? -1) !== $this->auth->sessionVersion($id)) {
            // Session was invalidated elsewhere (e.g. "log out of other devices").
            unset($_SESSION['user_id'], $_SESSION['session_version']);
            $this->revokeRememberCookie();
            return $this->currentUserCache = null;
        }

        return $this->currentUserCache = $user;
    }

    /**
     * If there is no active session but a valid "remember me" cookie is
     * present, transparently re-establishes the session without requiring
     * another OTP.
     */
    private function attemptRememberLogin(): void
    {
        if (!empty($_SESSION['user_id'])) {
            return;
        }

        $cookie = $_COOKIE[$this->rememberCookieName] ?? null;
        if (!is_string($cookie) || $cookie === '') {
            return;
        }

        $result = $this->auth->loginFromRememberToken($cookie);
        if ($result === null) {
            $this->clearRememberCookie();
            return;
        }

        $this->session->regenerate();
        $_SESSION['user_id'] = $result['user_id'];
        $_SESSION['session_version'] = $result['session_version'];
        $this->setRememberCookie($result['selector'], $result['validator'], $result['expires_at']);
    }

    private function setRememberCookie(string $selector, string $validator, int $expiresAt): void
    {
        setcookie($this->rememberCookieName, $selector . ':' . $validator, [
            'expires' => $expiresAt,
            'path' => '/',
            'domain' => '',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$this->rememberCookieName] = $selector . ':' . $validator;
    }

    private function clearRememberCookie(): void
    {
        setcookie($this->rememberCookieName, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[$this->rememberCookieName]);
    }

    /**
     * Revokes the current device's remember-me token (DB row + cookie).
     * Used on explicit logout so a stolen/cached cookie cannot resurrect
     * the session after the user signs out.
     */
    private function revokeRememberCookie(): void
    {
        $cookie = $_COOKIE[$this->rememberCookieName] ?? null;
        if (is_string($cookie) && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            if ($selector !== '') {
                $this->auth->revokeRememberTokenBySelector($selector);
            }
        }
        $this->clearRememberCookie();
    }

    private function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    private function requireCsrf(): void
    {
        if (!$this->csrf->validate($_POST['_csrf'] ?? null)) {
            throw new \RuntimeException('Invalid CSRF token.');
        }
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    private function redirect(string $path): void
    {
        header('Location: ' . UrlPath::appPath($path, $this->basePath));
        exit;
    }

    private function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    }
}
