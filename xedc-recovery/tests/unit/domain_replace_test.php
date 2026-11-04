<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/lib/domain_replace.php';

$failures = 0;

function check(string $name, bool $ok): void
{
    global $failures;
    if ($ok) {
        fwrite(STDOUT, "PASS $name\n");
        return;
    }
    $failures++;
    fwrite(STDOUT, "FAIL $name\n");
}

$old = 'old.example.test';
$new = 'new.example.test';

$samples = [
    'https://old.example.test/a' => 'https://new.example.test/a',
    'http://old.example.test/a' => 'http://new.example.test/a',
    '//old.example.test/a' => '//new.example.test/a',
    'www.old.example.test' => 'www.new.example.test',
    'https://WWW.OLD.EXAMPLE.TEST/x' => 'https://WWW.new.example.test/x',
    'x.old.example.test' => 'x.new.example.test',
    'old%2Eexample%2Etest' => 'new%2Eexample%2Etest',
    'old%2eexample%2etest' => 'new%2eexample%2etest',
];
foreach ($samples as $sample => $expected) {
    check("replace $sample", xedc_replace_hosts($sample, $old, $new) === $expected);
}

$kept = [
    'notold.example.test',
    'old.example.test.example.com',
    'prefix-old.example.test',
];
foreach ($kept as $sample) {
    check("keep $sample", xedc_replace_hosts($sample, $old, $new) === $sample);
}

$payload = [
    'url' => 'https://old.example.test/path',
    'nested' => ['bare' => 'old.example.test'],
];
$serialized = serialize($payload);
$changed = xedc_transform_text($serialized, $old, $new);
check('serialized parses', xedc_php_accepts($changed));
$restored = unserialize($changed, ['allowed_classes' => true]);
check('serialized value', is_array($restored) && $restored['url'] === 'https://new.example.test/path' && $restored['nested']['bare'] === 'new.example.test');
check('serialized length marker', preg_match('/s:\d+:"/', $changed) === 1);

$object = new stdClass();
$object->host = 'www.old.example.test';
$objectRaw = serialize($object);
$objectNew = xedc_transform_text($objectRaw, $old, $new);
$objectBack = unserialize($objectNew, ['allowed_classes' => true]);
check('object host', $objectBack instanceof stdClass && $objectBack->host === 'www.new.example.test');

$json = json_encode(['canonical' => 'https://old.example.test/node/1'], JSON_UNESCAPED_SLASHES);
$jsonNew = xedc_transform_text((string) $json, $old, $new);
$jsonData = json_decode($jsonNew, true);
check('json', is_array($jsonData) && $jsonData['canonical'] === 'https://new.example.test/node/1');

$inner = serialize(['host' => 'old.example.test']);
$outer = serialize(['blob' => $inner]);
$outerNew = xedc_transform_text($outer, $old, $new);
$outerBack = unserialize($outerNew, ['allowed_classes' => true]);
$innerBack = unserialize($outerBack['blob'], ['allowed_classes' => true]);
check('nested serialize', $innerBack['host'] === 'new.example.test');

$binary = "a\0b old.example.test";
$binaryResult = xedc_transform_column($binary, $old, $new);
check('binary protected', $binaryResult['unsupported'] === true && $binaryResult['value'] === $binary);

$custom = 'C:4:"Test":4:{abcd}';
check('custom object kept', xedc_ser_complete($custom) === false || str_contains(xedc_transform_text($custom, $old, $new), 'abcd'));

if ($failures > 0) {
    fwrite(STDOUT, "FAILED $failures\n");
    exit(1);
}
fwrite(STDOUT, "OK\n");
exit(0);
