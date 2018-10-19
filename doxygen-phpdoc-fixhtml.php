#!/usr/bin/php
<?php
/**
 * Apply fixes to Doxygen's XHTML output. Processes all .html files in the given
 * directory except source code.
 */

foreach (['/../../', '/vendor/', null] as $autoloadPath) {
    if ($autoloadPath) {
        $autoloadPath = __DIR__ . $autoloadPath . 'autoload.php';
        if (file_exists($autoloadPath)) {
            break;
        }
    }
}
if ($autoloadPath) {
    require_once $autoloadPath;
} else {
    error_log('FATAL: autoloader not found');
    exit(1);
}

$dir = @$_SERVER['argv'][1];
if (!is_dir($dir)) {
    error_log('Invalid directory');
    exit(1);
}

$separatorQueries = [
    \Zend\Dom\Document\Query::cssToXpath('html:a.el') => null,
    \Zend\Dom\Document\Query::cssToXpath('html:a.elRef') => null,
    \Zend\Dom\Document\Query::cssToXpath('html:area') => 'alt',
];
$phpLinkQuery = '//*[@href]';

$files = new \RecursiveDirectoryIterator(
    $dir,
    \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS
);
$iterator = new \RecursiveIteratorIterator($files);
foreach ($iterator as $file) {
    if (substr($file, -5) != '.html' or substr($file, -12) == '_source.html') {
        continue;
    }

    $document = new \DOMDocument;
    $document->preserveWhiteSpace = false;
    if (!@$document->load($file)) {
        error_log('WARNING: could not parse ' . $file);
        continue;
    }
    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace("html", "http://www.w3.org/1999/xhtml");

    // Replace most occurrences of Doxygen's namespace separator (::)
    // with the PHP separator. This is necessarily incomplete because
    // some occurrences cannot be reliably identified.
    foreach ($separatorQueries as $query => $attribute) {
        foreach ($xpath->query($query) as $node) {
            if ($attribute) {
                $node->setAttribute(
                    $attribute,
                    str_replace('::', '\\', $node->getAttribute($attribute))
                );
            } else {
                $node->textContent = str_replace('::', '\\', $node->textContent);
            }
        }
    }

    // Doxygen appends a .html suffix to external links which must be removed
    // from PHP documentation links.
    foreach ($xpath->query($phpLinkQuery) as $node) {
        $href = $node->getAttribute('href');
        if (preg_match('#^https://php\.net/manual/en/[\w\.-]+?\.php.html$#', $href)) {
            $node->setAttribute('href', substr($href, 0, -5));
        }
    }

    $document->formatOutput = false;
    if (!$document->save($file)) {
        error_log('WARNING: could not write ' . $file);
    }
}
