<?php
error_reporting(0);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$__fm_wp_load = __DIR__ . '/wp-load.php';
if (file_exists($__fm_wp_load)) {
    require_once $__fm_wp_load;
    $assets = get_option('fm_home_assets', []);
    if (is_array($assets) && !empty($assets['filing_mode'])) {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$db = mysqli_connect('localhost', 'wp_user', 'WpPass2024', 'wordpress');
if (!$db) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_set_charset($db, 'utf8mb4');

$p = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$n = isset($_GET['n']) ? min(200, max(4, intval($_GET['n']))) : 48;
$cat = isset($_GET['cat']) ? trim($_GET['cat']) : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$offset = ($p - 1) * $n;

$where = '1=1';
if ($cat !== '') {
    $cat_esc = mysqli_real_escape_string($db, $cat);
    $where .= " AND category='$cat_esc'";
}
if ($q !== '') {
    $q_esc = mysqli_real_escape_string($db, $q);
    $where .= " AND font_name LIKE '%$q_esc%'";
}

$sql = "SELECT font_name, font_slug, font_class, category, preview_text FROM wp_font_manager WHERE $where ORDER BY id DESC LIMIT $offset,$n";
$result = mysqli_query($db, $sql);

$fonts = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $fonts[] = $row;
    }
}

mysqli_close($db);
echo json_encode($fonts, JSON_UNESCAPED_UNICODE);
