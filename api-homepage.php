<?php
require_once __DIR__ . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
$assets = get_option('fm_homepage_assets', []);
if (!is_array($assets)) {
    $assets = [];
}
$assets = wp_parse_args($assets, [
    'hero' => ['image_id' => 0, 'image_url' => '', 'title' => '首页轮播图', 'subtitle' => '后台可上传和修改'],
    'squares' => [
        ['image_id' => 0, 'image_url' => '', 'title' => '方形图片 1', 'subtitle' => '后台可上传和修改'],
        ['image_id' => 0, 'image_url' => '', 'title' => '方形图片 2', 'subtitle' => '后台可上传和修改'],
    ],
]);
echo wp_json_encode($assets, JSON_UNESCAPED_UNICODE);
