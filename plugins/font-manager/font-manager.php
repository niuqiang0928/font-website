<?php
/*
Plugin Name: Font Manager
Description: 字体管理插件 - 上传、分类、生成详情页、自动更新首页
Version: 1.3
Author: Manon
*/

register_activation_hook(__FILE__, function(){
    global $wpdb;
    $table = $wpdb->prefix . 'font_manager';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
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
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});


if (!function_exists('fm_get_token_from_request')) {
    function fm_get_token_from_request(){
        $token = '';
        if (!empty($_GET['token'])) {
            $token = sanitize_text_field(wp_unslash($_GET['token']));
        }
        if (empty($token) && !empty($_SERVER['HTTP_X_FONT_AUTH_TOKEN'])) {
            $token = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FONT_AUTH_TOKEN']));
        }
        if (empty($token) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = trim($_SERVER['HTTP_AUTHORIZATION']);
            if (stripos($auth, 'Bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        }
        return $token;
    }
}

if (!function_exists('fm_get_authenticated_user')) {
    function fm_get_authenticated_user(){
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($user instanceof WP_User && $user->exists()) {
                return $user;
            }
        }

        $token = fm_get_token_from_request();
        if (empty($token)) {
            return false;
        }

        $parts = explode('|', $token);
        if (count($parts) !== 3) {
            return false;
        }

        $user_id = intval($parts[0]);
        $expiry  = intval($parts[1]);
        if ($user_id <= 0 || $expiry < time()) {
            return false;
        }

        $stored = get_user_meta($user_id, 'font_auth_token', true);
        if (!$stored || !hash_equals($stored, $token)) {
            return false;
        }

        $user = get_user_by('id', $user_id);
        return $user instanceof WP_User ? $user : false;
    }
}

if (!function_exists('fm_get_protected_download_url')) {
    function fm_get_protected_download_url($font_slug){
        return '/wp-json/font-manager/v1/download/' . rawurlencode($font_slug);
    }
}


if (!function_exists('fm_font_inline_style')) {
    function fm_font_inline_style($font_class){
        $family = str_replace(["\\", "'", '"'], ['\\\\', "\\'", '&quot;'], (string)$font_class);
        return ' style="font-family:\'' . $family . '\',-apple-system,BlinkMacSystemFont,&quot;Microsoft YaHei&quot;,sans-serif" data-font-family="' . esc_attr($font_class) . '"';
    }
}

add_action('rest_api_init', function(){
    register_rest_route('font-manager/v1', '/download/(?P<slug>[A-Za-z0-9._-]+)', [
        'methods' => 'GET',
        'callback' => function($request){
            $user = fm_get_authenticated_user();
            if (!$user) {
                return new WP_Error('not_logged_in', '请先登录后再下载字体文件', ['status' => 401]);
            }

            $slug = sanitize_text_field($request['slug']);
            global $wpdb;
            $table = $wpdb->prefix . 'font_manager';
            $font = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE font_slug=%s LIMIT 1", $slug), ARRAY_A);
            if (!$font) {
                return new WP_Error('font_not_found', '字体不存在', ['status' => 404]);
            }

            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . '/font-upload/' . $font['font_file'];
            if (!file_exists($file_path) || !is_readable($file_path)) {
                return new WP_Error('file_not_found', '字体文件不存在', ['status' => 404]);
            }

            $filetype = wp_check_filetype($font['font_file']);
            $mime = !empty($filetype['type']) ? $filetype['type'] : 'application/octet-stream';
            $download_name = basename($font['font_file']);

            while (ob_get_level()) {
                ob_end_clean();
            }

            nocache_headers();
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . rawurlencode($download_name) . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
            header('Content-Length: ' . filesize($file_path));
            header('X-Content-Type-Options: nosniff');
            readfile($file_path);
            exit;
        },
        'permission_callback' => '__return_true',
    ]);
});

add_action('admin_menu', function(){
    add_menu_page('字体管理', '字体管理', 'manage_options', 'font-manager', 'fm_admin_page', 'dashicons-art', 80);
});

function fm_admin_page(){
    $upload_dir = wp_upload_dir();
    $font_dir = $upload_dir["basedir"] . "/font-upload";
    if (!is_dir($font_dir)) wp_mkdir_p($font_dir);
    global $wpdb;
    $table = $wpdb->prefix . "font_manager";
    $db_fonts = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC", ARRAY_A);
    $cat_list = ["宋体","楷体","黑体","艺术体","像素体","编程字体","英文","其他"];
?>
<div class="wrap">
<h1>字体管理 <button class="fm-refresh" onclick="fmRegenHomepage()">重新生成首页</button><button class="fm-refresh" onclick="fmRegenDetailPages()" style="background:#3858e9">重新生成全部详情页</button></h1>
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
</style>

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
<div class="fm-row"><label>CSS class名称（自动生成）</label><input type="text" id="fm_class" name="font_class" placeholder="自动生成，如 f_sourcehanserif_ttf" readonly></div>
<input type="hidden" name="action" value="fm_upload">
<button type="submit" class="fm-btn">上传字体</button>
</form>
<div id="fmMsg" style="margin-top:15px"></div>
</div>

<script>
document.getElementById("fmForm").onsubmit = function(e){
    e.preventDefault();
    var fd = new FormData(this);
    document.getElementById("fmMsg").innerHTML = "上传中...";
    fetch(ajaxurl, {method:"POST", body: fd})
    .then(r => r.json())
    .then(function(d){
        if(d.success){
            document.getElementById("fmMsg").innerHTML = "<p style=color:green>成功！字体："+d.data.font_name+"</p>";
        } else {
            document.getElementById("fmMsg").innerHTML = "<p style=color:red>失败："+d.data+"</p>";
        }
        if(d.success){ setTimeout(function(){ location.reload(); }, 1500); }
    });
};
document.querySelector("input[name=font_file]").onchange = function(){
    var name = this.files[0].name.replace(/\.[^.]+$/,"").replace(/[^a-zA-Z0-9_]/g,"_");
    var ext = this.files[0].name.split(".").pop();
    document.getElementById("fm_class").value = "f_" + name.toLowerCase() + "_" + ext;
};
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
function fmRegenHomepage(){
    if(!confirm("确定要重新生成首页吗？")) return;
    fetch(ajaxurl + "?action=fm_regen_homepage", {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
    .then(r => r.json())
    .then(function(d){ alert(d.success ? "首页已更新 (" + d.total + " 款字体)" : "失败: " + d.message); location.reload(); })
    .catch(function(e){ alert("错误: " + e); });
}
function fmRegenDetailPages(){
    if(!confirm("确定要重新生成全部详情页吗？")) return;
    fetch(ajaxurl + "?action=fm_regen_detail_pages", {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
    .then(r => r.json())
    .then(function(d){ alert(d.success ? "详情页已更新 (" + d.total + " 个)" : "失败: " + d.message); if(d.success) location.reload(); })
    .catch(function(e){ alert("错误: " + e); });
}
document.querySelectorAll(".fm-delete").forEach(function(el){
    el.onclick = function(){
        if(!confirm("确定删除？")) return;
        var fd = new FormData();
        fd.append("action", "fm_delete_font");
        fd.append("id", this.dataset.id);
        fd.append("slug", this.dataset.slug);
        fetch(ajaxurl, {method:"POST", body:fd})
        .then(r=>r.json())
        .then(function(d){ alert(d.success?"删除成功":"失败"); location.reload(); });
    };
});
</script>
</div>
<?php
}
add_action("wp_ajax_fm_upload", function(){
    header("Content-Type: application/json");
    if(!current_user_can("manage_options")){echo json_encode(["success"=>false,"data"=>"权限不足"]);wp_die();}
    if(empty($_FILES["font_file"])){echo json_encode(["success"=>false,"data"=>"没有文件"]);wp_die();}
    $file = $_FILES["font_file"];
    if($file["error"]!==0){echo json_encode(["success"=>false,"data"=>"上传错误:".$file["error"]]);wp_die();}
    $name = sanitize_text_field($_POST["font_name"]);
    $category = sanitize_text_field($_POST["category"]);
    $preview = sanitize_text_field($_POST["preview_text"]) ?: "字";
    $font_class = sanitize_html_class($_POST["font_class"]);
    $ext = strtolower(pathinfo($file["name"],PATHINFO_EXTENSION));
    if(!in_array($ext,["ttf","otf","woff","woff2"])){$ext="ttf";$file["name"].=".ttf";}
    $upload_dir = wp_upload_dir();
    $font_dir = $upload_dir["basedir"]."/font-upload";
    if(!is_dir($font_dir)) wp_mkdir_p($font_dir);
    $dest = $font_dir."/".$file["name"];
    if(file_exists($dest)){@unlink($dest);}
    if(!move_uploaded_file($file["tmp_name"],$dest)){echo json_encode(["success"=>false,"data"=>"保存失败"]);wp_die();}
    $font_url = $upload_dir["baseurl"]."/font-upload/".$file["name"];
    $font_slug = sanitize_title($name)."_".pathinfo($file["name"],PATHINFO_EXTENSION);
    $font_slug = preg_replace("/[^a-z0-9_]/","",strtolower($font_slug));
    global $wpdb;
    $table = $wpdb->prefix."font_manager";
    $exists = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE font_slug=%s",$font_slug));
    if($exists){$font_slug = $font_slug."_".time();}
    $size = filesize($dest);
    $wpdb->insert($table,["font_name"=>$name,"font_slug"=>$font_slug,"font_file"=>$file["name"],"font_class"=>$font_class,"category"=>$category,"file_size"=>$size,"preview_text"=>$preview]);
    $id = $wpdb->insert_id;
    $font_url_web = $upload_dir["baseurl"]."/font-upload/".$file["name"];
    fm_generate_detail_page($id, $name, $font_slug, $file["name"], $font_class, $category, $preview, $size, $font_url_web);
    fm_update_homepage($id, (array)$wpdb->get_row("SELECT * FROM $table WHERE id=$id", ARRAY_A));
    echo json_encode(["success"=>true,"data"=>["font_name"=>$name,"font_slug"=>$font_slug,"font_class"=>$font_class,"preview_text"=>$preview,"id"=>$id]]);
    wp_die();
});

function fm_generate_font_card($f){
    $name = htmlspecialchars($f["font_name"]);
    $slug = htmlspecialchars($f["font_slug"]);
    $file = htmlspecialchars($f["font_file"]);
    $fclass = htmlspecialchars($f["font_class"]);
    $cat = htmlspecialchars($f["category"]);
    $size = intval($f["file_size"]);
    $size_str = $size > 1048576 ? round($size/1048576,1)."M" : round($size/1024)."K";
    $ext = strtoupper(pathinfo($file,PATHINFO_EXTENSION));
    $detail_url = "/font/".$slug."/";
    $font_url = "/wp-content/uploads/font-upload/".$file;
    return '<div class="font-card" data-name="'.$name.'" data-cat="'.$cat.'" data-font-url="'.$font_url.'" data-font-class="'.$fclass.'"><a href="'.$detail_url.'" class="card-inner"><div class="font-preview"><div class="preview-main '.$fclass.'"'.fm_font_inline_style($fclass).'>'.$name.'</div><div class="preview-sub">点击查看效果演示</div></div><div class="font-info"><div class="font-title">'.$name.'</div><div class="font-tags"><span class="tag cat-tag">'.$cat.'</span><span class="tag">'.$ext.'</span><span class="tag">'.$size_str.'</span></div></div></a></div>';
}

function fm_update_homepage($id, $font){
    $index_path = ABSPATH . "font-index.html";
    if(!file_exists($index_path)) return false;
    $html = file_get_contents($index_path);
    if (strpos($html, '/wp-content/uploads/font-upload/fonts.css') === false) {
        $html = str_replace('</title>', '</title>' . "\n<link rel=\"stylesheet\" href=\"/wp-content/uploads/font-upload/fonts.css?v=3\">", $html);
    } else {
        $html = preg_replace('#/wp-content/uploads/font-upload/fonts\.css\?v=\d+#', '/wp-content/uploads/font-upload/fonts.css?v=3', $html);
    }
    $font_card = fm_generate_font_card($font);
    // Check if already exists
    if(strpos($html, $font_card) !== false) return true;
    // Insert before no-result div
    $no_result_pattern = '<div class="no-result" id="noResult">没有找到匹配的字体</div>';
    if(strpos($html, $no_result_pattern) !== false){
        $new_html = str_replace($no_result_pattern, $font_card."
  ".$no_result_pattern, $html);
        @file_put_contents($index_path, $new_html);
        return true;
    }
    return false;
}

function fm_regenerate_homepage(){
    global $wpdb;
    $table = $wpdb->prefix . "font_manager";
    $fonts = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC", ARRAY_A);
    $index_path = ABSPATH . "font-index.html";
    if(!file_exists($index_path)) return ["success"=>false,"message"=>"font-index.html not found"];
    $html = file_get_contents($index_path);
    if (strpos($html, '/wp-content/uploads/font-upload/fonts.css') === false) {
        $html = str_replace('</title>', '</title>' . "\n<link rel=\"stylesheet\" href=\"/wp-content/uploads/font-upload/fonts.css?v=3\">", $html);
    } else {
        $html = preg_replace('#/wp-content/uploads/font-upload/fonts\.css\?v=\d+#', '/wp-content/uploads/font-upload/fonts.css?v=3', $html);
    }
    $cat_counts = ["all"=>count($fonts)];
    foreach($fonts as $f){
        $cat = $f["category"];
        if(!isset($cat_counts[$cat])) $cat_counts[$cat] = 0;
        $cat_counts[$cat]++;
    }
    $cats_order = ["all","宋体","楷体","黑体","艺术体","像素体","编程字体","英文","其他"];
    $cat_labels = ["all"=>"全部","宋体"=>"宋体","楷体"=>"楷体","黑体"=>"黑体","艺术体"=>"艺术体","像素体"=>"像素体","编程字体"=>"编程字体","英文"=>"英文","其他"=>"其他"];
    $nav = "";
    foreach($cats_order as $cat){
        $cnt = $cat_counts[$cat] ?? 0;
        if($cat === "all" || $cnt > 0){
            $active = ($cat === "all") ? " active" : "";
            $nav .= '<button class="nav-btn'.$active.'" data-cat="'.$cat.'">'.$cat_labels[$cat].' <span class="count">'.$cnt.'</span></button>';
        }
    }
    // Build new nav block
    $nav_block = '<div class="nav" id="nav">' . $nav . '</div>';
    // Replace the nav section using strpos
    $nav_start = strpos($html, '<div class="nav" id="nav">');
    $nav_end_tag = '</div>';
    $nav_end_pos = strpos($html, $nav_end_tag, $nav_start);
    $grid_start = strpos($html, '<div class="grid"', $nav_end_pos);
    if($nav_start !== false && $grid_start !== false){
        $before_nav = substr($html, 0, $nav_start);
        $after_grid = substr($html, $grid_start);
        $html = $before_nav . $nav_block . "
" . $after_grid;
    }
    // Build card grid
    $cards = "";
    foreach($fonts as $f){
        $cards .= "
  " . fm_generate_font_card((array)$f);
    }
    $no_result = '<div class="no-result" id="noResult">没有找到匹配的字体</div>';
    $grid_open = '<div class="grid" id="grid">';
    $grid_content = $grid_open . "
  " . $no_result . $cards . "
</div>
</div>";
    // Replace grid section
    $grid_pattern_start = '<div class="grid" id="grid">';
    $gp_start = strpos($html, $grid_pattern_start);
    $gp_end = strpos($html, '</div>', $gp_start);
    // Find the last </div></div> at the end
    $last_close = strrpos($html, '</div>');
    if($gp_start !== false && $last_close !== false){
        $before_grid = substr($html, 0, $gp_start);
        $html = $before_grid . $grid_content;
    }
    @file_put_contents($index_path, $html);
    return ["success"=>true,"total"=>count($fonts),"counts"=>$cat_counts];
}


function fm_regenerate_detail_pages(){
    global $wpdb;
    $table = $wpdb->prefix . "font_manager";
    $fonts = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC", ARRAY_A);
    $upload_dir = wp_upload_dir();
    foreach($fonts as $font){
        fm_generate_detail_page(
            intval($font['id']),
            $font['font_name'],
            $font['font_slug'],
            $font['font_file'],
            $font['font_class'],
            $font['category'],
            $font['preview_text'],
            intval($font['file_size']),
            $upload_dir['baseurl'] . '/font-upload/' . $font['font_file']
        );
    }
    return ["success"=>true,"total"=>count($fonts)];
}

function fm_generate_detail_page($id,$font_name,$font_slug,$font_file,$font_class,$category,$preview_text,$file_size,$font_url){
    $slug_dir = ABSPATH."font/".$font_slug;
    if(!is_dir($slug_dir)) mkdir($slug_dir, 0755, true);
    $file_size_mb = number_format($file_size/1024/1024, 1);
    $ext = strtoupper(pathinfo($font_file, PATHINFO_EXTENSION));
    $default_preview = $preview_text ?: "字体预览";
    $css = '<style>
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
    .preview-chars{font-size:48px;letter-spacing:8px;margin-top:20px;color:#ccc}
    .preview-label{color:#666;font-size:13px;margin-bottom:16px}
    .preview-input-wrap{position:relative;max-width:600px;margin:0 auto}
    .preview-hint{color:#666;font-size:12px;text-align:center;margin-bottom:8px}.preview-input{width:100%;padding:14px 20px;background:#252525;border:1px solid #4a4a4a;border-radius:12px;color:#fff;font-size:16px;text-align:center;outline:none;transition:border-color .2s}
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
    </style>';
    $download_url = fm_get_protected_download_url($font_slug);
    $preview_html = '<div class="preview-section"><div class="preview-label">字体预览</div><div class="preview-box"><div class="preview-main '.$font_class.'" id="previewText"'.fm_font_inline_style($font_class).'>'.$default_preview.'</div></div><div class="preview-input-wrap"><div class="preview-hint">可自由输入文字预览效果</div><input type="text" class="preview-input" id="previewInput" placeholder="输入任意文字预览效果…" value="'.$default_preview.'"></div></div>';
    $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>'.$font_name.' - 免费字体下载</title>'.$css.'<link rel="stylesheet" href="/wp-content/uploads/font-upload/fonts.css?v=3"></head><body><header class="header"><a href="/font-index.html" class="logo"><span class="logo-icon">←</span> 免费字体</a><div class="auth-bar" id="authBar"><a href="/login.html" class="auth-link">登录</a><span style="color:#555">|</span><a href="/register.html" class="auth-link">注册</a></div></header><div class="container"><div class="font-detail"><div class="font-header"><div class="font-title">'.$font_name.'</div><div class="font-meta"><span class="tag">'.$category.'</span><span class="tag">'.$ext.'</span><span class="tag">'.$file_size_mb.' MB</span></div></div>'.$preview_html.'<div class="font-info-grid"><div class="info-card"><div class="info-label">字体名称</div><div class="info-value">'.$font_name.'</div></div><div class="info-card"><div class="info-label">字体格式</div><div class="info-value">'.$ext.'</div></div><div class="info-card"><div class="info-label">文件大小</div><div class="info-value">'.$file_size_mb.' MB</div></div><div class="info-card"><div class="info-label">字体分类</div><div class="info-value">'.$category.'</div></div></div><div class="download-section"><button class="download-btn" id="downloadBtn" data-url="'.$download_url.'">↓ 下载字体文件</button></div></div></div><div id="loginModal" class="login-modal"><div class="login-modal-content"><button class="close-modal" onclick="closeLoginModal()">×</button><h3>请先登录</h3><p>登录后才能下载字体文件</p><a href="/login.html" class="login-modal-btn">立即登录</a></div></div><script src="/checkauth.js"></script><script>document.getElementById("previewInput").addEventListener("input",function(){var t=this.value||this.getAttribute("placeholder");document.getElementById("previewText").textContent=t});</script></body></html>';
    file_put_contents($slug_dir."/index.html", $html);
    return true;
}

add_action("wp_ajax_fm_regen_homepage", function(){
    header("Content-Type: application/json");
    if(!current_user_can("manage_options")){echo json_encode(["success"=>false,"message"=>"需要管理员权限"]);wp_die();}
    $result = fm_regenerate_homepage();
    echo json_encode($result);
    wp_die();
});


add_action("wp_ajax_fm_regen_detail_pages", function(){
    header("Content-Type: application/json");
    if(!current_user_can("manage_options")){echo json_encode(["success"=>false,"message"=>"需要管理员权限"]);wp_die();}
    $result = fm_regenerate_detail_pages();
    echo json_encode($result);
    wp_die();
});

add_action("wp_ajax_fm_delete_font", function(){
    header("Content-Type: application/json");
    if(!current_user_can("manage_options")){echo json_encode(["success"=>false]);wp_die();}
    $id = intval($_POST["id"]);
    $slug = sanitize_text_field($_POST["slug"]);
    global $wpdb;
    $table = $wpdb->prefix."font_manager";
    $font = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id), ARRAY_A);
    if($font){
        $upload_dir = wp_upload_dir();
        $font_file = $upload_dir["basedir"]."/font-upload/".$font["font_file"];
        if(file_exists($font_file)) @unlink($font_file);
        $slug_dir = ABSPATH."font/".$slug;
        if(is_dir($slug_dir)){@unlink($slug_dir."/index.html");@rmdir($slug_dir);}
        $wpdb->delete($table,["id"=>$id]);
    }
    echo json_encode(["success"=>true]);
    wp_die();
});
