<?php

namespace Tests\Concurrency\Mysql\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MysqlTestCase;

/**
 * One real workflow running in its own PHP process (run-workflow.php), with its own MySQL connection. The test holds a lock on
 * a second connection, so the workflow really waits for it. Nothing is simulated and nothing is a fixed sleep: the test waits
 * until MySQL itself shows the workflow's locking read is blocked, and reads the "ATTEMPT n" lines the workflow prints.
 */
final class ChildWorkflow
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $stdout;

    private string $buffer = '';

    private ?int $exitCode = null;

    /** @var array<string, mixed>|null */
    private ?array $result = null;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(string $command, array $arguments)
    {
        $env = [
            ...getenv(),
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => MysqlTestCase::TESTING_DATABASE,
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'null',
        ];

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/run-workflow.php', $command, json_encode($arguments, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the workflow process.');
        }

        $this->process = $process;
        $this->stdout = $pipes[1];
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->stderr = $pipes[2];
    }

    /** @var resource */
    private $stderr;

    /**
     * How many "ATTEMPT n" lines the workflow has printed so far (the transaction attempts begun).
     */
    public function attemptsStarted(): int
    {
        $this->pump();

        return preg_match_all('/^ATTEMPT \d+$/m', $this->buffer);
    }

    /**
     * Wait until the workflow has printed this many attempts.
     */
    public function waitForAttempts(int $attempts, int $seconds = 30): void
    {
        $deadline = microtime(true) + $seconds;

        while ($this->attemptsStarted() < $attempts) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("The workflow did not start attempt {$attempts} within {$seconds}s. Output so far: ".$this->buffer);
            }

            usleep(50_000);
        }
    }

    /**
     * Wait for the end and return the decoded RESULT line.
     *
     * @return array<string, mixed>
     */
    public function result(int $seconds = 60): array
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $deadline = microtime(true) + $seconds;

        while (true) {
            $this->pump();
            $status = proc_get_status($this->process);

            if (! $status['running']) {
                $this->exitCode ??= $status['exitcode'];
                $this->pump();

                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($this->process);

                throw new RuntimeException("The workflow did not finish within {$seconds}s. Output so far: ".$this->buffer);
            }

            usleep(50_000);
        }

        $stderr = (string) stream_get_contents($this->stderr);
        $this->closeProcess();

        if (preg_match('/^RESULT:(.+)$/m', $this->buffer, $match) !== 1) {
            throw new RuntimeException('The workflow printed no RESULT. Output: '.$this->buffer.' Error: '.$stderr);
        }

        return $this->result = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function isRunning(): bool
    {
        return $this->result === null && proc_get_status($this->process)['running'];
    }

    /**
     * Stop a workflow a failed test left behind.
     */
    public function kill(): void
    {
        if ($this->result === null && is_resource($this->process)) {
            proc_terminate($this->process);
            $this->closeProcess();
        }
    }

    private function pump(): void
    {
        $this->buffer .= (string) stream_get_contents($this->stdout);
    }

    private function closeProcess(): void
    {
        foreach ([$this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_close($this->process);
        }
    }

    /**
     * Wait until MySQL shows at least $count statements stuck waiting on a row lock. A blocked "... for update" statement of
     * this user runs for a second or more, and every normal statement here takes milliseconds (the user has no PROCESS
     * privilege, so SHOW FULL PROCESSLIST of its own threads is what can be seen; the plain form cuts the text at 100 characters).
     */
    public static function waitUntilBlocked(int $count = 1, int $seconds = 20): void
    {
        $deadline = microtime(true) + $seconds;
        $blocked = 0;

        while (true) {
            $blocked = collect(DB::select('show full processlist'))
                ->filter(fn ($row) => (int) $row->Time >= 1 && str_contains(strtolower((string) $row->Info), 'for update'))
                ->count();

            if ($blocked >= $count) {
                return;
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException("Expected {$count} statement(s) blocked on a lock within {$seconds}s, saw {$blocked}.");
            }

            usleep(100_000);
        }
    }
}
