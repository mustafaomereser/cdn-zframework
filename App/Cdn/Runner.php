<?php

namespace App\Cdn;

use zFramework\Core\Facades\Auth;

/**
 * Running `php terminal` and `php cdn` from the panel.
 *
 * This is a remote shell on a machine whose whole job is to be reachable from
 * the internet, and it is worth saying so plainly rather than burying it: the
 * skeleton this project started from had a route that POSTed straight into the
 * terminal, and removing it was one of the first things done here. What follows
 * is the same capability with the parts that made it indefensible taken out.
 *
 *   - Off unless `admin.console.enabled` says otherwise. A feature like this
 *     should be something somebody turned on, not something they inherited.
 *   - Operator only, behind the panel's own session and csrf.
 *   - An allowlist of first words. Not a denylist: a denylist is a list of the
 *     dangerous things somebody thought of.
 *   - No shell. proc_open is given an argument array, so nothing in the input
 *     is ever parsed as a pipe, a redirect or a second command - the worst a
 *     stray `;` can do is make a command not match the allowlist.
 *   - A timeout, because a command that never returns is a php-fpm worker that
 *     never returns.
 *   - Every run is an audit row: what was typed, by whom, and what it exited
 *     with.
 *
 * What it is not is a sandbox. An allowed command can still do whatever that
 * command does - `cdn gc` deletes files, because that is what it is for. The
 * allowlist decides which programs, not what they are permitted to touch.
 */
class Runner
{
    /**
     * Is the console available at all?
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return (bool) Support::config('admin.console.enabled', false);
    }

    /**
     * The two entry points, and where they live.
     *
     * @return array<string,string>
     */
    public static function scripts(): array
    {
        return [
            'terminal' => base_path('terminal'),
            'cdn'      => base_path('cdn'),
        ];
    }

    /**
     * First words that may be run, per script.
     *
     * Read from config so an installation can widen or narrow it without
     * editing this file. The defaults leave out the ones that are not a
     * maintenance task at all: `db` migrates and can drop columns, `release`
     * and `update` rewrite the framework on disk, `make` writes new php files
     * into the application, and `test` runs whatever it finds.
     *
     * @param string $script
     * @return array
     */
    public static function allowed(string $script): array
    {
        $allowed = (array) Support::config('admin.console.allow', []);

        return array_values(array_filter((array) ($allowed[$script] ?? [])));
    }

    /**
     * Split what was typed into arguments, without a shell.
     *
     * Quoted runs stay together so a path with a space in it survives; nothing
     * else in the string means anything.
     *
     * @param string $line
     * @return array
     */
    public static function parse(string $line): array
    {
        preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', trim($line), $matches, PREG_SET_ORDER);

        $arguments = [];

        foreach ($matches as $match) {
            $arguments[] = $match[3] ?? '';

            if (($match[1] ?? '') !== '') $arguments[count($arguments) - 1] = $match[1];
            elseif (($match[2] ?? '') !== '') $arguments[count($arguments) - 1] = $match[2];
        }

        return array_values(array_filter($arguments, fn($argument) => $argument !== ''));
    }

    /**
     * Run one line.
     *
     * @param string $script `terminal` or `cdn`
     * @param string $line   What was typed after it.
     * @return array{ok:bool,output:string,code:int|null,command:string}
     */
    public static function run(string $script, string $line): array
    {
        $scripts = self::scripts();

        if (!self::enabled())              return self::refuse('console-disabled');
        if (!isset($scripts[$script]))     return self::refuse('unknown-script');
        if (!is_file($scripts[$script]))   return self::refuse('missing-script');

        $arguments = self::parse($line);

        if (!count($arguments)) return self::refuse('empty');

        $allowed = self::allowed($script);
        $command = strtolower($arguments[0]);

        # `*` is how an installation says it accepts the risk in full. It is not
        # the default and it is not what the config file suggests.
        if (!in_array('*', $allowed, true) && !in_array($command, $allowed, true)) {
            return self::refuse('not-allowed:' . $command);
        }

        $binary  = self::php();
        $timeout = max(5, (int) Support::config('admin.console.timeout', 120));

        # An argument array, not a string: there is no shell in this pipeline, so
        # `; rm -rf /` is an argument that the command will not understand rather
        # than a second command.
        $process = @proc_open(
            array_merge([$binary, $scripts[$script]], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            null
        );

        if (!is_resource($process)) return self::refuse('spawn-failed');

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output   = '';
        $deadline = time() + $timeout;
        $code     = null;

        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);

            if (!$status['running']) {
                $code = $status['exitcode'];
                break;
            }

            if (time() >= $deadline) {
                proc_terminate($process, 9);
                $output .= "\n… timed out after {$timeout}s";
                break;
            }

            usleep(50000);
        }

        # Whatever was still in the pipes when it exited.
        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);

        proc_close($process);

        $line = $script . ' ' . implode(' ', $arguments);

        Operator::audit('console', 'console', ['id' => Auth::id(), 'name' => $line], [
            'command' => $line,
            'exit'    => $code,
        ]);

        return [
            'ok'      => $code === 0,
            'code'    => $code,
            'output'  => self::plain($output),
            'command' => $line,
        ];
    }

    /**
     * The php binary to spawn.
     *
     * PHP_BINARY is the running interpreter, which is the right one by
     * definition - except under some server APIs where it is the web server.
     * Then it is whatever config says, and the console reports the failure
     * rather than guessing.
     *
     * @return string
     */
    private static function php(): string
    {
        $configured = (string) Support::config('admin.console.php', '');

        if ($configured !== '') return $configured;

        if (PHP_BINARY && !in_array(basename(PHP_BINARY), ['httpd', 'apache2', 'nginx'], true)) return PHP_BINARY;

        return 'php';
    }

    /**
     * Strip the terminal's colour codes.
     *
     * Terminal::text writes ansi escapes; in a browser they are line noise.
     *
     * @param string $output
     * @return string
     */
    private static function plain(string $output): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $output);
    }

    /**
     * @param string $reason
     * @return array
     */
    private static function refuse(string $reason): array
    {
        return ['ok' => false, 'code' => null, 'output' => '', 'command' => '', 'refused' => $reason];
    }
}
