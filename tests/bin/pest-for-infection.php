#!/usr/bin/env php
<?php

/*
| Runs Pest for Infection, which drives it through its PHPUnit adapter and so expects PHPUnit's
| behaviour in four places:
|
|  - `--version`: Infection picks its config format by the PHPUnit version, and Pest answers
|    with its own (4.x). This answers with PHPUnit's.
|  - stderr: Infection stops the initial run at its first byte of stderr, and native toolkits
|    write warnings there (GTK's "skipped frame"). Pest runs in a child process with its stderr
|    folded into stdout, given the coverage settings Infection gave this PHP. Stopping this
|    process stops the child.
|  - The JUnit log of the initial run: coverage names a test by Pest's generated class
|    (P\Tests\...) while Pest's log names it Tests\..., and the log gives test files relative to
|    the project while Infection runs mutants from a temporary directory. Once Pest has written
|    the log, its names are matched to coverage and its paths made absolute.
|  - The verdict of a mutant run: Infection reads PHPUnit's summary line ("OK (" or
|    "FAILURES!"), not the exit code, and Pest prints neither. It is printed from the exit code.
*/

$root = dirname(__DIR__, 2);
$vendor = "{$root}/vendor";
$args = array_slice($argv, 1);

if (in_array('--version', $args, true)) {
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg("{$vendor}/bin/phpunit").' --version', $code);
    exit($code);
}

$junit = null;
$initial = false;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--log-junit=')) {
        $junit = substr($arg, strlen('--log-junit='));
    }
    if (str_starts_with($arg, '--coverage-')) {
        $initial = true;
    }
}

$php = [PHP_BINARY];
foreach (['pcov.enabled', 'pcov.directory', 'pcov.exclude', 'xdebug.mode'] as $setting) {
    $value = ini_get($setting);
    if ($value !== false && $value !== '') {
        array_push($php, '-d', "{$setting}={$value}");
    }
}

// The child is ours to stop: when Infection stops this process on a timeout, it goes too, or it
// would keep running (a mutant can loop forever) with its windows open.
$child = proc_open([...$php, "{$vendor}/bin/pest", ...$args], [1 => STDOUT, 2 => STDOUT], $pipes);
$stop = static function () use ($child): never {
    proc_terminate($child, SIGKILL);
    exit(143);
};
pcntl_async_signals(true);
pcntl_signal(SIGTERM, $stop);
pcntl_signal(SIGINT, $stop);
pcntl_signal(SIGHUP, $stop);
while (($status = proc_get_status($child))['running']) {
    usleep(20_000);
}
$code = $status['exitcode'];
proc_close($child);

if ($initial) {
    if (! is_null($junit) && is_file($junit)) {
        $xml = file_get_contents($junit);
        $xml = preg_replace('/(<testsuite name="|<testcase [^>]*?\bclass=")(?!P\\\\)(Tests\\\\)/', '$1P\\\\$2', $xml);
        $xml = preg_replace('/\bfile="(?!\/)/', 'file="'.$root.'/', $xml);
        file_put_contents($junit, $xml);
    }

    exit($code);
}

echo $code === 0 ? "\nOK (Pest: every test passed)\n" : "\nFAILURES!\n";
exit($code);
