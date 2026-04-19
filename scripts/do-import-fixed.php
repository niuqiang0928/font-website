<?php
require_once('/www/wwwroot/wordpress/wp-load.php');
global $wpdb;
$table = $wpdb->prefix . 'font_manager';

$html = file_get_contents(ABSPATH . 'font-index.html');
preg_match_all('/<div class="font-card"([^>]*)>/', $html, $matches, PREG_SET_ORDER);

$imported = 0;
$skipped = 0;
$errors = array();

foreach ($matches as $m) {
    $attrs = $m[1];

    preg_match('/data-name="([^"]*)"/', $attrs, $n);
    preg_match('/data-cat="([^"]*)"/', $attrs, $c);
    preg_match('/data-font-url="([^"]*)"/', $attrs, $u);
    preg_match('/data-font-class="([^"]*)"/', $attrs, $cl);

    $name = isset($n[1]) ? trim($n[1]) : '';
    $cat = isset($c[1]) ? trim($c[1]) : '其他';
    $font_url = isset($u[1]) ? trim($u[1]) : '';
    $font_class = isset($cl[1]) ? trim($cl[1]) : '';

    if (empty($name)) {
        $skipped++;
        continue;
    }

    // Extract filename from URL
    $font_file = preg_replace('/.*\//', '', $font_url);
    if (empty($font_file)) {
        $font_file = $name . '.ttf';
    }

    // Generate slug: sanitize font name (keep Chinese, letters, numbers)
    // Replace anything that's not letter/number/Chinese with underscore
    $slug = preg_replace('/[^\p{L}\p{N}_]/u', '_', $name);
    $slug = preg_replace('/_+/', '_', $slug);
    $slug = trim($slug, '_');
    $ext = strtolower(pathinfo($font_file, PATHINFO_EXTENSION));
    $slug = strtolower($slug) . '_' . $ext;
    $slug = preg_replace('/[^a-z0-9_]/', '', $slug);

    // Check if already exists
    $exists = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE font_slug=%s", $slug));
    if ($exists) {
        for ($i = 2; $i < 1000; $i++) {
            $new_slug = $slug . '_' . $i;
            $exists2 = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE font_slug=%s", $new_slug));
            if (!$exists2) {
                $slug = $new_slug;
                break;
            }
        }
        if ($exists2) {
            $skipped++;
            continue;
        }
    }

    // Get file size
    $upload = wp_upload_dir();
    $font_path = $upload['basedir'] . '/font-upload/' . $font_file;
    $file_size = file_exists($font_path) ? filesize($font_path) : 0;

    $result = $wpdb->insert($table, array(
        'font_name' => $name,
        'font_slug' => $slug,
        'font_file' => $font_file,
        'font_class' => $font_class,
        'category' => $cat,
        'file_size' => $file_size,
        'preview_text' => mb_substr($name, 0, 10)
    ));

    if ($result === false) {
        $errors[] = 'Failed: ' . $name . ' - ' . $wpdb->last_error;
        $skipped++;
    } else {
        $imported++;
    }
}

echo 'Imported: ' . $imported . "\n";
echo 'Skipped: ' . $skipped . "\n";
if (count($errors) > 0) {
    echo "Errors:\n";
    foreach (array_slice($errors, 0, 10) as $e) echo '  ' . $e . "\n";
}
?>
