<?php
/** Native PHP regression runner. Every suite gets a fresh process for its fakes. */
$files = glob(__DIR__ . '/*_test.php');
sort($files);
if (!$files) { fwrite(STDERR, "No regression suites found\n"); exit(1); }
$failed = [];
foreach ($files as $file) {
    $process = proc_open([PHP_BINARY, $file], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, dirname(__DIR__));
    if (!is_resource($process) || proc_close($process) !== 0) $failed[] = basename($file);
}
echo count($files) . ' suites, ' . count($failed) . " failures\n";
if ($failed) { fwrite(STDERR, implode(', ', $failed) . "\n"); exit(1); }
