<?php
/** Exercise the real patched parser used for native kubeconfig input. */
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) { echo "YAML dependency checks skipped: install Composer dependencies\n"; return; }
require_once $autoload;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

$config = Yaml::parse("apiVersion: v1\nclusters:\n  - name: fixture\n    cluster:\n      server: https://example.com:6443\n");
if ($config['clusters'][0]['cluster']['server'] !== 'https://example.com:6443') throw new RuntimeException('Valid kubeconfig YAML no longer parses');
$checks = 1;
$probes = [
    'inline recursion' => 'value: ' . str_repeat('[', 160) . 'safe' . str_repeat(']', 160),
    'collection alias limit' => "anchor: &fixture [one, two]\naliases: [" . implode(', ', array_fill(0, 160, '*fixture')) . "]\n",
];
foreach ($probes as $name => $input) {
    try { Yaml::parse($input); }
    catch (ParseException $e) {
        if (!str_contains($e->getMessage(), '128')) throw new RuntimeException('Unexpected YAML rejection: ' . $name, 0, $e);
        $checks++;
        continue;
    }
    throw new RuntimeException('Unsafe YAML accepted without a bounded parser: ' . $name);
}
echo 'YAML dependency security: ' . $checks . " checks passed\n";
