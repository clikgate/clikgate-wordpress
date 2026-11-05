<?php
/**
 * Release check: the plugin header "Version", readme.txt "Stable tag" and CLIKGATE_VERSION must match,
 * and match the release version when one is given (the `wordpress-v<version>` tag without its prefix).
 *
 *   php scripts/check-version.php [1.2.3 | wordpress-v1.2.3]
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$main = (string) file_get_contents($root . '/clikgate.php');
$readme = (string) file_get_contents($root . '/readme.txt');

$found = [
    'plugin header Version' => preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/mi', $main, $m) === 1 ? $m[1] : null,
    'CLIKGATE_VERSION' => preg_match("/define\\('CLIKGATE_VERSION',\\s*'([^']+)'\\)/", $main, $m) === 1 ? $m[1] : null,
    'readme.txt Stable tag' => preg_match('/^Stable tag:\s*(\S+)\s*$/mi', $readme, $m) === 1 ? $m[1] : null,
];
$expected = $argv[1] ?? null;
if ($expected !== null && $expected !== '') {
    $found['release tag'] = preg_replace('/^wordpress-v/', '', $expected);
}

$errors = [];
foreach ($found as $where => $version) {
    if ($version === null || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
        $errors[] = "$where is missing or not x.y.z (" . json_encode($version) . ')';
    }
}
if (count(array_unique(array_filter($found))) > 1) {
    $errors[] = 'versions differ: ' . implode(', ', array_map(static fn ($k, $v) => "$k=$v", array_keys($found), $found));
}
// The changelog must describe the release
$version = $found['plugin header Version'];
if ($version !== null && !str_contains($readme, "= $version =")) {
    $errors[] = "readme.txt has no changelog entry \"= $version =\"";
}

if ($errors !== []) {
    fwrite(STDERR, "Version check failed:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
echo "Version check passed: $version\n";
