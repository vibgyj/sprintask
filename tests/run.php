<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use App\Database;
use App\Mail\LogMailer;
use App\Services\AuthService;
use App\Services\ReportService;
use App\Services\WorkService;
use App\UrlPath;
use App\Util;

require dirname(__DIR__) . '/public/bootstrap_resolver.php';

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function assertThrows(callable $fn, string $message): void {
    $thrown = false;
    try {
        $fn();
    } catch (Throwable) {
        $thrown = true;
    }
    assertTrue($thrown, $message);
}

function setupDb(): array {
    $tmp = sys_get_temp_dir() . '/sprintask_test_' . bin2hex(random_bytes(5)) . '.sqlite';
    $db = new Database($tmp);
    $db->migrate(dirname(__DIR__) . '/database/schema.sql');
    return [$db, $tmp];
}

function baseSecurity(): array {
    return [
        'otp_ttl_seconds' => 600,
        'otp_max_attempts' => 3,
        'otp_resend_cooldown_seconds' => 0,
        'otp_email_window_seconds' => 3600,
        'otp_email_max_requests' => 6,
        'otp_ip_window_seconds' => 3600,
        'otp_ip_max_requests' => 50,
        'remember_me_ttl_seconds' => 2592000,
    ];
}

function testOtpAndMail(): void {
    [$db, $path] = setupDb();
    $log = sys_get_temp_dir() . '/mail_' . bin2hex(random_bytes(3)) . '.log';
    $auth = new AuthService($db, new LogMailer($log), baseSecurity());

    $req = $auth->requestOtp('register', 'a@example.com', 'A', '127.0.0.1', 'test');
    assertTrue($req['ok'] === true, 'OTP request should succeed');
    assertTrue(is_file($log), 'Dev log mail should be created');

    $bad = $auth->verifyOtp('register', 'a@example.com', '000000', 'A');
    assertTrue($bad['ok'] === false, 'Bad OTP must fail');

    $ok = $auth->verifyOtp('register', 'a@example.com', (string) $req['code'], 'A');
    assertTrue($ok['ok'] === true, 'Good OTP must pass');

    $replay = $auth->verifyOtp('register', 'a@example.com', (string) $req['code'], 'A');
    assertTrue($replay['ok'] === false, 'OTP replay must fail after single use');

    unlink($path);
    unlink($log);
}

function testOtpExpiryAndMaxAttempts(): void {
    [$db, $path] = setupDb();
    $log = sys_get_temp_dir() . '/mail_' . bin2hex(random_bytes(3)) . '.log';
    $auth = new AuthService($db, new LogMailer($log), baseSecurity());

    $req = $auth->requestOtp('register', 'expire@example.com', 'Expire', '127.0.0.2', 'test');
    assertTrue($req['ok'] === true, 'OTP request for expiry test should succeed');

    $db->pdo()->exec('UPDATE otp_challenges SET expires_at_utc = ' . (time() - 1) . " WHERE email = 'expire@example.com'");
    $expired = $auth->verifyOtp('register', 'expire@example.com', (string) $req['code'], 'Expire');
    assertTrue($expired['ok'] === false, 'Expired OTP must fail');

    $maxReq = $auth->requestOtp('register', 'attempts@example.com', 'Attempts', '127.0.0.3', 'test');
    assertTrue($maxReq['ok'] === true, 'OTP request for attempt lockout should succeed');

    $auth->verifyOtp('register', 'attempts@example.com', '000000', 'Attempts');
    $auth->verifyOtp('register', 'attempts@example.com', '000000', 'Attempts');
    $auth->verifyOtp('register', 'attempts@example.com', '000000', 'Attempts');

    $afterLockout = $auth->verifyOtp('register', 'attempts@example.com', (string) $maxReq['code'], 'Attempts');
    assertTrue($afterLockout['ok'] === false, 'OTP should be unusable after max-attempt lockout');

    unlink($path);
    unlink($log);
}

function testOtpThrottleAndLoginFlow(): void {
    [$db, $path] = setupDb();
    $log = sys_get_temp_dir() . '/mail_' . bin2hex(random_bytes(3)) . '.log';
    $pdo = $db->pdo();
    $now = time();
    $pdo->exec("INSERT INTO users (email,name,timezone,theme,created_at_utc) VALUES ('login@example.com','Login','UTC','system',$now)");

    $cooldownSecurity = array_merge(baseSecurity(), [
        'otp_resend_cooldown_seconds' => 120,
    ]);
    $cooldownAuth = new AuthService($db, new LogMailer($log), $cooldownSecurity);
    $loginReq = $cooldownAuth->requestOtp('login', 'login@example.com', null, '127.0.0.4', 'test');
    assertTrue($loginReq['ok'] === true, 'Login OTP request should succeed for existing user');
    $cooldownReq = $cooldownAuth->requestOtp('login', 'login@example.com', null, '127.0.0.4', 'test');
    assertTrue($cooldownReq['ok'] === false, 'Immediate resend should be throttled by cooldown');

    $loginOk = $cooldownAuth->verifyOtp('login', 'login@example.com', (string) $loginReq['code']);
    assertTrue($loginOk['ok'] === true && (int) $loginOk['user_id'] === 1, 'Login OTP verification should return existing user');

    $unknownLogin = $cooldownAuth->requestOtp('login', 'missing@example.com', null, '127.0.0.5', 'test');
    assertTrue($unknownLogin['ok'] === false, 'Login OTP request should fail for missing account');

    [$dbThrottle, $pathThrottle] = setupDb();
    $throttleLog = sys_get_temp_dir() . '/mail_' . bin2hex(random_bytes(3)) . '.log';
    $throttleAuth = new AuthService($dbThrottle, new LogMailer($throttleLog), array_merge(baseSecurity(), [
        'otp_email_window_seconds' => 3600,
        'otp_email_max_requests' => 2,
        'otp_ip_window_seconds' => 3600,
        'otp_ip_max_requests' => 2,
        'otp_resend_cooldown_seconds' => 0,
    ]));

    $throttleAuth->requestOtp('register', 'rate@example.com', 'Rate', '127.0.0.6', 'test');
    $throttleAuth->requestOtp('register', 'rate@example.com', 'Rate', '127.0.0.6', 'test');
    $emailLimited = $throttleAuth->requestOtp('register', 'rate@example.com', 'Rate', '127.0.0.6', 'test');
    assertTrue($emailLimited['ok'] === false, 'Per-email throttle should reject excess OTP requests');

    $throttleAuth->requestOtp('register', 'ip1@example.com', 'IP1', '127.0.0.7', 'test');
    $throttleAuth->requestOtp('register', 'ip2@example.com', 'IP2', '127.0.0.7', 'test');
    $ipLimited = $throttleAuth->requestOtp('register', 'ip3@example.com', 'IP3', '127.0.0.7', 'test');
    assertTrue($ipLimited['ok'] === false, 'Per-IP throttle should reject excess OTP requests');

    unlink($path);
    unlink($log);
    unlink($pathThrottle);
    unlink($throttleLog);
}

function testOwnershipCascadeAndTimerIndex(): void {
    [$db, $path] = setupDb();
    $pdo = $db->pdo();
    $now = time();
    $pdo->exec("INSERT INTO users (email,name,timezone,theme,created_at_utc) VALUES ('u1@test','U1','UTC','system',$now),('u2@test','U2','UTC','system',$now)");
    $pdo->exec("INSERT INTO categories (user_id,name,created_at_utc) VALUES (1,'C1',$now),(2,'C2',$now)");
    $pdo->exec("INSERT INTO tasks (user_id,category_id,name,created_at_utc) VALUES (1,1,'T1',$now),(2,2,'T2',$now)");

    $work = new WorkService($db);
    $work->startTimer(1, 1);
    $failed = false;
    try { $work->startTimer(1, 1); } catch (Throwable) { $failed = true; }
    assertTrue($failed, 'Second running timer must fail');

    $failedOwnership = false;
    try { $work->createTask(1, 2, 'Bad link'); } catch (Throwable) { $failedOwnership = true; }
    assertTrue($failedOwnership, 'Cross-user task/category link should fail via composite FK');

    sleep(1);
    $work->stopTimer(1);
    assertThrows(static fn() => $work->deleteCategory(1, 1), 'Category delete must be blocked while it has time entries');
    assertThrows(static fn() => $work->deleteTask(1, 1), 'Task delete must be blocked while it has time entries');

    $pdo->exec('DELETE FROM categories WHERE id = 1');
    $count = (int) $pdo->query('SELECT COUNT(*) FROM time_entries WHERE user_id = 1')->fetchColumn();
    assertTrue($count === 0, 'FK cascade should still remove entries at the DB level');

    unlink($path);
}

function testReportClipping(): void {
    [$db, $path] = setupDb();
    $pdo = $db->pdo();
    $now = time();
    $pdo->exec("INSERT INTO users (email,name,timezone,theme,created_at_utc) VALUES ('dst@test','DST','America/New_York','system',$now)");
    $pdo->exec("INSERT INTO categories (user_id,name,created_at_utc) VALUES (1,'Build',$now)");
    $pdo->exec("INSERT INTO tasks (user_id,category_id,name,created_at_utc) VALUES (1,1,'Code',$now)");

    $start = (new DateTimeImmutable('2026-03-08 01:30:00', new DateTimeZone('America/New_York')))->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
    $end = (new DateTimeImmutable('2026-03-08 03:30:00', new DateTimeZone('America/New_York')))->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
    $pdo->exec("INSERT INTO time_entries (user_id,task_id,started_at_utc,ended_at_utc,created_at_utc,updated_at_utc) VALUES (1,1,$start,$end,$now,$now)");

    $reports = new ReportService($db);
    $week = $reports->report(1, 'America/New_York', '7d', '24h', '2026-03-07T00:00', null, $end + 10);
    assertTrue($week['overall']['total_seconds'] === 3600, 'DST 7d report should count the real 1h of the entry');
    assertTrue(count($week['buckets']) === 7, '7d range aggregated by 24h should yield 7 buckets');
    assertTrue($week['buckets'][1]['total_seconds'] === 3600, 'DST day bucket should hold the entry');
    assertTrue($week['buckets'][1]['end_local']->getTimestamp() - $week['buckets'][1]['start_local']->getTimestamp() === 23 * 3600, 'DST day bucket should be a 23h calendar day');

    $clipped = $reports->report(1, 'America/New_York', '24h', '24h', '2026-03-08T03:00', null, $end + 10);
    assertTrue($clipped['overall']['total_seconds'] === 1800, 'Report should clip entries to the window start');
    $tree = $clipped['overall']['categories'];
    assertTrue(count($tree) === 1 && $tree[0]['tasks'][0]['name'] === 'Code' && count($tree[0]['tasks'][0]['items']) === 1, 'Report tree should be category > task > entry');

    $clamped = $reports->report(1, 'America/New_York', '24h', '30d', '2026-03-08T03:00', null, $end + 10);
    assertTrue($clamped['aggregate'] === '24h' && count($clamped['buckets']) === 1, 'Aggregate larger than range should clamp to the range');

    $pdo->exec("INSERT INTO tasks (user_id,category_id,name,created_at_utc) VALUES (1,1,'Review',$now)");
    $pdo->exec('INSERT INTO time_entries (user_id,task_id,started_at_utc,ended_at_utc,created_at_utc,updated_at_utc) VALUES (1,2,' . ($start - 600) . ',' . $start . ",$now,$now)");
    $filtered = $reports->report(1, 'America/New_York', '7d', '7d', '2026-03-07T00:00', 2, $end + 10);
    assertTrue($filtered['overall']['total_seconds'] === 600, 'Task filter should restrict report to that task');

    $defaulted = $reports->report(1, 'America/New_York', '7d', '24h', '', null, $end + 10);
    assertTrue($defaulted['start_defaulted'] === true && $defaulted['start_local']->format('H:i') === '00:00', 'Missing start should default to local midnight');
    assertTrue($defaulted['end_local']->format('Y-m-d') === '2026-03-09', '7d default window should end at the end of today');

    $history = $reports->history(1, '24h', $end + 60);
    assertTrue($history['total_seconds'] === 3600 + 600, 'History should aggregate completed entries in the rolling window');
    $pdo->exec("INSERT INTO time_entries (user_id,task_id,started_at_utc,ended_at_utc,created_at_utc,updated_at_utc) VALUES (1,1,$end,NULL,$now,$now)");
    $historyRunning = $reports->history(1, '24h', $end + 60);
    assertTrue($historyRunning['total_seconds'] === 3600 + 600, 'History should exclude the running timer');
    assertTrue(ReportService::normalizeRange('bogus') === '7d', 'Unknown ranges should fall back to 7d');

    unlink($path);
}

function testManualEntryValidation(): void {
    [$db, $path] = setupDb();
    $pdo = $db->pdo();
    $now = time();
    $pdo->exec("INSERT INTO users (email,name,timezone,theme,created_at_utc) VALUES ('manual@test','Manual','UTC','system',$now)");
    $pdo->exec("INSERT INTO categories (user_id,name,created_at_utc) VALUES (1,'Ops',$now)");
    $pdo->exec("INSERT INTO tasks (user_id,category_id,name,created_at_utc) VALUES (1,1,'Fix',$now)");

    $work = new WorkService($db);

    assertThrows(
        static fn() => $work->createManualEntry(1, 1, '2026-01-01T10:00', '2026-01-01T09:00', 'UTC'),
        'Manual entry should reject end <= start'
    );

    assertThrows(
        static fn() => $work->createManualEntry(1, 1, 'not-a-date', '2026-01-01T11:00', 'UTC'),
        'Manual entry should reject invalid start date-time'
    );

    $work->createManualEntry(1, 1, '2026-01-01T10:00', '2026-01-01T11:00', 'UTC');
    $entryId = (int) $pdo->query('SELECT id FROM time_entries WHERE user_id = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();

    assertThrows(
        static fn() => $work->updateEntry(1, $entryId, '2026-01-01T12:00', '2026-01-01T11:00', 'UTC'),
        'Entry update should reject invalid manual ranges'
    );

    unlink($path);
}

function testNoCaseUniquenessAndResolveOrCreateStart(): void {
    [$db, $path] = setupDb();
    $pdo = $db->pdo();
    $now = time();
    $pdo->exec("INSERT INTO users (email,name,timezone,theme,created_at_utc) VALUES ('compose@test','Composer','UTC','system',$now)");

    $work = new WorkService($db);
    $work->createCategory(1, 'Build');
    assertThrows(
        static fn() => $work->createCategory(1, 'build'),
        'Category NOCASE uniqueness should reject duplicate names'
    );

    $work->createTask(1, 1, 'Code');
    assertThrows(
        static fn() => $work->createTask(1, 1, 'code'),
        'Task NOCASE uniqueness should reject duplicates in same category'
    );

    $work->resolveOrCreateAndStartTimer(1, 'Build', 'Code', 1, 1);
    $running = $work->runningEntry(1);
    assertTrue($running !== null && (int) $running['task_id'] === 1, 'Resolve/start should accept selected IDs that match names');
    $work->stopTimer(1);

    $work->resolveOrCreateAndStartTimer(1, 'Build', 'Deploy', 1, null);
    $runningNewTask = $work->runningEntry(1);
    assertTrue($runningNewTask !== null && strcasecmp((string) $runningNewTask['task_name'], 'Deploy') === 0, 'Resolve/start should create missing task only on Start');
    $work->stopTimer(1);

    $work->resolveOrCreateAndStartTimer(1, 'Ops', 'Review', null, null);
    $runningNewCategory = $work->runningEntry(1);
    assertTrue($runningNewCategory !== null && strcasecmp((string) $runningNewCategory['category_name'], 'Ops') === 0, 'Resolve/start should create missing category and task atomically');
    $work->stopTimer(1);

    assertThrows(
        static fn() => $work->resolveOrCreateAndStartTimer(1, '', 'Review', null, null),
        'Ambiguous typed task without selected category should be rejected by requiring category'
    );

    unlink($path);
}

function testCategoryScopedDuplicateTaskNames(): void {
    [$db, $path] = setupDb();
    $pdo = $db->pdo();
    $now = time();
    $pdo->exec("INSERT INTO users (email,name,timezone,theme,created_at_utc) VALUES ('scoped@test','Scoped','UTC','system',$now)");

    $work = new WorkService($db);
    $work->createCategory(1, 'Client A');
    $work->createCategory(1, 'Client B');
    $work->createTask(1, 1, 'Standup');
    $work->createTask(1, 2, 'Standup');

    $work->resolveOrCreateAndStartTimer(1, 'Client B', 'Standup', null, null);
    $running = $work->runningEntry(1);
    assertTrue($running !== null && strcasecmp((string) $running['category_name'], 'Client B') === 0, 'Category-scoped duplicate task names should resolve within selected category only');

    $work->stopTimer(1);
    unlink($path);
}

function testLocalTimeDisplayHelpers(): void {
    $utc = (new DateTimeImmutable('2026-07-10 18:19:00', new DateTimeZone('UTC')))->getTimestamp();
    $time = Util::utcToLocalTime($utc, 'UTC');
    $full = Util::utcToLocalDateTime($utc, 'UTC');

    assertTrue($time === '06:19 PM', 'Visible time helper should output 12-hour clock');
    assertTrue($full === '2026-07-10 06:19 PM', 'Full datetime helper should include date and local time');
}

function testUrlBasePathHelpers(): void {
    assertTrue(UrlPath::basePathFromScript('/index.php') === '', 'Root index.php should resolve empty base path');
    assertTrue(UrlPath::basePathFromScript('/sprintask/index.php') === '/sprintask', 'Nested index.php should resolve mount base path');
    assertTrue(UrlPath::basePathFromScript('https://example.com/sprintask/index.php') === '/sprintask', 'Absolute script URL should resolve mount base path');

    assertTrue(UrlPath::resolveBasePath(['SCRIPT_NAME' => '/sprintask/index.php'], '') === '/sprintask', 'Runtime script mount should drive base path when APP_BASE_URL is empty');
    assertTrue(UrlPath::resolveBasePath(['SCRIPT_NAME' => '/index.php'], 'https://example.com/sprintask/') === '/sprintask', 'APP_BASE_URL path should override runtime base path when set');
    assertTrue(UrlPath::resolveBasePath(['SCRIPT_NAME' => '/sprintask/index.php'], 'https://example.com/') === '', 'APP_BASE_URL root path should override runtime base path');

    assertTrue(UrlPath::stripBasePath('/sprintask/reports', '/sprintask') === '/reports', 'Base path should be stripped before routing');
    assertTrue(UrlPath::stripBasePath('/sprintask', '/sprintask') === '/', 'Mount path itself should map to app root route');
    assertTrue(UrlPath::stripBasePath('/reports', '/sprintask') === '/reports', 'Non-prefixed request path should remain unchanged');

    assertTrue(UrlPath::appPath('/', '') === '/', 'Root app URL should remain root without base path');
    assertTrue(UrlPath::appPath('/reports', '/sprintask') === '/sprintask/reports', 'App URL should include mount base path');
    assertTrue(UrlPath::appPath('/', '/sprintask') === '/sprintask/', 'App root URL should include trailing slash when mounted');
}

function testAppRootResolver(): void {
    $publicDir = '/tmp/site/public';

    assertTrue(
        sprintaskResolveAppRoot([], $publicDir) === '/tmp/site',
        'Default app-root should resolve to parent of public directory'
    );

    assertTrue(
        sprintaskResolveAppRoot(['SPRINTASK_ROOT' => '/home/cpanel-user/private-app'], $publicDir) === '/home/cpanel-user/private-app',
        'Server-provided SPRINTASK_ROOT should override default app-root'
    );

    assertTrue(
        sprintaskResolveBootstrapPath(['SPRINTASK_ROOT' => '/home/cpanel-user/private-app/'], $publicDir) === '/home/cpanel-user/private-app/src/bootstrap.php',
        'Bootstrap path should normalize trailing slashes in configured app-root'
    );

    assertThrows(
        static fn() => sprintaskRequireBootstrapPath('/definitely/missing/src/bootstrap.php'),
        'Resolver should throw a clear error when bootstrap path is missing'
    );
}

function renderDashboardTemplateForTest(array $data): string {
    $templatePath = dirname(__DIR__) . '/templates/dashboard.php';
    $defaults = [
        'user' => ['name' => 'Tester', 'timezone' => 'UTC'],
        'composer' => ['categories' => [], 'tasks' => []],
        'categories' => [],
        'history' => ['range' => '7d', 'categories' => [], 'total_seconds' => 0],
        'running' => null,
        'csrf_token' => 'test-csrf',
        'url' => static fn(string $path = '/'): string => $path,
    ];

    extract(array_merge($defaults, $data), EXTR_SKIP);

    ob_start();
    require $templatePath;
    return (string) ob_get_clean();
}

function testDashboardTimerBaselineRendering(): void {
    $html = renderDashboardTemplateForTest([
        'server_now_utc' => 1_090,
        'running' => [
            'started_at_utc' => 1_000,
            'category_name' => 'Build',
            'task_name' => 'Code',
        ],
    ]);
    assertTrue(str_contains($html, 'data-elapsed-baseline="90"'), 'Dashboard should render server-derived elapsed baseline for running timer');

    $futureStartHtml = renderDashboardTemplateForTest([
        'server_now_utc' => 1_000,
        'running' => [
            'started_at_utc' => 1_100,
            'category_name' => 'Build',
            'task_name' => 'Code',
        ],
    ]);
    assertTrue(str_contains($futureStartHtml, 'data-elapsed-baseline="0"'), 'Dashboard baseline should clamp negative elapsed time to zero');
}

function testRememberMeTokenLifecycle(): void {
    [$db, $path] = setupDb();
    $log = sys_get_temp_dir() . '/mail_' . bin2hex(random_bytes(3)) . '.log';
    $auth = new AuthService($db, new LogMailer($log), baseSecurity());
    $pdo = $db->pdo();
    $now = time();
    $pdo->exec("INSERT INTO users (email,name,timezone,theme,created_at_utc) VALUES ('remember@test','Remember','UTC','system',$now)");
    $userId = 1;

    $login = $auth->completeLogin($userId, true, false, '127.0.0.1', 'test-agent');
    assertTrue($login['remember'] !== null, 'Remember-me should issue a token when requested');
    $cookie = $login['remember']['selector'] . ':' . $login['remember']['validator'];

    $resolved = $auth->loginFromRememberToken($cookie);
    assertTrue($resolved !== null && $resolved['user_id'] === $userId, 'Valid remember cookie should resolve to the user');

    $reused = $auth->loginFromRememberToken($cookie);
    assertTrue($reused === null, 'Rotated remember token must reject the previous cookie value (single use)');

    $login2 = $auth->completeLogin($userId, true, false, '127.0.0.1', 'test-agent');
    $selector2 = $login2['remember']['selector'];
    $tampered = $selector2 . ':deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
    $badResult = $auth->loginFromRememberToken($tampered);
    assertTrue($badResult === null, 'Tampered validator must be rejected');

    $afterTamper = $auth->loginFromRememberToken($selector2 . ':' . $login2['remember']['validator']);
    assertTrue($afterTamper === null, 'Token must be revoked after a failed validator attempt');

    unlink($path);
    if (is_file($log)) {
        unlink($log);
    }
}

function testLogoutOtherDevicesInvalidatesTokensAndVersion(): void {
    [$db, $path] = setupDb();
    $log = sys_get_temp_dir() . '/mail_' . bin2hex(random_bytes(3)) . '.log';
    $auth = new AuthService($db, new LogMailer($log), baseSecurity());
    $pdo = $db->pdo();
    $now = time();
    $pdo->exec("INSERT INTO users (email,name,timezone,theme,created_at_utc) VALUES ('multidevice@test','Multi','UTC','system',$now)");
    $userId = 1;

    $deviceA = $auth->completeLogin($userId, true, false, '127.0.0.1', 'device-a');
    $deviceACookie = $deviceA['remember']['selector'] . ':' . $deviceA['remember']['validator'];
    $versionA = $deviceA['session_version'];

    $deviceB = $auth->completeLogin($userId, true, true, '127.0.0.2', 'device-b');
    assertTrue($deviceB['session_version'] === $versionA + 1, 'Logging out other devices should bump the session version');

    $staleFromA = $auth->loginFromRememberToken($deviceACookie);
    assertTrue($staleFromA === null, 'Device A remember token should be invalidated by device B logging out other devices');

    assertTrue($auth->sessionVersion($userId) === $versionA + 1, 'sessionVersion() should reflect the bump');

    $deviceBCookie = $deviceB['remember']['selector'] . ':' . $deviceB['remember']['validator'];
    $stillWorks = $auth->loginFromRememberToken($deviceBCookie);
    assertTrue($stillWorks !== null, 'The device performing logout-other-devices should keep its own new session valid');

    unlink($path);
    if (is_file($log)) {
        unlink($log);
    }
}

function testRememberTokenExpiry(): void {
    [$db, $path] = setupDb();
    $log = sys_get_temp_dir() . '/mail_' . bin2hex(random_bytes(3)) . '.log';
    $auth = new AuthService($db, new LogMailer($log), baseSecurity());
    $pdo = $db->pdo();
    $now = time();
    $pdo->exec("INSERT INTO users (email,name,timezone,theme,created_at_utc) VALUES ('expiretoken@test','ExpireToken','UTC','system',$now)");
    $userId = 1;

    $login = $auth->completeLogin($userId, true, false, '127.0.0.1', 'test');
    $pdo->exec('UPDATE remember_tokens SET expires_at_utc = ' . ($now - 1) . " WHERE user_id = $userId");
    $cookie = $login['remember']['selector'] . ':' . $login['remember']['validator'];

    $result = $auth->loginFromRememberToken($cookie);
    assertTrue($result === null, 'Expired remember token must be rejected');

    unlink($path);
    if (is_file($log)) {
        unlink($log);
    }
}

function testDashboardHistoryTreeRendering(): void {
    $html = renderDashboardTemplateForTest([
        'server_now_utc' => 10_000,
        'history' => [
            'range' => '24h',
            'total_seconds' => 3600,
            'categories' => [[
                'id' => 1,
                'name' => 'Build <x>',
                'seconds' => 3600,
                'tasks' => [[
                    'id' => 7,
                    'name' => 'Code',
                    'seconds' => 3600,
                    'items' => [[
                        'id' => 42,
                        'started_at_utc' => 1_000,
                        'ended_at_utc' => 4_600,
                        'seconds' => 3600,
                        'clipped_seconds' => 3600,
                    ]],
                ]],
            ]],
        ],
    ]);
    assertTrue(str_contains($html, '<details class="tree-node tree-category" open>'), 'Categories should render expanded by default');
    assertTrue(str_contains($html, '<details class="tree-node tree-task">'), 'Tasks should render collapsed by default');
    assertTrue(str_contains($html, 'Build &lt;x&gt;'), 'Tree labels must be escaped');
    assertTrue(str_contains($html, 'id="entry-detail-42"') && str_contains($html, 'name="range" value="24h"'), 'Entries should render edit forms that keep the range');
    assertTrue(str_contains($html, 'aria-current="page">24h<'), 'Active range filter should be marked');
}

function testSettingsGridRowsAndRename(): void {
    [$db, $path] = setupDb();
    $pdo = $db->pdo();
    $now = time();
    $pdo->exec("INSERT INTO users (email,name,timezone,theme,created_at_utc) VALUES ('grid@test','Grid','UTC','system',$now),('other@test','O','UTC','system',$now)");
    $pdo->exec("INSERT INTO categories (user_id,name,created_at_utc) VALUES (1,'beta',$now),(1,'Alpha',$now),(1,'Empty',$now),(2,'Theirs',$now)");
    $pdo->exec("INSERT INTO tasks (user_id,category_id,name,created_at_utc) VALUES (1,1,'zeta',$now),(1,1,'Eta',$now),(1,2,'Mu',$now),(2,4,'X',$now)");
    $pdo->exec("INSERT INTO time_entries (user_id,task_id,started_at_utc,ended_at_utc,created_at_utc,updated_at_utc) VALUES (1,3,100,200,$now,$now)");

    $work = new WorkService($db);
    $grid = $work->settingsRows(1);
    $labels = array_map(static fn(array $r): string => $r['category_name'] . '/' . ($r['task_name'] ?? '-'), $grid['rows']);
    assertTrue($labels === ['Alpha/Mu', 'beta/Eta', 'beta/zeta', 'Empty/-'], 'Grid should list task rows plus empty categories, sorted case-insensitively');
    assertTrue((int) $grid['rows'][0]['entry_count'] === 1, 'Grid rows should carry entry counts');

    $byTaskDesc = $work->settingsRows(1, 'task', 'desc');
    assertTrue($byTaskDesc['rows'][0]['task_name'] === 'zeta', 'Grid should sort by task descending');

    $paged = $work->settingsRows(1, 'category', 'asc', 9, 10);
    assertTrue($paged['page'] === 1 && $paged['pages'] === 1 && $paged['per_page'] === 10, 'Out-of-range page should clamp');
    assertTrue($work->settingsRows(1, 'category', 'asc', 1, 7)['per_page'] === 10, 'Unsupported page size should fall back to 10');

    $work->updateCategoryAndTask(1, 1, 'Beta Renamed', 2, 'Eta 2');
    $names = $pdo->query("SELECT c.name || '/' || t.name FROM tasks t JOIN categories c ON c.id = t.category_id WHERE t.user_id = 1 AND c.id = 1 ORDER BY t.id")->fetchAll(PDO::FETCH_COLUMN);
    assertTrue($names === ['Beta Renamed/zeta', 'Beta Renamed/Eta 2'], 'Renaming a category from one row should apply to every row of that category');

    assertThrows(static fn() => $work->updateCategoryAndTask(1, 1, 'alpha', 1, 'zeta'), 'Renaming onto an existing category name should fail');
    assertTrue($pdo->query('SELECT name FROM categories WHERE id = 1')->fetchColumn() === 'Beta Renamed', 'Failed row edit should roll back');
    assertThrows(static fn() => $work->updateCategoryAndTask(1, 2, 'Alpha', 1, 'hijack'), 'Task must belong to the edited category');
    assertThrows(static fn() => $work->updateCategoryAndTask(1, 4, 'Mine', null, null), 'Cannot edit another user\'s category');

    assertThrows(static fn() => $work->deleteTask(1, 3), 'Task with entries cannot be deleted');
    $work->deleteTask(1, 2);
    $work->deleteCategory(1, 3);
    $remaining = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE user_id = 1')->fetchColumn();
    assertTrue($remaining === 2, 'Categories without entries can be deleted');

    unlink($path);
}

function testResumeTask(): void {
    [$db, $path] = setupDb();
    $pdo = $db->pdo();
    $now = time();
    $pdo->exec("INSERT INTO users (email,name,timezone,theme,created_at_utc) VALUES ('r@test','R','UTC','system',$now),('o@test','O','UTC','system',$now)");
    $pdo->exec("INSERT INTO categories (user_id,name,created_at_utc) VALUES (1,'Build',$now),(2,'Theirs',$now)");
    $pdo->exec("INSERT INTO tasks (user_id,category_id,name,created_at_utc) VALUES (1,1,'Code',$now),(1,1,'Review',$now),(2,2,'X',$now)");
    $pdo->exec("INSERT INTO time_entries (user_id,task_id,started_at_utc,ended_at_utc,created_at_utc,updated_at_utc) VALUES (1,1,100,200,$now,$now)");

    $work = new WorkService($db);
    $work->resumeTask(1, 1);
    $running = $work->runningEntry(1);
    assertTrue($running !== null && (int) $running['task_id'] === 1, 'Resume should start a new running entry for the task');
    assertTrue((int) $pdo->query('SELECT COUNT(*) FROM time_entries WHERE task_id = 1')->fetchColumn() === 2, 'Resume should create a new entry, not reopen the old one');

    $work->resumeTask(1, 1);
    assertTrue((int) $pdo->query('SELECT COUNT(*) FROM time_entries WHERE user_id = 1')->fetchColumn() === 2, 'Resuming the running task should be a no-op');

    $work->resumeTask(1, 2);
    $running = $work->runningEntry(1);
    assertTrue($running !== null && (int) $running['task_id'] === 2, 'Resume should switch the running timer to the other task');
    $open = (int) $pdo->query('SELECT COUNT(*) FROM time_entries WHERE user_id = 1 AND ended_at_utc IS NULL')->fetchColumn();
    assertTrue($open === 1, 'Previous running timer should be stopped when resuming another task');

    assertThrows(static fn() => $work->resumeTask(1, 3), 'Cannot resume another user\'s task');
    assertTrue((int) $work->runningEntry(1)['task_id'] === 2, 'Failed resume must not stop the current timer');

    unlink($path);
}

testOtpAndMail();
testOtpExpiryAndMaxAttempts();
testResumeTask();
testOtpThrottleAndLoginFlow();
testRememberMeTokenLifecycle();
testLogoutOtherDevicesInvalidatesTokensAndVersion();
testRememberTokenExpiry();
testOwnershipCascadeAndTimerIndex();
testReportClipping();
testManualEntryValidation();
testNoCaseUniquenessAndResolveOrCreateStart();
testCategoryScopedDuplicateTaskNames();
testLocalTimeDisplayHelpers();
testUrlBasePathHelpers();
testAppRootResolver();
testDashboardTimerBaselineRendering();
testDashboardHistoryTreeRendering();
testSettingsGridRowsAndRename();

echo "All tests passed\n";
