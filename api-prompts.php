<?php
error_reporting(0);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$db = mysqli_connect('localhost', 'wp_user', 'WpPass2024', 'wordpress');
mysqli_set_charset($db, 'utf8mb4');

$p = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$n = isset($_GET['n']) ? min(48, intval($_GET['n'] ?? 9)) : 9;
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
    $where .= " AND prompt LIKE '%$q_esc%'";
}

$sql = "SELECT id, image_url, prompt, category, created_at FROM wp_font_prompts WHERE $where ORDER BY id DESC LIMIT $offset,$n";
$result = mysqli_query($db, $sql);

$items = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = $row;
    }
}

$cnt_sql = "SELECT COUNT(*) FROM wp_font_prompts WHERE $where";
$cnt_res = mysqli_query($db, $cnt_sql);
$total = mysqli_fetch_row($cnt_res)[0];
$has_more = ($offset + $n) < $total;

mysqli_close($db);
echo json_encode(['total'=>$total,'page'=>$p,'per_page'=>$n,'has_more'=>$has_more,'items'=>$items], JSON_UNESCAPED_UNICODE);