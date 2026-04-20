<?php
/**
 * Detail Page Preview Font Hotfix
 *
 * Usage:
 *   1) Put this file in your site root, or anywhere PHP can run.
 *   2) Edit $siteRoot if needed.
 *   3) Run: php detail-page-hotfix.php
 *
 * It patches existing static detail pages under /font/ so preview text uses
 * the correct font-family inline, even on old generated pages.
 */

$siteRoot = '/www/wwwroot/wordpress';
$fontRoot = rtrim($siteRoot, '/').'/font';
$fontsCssVersion = '4';

if (!is_dir($fontRoot)) {
    fwrite(STDERR, "font directory not found: {$fontRoot}\n");
    exit(1);
}

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($fontRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) continue;
    if (strtolower($file->getExtension()) !== 'html') continue;
    $files[] = $file->getPathname();
}

if (!$files) {
    echo "No detail html files found under {$fontRoot}\n";
    exit(0);
}

function patchPreviewMain($html, &$changed) {
    return preg_replace_callback(
        '#<div\s+class="([^"]*\bpreview-main\b[^"]*)"([^>]*)\sid="previewText"([^>]*)>#i',
        function ($m) use (&$changed) {
            $classAttr = $m[1];
            $before = $m[2] ?? '';
            $after = $m[3] ?? '';
            if (preg_match('/\b(f_[A-Za-z0-9_]+)\b/', $classAttr, $fm)) {
                $family = $fm[1];
                $attrs = $before . ' id="previewText"' . $after;

                if (!preg_match('/\bdata-font-family=/', $attrs)) {
                    $attrs .= ' data-font-family="' . htmlspecialchars($family, ENT_QUOTES, 'UTF-8') . '"';
                    $changed = true;
                }

                $inline = "font-family:'{$family}',-apple-system,BlinkMacSystemFont,&quot;Microsoft YaHei&quot;,sans-serif";
                if (preg_match('/\bstyle="([^"]*)"/i', $attrs, $sm)) {
                    $style = $sm[1];
                    if (stripos($style, 'font-family:') === false) {
                        $newStyle = rtrim($style, '; ') . ';' . html_entity_decode($inline, ENT_QUOTES, 'UTF-8');
                        $attrs = preg_replace('/\bstyle="([^"]*)"/i', ' style="' . str_replace('"', '&quot;', $newStyle) . '"', $attrs, 1);
                        $changed = true;
                    }
                } else {
                    $attrs .= ' style="' . $inline . '"';
                    $changed = true;
                }

                return '<div class="' . $classAttr . '"' . $attrs . '>';
            }
            return $m[0];
        },
        $html
    );
}

$patched = 0;
$scanned = 0;
$errors = [];

foreach ($files as $path) {
    $scanned++;
    $html = @file_get_contents($path);
    if ($html === false) {
        $errors[] = "Read failed: {$path}";
        continue;
    }

    $original = $html;
    $changed = false;

    // bump / insert fonts.css version
    if (strpos($html, '/wp-content/uploads/font-upload/fonts.css') === false) {
        $html = preg_replace(
            '#</title>#i',
            "</title>\n<link rel=\"stylesheet\" href=\"/wp-content/uploads/font-upload/fonts.css?v={$fontsCssVersion}\">",
            $html,
            1,
            $count
        );
        if ($count) $changed = true;
    } else {
        $newHtml = preg_replace(
            '#/wp-content/uploads/font-upload/fonts\.css(?:\?v=\d+)?#i',
            "/wp-content/uploads/font-upload/fonts.css?v={$fontsCssVersion}",
            $html,
            -1,
            $count
        );
        if ($count) {
            $html = $newHtml;
            $changed = true;
        }
    }

    // normalize auth links
    $newHtml = str_replace('href="/register"', 'href="/register.html"', $html, $count1);
    if ($count1) {
        $html = $newHtml;
        $changed = true;
    }

    $newHtml = str_replace('href="/login"', 'href="/login.html"', $html, $count2);
    if ($count2) {
        $html = $newHtml;
        $changed = true;
    }

    // inject inline font-family on preview
    $newHtml = patchPreviewMain($html, $changed);
    if ($newHtml !== null) $html = $newHtml;

    if ($html !== $original) {
        $backup = $path . '.bak';
        if (!file_exists($backup)) {
            @file_put_contents($backup, $original);
        }
        if (@file_put_contents($path, $html) === false) {
            $errors[] = "Write failed: {$path}";
            continue;
        }
        $patched++;
        echo "PATCHED: {$path}\n";
    }
}

echo "\nDone. scanned={$scanned}, patched={$patched}, errors=" . count($errors) . "\n";
if ($errors) {
    echo implode("\n", $errors) . "\n";
}
