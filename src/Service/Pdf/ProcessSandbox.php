<?php

declare(strict_types=1);

namespace App\Service\Pdf;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the local PDF tools (qpdf, Ghostscript, ImageMagick, poppler) on files
 * that come from the lawyer's uploads. Everything happens in a private work
 * directory, with the environment stripped of the application's secrets, a
 * timeout per call and a total budget per build, so a hostile or huge file
 * cannot hold a PHP worker past the web server's own timeout. Nothing is sent
 * to a remote service: the files are covered by professional secrecy.
 */
final class ProcessSandbox
{
    public const DEFAULT_TOTAL_BUDGET_SECONDS = 100.0;

    private const ALLOWED_ENV = ['PATH'];

    private ?string $workDir = null;
    private float $deadline = 0.0;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    /** @param list<string> $binaries */
    public static function binariesAvailable(array $binaries): bool
    {
        $finder = new ExecutableFinder();
        foreach ($binaries as $binary) {
            if ($finder->find($binary) === null) {
                return false;
            }
        }

        return true;
    }

    /** Starts a fresh work directory and total budget. Ends any previous one. */
    public function begin(float $totalBudgetSeconds = self::DEFAULT_TOTAL_BUDGET_SECONDS): void
    {
        $this->end();
        $dir = $this->projectDir . '/var/pdf_work/' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new PdfToolException(sprintf('Cannot create PDF work directory %s', $dir));
        }
        $this->workDir = $dir;
        $this->deadline = microtime(true) + $totalBudgetSeconds;
    }

    public function end(): void
    {
        if ($this->workDir !== null) {
            self::removeTree($this->workDir);
        }
        $this->workDir = null;
    }

    public function workDir(): string
    {
        if ($this->workDir === null) {
            $this->begin();
        }

        return (string) $this->workDir;
    }

    /** A fresh path inside the work directory. */
    public function tempPath(string $extension = 'pdf'): string
    {
        return $this->workDir() . '/' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    public function remainingSeconds(): float
    {
        return max(0.0, $this->deadline - microtime(true));
    }

    /**
     * @param list<string> $command
     * @param list<int>    $okExitCodes qpdf exits 3 on recoverable warnings, which is fine
     */
    public function run(array $command, float $timeoutSeconds = 60.0, array $okExitCodes = [0]): Process
    {
        $workDir = $this->workDir();
        $timeout = min($timeoutSeconds, $this->remainingSeconds());
        if ($timeout < 1.0) {
            throw new PdfToolException('PDF processing budget exhausted', 'filing.package.error.timeout');
        }

        $process = new Process($command, $workDir, $this->environment($workDir), null, $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new PdfToolException(sprintf('%s timed out', $command[0]), 'filing.package.error.timeout', [], $e);
        } catch (\RuntimeException $e) {
            // ProcessStartFailedException and friends: the binary is missing or not executable.
            throw new PdfToolException(sprintf('%s could not run: %s', $command[0], $e->getMessage()), 'filing.package.error.tool_missing', [], $e);
        }

        if (!in_array($process->getExitCode(), $okExitCodes, true)) {
            throw new PdfToolException(sprintf(
                '%s exited with %d: %s',
                $command[0],
                (int) $process->getExitCode(),
                mb_substr(trim($process->getErrorOutput() . ' ' . $process->getOutput()), 0, 500),
            ));
        }

        return $process;
    }

    /**
     * Symfony Process inherits the parent environment; every variable outside the
     * allow-list is unset explicitly so DATABASE_URL, APP_SECRET and API keys never
     * reach a tool that parses untrusted input.
     *
     * @return array<string, string|false>
     */
    private function environment(string $workDir): array
    {
        $env = [];
        foreach (array_keys(getenv()) as $name) {
            if (!in_array($name, self::ALLOWED_ENV, true)) {
                $env[$name] = false;
            }
        }
        foreach (array_keys($_ENV) as $name) {
            if (!in_array($name, self::ALLOWED_ENV, true)) {
                $env[$name] = false;
            }
        }
        foreach (array_keys($_SERVER) as $name) {
            if (is_string($name) && !in_array($name, self::ALLOWED_ENV, true) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) === 1) {
                $env[$name] = false;
            }
        }

        return [
            ...$env,
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $workDir,
            'TMPDIR' => $workDir,
            'MAGICK_TEMPORARY_PATH' => $workDir,
            'MAGICK_MEMORY_LIMIT' => '256MiB',
            'MAGICK_DISK_LIMIT' => '1GiB',
            'MAGICK_THREAD_LIMIT' => '2',
        ];
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    public function __destruct()
    {
        $this->end();
    }
}
