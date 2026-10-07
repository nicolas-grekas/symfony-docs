<?php

/*
 * Compares reference/attributes.rst with the attribute classes of a
 * symfony/symfony checkout. Entries that match no attribute are errors.
 * Attributes missing from the page are warnings, since their docs usually
 * land after the code.
 *
 * An entry matches an attribute when both have the same class name and the
 * title of the section, without spaces, is part of the attribute's namespace
 * ("Doctrine Bridge" stands for "Bridge\Doctrine").
 *
 * Usage: php _build/check-attributes.php <path to symfony/symfony>
 */

if (!is_dir($src = ($argv[1] ?? '').'/src/Symfony')) {
    fwrite(STDERR, "Usage: php {$argv[0]} <path to symfony/symfony>\n");
    exit(2);
}

$page = 'reference/attributes.rst';

// provided by Twig and Symfony UX, which have their own repositories
$external = ['AsTwigFilter', 'AsTwigFunction', 'AsTwigTest', 'AsEntityAutocompleteField', 'AsLiveComponent', 'AsTwigComponent', 'Broadcast'];

// FQCN => whether the page should list it
$attributes = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
    $path = strtr($file->getPathname(), '\\', '/');

    if (!str_ends_with($path, '.php') || str_contains($path, '/Tests/')) {
        continue;
    }

    $code = file_get_contents($path);

    if (!preg_match('{^(?:/\*\*((?:(?!\*/).)*+)\*/\n)?(?:#\[[^\n]*+\n)*#\[\\\\?Attribute\b[^\n]*+\n(?:#\[[^\n]*+\n)*((?:\w++ )*)class (\w++)}ms', $code, $m)) {
        continue;
    }

    [, $docblock, $modifiers, $class] = $m;
    preg_match('/^namespace ([^;]++);/m', $code, $namespace);

    // constraints are listed in reference/constraints.rst
    $attributes[$namespace[1].'\\'.$class] = !str_contains($path, '/Validator/Constraints/')
        && !str_contains($modifiers, 'abstract')
        && !preg_match('/@(internal|deprecated)\b/', $docblock);
}

$entries = [];
$previous = $section = '';

foreach (file(dirname(__DIR__).'/'.$page, \FILE_IGNORE_NEW_LINES) as $i => $line) {
    if (preg_match('/^~++$/', $line)) {
        $section = str_replace(' ', '', preg_replace('/^(.+) Bridge$/', 'Bridge\\\\$1', $previous));
    } elseif (preg_match('/^\* (?::\w++:)?`{1,2}(\w++)/', $line, $m) && !in_array($m[1], $external, true)) {
        $entries[] = [$section, $m[1], $i + 1];
    }

    $previous = $line;
}

$matches = static fn (string $fqcn, array $entry): bool => str_ends_with($fqcn, '\\'.$entry[1]) && false !== stripos('\\'.$fqcn.'\\', '\\'.$entry[0].'\\');

$github = false !== getenv('GITHUB_ACTIONS');
$report = static function (string $level, string $message, int $line = 0) use ($github, $page) {
    echo $github ? sprintf("::%s file=%s%s::%s\n", $level, $page, $line ? ",line=$line" : '', $message) : sprintf("%s: %s%s\n", strtoupper($level), $line ? "line $line: " : '', $message);
};

ksort($attributes);
foreach ($attributes as $fqcn => $expected) {
    foreach ($entries as $entry) {
        if ($matches($fqcn, $entry)) {
            continue 2;
        }
    }

    if ($expected) {
        $report('warning', "#[$fqcn] is not listed");
    }
}

$stale = false;
foreach ($entries as $entry) {
    foreach ($attributes as $fqcn => $expected) {
        if ($matches($fqcn, $entry)) {
            continue 2;
        }
    }

    $stale = true;
    $report('error', "{$entry[1]} is not an attribute of the \"{$entry[0]}\" namespace", $entry[2]);
}

exit($stale ? 1 : 0);
