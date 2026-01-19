<?php
declare(strict_types=1);

/**
 * Task Tracker CLI - Pure PHP
 *
 * Commands:
 *   php task-cli.php add "Description"
 *   php task-cli.php update <id> "New description"
 *   php task-cli.php delete <id>
 *   php task-cli.php mark-in-progress <id>
 *   php task-cli.php mark-done <id>
 *   php task-cli.php list [done|todo|in-progress]
 */

final class TaskStore
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function load(): array
    {
        if (!file_exists($this->file)) {
            // Create an empty file on first run
            $this->save([]);
            return [];
        }

        $raw = file_get_contents($this->file);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // If corrupted, do not silently overwrite; surface error.
            throw new RuntimeException("Storage file is not valid JSON: {$this->file}");
        }

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     */
    public function save(array $tasks): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException("Failed to create storage directory: {$dir}");
            }
        }

        $tmp = $this->file . '.tmp';
        $json = json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException("Failed to encode tasks to JSON.");
        }

        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temp storage file: {$tmp}");
        }

        if (!rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to replace storage file: {$this->file}");
        }
    }
}

final class TaskService
{
    private TaskStore $store;

    public function __construct(TaskStore $store)
    {
        $this->store = $store;
    }

    public function add(string $description): array
    {
        $description = trim($description);
        $this->assertDescription($description);

        $tasks = $this->store->load();
        $nextId = $this->nextId($tasks);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);

        $task = [
            'id' => $nextId,
            'description' => $description,
            'status' => 'todo',
            'createdAt' => $now,
            'updatedAt' => $now,
        ];

        $tasks[] = $task;
        $this->store->save($tasks);

        return $task;
    }

    public function update(int $id, string $description): array
    {
        $description = trim($description);
        $this->assertDescription($description);

        $tasks = $this->store->load();
        $idx = $this->findIndexById($tasks, $id);

        $tasks[$idx]['description'] = $description;
        $tasks[$idx]['updatedAt'] = $this->now();

        $this->store->save($tasks);

        return $tasks[$idx];
    }

    public function delete(int $id): void
    {
        $tasks = $this->store->load();
        $idx = $this->findIndexById($tasks, $id);

        array_splice($tasks, $idx, 1);
        $this->store->save($tasks);
    }

    public function markStatus(int $id, string $status): array
    {
        $this->assertStatus($status);

        $tasks = $this->store->load();
        $idx = $this->findIndexById($tasks, $id);

        $tasks[$idx]['status'] = $status;
        $tasks[$idx]['updatedAt'] = $this->now();

        $this->store->save($tasks);

        return $tasks[$idx];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $filter = null): array
    {
        $tasks = $this->store->load();

        if ($filter === null) {
            return $tasks;
        }

        $filter = strtolower(trim($filter));
        if ($filter === 'in-progress') {
            $filter = 'in_progress';
        }

        $this->assertListFilter($filter);

        return array_values(array_filter($tasks, static function (array $t) use ($filter): bool {
            return isset($t['status']) && $t['status'] === $filter;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     */
    private function nextId(array $tasks): int
    {
        $max = 0;
        foreach ($tasks as $t) {
            if (isset($t['id']) && is_int($t['id']) && $t['id'] > $max) {
                $max = $t['id'];
            } elseif (isset($t['id']) && is_numeric($t['id'])) {
                $val = (int)$t['id'];
                if ($val > $max) $max = $val;
            }
        }
        return $max + 1;
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     */
    private function findIndexById(array $tasks, int $id): int
    {
        foreach ($tasks as $i => $t) {
            if (isset($t['id']) && (int)$t['id'] === $id) {
                return (int)$i;
            }
        }
        throw new RuntimeException("Task not found: {$id}");
    }

    private function assertDescription(string $description): void
    {
        $len = mb_strlen($description);
        if ($len < 3 || $len > 200) {
            throw new InvalidArgumentException("Description must be between 3 and 200 characters.");
        }
    }

    private function assertStatus(string $status): void
    {
        $allowed = ['todo', 'in_progress', 'done'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException("Invalid status. Allowed: todo, in_progress, done.");
        }
    }

    private function assertListFilter(string $filter): void
    {
        $allowed = ['todo', 'in_progress', 'done'];
        if (!in_array($filter, $allowed, true)) {
            throw new InvalidArgumentException("Invalid filter. Allowed: done, todo, in-progress.");
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}

final class CliApp
{
    private TaskService $service;

    public function __construct(TaskService $service)
    {
        $this->service = $service;
    }

    public function run(array $argv): int
    {
        // argv[0] is script
        $command = $argv[1] ?? null;

        try {
            if ($command === null || in_array($command, ['-h', '--help', 'help'], true)) {
                $this->printHelp();
                return 0;
            }

            return match ($command) {
                'add' => $this->cmdAdd($argv),
                'update' => $this->cmdUpdate($argv),
                'delete' => $this->cmdDelete($argv),
                'mark-in-progress' => $this->cmdMark($argv, 'in_progress'),
                'mark-done' => $this->cmdMark($argv, 'done'),
                'list' => $this->cmdList($argv),
                default => $this->unknown($command),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function cmdAdd(array $argv): int
    {
        $description = $argv[2] ?? '';
        if ($description === '') {
            throw new InvalidArgumentException("Missing description. Example: php task-cli.php add \"Buy milk\"");
        }

        $task = $this->service->add($description);
        fwrite(STDOUT, "Task added successfully (ID: {$task['id']})." . PHP_EOL);
        return 0;
    }

    private function cmdUpdate(array $argv): int
    {
        $idRaw = $argv[2] ?? null;
        $description = $argv[3] ?? '';

        if ($idRaw === null || $description === '') {
            throw new InvalidArgumentException("Usage: php task-cli.php update <id> \"New description\"");
        }

        $id = $this->parseId($idRaw);
        $task = $this->service->update($id, $description);

        fwrite(STDOUT, "Task updated successfully (ID: {$task['id']})." . PHP_EOL);
        return 0;
    }

    private function cmdDelete(array $argv): int
    {
        $idRaw = $argv[2] ?? null;
        if ($idRaw === null) {
            throw new InvalidArgumentException("Usage: php task-cli.php delete <id>");
        }

        $id = $this->parseId($idRaw);
        $this->service->delete($id);

        fwrite(STDOUT, "Task deleted successfully (ID: {$id})." . PHP_EOL);
        return 0;
    }

    private function cmdMark(array $argv, string $status): int
    {
        $idRaw = $argv[2] ?? null;
        if ($idRaw === null) {
            $cmd = $status === 'done' ? 'mark-done' : 'mark-in-progress';
            throw new InvalidArgumentException("Usage: php task-cli.php {$cmd} <id>");
        }

        $id = $this->parseId($idRaw);
        $task = $this->service->markStatus($id, $status);

        $label = $status === 'done' ? 'done' : 'in_progress';
        fwrite(STDOUT, "Task marked as {$label} (ID: {$task['id']})." . PHP_EOL);
        return 0;
    }

    private function cmdList(array $argv): int
    {
        $filter = $argv[2] ?? null;
        $tasks = $this->service->list($filter);

        if (count($tasks) === 0) {
            fwrite(STDOUT, "No tasks found." . PHP_EOL);
            return 0;
        }

        foreach ($tasks as $t) {
            $id = $t['id'] ?? '';
            $desc = $t['description'] ?? '';
            $status = $t['status'] ?? '';
            fwrite(STDOUT, sprintf("[%s] #%d %s%s", $status, $id, $desc, PHP_EOL));
        }

        return 0;
    }

    private function parseId(string $raw): int
    {
        if (!preg_match('/^\d+$/', $raw)) {
            throw new InvalidArgumentException("Invalid id: {$raw}");
        }
        return (int)$raw;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, "Unknown command: {$command}" . PHP_EOL);
        $this->printHelp();
        return 1;
    }

    private function printHelp(): void
    {
        $help = <<<TXT
            Task Tracker CLI (Pure PHP)

            Usage:
            php task-cli.php add "Description"
            php task-cli.php update <id> "New description"
            php task-cli.php delete <id>
            php task-cli.php mark-in-progress <id>
            php task-cli.php mark-done <id>
            php task-cli.php list [done|todo|in-progress]

            Examples:
            php task-cli.php add "Buy milk"
            php task-cli.php update 1 "Buy almond milk"
            php task-cli.php mark-in-progress 1
            php task-cli.php mark-done 1
            php task-cli.php list
            php task-cli.php list done

            TXT;
        fwrite(STDOUT, $help);
    }
}

// Bootstrap
$storageFile = __DIR__ . DIRECTORY_SEPARATOR . 'tasks.json';
$store = new TaskStore($storageFile);
$service = new TaskService($store);
$app = new CliApp($service);

exit($app->run($argv));
