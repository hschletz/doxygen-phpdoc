#!/usr/bin/php
<?php
/**
 * Read the PHP source given on the command line and output its content with
 * modifications that ensure correct processing by Doxygen.
 *
 * This script is typically invoked by Doxygen, controlled by the INPUT_FILTER
 * or FILTER_PATTERNS options.
 */

/**
 * Index of the type in token structure
 */
define('TOKEN_TYPE', 0);

/**
 * Index of the content in token structure
 */
define('TOKEN_CONTENT', 1);

/**
 * Index of the line number in token structure
 */
define('TOKEN_LINE', 2);

/**
 * Callback to replace "link" commands
 *
 * @param string[] $matches
 * @return string
 */
function replaceLink($matches)
{
    // For non-inline links, the remainder may contain the next line. Use only
    // the first line as link text and preserve the rest.
    $href = $matches[1];
    $remainder = explode("\n", @$matches[2]);
    $text = trim(array_shift($remainder));
    return sprintf(
        '<a href="%s">%s</a>%s%s',
        $href,
        $text ?: $href,
        $remainder ? "\n" : '',
        implode("\n", $remainder)
    );
}

/**
 * Callback to transform return command
 *
 * @param string[] $matches
 * @return string
 */
function generateReturnTypes($matches)
{
    // List of types, modified to be recognized by Doxygen
    $types = [];
    // List of original types to be prepended to the description.
    // Discarded if identical to $types.
    $description = [];
    foreach (explode('|', $matches[1]) as $type) {
        // Replace typed arrays
        if (substr($type, -2) == '[]') {
            $types[] = 'array';
        } else {
            $types[] = $type;
        }
        $description[] = $type;
    }
    $retval = '@retval ' . implode(' | ', $types);
    if ($description != $types) {
        $retval .= ' ' . implode(' | ', $description);
    }
    return $retval;
};


$filename = @$_SERVER['argv'][1];
if (!is_file($filename)) {
    error_log('Invalid filename: ' . $filename);
    exit(1);
}
$input = file_get_contents($filename);
if ($input === false) {
    error_log('Error reading ' . $filename);
    exit(1);
}

// Flag to indicate that the file header docblock has been encountered.
$fileHeaderPassed = false;

// Parse source file and modify tokens as needed.
$tokens = token_get_all($input);
foreach ($tokens as $index => $token) {
    if (is_string($token)) {
        print $token;
    } else {
        list($type, $content) = $token;
        switch ($type) {
            case T_DOC_COMMENT:
                // Doxygen would interpret backslashes as commands. Assuming we
                // are not using backslashes for commands (only @), replace
                // single backslashes with '::' which is Doxygen's namespace
                // separator. Multiple consecutive backslashes are left
                // untouched - these are escaped literal backslashes.
                // The regex is a literal backslash in the middle (double
                // escaped - once for the regex and again for the PHP string
                // literal, resulting in 4 backslashes), preceded by a negative
                // lookbehind for a another backslash and followed by a negative
                // lookahead.
                $content = preg_replace(
                    '#(?<!\\\\)\\\\(?!\\\\)#',
                    '::',
                    $content
                );

                // Inline links to properties require a $ prefix.
                $content = preg_replace(
                    '#\{@link (\w+)\}#',
                    '{@link $$1}',
                    $content
                );
                // Doxygen's "link" command does not support links to URLs.
                // Transform inline links.
                $content = preg_replace_callback(
                    '#\{@link\s+(.*?://[\w/\.%_\#-]+?)(\s(.*))?\}#',
                    'replaceLink',
                    $content
                );
                // Transform non-inline links.
                $content = preg_replace_callback(
                    '#@link\s+(.*?://[\w/\.%_\#-]+)(\s(.*))?#',
                    'replaceLink',
                    $content
                );

                if ($fileHeaderPassed) {
                    // Fixes for @var command
                    if (strpos($content, '@var') !== false) {
                        // Replace typed arrays with "array" and put typehint in
                        // description.
                        $content = preg_replace(
                            "/\*(.*)\n(.*)@var (.*)\[\]/",
                            "* $3[ ]$1\n$2@var array",
                            $content
                        );
                        // Doxygen requires the variable name appended to the
                        // @var command. Look ahead in token list to retrieve
                        // the name. Offset varies depending on intermittent
                        // keywords (public/protected, static).
                        $line = null;
                        for ($offset = 4; $offset <= 6; $offset++) {
                            $variableToken = $tokens[$index + $offset];
                            if (is_array($variableToken)) {
                                if ($variableToken[TOKEN_TYPE] == T_VARIABLE) {
                                    break;
                                }
                                $line = $variableToken[TOKEN_LINE];
                            }
                        }
                        if (!is_array($variableToken) or $variableToken[TOKEN_TYPE] != T_VARIABLE) {
                            // End of search range reached without result
                            $warning = 'WARNING: Variable not found';
                            if ($line) {
                                $warning .= ' around line ' . $line;
                            }
                            error_log($warning);
                            continue;
                        }
                        $content = preg_replace(
                            '/(@var .*)/',
                            '$1 ' . $variableToken[TOKEN_CONTENT],
                            $content
                        );
                    }

                    // Replace typed arrays in @param with "array" and put
                    // typehint in description.
                    $content = preg_replace(
                        '/@param (\S+)\[\] (\$\S+)/',
                        '@param array $2 $1[ ]',
                        $content
                    );

                    // Doxygen's @internal command has a different meaning.
                    // Replace it with @private which is not exactly the same,
                    // but has a similar effect for most configurations.
                    $content = preg_replace(
                        '/@internal(\s)/',
                        '@private$1',
                        $content
                    );

                    // Doxygen's @property command is not suitable for PHP's
                    // magic properties. Rearrange and format line content and
                    // put it into a @remark section to distinguish it from the
                    // rest of the class description.
                    // Back references: $1=type $3=name $4=description
                    $content = preg_replace(
                        '/@property\s+([\w:]+(\[\])?)\s+\$?(\w+)(.*)/',
                        '@remark Property <b>$3</b> <em>($1)</em>$4',
                        $content
                    );

                    // Treat @property-read similar to @property, add a
                    // "readonly" marker.
                    $content = preg_replace(
                        '/@property-read\s+([\w:]+(\[\])?)\s+\$?(\w+)(.*)/',
                        '@remark Property <b>$3</b> <em>($1, readonly)</em>$4',
                        $content
                    );

                    // Doxygen's @return[s] command behaves differently. The
                    // equivalent command is @retval.
                    // Typed arrays are not recognized. Move the typehint to the
                    // description.
                    // Alternative return types are not recognized. Put a space
                    // around the | to make Doxygen recognize the individual
                    // types.
                    $content = preg_replace_callback(
                        '/@returns?\s+([\w\[\]|:]+)/',
                        'generateReturnTypes',
                        $content
                    );

                    // Remove braces from inline @inheritdoc because Doxygen
                    // would insert them literally.
                    $content = str_replace('{@inheritdoc}', '@inheritdoc', $content);
                } else {
                    // This docblock is the file header.
                    // Insert @file command in the first line to prevent Doxygen
                    // from interpreting it as documentation for the subsequent
                    // namespace declaration.
                    $content = preg_replace("#\n#", " @file\n", $content, 1);

                    // Doxygen has no "license" command.
                    $content = str_replace('@license', '@copyright', $content);
                }
                break;
            case T_NAMESPACE:
                // End of file header area
                $fileHeaderPassed = true;
                break;
        }
        print $content;
    }
}
