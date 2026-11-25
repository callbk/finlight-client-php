<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

/**
 * Runs `php -S 127.0.0.1:<port> <router>` for the duration of a test.
 */
final class PhpServer
{
    /** @var resource */
    private $process;

    public readonly int $port;

    /**
     * @param array<string, string> $env
     */
    public function __construct(string $router, array $env = [])
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new \RuntimeException('no free port');
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        $cmd = [\PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $router];
        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, Kit::root(), $env + self::baseEnv());
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start php -S');
        }
        $this->process = $process;
        for ($i = 0; $i < 100; ++$i) {
            $conn = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 0.1);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            usleep(50_000);
        }
        $this->stop();
        throw new \RuntimeException('php -S did not start');
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * @return array<string, string>
     */
    public static function baseEnv(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'TMPDIR'] as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * Run a PHP script and return [exit code, stdout, stderr].
     *
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    public static function run(string $script, array $env): array
    {
        $process = proc_open([\PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, Kit::root(), $env + self::baseEnv());
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot run ' . $script);
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
