# 字体网站 - WordPress 插件与主题

## 项目结构

```
├── plugins/
│   └── font-manager/          # 字体管理插件（后台、AJAX、详情页生成）
├── themes/
│   └── font-auth/             # 认证主题（登录/注册 REST API）
├── scripts/
│   └── do-import-fixed.php    # 字体批量导入脚本
├── font-index.html            # 首页（静态 HTML，带分类导航）
├── font-pages/                # 详情页模板示例
└── fonts.css                  # @font-face 声明（由 gen_fonts_css.php 自动生成）
```

## 部署步骤

### 1. 上传到 WordPress

- `plugins/font-manager/` → `wp-content/plugins/font-manager/`
- `themes/font-auth/` → `wp-content/themes/font-auth/`
- `font-index.html` → 网站根目录
- `font/` 目录（146个详情页）→ 网站根目录
- `fonts.css` → `wp-content/uploads/font-upload/fonts.css`

### 2. 数据库

```sql
CREATE TABLE wp_font_manager (
  id INT AUTO_INCREMENT PRIMARY KEY,
  font_name VARCHAR(255),
  font_slug VARCHAR(255) UNIQUE,
  font_file VARCHAR(255),
  font_class VARCHAR(255),
  category VARCHAR(100),
  preview_text VARCHAR(255),
  file_size BIGINT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 3. 生成 fonts.css

```bash
php scripts/gen_fonts_css.php
```

### 4. 字体文件

字体文件（`.ttf`、`.otf`）放在：
```
wp-content/uploads/font-upload/
```

## 主要功能

- **后台管理**：/wp-admin/admin.php?page=font-manager
- **上传字体**：自动写入DB + 生成详情页 + 更新首页
- **分类导航**：首页支持按分类筛选（JS过滤）
- **详情页**：每个字体独立的预览页面，支持自定义文字预览
- **下载保护**：未登录用户无法下载字体文件

## API 端点

- `POST /wp-json/font-auth/v1/login` - 登录
- `POST /wp-json/font-auth/v1/register` - 注册
- `GET /wp-json/font-auth/v1/me` - 验证 Token
