<?php
/**
 * Plugin Checklist — compile .po → .mo (GNU gettext binary).
 * Supports plural forms (msgid_plural / msgstr[N]) and preserves the header
 * entry (msgid "") which carries Plural-Forms — without it gettext cannot
 * select a plural form and _n() silently returns the untranslated source.
 *
 * Modified 2026 — i18n, settings and native-CRUD rework.
 *
 * Usage: php locales/compile_mo.php [directory]
 * CLI only.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

/**
 * @return array<int, array{id:string, id_plural:?string, str:array<int,string>}>
 */
function parsePo(string $content): array
{
    $entries = [];
    $cur     = null;
    $target  = null;

    foreach (explode("\n", $content) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (preg_match('/^msgid\s+"(.*)"$/s', $line, $m)) {
            if ($cur !== null) {
                $entries[] = $cur;
            }
            $cur    = ['id' => stripcslashes($m[1]), 'id_plural' => null, 'str' => []];
            $target = 'id';
        } elseif (preg_match('/^msgid_plural\s+"(.*)"$/s', $line, $m)) {
            if ($cur === null) { continue; }
            $cur['id_plural'] = stripcslashes($m[1]);
            $target = 'id_plural';
        } elseif (preg_match('/^msgstr\[(\d+)\]\s+"(.*)"$/s', $line, $m)) {
            if ($cur === null) { continue; }
            $idx              = (int) $m[1];
            $cur['str'][$idx] = stripcslashes($m[2]);
            $target           = 'str:' . $idx;
        } elseif (preg_match('/^msgstr\s+"(.*)"$/s', $line, $m)) {
            if ($cur === null) { continue; }
            $cur['str'][0] = stripcslashes($m[1]);
            $target        = 'str:0';
        } elseif (preg_match('/^"(.*)"$/s', $line, $m)) {
            if ($cur === null || $target === null) { continue; }
            $chunk = stripcslashes($m[1]);
            if ($target === 'id') {
                $cur['id'] .= $chunk;
            } elseif ($target === 'id_plural') {
                $cur['id_plural'] .= $chunk;
            } else {
                $i              = (int) substr($target, 4);
                $cur['str'][$i] = ($cur['str'][$i] ?? '') . $chunk;
            }
        }
    }
    if ($cur !== null) {
        $entries[] = $cur;
    }

    return $entries;
}

function generateMo(array $entries): string
{
    $pairs = [];

    foreach ($entries as $e) {
        $key = $e['id'];
        if ($e['id_plural'] !== null && $e['id_plural'] !== '') {
            $key .= "\x00" . $e['id_plural'];
        }

        ksort($e['str']);
        $val = implode("\x00", $e['str']);

        // Keep the header (msgid "") — it carries Plural-Forms/charset.
        // Drop entries with an empty translation so gettext falls back to source.
        if ($val === '' && $e['id'] !== '') {
            continue;
        }
        $pairs[$key] = $val;
    }

    ksort($pairs, SORT_STRING); // gettext requires byte-wise sorted originals

    $ids  = array_keys($pairs);
    $strs = array_values($pairs);
    $n    = count($ids);
    $p    = 28 + $n * 16;

    $ot = []; $tt = []; $os = ''; $ts = '';
    foreach ($ids as $s)  { $ot[] = [$p, strlen($s)]; $os .= $s . "\x00"; $p += strlen($s) + 1; }
    foreach ($strs as $s) { $tt[] = [$p, strlen($s)]; $ts .= $s . "\x00"; $p += strlen($s) + 1; }

    $mo = pack('V', 0x950412de) . pack('V', 0) . pack('V', $n)
        . pack('V', 28) . pack('V', 28 + $n * 8)
        . pack('V', 0)  . pack('V', 28 + $n * 16);
    foreach ($ot as [$off, $len]) { $mo .= pack('VV', $len, $off); }
    foreach ($tt as [$off, $len]) { $mo .= pack('VV', $len, $off); }

    return $mo . $os . $ts;
}

$dir = $argv[1] ?? __DIR__;
$dir = rtrim($dir, "/\\");

$found = glob("$dir/*.po") ?: [];
if ($found === []) {
    echo "No .po files in $dir\n";
    exit(1);
}

foreach ($found as $po_path) {
    $mo_path = preg_replace('/\.po$/', '.mo', $po_path);
    $mo      = generateMo(parsePo((string) file_get_contents($po_path)));
    file_put_contents($mo_path, $mo);
    echo 'OK: ' . basename($mo_path) . ' (' . strlen($mo) . " bytes)\n";
}
exit(0);
