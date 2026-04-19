<?php
/**
 * Font Manager - Homepage Regeneration & Import Tool
 * Run via: php /www/wwwroot/wordpress/wp-content/plugins/font-manager/regen-homepage.php
 */
require_once('/www/wwwroot/wordpress/wp-load.php');

global $wpdb;
$table = $wpdb->prefix . 'font_manager';
$font_dir = ABSPATH . 'font/';
$upload_dir = wp_upload_dir();

// ===== ACTION =====
$action = $argv[1] ?? 'help';

if ($action === 'import') {
    // Import existing fonts from font-index.html into DB
    echo "=== Importing existing fonts from font-index.html ===\n";
    $html = file_get_contents(ABSPATH . 'font-index.html');
    
    // Parse font-card divs
    preg_match_all('/<div class="font-card"([^>]*)>/', $html, $matches, PREG_SET_ORDER);
    $imported = 0;
    $skipped = 0;
    
    foreach ($matches as $m) {
        $attrs = $m[1];
        preg_match('/data-name="([^"]*)"/', $attrs, $n);
        preg_match('/data-cat="([^"]*)"/', $attrs, $c);
        preg_match('/data-font-url="([^"]*)"/', $attrs, $u);
        preg_match('/data-font-class="([^"]*)"/', $attrs, $cl);
        preg_match('/href="\/font\/([^"]*)\//', $attrs, $h);
        
        $name = $n[1] ?? '';
        $cat = $c[1] ?? '其他';
        $font_url = $u[1] ?? '';
        $font_class = $cl[1] ?? '';
        $slug = $h[1] ?? '';
        $font_file = basename($font_url);
        
        if (empty($name) || empty($font_file)) {
            $skipped++;
            continue;
        }
        
        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE font_file = %s",
            $font_file
        ));
        
        if ($exists) {
            echo "  SKIP (exists): $name ($font_file)\n";
            $skipped++;
            continue;
        }
        
        // Get file size
        $font_path = ABSPATH . ltrim($font_url, '/');
        $file_size = file_exists($font_path) ? filesize($font_path) : 0;
        
        $result = $wpdb->insert($table, [
            'font_name' => $name,
            'font_slug' => $slug,
            'font_file' => $font_file,
            'font_class' => $font_class,
            'category' => $cat,
            'preview_text' => '字体展示文字 ABCabc123',
            'file_size' => $file_size,
        ]);
        
        if ($result) {
            echo "  IMPORTED: $name ($font_file) [$cat]\n";
            $imported++;
        } else {
            echo "  ERROR: $name - " . $wpdb->last_error . "\n";
        }
    }
    
    echo "\nDone! Imported: $imported, Skipped: $skipped\n";

} elseif ($action === 'regen') {
    echo "=== Regenerating font-index.html homepage ===\n";
    
    // Get all fonts from DB
    $fonts = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC", ARRAY_A);
    echo "Found " . count($fonts) . " fonts in DB\n";
    
    // Count by category
    $cat_counts = [];
    foreach ($fonts as $f) {
        $cat = $f['category'];
        if (!isset($cat_counts[$cat])) $cat_counts[$cat] = 0;
        $cat_counts[$cat]++;
    }
    
    // Generate nav buttons
    $total = count($fonts);
    $nav_btns = '<button class="nav-btn active" data-cat="all">全部 <span class="count">' . $total . '</span></button>';
    $cats_order = ['宋体', '楷体', '黑体', '艺术体', '像素体', '编程字体', '英文', '其他'];
    foreach ($cats_order as $cat) {
        $cnt = $cat_counts[$cat] ?? 0;
        if ($cnt > 0) {
            $nav_btns .= '<button class="nav-btn" data-cat="' . $cat . '">' . $cat . ' <span class="count">' . $cnt . '</span></button>';
        }
    }
    
    // Generate font cards
    $font_cards = '';
    foreach ($fonts as $f) {
        $ext = strtolower(pathinfo($f['font_file'], PATHINFO_EXTENSION));
        $size_mb = number_format($f['file_size'] / 1024 / 1024, 1);
        $size_str = $size_mb > 1 ? $size_mb . 'M' : number_format($f['file_size'] / 1024, 0) . 'K';
        $preview = $f['preview_text'] ?: '字体展示文字';
        $detail_url = '/font/' . $f['font_slug'] . '_ttf/';
        
        $font_cards .= '<div class="font-card" data-name="' . htmlspecialchars($f['font_name']) . '" data-cat="' . htmlspecialchars($f['category']) . '" data-font-url="/wp-content/uploads/font-upload/' . htmlspecialchars($f['font_file']) . '" data-font-class="' . htmlspecialchars($f['font_class']) . '">';
        $font_cards .= '<a href="' . $detail_url . '" class="card-inner">';
        $font_cards .= '<div class="font-preview"><div class="preview-main ' . $f['font_class'] . '">' . htmlspecialchars($f['font_name']) . '</div><div class="preview-sub">点击查看效果演示</div></div>';
        $font_cards .= '<div class="font-info"><div class="font-title">' . htmlspecialchars($f['font_name']) . '</div><div class="font-tags"><span class="tag cat-tag">' . htmlspecialchars($f['category']) . '</span><span class="tag">' . strtoupper($ext) . '</span><span class="tag">' . $size_str . '</span></div></div>';
        $font_cards .= '</a></div>';
    }
    
    // Read current font-index.html
    $html = file_get_contents(ABSPATH . 'font-index.html');
    
    // Replace nav buttons - find and replace the nav-btn line
    $html = preg_replace(
        '/<button class="nav-btn[^"]*"[^>]*>.*?<\/button>(?:<button class="nav-btn[^"]*"[^>]*>.*?<\/button>)*/',
        $nav_btns,
        $html
    );
    
    // Replace grid content
    $html = preg_replace(
        '/<div id="grid">\s*<div class="no-result"[^>]*>.*?<\/div>\s*(<div class="font-card".*?)<\/div>\s*<\/div>/s',
        '<div id="grid">' . "\n  <div class="no-result" id="noResult">没有找到匹配的字体</div>" . $font_cards . '</div>',
        $html
    );
    
    file_put_contents(ABSPATH . 'font-index.html', $html);
    echo "Done! Homepage regenerated with " . count($fonts) . " fonts.\n";
    echo "Total: $total fonts\n";
    foreach ($cat_counts as $cat => $cnt) {
        echo "  $cat: $cnt\n";
    }

} elseif ($action === 'help') {
    echo "Font Manager Tool\n";
    echo "Usage: php regen-homepage.php <action>\n\n";
    echo "Actions:\n";
    echo "  import  - Import existing fonts from font-index.html into DB\n";
    echo "  regen   - Regenerate font-index.html from DB fonts\n";
    echo "  help    - Show this message\n";
} else {
    echo "Unknown action: $action\n";
    echo "Run: php regen-homepage.php help\n";
}
