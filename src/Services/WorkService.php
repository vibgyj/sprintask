<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Util;
use PDO;
use PDOException;

final class WorkService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function categoriesWithTasks(int $userId): array
    {
        $pdo = $this->db->pdo();
        $catStmt = $pdo->prepare('SELECT * FROM categories WHERE user_id = :user ORDER BY name');
        $catStmt->execute([':user' => $userId]);
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        $taskStmt = $pdo->prepare('SELECT * FROM tasks WHERE user_id = :user ORDER BY name');
        $taskStmt->execute([':user' => $userId]);
        $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

        $byCategory = [];
        foreach ($tasks as $task) {
            $byCategory[(int) $task['category_id']][] = $task;
        }

        foreach ($categories as &$category) {
            $category['tasks'] = $byCategory[(int) $category['id']] ?? [];
        }

        return $categories;
    }

    public function createCategory(int $userId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Category name is required.');
        }
        try {
            $stmt = $this->db->pdo()->prepare('INSERT INTO categories (user_id, name, created_at_utc) VALUES (:user, :name, :created)');
            $stmt->execute([':user' => $userId, ':name' => $name, ':created' => time()]);
        } catch (PDOException $e) {
            if ($this->isUniqueConstraint($e)) {
                throw new \RuntimeException('Category already exists (case-insensitive).');
            }
            throw $e;
        }
    }

    public function renameCategory(int $userId, int $categoryId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Category name is required.');
        }
        try {
            $stmt = $this->db->pdo()->prepare('UPDATE categories SET name = :name WHERE id = :id AND user_id = :user');
            $stmt->execute([':name' => $name, ':id' => $categoryId, ':user' => $userId]);
        } catch (PDOException $e) {
            if ($this->isUniqueConstraint($e)) {
                throw new \RuntimeException('Category already exists (case-insensitive).');
            }
            throw $e;
        }
    }

    /**
     * Deletes a category (and its entry-less tasks). The "no time entries"
     * condition is part of the DELETE itself, so an entry started concurrently
     * can never be cascade-deleted between a check and the delete.
     */
    public function deleteCategory(int $userId, int $categoryId): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM categories WHERE id = :id AND user_id = :user'
            . ' AND NOT EXISTS (SELECT 1 FROM time_entries e JOIN tasks t ON t.id = e.task_id AND t.user_id = e.user_id WHERE e.user_id = :user AND t.category_id = :id)');
        $stmt->execute([':id' => $categoryId, ':user' => $userId]);
        if ($stmt->rowCount() > 0) {
            return;
        }

        $countStmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM time_entries e JOIN tasks t ON t.id = e.task_id AND t.user_id = e.user_id WHERE e.user_id = :user AND t.category_id = :id');
        $countStmt->execute([':user' => $userId, ':id' => $categoryId]);
        $this->throwIfHasEntries('category', (int) $countStmt->fetchColumn());
    }

    private function throwIfHasEntries(string $what, int $entryCount): void
    {
        if ($entryCount > 0) {
            throw new \RuntimeException(sprintf('Cannot delete %s: it has %d time %s. Delete those first.', $what, $entryCount, $entryCount === 1 ? 'entry' : 'entries'));
        }
    }

    public function createTask(int $userId, int $categoryId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Task name is required.');
        }
        try {
            $stmt = $this->db->pdo()->prepare('INSERT INTO tasks (user_id, category_id, name, created_at_utc) VALUES (:user, :category, :name, :created)');
            $stmt->execute([':user' => $userId, ':category' => $categoryId, ':name' => $name, ':created' => time()]);
        } catch (PDOException $e) {
            if ($this->isUniqueConstraint($e)) {
                throw new \RuntimeException('Task already exists in that category (case-insensitive).');
            }
            throw $e;
        }
    }

    public function renameTask(int $userId, int $taskId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Task name is required.');
        }
        try {
            $stmt = $this->db->pdo()->prepare('UPDATE tasks SET name = :name WHERE id = :id AND user_id = :user');
            $stmt->execute([':name' => $name, ':id' => $taskId, ':user' => $userId]);
        } catch (PDOException $e) {
            if ($this->isUniqueConstraint($e)) {
                throw new \RuntimeException('Task already exists in that category (case-insensitive).');
            }
            throw $e;
        }
    }

    /**
     * Renames a category and/or one of its tasks atomically (settings grid row
     * edit). Renaming the category applies to every task row in it, since rows
     * share the category record.
     */
    public function updateCategoryAndTask(int $userId, int $categoryId, string $categoryName, ?int $taskId, ?string $taskName): void
    {
        $categoryName = trim($categoryName);
        if ($categoryName === '') {
            throw new \RuntimeException('Category name is required.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $category = $this->findCategoryByIdForUser($userId, $categoryId);
            if (!$category) {
                throw new \RuntimeException('Category not found.');
            }
            if ($category['name'] !== $categoryName) {
                $this->renameCategory($userId, $categoryId, $categoryName);
            }

            if (($taskId ?? 0) > 0) {
                $task = $this->findTaskByIdForUser($userId, (int) $taskId);
                if (!$task || (int) $task['category_id'] !== $categoryId) {
                    throw new \RuntimeException('Task not found in that category.');
                }
                $taskName = trim((string) $taskName);
                if ($task['name'] !== $taskName) {
                    $this->renameTask($userId, (int) $taskId, $taskName);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Flat category/task rows for the settings grid. Categories without tasks
     * produce one row with a null task. entry_count drives delete blocking.
     *
     * @return array{rows: list<array>, total: int, page: int, pages: int, per_page: int, sort: string, dir: string}
     */
    public function settingsRows(int $userId, string $sort = 'category', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $sort = in_array($sort, ['category', 'task'], true) ? $sort : 'category';
        $dir = $dir === 'desc' ? 'desc' : 'asc';
        $perPage = in_array($perPage, [10, 50, 100], true) ? $perPage : 10;

        $stmt = $this->db->pdo()->prepare('SELECT c.id AS category_id, c.name AS category_name, t.id AS task_id, t.name AS task_name,'
            . ' (SELECT COUNT(*) FROM time_entries e WHERE e.user_id = c.user_id AND e.task_id = t.id) AS entry_count'
            . ' FROM categories c LEFT JOIN tasks t ON t.category_id = c.id AND t.user_id = c.user_id'
            . ' WHERE c.user_id = :user');
        $stmt->execute([':user' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $primary = $sort === 'task' ? 'task_name' : 'category_name';
        $secondary = $sort === 'task' ? 'category_name' : 'task_name';
        usort($rows, static function (array $a, array $b) use ($primary, $secondary, $dir): int {
            $cmp = strcasecmp((string) $a[$primary], (string) $b[$primary])
                ?: strcasecmp((string) $a[$secondary], (string) $b[$secondary])
                ?: ((int) $a['category_id'] <=> (int) $b['category_id'])
                ?: ((int) $a['task_id'] <=> (int) $b['task_id']);
            return $dir === 'desc' ? -$cmp : $cmp;
        });

        $total = count($rows);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        return [
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'sort' => $sort,
            'dir' => $dir,
        ];
    }

    public function timerComposerOptions(int $userId): array
    {
        $pdo = $this->db->pdo();
        $categoriesStmt = $pdo->prepare('SELECT id, name FROM categories WHERE user_id = :user ORDER BY name COLLATE NOCASE, id');
        $categoriesStmt->execute([':user' => $userId]);
        $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

        $tasksStmt = $pdo->prepare('SELECT t.id, t.name, t.category_id, c.name AS category_name FROM tasks t JOIN categories c ON c.id = t.category_id AND c.user_id = t.user_id WHERE t.user_id = :user ORDER BY t.name COLLATE NOCASE, c.name COLLATE NOCASE, t.id');
        $tasksStmt->execute([':user' => $userId]);
        $tasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'categories' => $categories,
            'tasks' => $tasks,
        ];
    }

    /** Deletes a task only if it has no time entries (atomically, see deleteCategory). */
    public function deleteTask(int $userId, int $taskId): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tasks WHERE id = :id AND user_id = :user'
            . ' AND NOT EXISTS (SELECT 1 FROM time_entries WHERE user_id = :user AND task_id = :id)');
        $stmt->execute([':id' => $taskId, ':user' => $userId]);
        if ($stmt->rowCount() > 0) {
            return;
        }

        $countStmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM time_entries WHERE user_id = :user AND task_id = :id');
        $countStmt->execute([':user' => $userId, ':id' => $taskId]);
        $this->throwIfHasEntries('task', (int) $countStmt->fetchColumn());
    }

    public function runningEntry(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT e.*, t.name AS task_name, c.name AS category_name FROM time_entries e JOIN tasks t ON t.id = e.task_id AND t.user_id = e.user_id JOIN categories c ON c.id = t.category_id AND c.user_id = t.user_id WHERE e.user_id = :user AND e.ended_at_utc IS NULL ORDER BY e.id DESC LIMIT 1');
        $stmt->execute([':user' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function startTimer(int $userId, int $taskId): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO time_entries (user_id, task_id, started_at_utc, ended_at_utc, created_at_utc, updated_at_utc) VALUES (:user, :task, :start, NULL, :created, :updated)');
            $now = time();
            $stmt->execute([':user' => $userId, ':task' => $taskId, ':start' => $now, ':created' => $now, ':updated' => $now]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException('Could not start timer (one active timer max, and task must belong to user).');
        }
    }

    public function resolveOrCreateAndStartTimer(
        int $userId,
        string $categoryName,
        string $taskName,
        ?int $categoryId,
        ?int $taskId
    ): void {
        $categoryName = trim($categoryName);
        $taskName = trim($taskName);
        if ($taskName === '') {
            throw new \RuntimeException('Task is required.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $resolvedTaskId = 0;
            if (($taskId ?? 0) > 0) {
                $task = $this->findTaskByIdForUser($userId, (int) $taskId);
                if (!$task) {
                    throw new \RuntimeException('Selected task was not found for this user.');
                }
                if (strcasecmp($taskName, (string) $task['name']) !== 0) {
                    throw new \RuntimeException('Task text no longer matches selected task. Please reselect.');
                }
                if (($categoryId ?? 0) > 0 && (int) $categoryId !== (int) $task['category_id']) {
                    throw new \RuntimeException('Selected task does not belong to selected category.');
                }
                if ($categoryName !== '' && strcasecmp($categoryName, (string) $task['category_name']) !== 0) {
                    throw new \RuntimeException('Category text no longer matches selected task category. Please reselect.');
                }
                $resolvedTaskId = (int) $task['id'];
            } else {
                $resolvedCategoryId = $this->resolveCategoryId($pdo, $userId, $categoryName, $categoryId);

                $existingTask = $this->findTaskByNameForCategory($userId, $resolvedCategoryId, $taskName);
                if ($existingTask) {
                    $resolvedTaskId = (int) $existingTask['id'];
                } else {
                    $resolvedTaskId = $this->insertTaskWithRaceResolution($pdo, $userId, $resolvedCategoryId, $taskName);
                }
            }

            $this->insertRunningEntry($pdo, $userId, $resolvedTaskId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            if ($e instanceof PDOException && $this->isUniqueConstraint($e)) {
                throw new \RuntimeException('Timer already running. Stop the active timer first.');
            }
            throw new \RuntimeException('Could not start timer.');
        }
    }

    /**
     * Starts a new running entry for an existing task. Any timer already
     * running on a different task is stopped first, in the same transaction;
     * resuming the task that is already running is a no-op.
     */
    public function resumeTask(int $userId, int $taskId): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if (!$this->findTaskByIdForUser($userId, $taskId)) {
                throw new \RuntimeException('Task not found.');
            }

            $running = $this->runningEntry($userId);
            if ($running && (int) $running['task_id'] === $taskId) {
                $pdo->commit();
                return;
            }

            $this->stopRunningEntry($pdo, $userId, time());
            $this->insertRunningEntry($pdo, $userId, $taskId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \RuntimeException('Could not resume task.');
        }
    }

    public function stopTimer(int $userId): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->stopRunningEntry($pdo, $userId, time());
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Ends the user's running entry at $now (at least 1s after its start). */
    private function stopRunningEntry(PDO $pdo, int $userId, int $now): void
    {
        $stmt = $pdo->prepare('UPDATE time_entries SET ended_at_utc = CASE WHEN started_at_utc >= :ended THEN started_at_utc + 1 ELSE :ended END, updated_at_utc = :updated WHERE user_id = :user AND ended_at_utc IS NULL');
        $stmt->execute([':ended' => $now, ':updated' => $now, ':user' => $userId]);
    }

    public function createManualEntry(int $userId, int $taskId, string $startLocal, string $endLocal, string $timezone): void
    {
        $startUtc = Util::localToUtc($startLocal, $timezone);
        $endUtc = Util::localToUtc($endLocal, $timezone);
        if ($startUtc === null || $endUtc === null || $endUtc <= $startUtc) {
            throw new \RuntimeException('Start/end date-times are invalid.');
        }

        $stmt = $this->db->pdo()->prepare('INSERT INTO time_entries (user_id, task_id, started_at_utc, ended_at_utc, created_at_utc, updated_at_utc) VALUES (:user, :task, :start, :end, :created, :updated)');
        $now = time();
        $stmt->execute([':user' => $userId, ':task' => $taskId, ':start' => $startUtc, ':end' => $endUtc, ':created' => $now, ':updated' => $now]);
    }

    public function updateEntry(int $userId, int $entryId, string $startLocal, string $endLocal, string $timezone): void
    {
        $startUtc = Util::localToUtc($startLocal, $timezone);
        $endUtc = Util::localToUtc($endLocal, $timezone);
        if ($startUtc === null || $endUtc === null || $endUtc <= $startUtc) {
            throw new \RuntimeException('Start/end date-times are invalid.');
        }

        $stmt = $this->db->pdo()->prepare('UPDATE time_entries SET started_at_utc = :start, ended_at_utc = :end, updated_at_utc = :updated WHERE id = :id AND user_id = :user');
        $stmt->execute([':start' => $startUtc, ':end' => $endUtc, ':updated' => time(), ':id' => $entryId, ':user' => $userId]);
    }

    public function deleteEntry(int $userId, int $entryId): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM time_entries WHERE id = :id AND user_id = :user');
        $stmt->execute([':id' => $entryId, ':user' => $userId]);
    }

    private function resolveCategoryId(PDO $pdo, int $userId, string $categoryName, ?int $categoryId): int
    {
        if (($categoryId ?? 0) > 0) {
            $category = $this->findCategoryByIdForUser($userId, (int) $categoryId);
            if (!$category) {
                throw new \RuntimeException('Selected category was not found for this user.');
            }
            if ($categoryName !== '' && strcasecmp($categoryName, (string) $category['name']) !== 0) {
                throw new \RuntimeException('Category text no longer matches selected category. Please reselect.');
            }
            return (int) $category['id'];
        }

        if ($categoryName === '') {
            throw new \RuntimeException('Category is required.');
        }

        $existingCategory = $this->findCategoryByNameForUser($userId, $categoryName);
        if ($existingCategory) {
            return (int) $existingCategory['id'];
        }

        return $this->insertCategoryWithRaceResolution($pdo, $userId, $categoryName);
    }

    private function findTaskByIdForUser(int $userId, int $taskId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT t.id, t.name, t.category_id, c.name AS category_name FROM tasks t JOIN categories c ON c.id = t.category_id AND c.user_id = t.user_id WHERE t.user_id = :user AND t.id = :id LIMIT 1');
        $stmt->execute([':user' => $userId, ':id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findCategoryByIdForUser(int $userId, int $categoryId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name FROM categories WHERE user_id = :user AND id = :id LIMIT 1');
        $stmt->execute([':user' => $userId, ':id' => $categoryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findCategoryByNameForUser(int $userId, string $categoryName): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name FROM categories WHERE user_id = :user AND name = :name COLLATE NOCASE LIMIT 1');
        $stmt->execute([':user' => $userId, ':name' => $categoryName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findTaskByNameForCategory(int $userId, int $categoryId, string $taskName): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name FROM tasks WHERE user_id = :user AND category_id = :category AND name = :name COLLATE NOCASE LIMIT 1');
        $stmt->execute([':user' => $userId, ':category' => $categoryId, ':name' => $taskName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function insertCategoryWithRaceResolution(PDO $pdo, int $userId, string $categoryName): int
    {
        $now = time();
        try {
            $stmt = $pdo->prepare('INSERT INTO categories (user_id, name, created_at_utc) VALUES (:user, :name, :created)');
            $stmt->execute([':user' => $userId, ':name' => $categoryName, ':created' => $now]);
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if (!$this->isUniqueConstraint($e)) {
                throw $e;
            }

            $existing = $this->findCategoryByNameForUser($userId, $categoryName);
            if (!$existing) {
                throw $e;
            }

            return (int) $existing['id'];
        }
    }

    private function insertTaskWithRaceResolution(PDO $pdo, int $userId, int $categoryId, string $taskName): int
    {
        $now = time();
        try {
            $stmt = $pdo->prepare('INSERT INTO tasks (user_id, category_id, name, created_at_utc) VALUES (:user, :category, :name, :created)');
            $stmt->execute([':user' => $userId, ':category' => $categoryId, ':name' => $taskName, ':created' => $now]);
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if (!$this->isUniqueConstraint($e)) {
                throw $e;
            }

            $existing = $this->findTaskByNameForCategory($userId, $categoryId, $taskName);
            if (!$existing) {
                throw $e;
            }

            return (int) $existing['id'];
        }
    }

    private function insertRunningEntry(PDO $pdo, int $userId, int $taskId): void
    {
        $stmt = $pdo->prepare('INSERT INTO time_entries (user_id, task_id, started_at_utc, ended_at_utc, created_at_utc, updated_at_utc) VALUES (:user, :task, :start, NULL, :created, :updated)');
        $now = time();
        $stmt->execute([':user' => $userId, ':task' => $taskId, ':start' => $now, ':created' => $now, ':updated' => $now]);
    }

    private function isUniqueConstraint(PDOException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'unique');
    }
}
