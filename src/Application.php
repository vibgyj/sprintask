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
    }

    public function run(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

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
                $this->session->regenerate();
                $_SESSION['user_id'] = (int) $result['user_id'];
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
                $this->session->regenerate();
                $_SESSION['user_id'] = (int) $result['user_id'];
                unset($_SESSION['otp_login_email']);
                $this->flash('success', 'Logged in.');
                $this->redirect('/');
            }
            $this->flash('error', (string) $result['error']);
            $this->redirect('/auth/login/verify');
        }

        if ($path === '/logout' && $method === 'POST') {
            $this->requireCsrf();
            $_SESSION = [];
            session_destroy();
            $this->redirect('/auth/login');
        }

        $user = $this->requireUser();

        if ($path === '/' && $method === 'GET') {
            $this->renderDashboard($user);
            return;
        }

        if ($path === '/categories/create' && $method === 'POST') {
            $this->requireCsrf();
            $this->work->createCategory((int) $user['id'], (string) ($_POST['name'] ?? ''));
            $this->redirect('/settings');
        }
        if ($path === '/categories/update' && $method === 'POST') {
            $this->requireCsrf();
            $this->work->renameCategory((int) $user['id'], (int) ($_POST['category_id'] ?? 0), (string) ($_POST['name'] ?? ''));
            $this->redirect('/settings');
        }
        if ($path === '/categories/delete' && $method === 'POST') {
            $this->requireCsrf();
            if (($_POST['confirm'] ?? '') !== 'DELETE') {
                throw new \RuntimeException('Confirmation phrase missing.');
            }
            $this->work->deleteCategory((int) $user['id'], (int) ($_POST['category_id'] ?? 0));
            $this->redirect('/settings');
        }

        if ($path === '/tasks/create' && $method === 'POST') {
            $this->requireCsrf();
            $this->work->createTask((int) $user['id'], (int) ($_POST['category_id'] ?? 0), (string) ($_POST['name'] ?? ''));
            $this->redirect('/settings');
        }
        if ($path === '/tasks/update' && $method === 'POST') {
            $this->requireCsrf();
            $this->work->renameTask((int) $user['id'], (int) ($_POST['task_id'] ?? 0), (string) ($_POST['name'] ?? ''));
            $this->redirect('/settings');
        }
        if ($path === '/tasks/delete' && $method === 'POST') {
            $this->requireCsrf();
            if (($_POST['confirm'] ?? '') !== 'DELETE') {
                throw new \RuntimeException('Confirmation phrase missing.');
            }
            $this->work->deleteTask((int) $user['id'], (int) ($_POST['task_id'] ?? 0));
            $this->redirect('/settings');
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
            $this->work->updateEntry((int) $user['id'], (int) ($_POST['entry_id'] ?? 0), (string) ($_POST['started_at'] ?? ''), (string) ($_POST['ended_at'] ?? ''), (string) $user['timezone']);
            $this->redirect('/');
        }
        if ($path === '/entries/delete' && $method === 'POST') {
            $this->requireCsrf();
            $this->work->deleteEntry((int) $user['id'], (int) ($_POST['entry_id'] ?? 0));
            $this->redirect('/');
        }

        if ($path === '/reports' && $method === 'GET') {
            $mode = ($_GET['mode'] ?? 'week') === 'month' ? 'month' : 'week';
            $anchor = (string) ($_GET['date'] ?? date('Y-m-d'));
            $report = $this->reports->build((int) $user['id'], (string) $user['timezone'], $mode, $anchor);
            $this->render('reports.php', ['report' => $report, 'user' => $user]);
            return;
        }

        if ($path === '/settings' && $method === 'GET') {
            $this->render('settings.php', [
                'user' => $user,
                'categories' => $this->work->categoriesWithTasks((int) $user['id']),
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
        $entries = $this->work->recentEntries((int) $user['id']);
        $this->render('dashboard.php', [
            'user' => $user,
            'categories' => $categories,
            'composer' => $composer,
            'running' => $running,
            'entries' => $entries,
        ]);
    }

    private function render(string $template, array $data = []): void
    {
        $data['template'] = $template;
        $data['csrf_token'] = $this->csrf->token();
        $data['flash'] = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        $data['current_user'] = $this->currentUser();

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
        $id = (int) ($_SESSION['user_id'] ?? 0);
        return $id > 0 ? $this->auth->findUserById($id) : null;
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
        header('Location: ' . $path);
        exit;
    }

    private function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    }
}
