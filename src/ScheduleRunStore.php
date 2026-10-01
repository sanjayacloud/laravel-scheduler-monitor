<?php

declare(strict_types=1);

namespace SanjayaCloud\SchedulerMonitor;

use Carbon\Carbon;

class ScheduleRunStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @return array{since: string|null, runs: array<string, array<string, mixed>>}
     */
    public function all(): array
    {
        if (! is_readable($this->path)) {
            return ['since' => null, 'runs' => []];
        }

        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            return ['since' => null, 'runs' => []];
        }

        try {
            flock($handle, LOCK_SH);

            return $this->decode((string) stream_get_contents($handle));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Read-modify-write under an exclusive lock, because overlapping tasks
     * finish in separate processes.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    public function update(string $key, callable $change): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return;
        }

        $handle = @fopen($this->path, 'c+b');

        if ($handle === false) {
            return;
        }

        try {
            flock($handle, LOCK_EX);
            $data = $this->decode((string) stream_get_contents($handle));
            $data['since'] = $data['since'] ?? Carbon::now()->toIso8601String();
            $data['runs'][$key] = $change($data['runs'][$key] ?? []);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array{since: string|null, runs: array<string, array<string, mixed>>}
     */
    private function decode(string $contents): array
    {
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return ['since' => null, 'runs' => []];
        }

        /** @var array<string, array<string, mixed>> $runs */
        $runs = is_array($decoded['runs'] ?? null) ? $decoded['runs'] : [];

        return [
            'since' => is_string($decoded['since'] ?? null) ? $decoded['since'] : null,
            'runs' => $runs,
        ];
    }
}
