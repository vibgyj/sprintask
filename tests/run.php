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
    $work->deleteCategory(1, 1);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM time_entries WHERE user_id = 1')->fetchColumn();
    assertTrue($count === 0, 'Cascade delete should remove entries');

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

    $report = (new ReportService($db))->build(1, 'America/New_York', 'week', '2026-03-08');
    assertTrue($report['total_seconds'] > 0, 'DST week report should include clipped overlap');

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

testOtpAndMail();
testOtpExpiryAndMaxAttempts();
testOtpThrottleAndLoginFlow();
testOwnershipCascadeAndTimerIndex();
testReportClipping();
testManualEntryValidation();
testNoCaseUniquenessAndResolveOrCreateStart();
testCategoryScopedDuplicateTaskNames();
testLocalTimeDisplayHelpers();
testUrlBasePathHelpers();
testAppRootResolver();

echo "All tests passed\n";
