<?php
/*
Plugin Name: Font Manager
Description: 字体管理插件 - 上传、分类、生成详情页、自动更新首页
Version: 1.6
Author: Manon
*/

define('FM_SCHEMA_VERSION', '1.6.0');

function fm_install_tables(){
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $font_table = $wpdb->prefix . 'font_manager';
    $prompt_table = $wpdb->prefix . 'font_prompts';

    $sql_font = "CREATE TABLE IF NOT EXISTS $font_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        font_name VARCHAR(255) NOT NULL,
        font_slug VARCHAR(255) NOT NULL UNIQUE,
        font_file VARCHAR(255) NOT NULL,
        font_class VARCHAR(128) NOT NULL,
        category VARCHAR(64) NOT NULL DEFAULT '其他',
        file_size BIGINT DEFAULT 0,
        preview_text VARCHAR(500) DEFAULT '字',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";

    $sql_prompt = "CREATE TABLE IF NOT EXISTS $prompt_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        image_id BIGINT DEFAULT 0,
        image_url TEXT NOT NULL,
        prompt LONGTEXT NOT NULL,
        category VARCHAR(64) NOT NULL DEFAULT '其他',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY category (category)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_font);
    dbDelta($sql_prompt);
    update_option('fm_schema_version', FM_SCHEMA_VERSION, false);
}

register_activation_hook(__FILE__, 'fm_install_tables');
add_action('admin_init', function(){
    if(get_option('fm_schema_version') !== FM_SCHEMA_VERSION){
        fm_install_tables();
    }
});

add_action('admin_menu', function(){
    add_menu_page('字体管理', '字体管理', 'manage_options', 'font-manager', 'fm_admin_page', 'dashicons-art', 80);
});

function fm_get_prompt_category_defaults(){
    return ['毛笔字','活动字','可爱字','创意字','其他'];
}

function fm_get_prompt_categories(){
    $saved = get_option('fm_prompt_categories', []);
    if(!is_array($saved)) $saved = [];
    $saved = array_values(array_filter(array_map(function($item){
        return sanitize_text_field(is_string($item) ? $item : '');
    }, $saved)));
    $categories = array_values(array_unique(array_merge(fm_get_prompt_category_defaults(), $saved)));
    return $categories ?: fm_get_prompt_category_defaults();
}

function fm_save_prompt_categories($categories){
    if(!is_array($categories)) $categories = [];
    $clean = array_values(array_unique(array_filter(array_map(function($item){
        return sanitize_text_field(is_string($item) ? $item : '');
    }, $categories))));
    if(empty($clean)) $clean = fm_get_prompt_category_defaults();
    update_option('fm_prompt_categories', $clean, false);
    return $clean;
}

function fm_prepare_prompt_table(){
    global $wpdb;
    fm_install_tables();
    $table = $wpdb->prefix . 'font_prompts';
    $charset = $wpdb->get_charset_collate();

    $wpdb->query("CREATE TABLE IF NOT EXISTS `$table` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        image_id BIGINT DEFAULT 0,
        image_url TEXT NOT NULL,
        prompt LONGTEXT NOT NULL,
        category VARCHAR(64) NOT NULL DEFAULT '其他',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY category (category)
    ) $charset");

    $columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
    if(!is_array($columns)) $columns = [];

    $alter_sql = [];
    if(!in_array('image_id', $columns, true)) $alter_sql[] = "ADD COLUMN image_id BIGINT DEFAULT 0 AFTER id";
    if(!in_array('image_url', $columns, true)) $alter_sql[] = "ADD COLUMN image_url TEXT NOT NULL AFTER image_id";
    if(!in_array('prompt', $columns, true)) $alter_sql[] = "ADD COLUMN prompt LONGTEXT NOT NULL AFTER image_url";
    if(!in_array('category', $columns, true)) $alter_sql[] = "ADD COLUMN category VARCHAR(64) NOT NULL DEFAULT '其他' AFTER prompt";
    if(!in_array('created_at', $columns, true)) $alter_sql[] = "ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER category";
    if($alter_sql){
        $wpdb->query("ALTER TABLE `$table` " . implode(', ', $alter_sql));
    }

    return $table;
}


function fm_get_home_assets_defaults(){
    return [
        'filing_mode' => 0,
        'carousel' => [],
        'squares' => [
            ['image_url'=>'', 'title'=>'', 'sub'=>'', 'link'=>''],
            ['image_url'=>'', 'title'=>'', 'sub'=>'', 'link'=>''],
        ],
    ];
}

function fm_get_home_assets(){
    $defaults = fm_get_home_assets_defaults();
    $assets = get_option('fm_home_assets', []);
    if(!is_array($assets)) $assets = [];
    $assets = wp_parse_args($assets, $defaults);
    if(empty($assets['squares']) || !is_array($assets['squares'])) {
        $assets['squares'] = $defaults['squares'];
    }
    $assets['squares'] = array_values($assets['squares']);
    while(count($assets['squares']) < 2){
        $assets['squares'][] = ['image_url'=>'', 'title'=>'', 'sub'=>'', 'link'=>''];
    }
    $assets['squares'] = array_slice($assets['squares'], 0, 2);
    if(empty($assets['carousel']) || !is_array($assets['carousel'])) {
        $assets['carousel'] = [];
    }
    $assets['filing_mode'] = !empty($assets['filing_mode']) ? 1 : 0;
    return $assets;
}


function fm_is_filing_mode_enabled(){
    $assets = fm_get_home_assets();
    return !empty($assets['filing_mode']);
}

function fm_filing_mode_overlay_style(){
    return '.fm-filing-overlay{display:none;position:fixed;inset:0;z-index:99999;background:#0b0b0d;align-items:center;justify-content:center;padding:24px}.fm-filing-card{width:min(560px,100%);background:#141418;border:1px solid rgba(255,255,255,.08);border-radius:24px;padding:36px 28px;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,.42)}.fm-filing-title{font-size:32px;font-weight:700;margin-bottom:10px}.fm-filing-sub{color:rgba(255,255,255,.55);font-size:15px;line-height:1.8}body.filing-mode{padding-top:0 !important;overflow:hidden}body.filing-mode > *{display:none !important}body.filing-mode > .fm-filing-overlay{display:flex !important}';
}

function fm_filing_mode_boot_script(){
    return '<script>(function(){function enable(){if(!document.body||document.body.classList.contains("filing-mode"))return;var overlay=document.createElement("div");overlay.className="fm-filing-overlay";overlay.innerHTML="<div class=\"fm-filing-card\"><div class=\"fm-filing-title\">网站备案中</div><div class=\"fm-filing-sub\">站点内容暂时关闭，备案完成后恢复访问。</div></div>";document.body.appendChild(overlay);document.body.classList.add("filing-mode");}window.__fmEnableFilingMode=enable;fetch("/wp-json/font-manager/v1/home-assets",{cache:"no-store"}).then(function(r){return r.ok?r.json():null;}).then(function(data){if(data&&data.filing_mode){enable();}}).catch(function(){});})();</script>';
}



function fm_safe_ascii_token($value, $prefix = 'item'){
    $value = remove_accents((string)$value);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string)$value, '_');
    if($value === ''){
        $hash_seed = is_scalar($value) ? (string)$value : wp_json_encode($value);
        $value = sanitize_key($prefix . '_' . substr(md5($hash_seed . '|' . microtime(true)), 0, 12));
    }
    return $value;
}

function fm_compute_font_class($font_file, $font_name = ''){
    $ext = strtolower((string)pathinfo((string)$font_file, PATHINFO_EXTENSION));
    if(!in_array($ext, ['ttf','otf','woff','woff2'], true)){
        $ext = 'ttf';
    }
    $base = (string)pathinfo((string)$font_file, PATHINFO_FILENAME);
    $ascii = remove_accents($base);
    $ascii = strtolower($ascii);
    $ascii = preg_replace('/[^a-z0-9]+/', '_', $ascii);
    $ascii = trim((string)$ascii, '_');
    if($ascii === '' || strlen($ascii) < 2){
        $seed = (string)$font_file . '|' . (string)$font_name;
        $ascii = 'font_' . substr(md5($seed), 0, 12);
    }
    return sanitize_html_class('f_' . $ascii . '_' . $ext);
}

function fm_slug_exists($slug, $exclude_id = 0){
    global $wpdb;
    $table = $wpdb->prefix . 'font_manager';
    if($exclude_id > 0){
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE font_slug=%s AND id<>%d LIMIT 1", $slug, $exclude_id));
    }
    return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE font_slug=%s LIMIT 1", $slug));
}

function fm_is_invalid_font_slug($slug){
    $slug = (string)$slug;
    if($slug === '') return true;
    if(!preg_match('/[a-z0-9]/', $slug)) return true;
    if(!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) return true;
    return false;
}

function fm_compute_font_slug($font_name, $font_file, $exclude_id = 0){
    $primary = remove_accents((string)$font_name);
    $primary = strtolower($primary);
    $primary = preg_replace('/[^a-z0-9]+/', '-', $primary);
    $primary = trim((string)$primary, '-');

    $file_base = remove_accents((string)pathinfo((string)$font_file, PATHINFO_FILENAME));
    $file_base = strtolower($file_base);
    $file_base = preg_replace('/[^a-z0-9]+/', '-', $file_base);
    $file_base = trim((string)$file_base, '-');

    $slug = $primary ?: $file_base;
    if($slug === '' || strlen($slug) < 2){
        $slug = 'font-' . substr(md5((string)$font_file . '|' . (string)$font_name), 0, 12);
    }

    $base_slug = $slug;
    $i = 2;
    while(fm_slug_exists($slug, $exclude_id)){
        $slug = $base_slug . '-' . $i;
        $i++;
    }
    return $slug;
}

function fm_font_format_from_extension($ext){
    $ext = strtolower((string)$ext);
    if($ext === 'otf') return 'opentype';
    if($ext === 'woff2') return 'woff2';
    if($ext === 'woff') return 'woff';
    return 'truetype';
}

function fm_fonts_css_path(){
    $upload_dir = wp_upload_dir();
    $font_dir = trailingslashit($upload_dir['basedir']) . 'font-upload';
    if(!is_dir($font_dir)){
        wp_mkdir_p($font_dir);
    }
    return trailingslashit($font_dir) . 'fonts.css';
}

function fm_fonts_css_url(){
    $upload_dir = wp_upload_dir();
    $version = (string)get_option('fm_fonts_css_version', '');
    $url = trailingslashit($upload_dir['baseurl']) . 'font-upload/fonts.css';
    if($version !== ''){
        $url .= '?v=' . rawurlencode($version);
    }
    return $url;
}

function fm_escape_font_family_for_style($family){
    $family = str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$family);
    return $family;
}

function fm_generate_fonts_css($fonts = null){
    global $wpdb;
    $table = $wpdb->prefix . 'font_manager';
    if($fonts === null){
        $fonts = $wpdb->get_results("SELECT font_file,font_class FROM $table ORDER BY id DESC", ARRAY_A);
    }
    if(!is_array($fonts)) $fonts = [];
    $upload_dir = wp_upload_dir();
    $baseurl = trailingslashit($upload_dir['baseurl']) . 'font-upload/';
    $lines = [];
    $lines[] = "/* Auto-generated fonts.css */";
    foreach($fonts as $font){
        $font_file = isset($font['font_file']) ? (string)$font['font_file'] : '';
        if($font_file === '') continue;
        $font_class = isset($font['font_class']) ? sanitize_html_class((string)$font['font_class']) : '';
        if($font_class === '') continue;
        $ext = strtolower((string)pathinfo($font_file, PATHINFO_EXTENSION));
        $format = fm_font_format_from_extension($ext);
        $url = $baseurl . rawurlencode($font_file);
        $lines[] = "@font-face {";
        $lines[] = "  font-family: '" . str_replace("'", "\\'", $font_class) . "';";
        $lines[] = "  src: url('" . str_replace("'", "\\'", $url) . "') format('" . $format . "');";
        $lines[] = "  font-weight: normal;";
        $lines[] = "  font-style: normal;";
        $lines[] = "  font-display: swap;";
        $lines[] = "}";
        $lines[] = "." . $font_class . " {";
        $lines[] = "  font-family: '" . str_replace("'", "\\'", $font_class) . "', -apple-system, BlinkMacSystemFont, 'Microsoft YaHei', sans-serif;";
        $lines[] = "}";
        $lines[] = "";
    }
    $ok = file_put_contents(fm_fonts_css_path(), implode("\n", $lines));
    if($ok !== false){
        update_option('fm_fonts_css_version', (string)time(), false);
        return true;
    }
    return false;
}

function fm_build_detail_page_html($font){
    $font_name = isset($font['font_name']) ? (string)$font['font_name'] : '';
    $font_slug = isset($font['font_slug']) ? (string)$font['font_slug'] : '';
    $font_file = isset($font['font_file']) ? (string)$font['font_file'] : '';
    $font_class = isset($font['font_class']) ? sanitize_html_class((string)$font['font_class']) : '';
    $category = isset($font['category']) ? (string)$font['category'] : '其他';
    $preview_text = trim((string)($font['preview_text'] ?? ''));
    if($preview_text === '') $preview_text = $font_name ?: '字体预览';
    $file_size = intval($font['file_size'] ?? 0);
    $file_size_mb = $file_size > 0 ? number_format($file_size / 1024 / 1024, 1) : '0.0';
    $ext = strtoupper((string)pathinfo($font_file, PATHINFO_EXTENSION));
    $download_url = '/wp-content/uploads/font-upload/' . rawurlencode($font_file);
    $fonts_css = fm_fonts_css_url();
    $style_family = fm_escape_font_family_for_style($font_class);

    $font_name_html = esc_html($font_name);
    $category_html = esc_html($category);
    $preview_html = esc_html($preview_text);
    $ext_html = esc_html($ext);
    $size_html = esc_html($file_size_mb . ' MB');
    $family_attr = esc_attr($font_class);
    $download_attr = esc_attr($download_url);

    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>'.$font_name_html.' - 免费字体下载</title><style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{background:#0f0f0f;color:#fff;font-family:-apple-system,BlinkMacSystemFont,"Microsoft YaHei",sans-serif;min-height:100vh}
    .header{background:#1a1a1a;padding:0 40px;display:flex;align-items:center;justify-content:space-between;height:60px}
    .logo{font-size:20px;font-weight:700;color:#fff;text-decoration:none;display:flex;align-items:center;gap:10px}
    .logo-icon{font-size:24px}
    input[type="text"]{width:100%;padding:10px 16px;background:#2a2a2a;border:1px solid #3a3a3a;color:#fff;border-radius:20px;font-size:14px;outline:none}
    input[type="text"]:focus{border-color:#4a4a4a;background:#333}
    .auth-bar{display:flex;align-items:center;gap:12px}
    .auth-link{color:#999;text-decoration:none;font-size:14px;transition:color .2s}
    .auth-link:hover{color:#fff}
    .user-name{color:#fff;font-size:14px}
    .container{max-width:1200px;margin:0 auto;padding:40px 20px}
    .font-detail{background:#1a1a1a;border-radius:16px;padding:40px;margin-top:20px}
    .font-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:40px}
    .font-title{font-size:36px;font-weight:600;margin-bottom:8px}
    .font-meta{display:flex;gap:12px;flex-wrap:wrap;margin-top:12px}
    .tag{background:#2a2a2a;padding:6px 14px;border-radius:50px;font-size:13px;color:#aaa}
    .preview-section{margin:40px 0}
    .preview-box{background:#0f0f0f;border-radius:12px;padding:60px 40px;text-align:center;margin-bottom:16px}
    .preview-main{font-size:120px;line-height:1.2;word-break:break-word;margin-bottom:20px;min-height:150px}
    .preview-label{color:#666;font-size:13px;margin-bottom:16px}
    .preview-input-wrap{position:relative;max-width:600px;margin:0 auto}
    .preview-hint{color:#666;font-size:12px;text-align:center;margin-bottom:8px}
    .preview-input{width:100%;padding:14px 20px;background:#252525;border:1px solid #4a4a4a;border-radius:12px;color:#fff;font-size:16px;text-align:center;outline:none;transition:border-color .2s}
    .preview-input:focus{border-color:#555}
    .preview-input::placeholder{color:#555}
    .font-info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:30px 0}
    .info-card{background:#252525;padding:20px;border-radius:12px}
    .info-label{color:#666;font-size:12px;margin-bottom:6px}
    .info-value{color:#fff;font-size:16px;font-weight:500}
    .download-section{margin-top:40px}
    .download-btn{display:inline-flex;align-items:center;gap:10px;background:#fff;color:#000;padding:16px 40px;border-radius:50px;font-size:16px;font-weight:600;text-decoration:none;transition:all .3s;cursor:pointer;border:none}
    .download-btn:hover{background:#f0f0f0;transform:scale(1.02)}
    .download-btn:active{transform:scale(0.98)}
    .login-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:1000;justify-content:center;align-items:center}
    .login-modal.active{display:flex}
    .login-modal-content{background:#1a1a1a;padding:40px;border-radius:16px;max-width:400px;width:90%;text-align:center;position:relative}
    .login-modal h3{font-size:24px;margin-bottom:8px}
    .login-modal p{color:#999;margin-bottom:24px}
    .login-modal-btn{display:inline-flex;align-items:center;gap:8px;background:#fff;color:#000;padding:12px 32px;border-radius:50px;font-size:15px;font-weight:600;text-decoration:none;margin-bottom:16px}
    .close-modal{position:absolute;top:20px;right:20px;background:none;border:none;color:#666;font-size:24px;cursor:pointer}
    </style><style>'.fm_filing_mode_overlay_style().'</style><link rel="stylesheet" href="'.$fonts_css.'"></head><body><header class="header"><a href="/font-index.html" class="logo"><span class="logo-icon">←</span> 免费字体</a><div class="auth-bar" id="authBar"><a href="/login.html" class="auth-link">登录</a><span style="color:#555">|</span><a href="/register.html" class="auth-link">注册</a></div></header><div class="container"><div class="font-detail"><div class="font-header"><div class="font-title">'.$font_name_html.'</div><div class="font-meta"><span class="tag">'.$category_html.'</span><span class="tag">'.$ext_html.'</span><span class="tag">'.$size_html.'</span></div></div><div class="preview-section"><div class="preview-label">字体预览</div><div class="preview-box"><div class="preview-main '.$family_attr.'" id="previewText" data-font-family="'.$family_attr.'" style="font-family:\''.$style_family.'\',-apple-system,BlinkMacSystemFont,\'Microsoft YaHei\',sans-serif">'.$preview_html.'</div></div><div class="preview-input-wrap"><div class="preview-hint">可自由输入文字预览效果</div><input type="text" class="preview-input" id="previewInput" placeholder="输入任意文字预览效果…" value="'.$preview_html.'"></div></div><div class="font-info-grid"><div class="info-card"><div class="info-label">字体名称</div><div class="info-value">'.$font_name_html.'</div></div><div class="info-card"><div class="info-label">字体格式</div><div class="info-value">'.$ext_html.'</div></div><div class="info-card"><div class="info-label">文件大小</div><div class="info-value">'.$size_html.'</div></div><div class="info-card"><div class="info-label">字体分类</div><div class="info-value">'.$category_html.'</div></div></div><div class="download-section"><button class="download-btn" id="downloadBtn" data-url="'.$download_attr.'">↓ 下载字体文件</button></div></div></div><div id="loginModal" class="login-modal"><div class="login-modal-content"><button class="close-modal" onclick="closeLoginModal()">×</button><h3>请先登录</h3><p>登录后才能下载字体文件</p><a href="/login.html" class="login-modal-btn">立即登录</a></div></div><script src="/checkauth.js"></script>'.fm_filing_mode_boot_script().'<script>document.getElementById("previewInput").addEventListener("input",function(){var t=this.value||this.getAttribute("placeholder");document.getElementById("previewText").textContent=t});</script></body></html>';
}

function fm_generate_detail_page($id,$font_name,$font_slug,$font_file,$font_class,$category,$preview_text,$file_size,$font_url){
    $slug_dir = trailingslashit(ABSPATH) . 'font/' . $font_slug;
    if(!is_dir($slug_dir)){
        wp_mkdir_p($slug_dir);
    }
    $font = [
        'id' => $id,
        'font_name' => $font_name,
        'font_slug' => $font_slug,
        'font_file' => $font_file,
        'font_class' => $font_class,
        'category' => $category,
        'preview_text' => $preview_text,
        'file_size' => $file_size,
    ];
    return file_put_contents(trailingslashit($slug_dir) . 'index.html', fm_build_detail_page_html($font)) !== false;
}

function fm_rebuild_font_assets($repair_existing = true){
    global $wpdb;
    $table = $wpdb->prefix . 'font_manager';
    $fonts = $wpdb->get_results("SELECT * FROM $table ORDER BY id ASC", ARRAY_A);
    if(!is_array($fonts)) $fonts = [];
    $updated = 0;
    $generated = 0;
    $slug_updates = 0;

    foreach($fonts as &$font){
        $new_class = fm_compute_font_class($font['font_file'] ?? '', $font['font_name'] ?? '');
        $new_slug = (string)($font['font_slug'] ?? '');
        $slug_invalid = fm_is_invalid_font_slug($new_slug);
        if($repair_existing && ($slug_invalid || fm_slug_exists($new_slug, intval($font['id'])))){
            $new_slug = fm_compute_font_slug($font['font_name'] ?? '', $font['font_file'] ?? '', intval($font['id']));
        }

        $updates = [];
        if(($font['font_class'] ?? '') !== $new_class){
            $updates['font_class'] = $new_class;
            $font['font_class'] = $new_class;
        }
        if(($font['font_slug'] ?? '') !== $new_slug){
            $updates['font_slug'] = $new_slug;
            $old_slug = (string)($font['font_slug'] ?? '');
            $font['font_slug'] = $new_slug;
            if($old_slug !== '' && $old_slug !== $new_slug){
                $old_dir = trailingslashit(ABSPATH) . 'font/' . $old_slug;
                if(is_dir($old_dir)){
                    @unlink(trailingslashit($old_dir) . 'index.html');
                    @rmdir($old_dir);
                }
            }
        }
        if($updates){
            $wpdb->update($table, $updates, ['id' => intval($font['id'])]);
            $updated++;
            if(isset($updates['font_slug'])) $slug_updates++;
        }

        if(fm_generate_detail_page(
            intval($font['id']),
            (string)$font['font_name'],
            (string)$font['font_slug'],
            (string)$font['font_file'],
            (string)$font['font_class'],
            (string)$font['category'],
            (string)$font['preview_text'],
            intval($font['file_size']),
            ''
        )){
            $generated++;
        }
    }
    unset($font);

    fm_generate_fonts_css($fonts);

    return [
        'success' => true,
        'total' => count($fonts),
        'updated' => $updated,
        'slug_updates' => $slug_updates,
        'generated' => $generated,
        'css' => 'ok',
    ];
}
function fm_admin_page(){
    wp_enqueue_media();
    $upload_dir = wp_upload_dir();
    $font_dir = $upload_dir["basedir"] . "/font-upload";
    if (!is_dir($font_dir)) wp_mkdir_p($font_dir);
    global $wpdb;
    $table = $wpdb->prefix . "font_manager";
    $prompt_table = $wpdb->prefix . "font_prompts";
    $db_fonts = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC", ARRAY_A);
    $db_prompts = $wpdb->get_results("SELECT * FROM $prompt_table ORDER BY id DESC LIMIT 200", ARRAY_A);
    $cat_list = ["宋体","楷体","黑体","艺术体","像素体","编程字体","英文","其他"];
    $prompt_cat_list = fm_get_prompt_categories();
?>
<div class="wrap">
<h1>字体管理 <button class="fm-refresh" onclick="fmRebuildFontAssets()">修复并重建详情页 + fonts.css</button></h1>
<style>
.fm-form{background:#fff;padding:20px;border-radius:8px;margin-top:20px;max-width:600px}
.fm-form h2{font-size:18px;margin-bottom:15px}
.fm-row{margin-bottom:15px}
.fm-row label{display:block;font-weight:bold;margin-bottom:5px}
.fm-row input,.fm-row select{width:100%;padding:8px;font-size:14px;border:1px solid #ccc;border-radius:4px}
.fm-btn{background:#2271b1;color:#fff;padding:10px 20px;border:none;border-radius:4px;cursor:pointer;font-size:14px}
.fm-btn:hover{background:#135e96}
.fm-list{margin-top:30px}
.fm-list table{width:100%;border-collapse:collapse}
.fm-list th,.fm-list td{padding:10px;border:1px solid #ddd;text-align:left}
.fm-list th{background:#f5f5f5}
.fm-refresh{background:#00a32a;color:#fff;padding:8px 16px;border:none;border-radius:4px;cursor:pointer;margin-left:10px;font-size:14px}
.fm-refresh:hover{background:#008a20}
.fm-delete{color:#b32d2e;cursor:pointer;text-decoration:none}
.fm-prompt-form{max-width:1100px}
.fm-prompt-layout{display:grid;grid-template-columns:340px 1fr;gap:24px}
.fm-prompt-image-box{background:#f8f8f8;border:1px solid #ddd;border-radius:10px;padding:14px}
.fm-prompt-preview{width:100%;aspect-ratio:1/1;background:#f1f1f1;border:1px dashed #ccc;border-radius:10px;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#999}
.fm-prompt-preview img{width:100%;height:100%;object-fit:cover;display:block}
.fm-prompt-actions{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap}
.fm-prompt-list td img{width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid #ddd;background:#f6f6f6;display:block}
.fm-prompt-text{max-width:520px;white-space:pre-wrap;word-break:break-word;color:#333}
.fm-inline-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.fm-cat-manage{margin-top:10px;padding:12px;border:1px dashed #ddd;border-radius:10px;background:#fafafa}
.fm-cat-tags{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.fm-cat-tag{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#f0f0f1;border:1px solid #dcdcde;font-size:12px}
.fm-cat-tag button{border:none;background:transparent;color:#b32d2e;cursor:pointer;padding:0;font-size:14px;line-height:1}
@media(max-width:900px){.fm-prompt-layout{grid-template-columns:1fr}}
</style>


<?php $home_assets = fm_get_home_assets(); ?>
<div class="fm-form" style="max-width:1100px">
<h2>首页展示管理 / 全站备案模式</h2>
<p style="margin:0 0 16px;color:#666">这里管理首页顶部的大轮播图，以及右侧两张方形图片。保存后，前台 <code>/font-index.html</code> 会自动读取并展示；同时也可以开启全站备案模式。</p>

<div style="margin:0 0 18px;padding:14px 16px;border:1px solid #dcdcde;border-radius:10px;background:#fafafa">
    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
        <input type="checkbox" id="fmFilingMode" style="margin-top:2px" <?php checked(!empty($home_assets['filing_mode'])); ?>>
        <span>
            <strong>开启备案模式</strong><br>
            <span style="color:#666">勾选并保存后，全站会进入备案模式：首页、字体列表、提示词页、搜索页，以及重建后的字体详情页都会隐藏内容；取消勾选并保存后恢复正常展示。</span>
        </span>
    </label>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
    <div>
        <h3 style="margin:0 0 12px">轮播图（可多张）</h3>
        <div id="fmCarouselList"></div>
        <p><button type="button" class="button button-secondary" id="fmAddCarousel">+ 新增轮播项</button></p>
    </div>
    <div>
        <h3 style="margin:0 0 12px">右侧方形图片（固定 2 张）</h3>
        <div id="fmSquareList"></div>
    </div>
</div>

<p style="margin-top:18px">
    <button type="button" class="fm-btn" id="fmSaveHomeAssets">保存首页展示</button>
    <span id="fmHomeMsg" style="margin-left:12px;color:#666"></span>
</p>
</div>

<style>
.fm-home-card{background:#f8f8f8;border:1px solid #ddd;border-radius:8px;padding:14px;margin-bottom:12px}
.fm-home-card .fm-home-grid{display:grid;grid-template-columns:160px 1fr;gap:14px}
.fm-home-preview{width:100%;aspect-ratio:16/9;background:#e9e9e9;border:1px dashed #ccc;border-radius:8px;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#999;font-size:12px}
.fm-home-preview img{width:100%;height:100%;object-fit:cover;display:block}
.fm-home-fields .fm-row{margin-bottom:10px}
.fm-home-actions{display:flex;gap:8px;align-items:center;margin-top:8px}
.fm-home-label{font-weight:600;margin-bottom:6px;display:block}
@media(max-width:900px){
    .fm-home-card .fm-home-grid{grid-template-columns:1fr}
}
</style>

<script>
(function(){
    var initialHomeAssets = <?php echo wp_json_encode($home_assets, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
    var carouselList = document.getElementById('fmCarouselList');
    var squareList = document.getElementById('fmSquareList');
    var homeMsg = document.getElementById('fmHomeMsg');
    var filingMode = document.getElementById('fmFilingMode');

    function esc(v){ return (v || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    function cardTemplate(type, index, item, fixedTitle){
        var preview = item.image_url ? '<img src="' + esc(item.image_url) + '" alt="">' : '未选择图片';
        return '' +
        '<div class="fm-home-card" data-type="' + type + '">' +
            '<div class="fm-home-grid">' +
                '<div>' +
                    '<div class="fm-home-preview">' + preview + '</div>' +
                    '<div class="fm-home-actions">' +
                        '<button type="button" class="button fm-pick-image">选择图片</button>' +
                        '<button type="button" class="button fm-clear-image">清空</button>' +
                        (type === 'carousel' ? '<button type="button" class="button-link-delete fm-remove-card">删除</button>' : '') +
                    '</div>' +
                '</div>' +
                '<div class="fm-home-fields">' +
                    '<div class="fm-row"><label class="fm-home-label">' + esc(fixedTitle || ('轮播项 ' + (index + 1))) + '</label>' +
                    '<input type="text" class="regular-text fm-image-url" value="' + esc(item.image_url || '') + '" placeholder="图片 URL"></div>' +
                    '<div class="fm-row"><input type="text" class="regular-text fm-title" value="' + esc(item.title || '') + '" placeholder="标题（可选）"></div>' +
                    '<div class="fm-row"><input type="text" class="regular-text fm-sub" value="' + esc(item.sub || '') + '" placeholder="副标题（可选）"></div>' +
                    '<div class="fm-row"><input type="text" class="regular-text fm-link" value="' + esc(item.link || '') + '" placeholder="点击跳转链接（可选）"></div>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function renderHomeAssets(){
        var carousel = Array.isArray(initialHomeAssets.carousel) ? initialHomeAssets.carousel : [];
        var squares = Array.isArray(initialHomeAssets.squares) ? initialHomeAssets.squares.slice(0,2) : [];
        while(squares.length < 2){ squares.push({image_url:'',title:'',sub:'',link:''}); }

        carouselList.innerHTML = carousel.map(function(item, idx){
            return cardTemplate('carousel', idx, item || {}, '');
        }).join('') || '<p style="color:#888">还没有轮播项，点击下方按钮新增。</p>';

        squareList.innerHTML = squares.map(function(item, idx){
            return cardTemplate('square', idx, item || {}, '方形图片 ' + (idx + 1));
        }).join('');
    }

    function bindMediaPicker(root){
        root.addEventListener('click', function(e){
            var card = e.target.closest('.fm-home-card');
            if(!card) return;

            if(e.target.classList.contains('fm-pick-image')){
                e.preventDefault();
                var frame = wp.media({
                    title: '选择图片',
                    library: { type: 'image' },
                    button: { text: '使用这张图片' },
                    multiple: false
                });
                frame.on('select', function(){
                    var attachment = frame.state().get('selection').first().toJSON();
                    card.querySelector('.fm-image-url').value = attachment.url || '';
                    var preview = card.querySelector('.fm-home-preview');
                    preview.innerHTML = attachment.url ? '<img src="' + esc(attachment.url) + '" alt="">' : '未选择图片';
                });
                frame.open();
            }

            if(e.target.classList.contains('fm-clear-image')){
                e.preventDefault();
                card.querySelector('.fm-image-url').value = '';
                card.querySelector('.fm-home-preview').innerHTML = '未选择图片';
            }

            if(e.target.classList.contains('fm-remove-card')){
                e.preventDefault();
                card.remove();
                if(root === carouselList && !carouselList.children.length){
                    carouselList.innerHTML = '<p style="color:#888">还没有轮播项，点击下方按钮新增。</p>';
                }
            }
        });

        root.addEventListener('input', function(e){
            if(e.target.classList.contains('fm-image-url')){
                var card = e.target.closest('.fm-home-card');
                if(!card) return;
                var url = e.target.value.trim();
                card.querySelector('.fm-home-preview').innerHTML = url ? '<img src="' + esc(url) + '" alt="">' : '未选择图片';
            }
        });
    }

    function collectCards(wrapper){
        return Array.prototype.slice.call(wrapper.querySelectorAll('.fm-home-card')).map(function(card){
            return {
                image_url: (card.querySelector('.fm-image-url') || {}).value ? card.querySelector('.fm-image-url').value.trim() : '',
                title: (card.querySelector('.fm-title') || {}).value ? card.querySelector('.fm-title').value.trim() : '',
                sub: (card.querySelector('.fm-sub') || {}).value ? card.querySelector('.fm-sub').value.trim() : '',
                link: (card.querySelector('.fm-link') || {}).value ? card.querySelector('.fm-link').value.trim() : ''
            };
        }).filter(function(item){ return item.image_url || item.title || item.sub || item.link; });
    }

    document.getElementById('fmAddCarousel').addEventListener('click', function(){
        var emptyText = carouselList.querySelector('p');
        if(emptyText && emptyText.parentNode === carouselList) carouselList.innerHTML = '';
        var idx = carouselList.querySelectorAll('.fm-home-card').length;
        carouselList.insertAdjacentHTML('beforeend', cardTemplate('carousel', idx, {}, ''));
    });

    document.getElementById('fmSaveHomeAssets').addEventListener('click', function(){
        var payload = {
            filing_mode: filingMode && filingMode.checked ? 1 : 0,
            carousel: collectCards(carouselList),
            squares: collectCards(squareList).slice(0,2)
        };
        while(payload.squares.length < 2){ payload.squares.push({image_url:'',title:'',sub:'',link:''}); }

        homeMsg.style.color = '#666';
        homeMsg.textContent = '保存中...';

        var fd = new FormData();
        fd.append('action', 'fm_save_home_assets');
        fd.append('payload', JSON.stringify(payload));

        fetch(ajaxurl, { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(res){
            if(res && res.success){
                homeMsg.style.color = 'green';
                homeMsg.textContent = '保存成功';
            }else{
                homeMsg.style.color = 'red';
                homeMsg.textContent = '保存失败：' + ((res && res.data) || '未知错误');
            }
        })
        .catch(function(){
            homeMsg.style.color = 'red';
            homeMsg.textContent = '保存失败，请重试';
        });
    });

    bindMediaPicker(carouselList);
    bindMediaPicker(squareList);
    renderHomeAssets();
})();
</script>

<div class="fm-form fm-prompt-form">
<h2>字体提示词管理</h2>
<p style="margin:0 0 16px;color:#666">支持后台自主上传图片并填写提示词，保存后前台 <code>/font-prompts.html</code> 会自动展示。这里也可以直接删除已有提示词。</p>

<div class="fm-prompt-layout">
    <div class="fm-prompt-image-box">
        <div class="fm-row">
            <label>提示词配图</label>
            <div class="fm-prompt-preview" id="fmPromptPreview">未选择图片</div>
            <div class="fm-prompt-actions">
                <button type="button" class="button" id="fmPromptPickImage">选择/上传图片</button>
                <button type="button" class="button" id="fmPromptClearImage">清空图片</button>
            </div>
        </div>
        <div class="fm-row" style="margin-bottom:0">
            <label>图片 URL</label>
            <input type="text" id="fmPromptImageUrl" placeholder="可手动输入图片 URL，或用上方按钮选择">
        </div>
    </div>

    <div>
        <form id="fmPromptForm">
            <div class="fm-row">
                <label>提示词分类</label>
                <select name="category" id="fmPromptCategorySelect">
                <?php foreach($prompt_cat_list as $cat): ?>
                    <option value="<?php echo esc_attr($cat); ?>"><?php echo esc_html($cat); ?></option>
                <?php endforeach; ?>
                </select>
                <div class="fm-cat-manage">
                    <div class="fm-inline-row">
                        <input type="text" id="fmNewPromptCategory" placeholder="新增分类名称，例如：科技字" style="min-width:220px">
                        <button type="button" class="button button-secondary" id="fmAddPromptCategoryBtn">新增分类</button>
                        <span id="fmPromptCatMsg" style="color:#666"></span>
                    </div>
                    <div class="fm-cat-tags" id="fmPromptCatTags"></div>
                </div>
            </div>
            <div class="fm-row">
                <label>提示词内容</label>
                <textarea name="prompt" rows="8" style="width:100%;padding:10px;font-size:14px;border:1px solid #ccc;border-radius:4px" placeholder="输入提示词内容..." required></textarea>
            </div>
            <input type="hidden" name="action" value="fm_upload_prompt">
            <input type="hidden" name="image_url" id="fmPromptImageUrlHidden" value="">
            <button type="submit" class="fm-btn">上传提示词</button>
            <span id="fmPromptMsg" style="margin-left:12px;color:#666"></span>
        </form>
    </div>
</div>
</div>

<script>
(function(){
    var pickBtn = document.getElementById('fmPromptPickImage');
    var clearBtn = document.getElementById('fmPromptClearImage');
    var preview = document.getElementById('fmPromptPreview');
    var imageInput = document.getElementById('fmPromptImageUrl');
    var hiddenInput = document.getElementById('fmPromptImageUrlHidden');
    var form = document.getElementById('fmPromptForm');
    var msg = document.getElementById('fmPromptMsg');
    var categorySelect = document.getElementById('fmPromptCategorySelect');
    var categoryInput = document.getElementById('fmNewPromptCategory');
    var categoryBtn = document.getElementById('fmAddPromptCategoryBtn');
    var categoryMsg = document.getElementById('fmPromptCatMsg');
    var categoryTags = document.getElementById('fmPromptCatTags');
    var defaultPromptCategories = <?php echo wp_json_encode($prompt_cat_list, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

    function esc(v){ return (v || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function setPreview(url){
        hiddenInput.value = url || '';
        imageInput.value = url || '';
        preview.innerHTML = url ? '<img src="' + esc(url) + '" alt="">' : '未选择图片';
    }
    function getCategories(){
        return Array.prototype.slice.call(categorySelect.options).map(function(opt){ return opt.value; });
    }
    function renderCategoryTags(){
        var defaults = defaultPromptCategories.slice();
        var current = getCategories();
        categoryTags.innerHTML = current.map(function(name){
            var deletable = defaults.indexOf(name) === -1;
            return '<span class="fm-cat-tag">' + esc(name) + (deletable ? '<button type="button" data-name="' + esc(name) + '" title="删除分类">×</button>' : '') + '</span>';
        }).join('');
    }
    function addCategoryOption(name, selected){
        if(!name) return;
        var exists = getCategories().indexOf(name) !== -1;
        if(!exists){
            var opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            categorySelect.appendChild(opt);
        }
        if(selected) categorySelect.value = name;
        renderCategoryTags();
    }
    function removeCategoryOption(name){
        Array.prototype.slice.call(categorySelect.options).forEach(function(opt){
            if(opt.value === name) opt.remove();
        });
        if(!categorySelect.value && categorySelect.options.length){
            categorySelect.value = categorySelect.options[0].value;
        }
        renderCategoryTags();
    }

    if(pickBtn){
        pickBtn.addEventListener('click', function(){
            var frame = wp.media({
                title: '选择提示词图片',
                library: { type: 'image' },
                button: { text: '使用这张图片' },
                multiple: false
            });
            frame.on('select', function(){
                var attachment = frame.state().get('selection').first().toJSON();
                setPreview((attachment && attachment.url) || '');
            });
            frame.open();
        });
    }

    if(clearBtn){
        clearBtn.addEventListener('click', function(){ setPreview(''); });
    }

    if(imageInput){
        imageInput.addEventListener('input', function(){
            var url = this.value.trim();
            hiddenInput.value = url;
            preview.innerHTML = url ? '<img src="' + esc(url) + '" alt="">' : '未选择图片';
        });
    }

    if(categoryBtn){
        categoryBtn.addEventListener('click', function(){
            var name = (categoryInput.value || '').trim();
            if(!name){
                categoryMsg.style.color = 'red';
                categoryMsg.textContent = '请先输入分类名';
                categoryInput.focus();
                return;
            }
            categoryMsg.style.color = '#666';
            categoryMsg.textContent = '保存中...';
            var fd = new FormData();
            fd.append('action', 'fm_add_prompt_category');
            fd.append('name', name);
            fetch(ajaxurl, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(res){
                if(res && res.success && res.data && res.data.name){
                    addCategoryOption(res.data.name, true);
                    categoryInput.value = '';
                    categoryMsg.style.color = 'green';
                    categoryMsg.textContent = '已新增分类';
                }else{
                    categoryMsg.style.color = 'red';
                    categoryMsg.textContent = '新增失败：' + ((res && res.data) || '未知错误');
                }
            })
            .catch(function(){
                categoryMsg.style.color = 'red';
                categoryMsg.textContent = '新增失败，请重试';
            });
        });
    }

    if(categoryTags){
        categoryTags.addEventListener('click', function(e){
            var btn = e.target.closest('button[data-name]');
            if(!btn) return;
            var name = btn.getAttribute('data-name') || '';
            if(!name) return;
            if(!confirm('确定删除分类“' + name + '”吗？已有提示词会保留原分类名称。')) return;
            categoryMsg.style.color = '#666';
            categoryMsg.textContent = '删除中...';
            var fd = new FormData();
            fd.append('action', 'fm_delete_prompt_category');
            fd.append('name', name);
            fetch(ajaxurl, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(res){
                if(res && res.success){
                    removeCategoryOption(name);
                    categoryMsg.style.color = 'green';
                    categoryMsg.textContent = '已删除分类';
                }else{
                    categoryMsg.style.color = 'red';
                    categoryMsg.textContent = '删除失败：' + ((res && res.data) || '未知错误');
                }
            })
            .catch(function(){
                categoryMsg.style.color = 'red';
                categoryMsg.textContent = '删除失败，请重试';
            });
        });
    }

    if(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();
            hiddenInput.value = imageInput.value.trim();
            msg.style.color = '#666';
            msg.textContent = '上传中...';
            var fd = new FormData(form);
            fetch(ajaxurl, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(res){
                if(res && res.success){
                    msg.style.color = 'green';
                    msg.textContent = '上传成功';
                    setTimeout(function(){ location.reload(); }, 800);
                }else{
                    msg.style.color = 'red';
                    msg.textContent = '上传失败：' + ((res && res.data) || '未知错误');
                }
            })
            .catch(function(){
                msg.style.color = 'red';
                msg.textContent = '上传失败，请重试';
            });
        });
    }

    renderCategoryTags();
})();
</script>

<div class="fm-list fm-prompt-list">
<h2>已有提示词（共 <?php echo count($db_prompts); ?> 条）</h2>
<?php if(empty($db_prompts)): ?>
<p>暂无提示词，请上传。</p>
<?php else: ?>
<table>
<tr><th>ID</th><th>配图</th><th>分类</th><th>提示词</th><th>创建时间</th><th>操作</th></tr>
<?php foreach($db_prompts as $item): ?>
<tr>
<td><?php echo intval($item['id']); ?></td>
<td><?php if(!empty($item['image_url'])): ?><img src="<?php echo esc_url($item['image_url']); ?>" alt=""><?php else: ?><span style="color:#999">无图片</span><?php endif; ?></td>
<td><?php echo esc_html($item['category']); ?></td>
<td><div class="fm-prompt-text"><?php echo esc_html(mb_strimwidth($item['prompt'], 0, 180, '...')); ?></div></td>
<td><?php echo esc_html($item['created_at']); ?></td>
<td><a href="#" class="fm-delete fm-delete-prompt" data-id="<?php echo intval($item['id']); ?>">删除</a></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</div>

<div class="fm-form">
<h2>上传新字体</h2>
<form id="fmForm" enctype="multipart/form-data">
<div class="fm-row"><label>字体名称</label><input type="text" name="font_name" required placeholder="例如：思源宋体"></div>
<div class="fm-row"><label>字体分类</label><select name="category">
<?php foreach($cat_list as $cat): ?>
<option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
<?php endforeach; ?>
</select></div>
<div class="fm-row"><label>字体文件 (TTF/OTF/WOFF/WOFF2)</label><input type="file" name="font_file" accept=".ttf,.otf,.woff,.woff2" required></div>
<div class="fm-row"><label>预览文字（留空用"字"）</label><input type="text" name="preview_text" placeholder="字" maxlength="100"></div>
<div class="fm-row"><label>字体标识 font_class（自动生成）</label><input type="text" id="fm_class" name="font_class" placeholder="自动生成，上传后会以此写入 fonts.css" readonly></div><p style="margin:-6px 0 12px;color:#666;font-size:12px">中文字体会自动转为安全标识，避免详情页和列表页字体预览失效。</p>
<input type="hidden" name="action" value="fm_upload">
<button type="submit" class="fm-btn">上传字体</button>
</form>
<div id="fmMsg" style="margin-top:15px"></div>
</div>

<script>
(function(){
    function makeToken(fileName){
        var clean = String(fileName || '').replace(/\.[^.]+$/, '');
        var ascii = clean.normalize ? clean.normalize('NFKD').replace(/[\u0300-\u036f]/g, '') : clean;
        ascii = ascii.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        if(!ascii){
            var hash = 0;
            for(var i=0;i<clean.length;i++){
                hash = ((hash << 5) - hash) + clean.charCodeAt(i);
                hash |= 0;
            }
            ascii = 'font_' + Math.abs(hash).toString(16);
        }
        return ascii;
    }

    var form = document.getElementById("fmForm");
    var fileInput = form.querySelector("input[name=font_file]");
    var classInput = document.getElementById("fm_class");
    if(fileInput){
        fileInput.onchange = function(){
            if(!this.files || !this.files[0]){
                classInput.value = '';
                return;
            }
            var file = this.files[0];
            var ext = (file.name.split(".").pop() || 'ttf').toLowerCase();
            classInput.value = "f_" + makeToken(file.name) + "_" + ext;
        };
    }

    form.onsubmit = function(e){
        e.preventDefault();
        var fd = new FormData(this);
        document.getElementById("fmMsg").innerHTML = "上传中...";
        fetch(ajaxurl, {method:"POST", body: fd})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if(d.success){
                document.getElementById("fmMsg").innerHTML = "<p style=color:green>上传成功，已生成详情页并刷新 fonts.css："+d.data.font_name+"</p>";
                setTimeout(function(){ location.reload(); }, 1200);
            } else {
                document.getElementById("fmMsg").innerHTML = "<p style=color:red>失败："+(d.data || "未知错误")+"</p>";
            }
        })
        .catch(function(){
            document.getElementById("fmMsg").innerHTML = "<p style=color:red>失败：网络错误</p>";
        });
    };
})();
</script>

<div class="fm-list">
<h2>已有字体（共 <?php echo count($db_fonts); ?> 款）</h2>
<?php if(empty($db_fonts)): ?>
<p>暂无字体，请上传。</p>
<?php return; endif; ?>
<table>
<tr><th>ID</th><th>名称</th><th>分类</th><th>文件</th><th>大小</th><th>操作</th></tr>
<?php foreach($db_fonts as $f):
    $size = $f["file_size"] > 1048576 ? round($f["file_size"]/1048576,1)."M" : round($f["file_size"]/1024)."K";
?>
<tr>
<td><?php echo $f["id"]; ?></td>
<td><?php echo htmlspecialchars($f["font_name"]); ?></td>
<td><?php echo $f["category"]; ?></td>
<td><?php echo $f["font_file"]; ?></td>
<td><?php echo $size; ?></td>
<td><a href="#" class="fm-delete" data-id="<?php echo $f["id"]; ?>" data-slug="<?php echo $f["font_slug"]; ?>">删除</a></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<script>
function fmRebuildFontAssets(){
    if(!confirm("确定要修复 font_class、重建全部详情页，并重新生成 fonts.css 吗？")) return;
    fetch(ajaxurl + "?action=fm_rebuild_font_assets", {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d.success){
            alert("完成：共 " + d.total + " 款字体，修复 " + d.updated + " 条，重建详情页 " + d.generated + " 个。");
            location.reload();
        }else{
            alert("失败：" + (d.message || "未知错误"));
        }
    })
    .catch(function(e){ alert("错误: " + e); });
}
document.querySelectorAll(".fm-delete[data-slug]").forEach(function(el){
    el.onclick = function(){
        if(!confirm("确定删除这个字体吗？")) return;
        var fd = new FormData();
        fd.append("action", "fm_delete_font");
        fd.append("id", this.dataset.id);
        fd.append("slug", this.dataset.slug);
        fetch(ajaxurl, {method:"POST", body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d){ alert(d.success?"删除成功":"失败"); location.reload(); });
    };
});
document.querySelectorAll(".fm-delete-prompt").forEach(function(el){
    el.onclick = function(){
        if(!confirm("确定删除这条提示词吗？")) return;
        var fd = new FormData();
        fd.append("action", "fm_delete_prompt");
        fd.append("id", this.dataset.id);
        fetch(ajaxurl, {method:"POST", body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d){ alert(d.success?"删除成功":"失败"); location.reload(); });
    };
});
</script>
</div>
<?php
}
add_action("wp_ajax_fm_upload", function(){
    header("Content-Type: application/json");
    if(!current_user_can("manage_options")){ echo wp_json_encode(["success"=>false,"data"=>"权限不足"]); wp_die(); }
    if(empty($_FILES["font_file"])){ echo wp_json_encode(["success"=>false,"data"=>"没有文件"]); wp_die(); }

    $file = $_FILES["font_file"];
    if(!empty($file["error"])){ echo wp_json_encode(["success"=>false,"data"=>"上传错误:".$file["error"]]); wp_die(); }

    $name = isset($_POST["font_name"]) ? sanitize_text_field(wp_unslash($_POST["font_name"])) : '';
    $category = isset($_POST["category"]) ? sanitize_text_field(wp_unslash($_POST["category"])) : '其他';
    $preview = isset($_POST["preview_text"]) ? sanitize_text_field(wp_unslash($_POST["preview_text"])) : '';
    if($preview === '') $preview = $name ?: '字体预览';

    $ext = strtolower((string)pathinfo($file["name"], PATHINFO_EXTENSION));
    if(!in_array($ext, ["ttf","otf","woff","woff2"], true)){
        echo wp_json_encode(["success"=>false,"data"=>"仅支持 TTF / OTF / WOFF / WOFF2"]);
        wp_die();
    }

    $upload_dir = wp_upload_dir();
    $font_dir = trailingslashit($upload_dir["basedir"]) . "font-upload";
    if(!is_dir($font_dir)) wp_mkdir_p($font_dir);

    $safe_file_name = sanitize_file_name($file["name"]);
    if($safe_file_name === ''){
        $safe_file_name = 'font-' . substr(md5($name . '|' . time()), 0, 12) . '.' . $ext;
    }

    $dest = trailingslashit($font_dir) . $safe_file_name;
    if(file_exists($dest)){
        $safe_file_name = wp_unique_filename($font_dir, $safe_file_name);
        $dest = trailingslashit($font_dir) . $safe_file_name;
    }

    if(!move_uploaded_file($file["tmp_name"], $dest)){
        echo wp_json_encode(["success"=>false,"data"=>"保存失败"]);
        wp_die();
    }

    $font_class = fm_compute_font_class($safe_file_name, $name);
    $font_slug = fm_compute_font_slug($name, $safe_file_name, 0);
    $size = @filesize($dest);
    if($size === false) $size = 0;

    global $wpdb;
    $table = $wpdb->prefix . "font_manager";
    $ok = $wpdb->insert($table, [
        "font_name" => $name,
        "font_slug" => $font_slug,
        "font_file" => $safe_file_name,
        "font_class" => $font_class,
        "category" => $category ?: '其他',
        "file_size" => $size,
        "preview_text" => $preview,
    ], ["%s","%s","%s","%s","%s","%d","%s"]);

    if(!$ok){
        @unlink($dest);
        echo wp_json_encode(["success"=>false,"data"=>"写入数据库失败"]);
        wp_die();
    }

    $id = intval($wpdb->insert_id);
    fm_generate_detail_page($id, $name, $font_slug, $safe_file_name, $font_class, $category, $preview, $size, '');
    fm_generate_fonts_css();

    echo wp_json_encode(["success"=>true,"data"=>[
        "font_name"=>$name,
        "font_slug"=>$font_slug,
        "font_class"=>$font_class,
        "preview_text"=>$preview,
        "id"=>$id
    ]]);
    wp_die();
});

function fm_generate_font_card($f){
    $name = esc_html($f["font_name"] ?? '');
    $slug = esc_attr($f["font_slug"] ?? '');
    $file = esc_attr($f["font_file"] ?? '');
    $fclass = esc_attr($f["font_class"] ?? '');
    $cat = esc_html($f["category"] ?? '');
    $size = intval($f["file_size"] ?? 0);
    $size_str = $size > 1048576 ? round($size/1048576,1)."M" : round($size/1024)."K";
    $ext = strtoupper((string)pathinfo($file,PATHINFO_EXTENSION));
    $detail_url = "/font/".$slug."/index.html";
    $font_url = "/wp-content/uploads/font-upload/".rawurlencode($file);
    $style = "font-family:'" . esc_attr(fm_escape_font_family_for_style($fclass)) . "',-apple-system,BlinkMacSystemFont,'Microsoft YaHei',sans-serif";
    return '<div class="font-card" data-name="'.$name.'" data-cat="'.$cat.'" data-font-url="'.esc_attr($font_url).'" data-font-class="'.$fclass.'"><a href="'.$detail_url.'" class="card-inner"><div class="font-preview"><div class="preview-main '.$fclass.'" data-font-family="'.$fclass.'" style="'.$style.'">'.$name.'</div><div class="preview-sub">点击查看效果演示</div></div><div class="font-info"><div class="font-title">'.$name.'</div><div class="font-tags"><span class="tag cat-tag">'.$cat.'</span><span class="tag">'.$ext.'</span><span class="tag">'.$size_str.'</span></div></div></a></div>';
}

function fm_update_homepage($id, $font){
    return true;
}

function fm_regenerate_homepage(){
    return ["success"=>true,"total"=>0,"counts"=>[]];
}

add_action("wp_ajax_fm_rebuild_font_assets", function(){
    header("Content-Type: application/json");
    if(!current_user_can("manage_options")){
        echo wp_json_encode(["success"=>false,"message"=>"需要管理员权限"]);
        wp_die();
    }
    echo wp_json_encode(fm_rebuild_font_assets(true));
    wp_die();
});

add_action("wp_ajax_fm_regen_homepage", function(){
    header("Content-Type: application/json");
    if(!current_user_can("manage_options")){
        echo wp_json_encode(["success"=>false,"message"=>"需要管理员权限"]);
        wp_die();
    }
    echo wp_json_encode(fm_rebuild_font_assets(true));
    wp_die();
});

add_action("wp_ajax_fm_delete_font", function(){
    header("Content-Type: application/json");
    if(!current_user_can("manage_options")){ echo wp_json_encode(["success"=>false]); wp_die(); }
    $id = intval($_POST["id"] ?? 0);
    global $wpdb;
    $table = $wpdb->prefix."font_manager";
    $font = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id), ARRAY_A);
    if($font){
        $upload_dir = wp_upload_dir();
        $font_file = trailingslashit($upload_dir["basedir"])."font-upload/".$font["font_file"];
        if(file_exists($font_file)) @unlink($font_file);
        $slug_dir = trailingslashit(ABSPATH)."font/".($font["font_slug"] ?? '');
        if(is_dir($slug_dir)){
            @unlink(trailingslashit($slug_dir)."index.html");
            @rmdir($slug_dir);
        }
        $wpdb->delete($table,["id"=>$id],["%d"]);
        fm_generate_fonts_css();
    }
    echo wp_json_encode(["success"=>true]);
    wp_die();
});



add_action('wp_ajax_fm_add_prompt_category', function(){
    header('Content-Type: application/json');
    if(!current_user_can('manage_options')){ echo wp_json_encode(['success'=>false,'data'=>'权限不足']); wp_die(); }
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    if($name === ''){ echo wp_json_encode(['success'=>false,'data'=>'分类名称不能为空']); wp_die(); }
    $categories = fm_get_prompt_categories();
    if(!in_array($name, $categories, true)){
        $categories[] = $name;
        $categories = fm_save_prompt_categories($categories);
    }
    echo wp_json_encode(['success'=>true,'data'=>['name'=>$name,'categories'=>$categories]]);
    wp_die();
});

add_action('wp_ajax_fm_delete_prompt_category', function(){
    header('Content-Type: application/json');
    if(!current_user_can('manage_options')){ echo wp_json_encode(['success'=>false,'data'=>'权限不足']); wp_die(); }
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    if($name === ''){ echo wp_json_encode(['success'=>false,'data'=>'参数错误']); wp_die(); }
    if(in_array($name, fm_get_prompt_category_defaults(), true)){
        echo wp_json_encode(['success'=>false,'data'=>'默认分类不能删除']);
        wp_die();
    }
    $categories = array_values(array_filter(fm_get_prompt_categories(), function($item) use ($name){
        return $item !== $name;
    }));
    $categories = fm_save_prompt_categories($categories);
    echo wp_json_encode(['success'=>true,'data'=>['categories'=>$categories]]);
    wp_die();
});

add_action('wp_ajax_fm_upload_prompt', function(){
    header('Content-Type: application/json');
    if(!current_user_can('manage_options')){ echo wp_json_encode(['success'=>false,'data'=>'权限不足']); wp_die(); }

    $prompt_table = fm_prepare_prompt_table();
    $image_url = isset($_POST['image_url']) ? esc_url_raw(wp_unslash($_POST['image_url'])) : '';
    $prompt = isset($_POST['prompt']) ? wp_kses_post(wp_unslash($_POST['prompt'])) : '';
    $prompt = trim($prompt);
    $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '其他';

    if(!$image_url){ echo wp_json_encode(['success'=>false,'data'=>'请先选择图片']); wp_die(); }
    if(!$prompt){ echo wp_json_encode(['success'=>false,'data'=>'请输入提示词内容']); wp_die(); }
    if($category === '') $category = '其他';

    $categories = fm_get_prompt_categories();
    if(!in_array($category, $categories, true)){
        $categories[] = $category;
        fm_save_prompt_categories($categories);
    }

    global $wpdb;
    $columns = $wpdb->get_col("SHOW COLUMNS FROM `$prompt_table`", 0);
    if(!is_array($columns) || empty($columns)){
        echo wp_json_encode(['success'=>false,'data'=>'提示词数据表不存在']);
        wp_die();
    }

    $raw_data = [
        'image_id' => 0,
        'image_url' => $image_url,
        'prompt' => $prompt,
        'category' => $category,
        'created_at' => current_time('mysql'),
    ];
    $formats_map = [
        'image_id' => '%d',
        'image_url' => '%s',
        'prompt' => '%s',
        'category' => '%s',
        'created_at' => '%s',
    ];
    $data = [];
    $formats = [];
    foreach($raw_data as $key => $value){
        if(in_array($key, $columns, true)){
            $data[$key] = $value;
            $formats[] = $formats_map[$key];
        }
    }

    $ok = $wpdb->insert($prompt_table, $data, $formats);
    if(false === $ok){
        $reason = $wpdb->last_error ? ('写入数据库失败：' . $wpdb->last_error) : '写入数据库失败';
        echo wp_json_encode(['success'=>false,'data'=>$reason]);
        wp_die();
    }
    echo wp_json_encode(['success'=>true,'data'=>['id'=>$wpdb->insert_id]]);
    wp_die();
});

add_action('wp_ajax_fm_delete_prompt', function(){
    header('Content-Type: application/json');
    if(!current_user_can('manage_options')){ echo wp_json_encode(['success'=>false,'data'=>'权限不足']); wp_die(); }
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if($id <= 0){ echo wp_json_encode(['success'=>false,'data'=>'参数错误']); wp_die(); }
    global $wpdb;
    $prompt_table = $wpdb->prefix . 'font_prompts';
    $wpdb->delete($prompt_table, ['id'=>$id], ['%d']);
    echo wp_json_encode(['success'=>true]);
    wp_die();
});

add_action('rest_api_init', function(){
    register_rest_route('font-manager/v1', '/prompts', [
        'methods' => 'GET',
        'callback' => function(){
            global $wpdb;
            $table = fm_prepare_prompt_table();
            $items = $wpdb->get_results("SELECT id,image_url,prompt,category,created_at FROM $table ORDER BY id DESC LIMIT 200", ARRAY_A);
            return rest_ensure_response(['items'=>$items, 'categories'=>fm_get_prompt_categories()]);
        },
        'permission_callback' => '__return_true',
    ]);
});


add_action('wp_ajax_fm_save_home_assets', function(){
    header('Content-Type: application/json');
    if(!current_user_can('manage_options')){
        echo wp_json_encode(['success'=>false, 'data'=>'权限不足']);
        wp_die();
    }

    $raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
    $data = json_decode($raw, true);
    if(!is_array($data)){
        echo wp_json_encode(['success'=>false, 'data'=>'数据格式错误']);
        wp_die();
    }

    $clean = [
        'filing_mode' => !empty($data['filing_mode']) ? 1 : 0,
        'carousel' => [],
        'squares' => [],
    ];

    if(!empty($data['carousel']) && is_array($data['carousel'])){
        foreach($data['carousel'] as $item){
            if(!is_array($item)) continue;
            $row = [
                'image_url' => esc_url_raw($item['image_url'] ?? ''),
                'title' => sanitize_text_field($item['title'] ?? ''),
                'sub' => sanitize_text_field($item['sub'] ?? ''),
                'link' => esc_url_raw($item['link'] ?? ''),
            ];
            if($row['image_url'] || $row['title'] || $row['sub'] || $row['link']){
                $clean['carousel'][] = $row;
            }
        }
    }

    if(!empty($data['squares']) && is_array($data['squares'])){
        foreach($data['squares'] as $item){
            if(!is_array($item)) continue;
            $row = [
                'image_url' => esc_url_raw($item['image_url'] ?? ''),
                'title' => sanitize_text_field($item['title'] ?? ''),
                'sub' => sanitize_text_field($item['sub'] ?? ''),
                'link' => esc_url_raw($item['link'] ?? ''),
            ];
            $clean['squares'][] = $row;
        }
    }

    while(count($clean['squares']) < 2){
        $clean['squares'][] = ['image_url'=>'', 'title'=>'', 'sub'=>'', 'link'=>''];
    }
    $clean['squares'] = array_slice($clean['squares'], 0, 2);

    update_option('fm_home_assets', $clean, false);

    echo wp_json_encode(['success'=>true, 'data'=>$clean]);
    wp_die();
});

add_action('rest_api_init', function(){
    register_rest_route('font-manager/v1', '/home-assets', [
        'methods' => 'GET',
        'callback' => function(){
            return rest_ensure_response(fm_get_home_assets());
        },
        'permission_callback' => '__return_true',
    ]);
});
