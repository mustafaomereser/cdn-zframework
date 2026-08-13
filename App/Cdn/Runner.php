<?php

namespace App\Cdn;

use zFramework\Core\Facades\Auth;
use zFramework\Kernel\Terminal;

/**
 * Running `php terminal` and `php cdn` from the panel.
 *
 * This is a remote shell on a machine whose whole job is to be reachable from
 * the internet, and it is worth saying so plainly rather than burying it: the
 * skeleton this project started from had a route that POSTed straight into the
 * terminal, and removing it was one of the first things done here. What follows
 * is the same capability with the parts that made it indefensible taken out.
 *
 *   - Off unless `admin.console.enabled` says otherwise.
 *   - Operator only, behind the panel's own session and csrf.
 *   - An allowlist of first words. Not a denylist: a denylist is a list of the
 *     dangerous things somebody thought of.
 *   - A timeout on the command, and an audit row per run.
 *
 * It runs **in this process**, not as a child. proc_open and friends are
 * disabled on most shared hosting, and a feature that only works where the
 * hosting is generous is a feature that does not work. Both entry points are
 * plain static calls - `Terminal::begin()` and `Console::begin()` - and the
 * request is already booted with everything they need, so there is nothing a
 * subprocess was buying except isolation we cannot rely on having.
 *
 * What that costs, and what is done about it:
 *
 *   - Output is captured with an output buffer rather than read off a pipe.
 *   - A command that calls exit() would take the response with it, so the
 *     buffer is flushed as json from a shutdown handler.
 *   - There is no killing it from outside. The timeout is set_time_limit, which
 *     is the only one an in-process command respects.
 *   - Statics the terminal keeps are saved and put back, so a command run here
 *     cannot change what the rest of the request sees.
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
     * The two entry points.
     *
     * Names rather than paths: nothing is spawned, so the files beside the
     * project root are only what somebody types on their own machine. These map
     * to Terminal::begin() and Console::begin().
     *
     * @return array
     */
    public static function scripts(): array
    {
        return ['cdn', 'terminal'];
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
        if (!self::enabled()) return self::refuse('console-disabled');

        if (!in_array($script, self::scripts(), true)) return self::refuse('unknown-script');

        # parseCommands() writes to readline's history whether or not there is a
        # terminal attached. Without the extension that is a fatal in the middle
        # of a request, so it is a refusal with a reason instead.
        if ($script === 'terminal' && !function_exists('readline_add_history')) return self::refuse('no-readline');

        $arguments = self::parse($line);

        if (!count($arguments)) return self::refuse('empty');

        $allowed = self::allowed($script);
        $command = strtolower($arguments[0]);

        # `*` is how an installation says it accepts the risk in full. It is not
        # the default and it is not what the config file suggests.
        if (!in_array('*', $allowed, true) && !in_array($command, $allowed, true)) {
            return self::refuse('not-allowed:' . $command);
        }

        # A command that never returns is a worker that never returns. In this
        # process the only limit that applies is php's own.
        $timeout = max(5, (int) Support::config('admin.console.timeout', 120));
        @set_time_limit($timeout);

        $line = $script . ' ' . implode(' ', $arguments);

        # The terminal keeps its parsed command in statics, and this request has
        # its own work to do afterwards. Saved and put back.
        $keep = [
            'commands'   => Terminal::$commands,
            'parameters' => Terminal::$parameters,
            'textlist'   => Terminal::$textlist,
            'terminate'  => Terminal::$terminate,
        ];

        # If the command exits - and some do - the response would end here with
        # nothing in it. This hands back what was printed before that happened.
        $flushed = false;

        register_shutdown_function(function () use (&$flushed, $line) {
            if ($flushed) return;

            $output = '';
            while (ob_get_level() > 0) $output = ob_get_clean() . $output;

            echo json_encode([
                'ok'      => false,
                'code'    => null,
                'command' => $line,
                'output'  => self::web($output) . "
" . _l('cdn.console.exited'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });

        ob_start();

        try {
            if ($script === 'terminal') {
                # --web makes Terminal::text() colour with markup instead of ansi
                # escapes, which is the difference between a browser showing
                # colour and showing `\e[32m`.
                Terminal::begin(array_merge(['terminal'], $arguments, ['--web']));
            } else {
                # Console has parameters of its own and never reads the
                # terminal's, so this only reaches Terminal::text().
                Terminal::$parameters = ['--web'];

                Console::begin(array_merge(['cdn'], $arguments));
            }

            $failed = null;
        } catch (\Throwable $thrown) {
            # A command that throws is one command failing, not the panel
            # failing. The message is the useful part of it.
            $failed = get_class($thrown) . ': ' . $thrown->getMessage();
        }

        $output  = (string) ob_get_clean();
        $flushed = true;

        Terminal::$commands   = $keep['commands'];
        Terminal::$parameters = $keep['parameters'];
        Terminal::$textlist   = $keep['textlist'];
        Terminal::$terminate  = $keep['terminate'];

        Operator::audit('console', 'console', ['id' => Auth::id(), 'name' => $line], [
            'command' => $line,
            'failed'  => $failed,
        ]);

        return [
            'ok'      => $failed === null,
            'code'    => $failed === null ? 0 : 1,
            'output'  => self::web($output) . ($failed === null ? '' : "
" . self::escape($failed)),
            'command' => $line,
        ];
    }

    /**
     * The terminal's output, safe to put in the page.
     *
     * `--web` wraps colours in <font> tags rather than ansi escapes, so the
     * output is markup - and some of it is data. A bucket named with a script
     * tag would be stored by one tenant and rendered in the operator's browser,
     * which is the whole shape of a stored xss.
     *
     * So everything is escaped, and then exactly two tags are let back in: the
     * colour font tag the terminal writes, with a hex colour and nothing else.
     *
     * @param string $output
     * @return string
     */
    private static function web(string $output): string
    {
        $escaped = self::escape($output);

        $escaped = preg_replace('/&lt;font color=&#039;(#[0-9a-f]{3,8})&#039;&gt;/i', '<font color="$1">', $escaped);
        $escaped = str_replace('&lt;/font&gt;', '</font>', $escaped);

        # Terminal::clear() prints fifty newlines before every command.
        return trim($escaped, "

");
    }

    /**
     * @param string $text
     * @return string
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
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
