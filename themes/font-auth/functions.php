<?php
/**
 * Font Auth theme functions
 */

if (!function_exists('font_auth_get_request_token')) {
    function font_auth_get_request_token() {
        $token = '';

        if (!empty($_GET['token'])) {
            $token = sanitize_text_field(wp_unslash($_GET['token']));
        }

        if (empty($token) && !empty($_SERVER['HTTP_X_FONT_AUTH_TOKEN'])) {
            $token = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FONT_AUTH_TOKEN']));
        }

        if (empty($token) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = trim((string) $_SERVER['HTTP_AUTHORIZATION']);
            if (stripos($auth, 'Bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        }

        return $token;
    }
}

if (!function_exists('font_auth_validate_token')) {
    function font_auth_validate_token($token) {
        if (empty($token)) {
            return false;
        }

        $parts = explode('|', (string) $token);
        if (count($parts) !== 3) {
            return false;
        }

        $user_id = intval($parts[0]);
        $expiry  = intval($parts[1]);

        if ($user_id <= 0 || $expiry < time()) {
            return false;
        }

        $stored = get_user_meta($user_id, 'font_auth_token', true);
        if (!$stored || !hash_equals((string) $stored, (string) $token)) {
            return false;
        }

        $user = get_user_by('id', $user_id);
        return $user instanceof WP_User ? $user : false;
    }
}

if (!function_exists('font_auth_get_current_user_from_request')) {
    function font_auth_get_current_user_from_request() {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($user instanceof WP_User && $user->exists()) {
                return $user;
            }
        }

        $token = font_auth_get_request_token();
        if (!empty($token)) {
            return font_auth_validate_token($token);
        }

        return false;
    }
}

if (!function_exists('font_auth_issue_token')) {
    function font_auth_issue_token($user_id) {
        $token = $user_id . '|' . (time() + 86400 * 30) . '|' . bin2hex(random_bytes(16));
        update_user_meta($user_id, 'font_auth_token', $token);
        return $token;
    }
}

if (!function_exists('font_auth_user_payload')) {
    function font_auth_user_payload(WP_User $user, $token = '') {
        return [
            'success'   => true,
            'logged_in' => true,
            'token'     => $token,
            'user'      => [
                'id'       => $user->ID,
                'username' => $user->user_login,
                'email'    => $user->user_email,
            ],
        ];
    }
}

if (!function_exists('font_auth_find_user_by_login_input')) {
    function font_auth_find_user_by_login_input($input) {
        $input = trim((string) $input);
        if ($input === '') {
            return false;
        }

        if (is_email($input)) {
            $user = get_user_by('email', $input);
            if ($user instanceof WP_User) {
                return $user;
            }
        }

        $user = get_user_by('login', $input);
        return $user instanceof WP_User ? $user : false;
    }
}

if (!function_exists('font_auth_mail_from_name')) {
    function font_auth_mail_from_name($name) {
        $settings = font_auth_get_mail_settings();
        if (!empty($settings['from_name'])) {
            return $settings['from_name'];
        }
        return get_bloginfo('name') ?: 'Font Gallery';
    }
}
add_filter('wp_mail_from_name', 'font_auth_mail_from_name');


if (!function_exists('font_auth_mail_settings_defaults')) {
    function font_auth_mail_settings_defaults() {
        return [
            'smtp_enabled' => 0,
            'from_email'   => get_option('admin_email'),
            'from_name'    => get_bloginfo('name') ?: 'Font Gallery',
            'host'         => '',
            'port'         => 587,
            'secure'       => 'tls',
            'smtp_auth'    => 1,
            'username'     => '',
            'password'     => '',
        ];
    }
}

if (!function_exists('font_auth_get_mail_settings')) {
    function font_auth_get_mail_settings() {
        $defaults = font_auth_mail_settings_defaults();
        $saved = get_option('font_auth_mail_settings', []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $settings = wp_parse_args($saved, $defaults);
        $settings['smtp_enabled'] = !empty($settings['smtp_enabled']) ? 1 : 0;
        $settings['smtp_auth'] = !empty($settings['smtp_auth']) ? 1 : 0;
        $settings['from_email'] = sanitize_email($settings['from_email']);
        $settings['from_name'] = sanitize_text_field((string) $settings['from_name']);
        $settings['host'] = sanitize_text_field((string) $settings['host']);
        $settings['port'] = max(1, intval($settings['port']));
        $settings['secure'] = in_array($settings['secure'], ['', 'tls', 'ssl'], true) ? $settings['secure'] : 'tls';
        $settings['username'] = sanitize_text_field((string) $settings['username']);
        $settings['password'] = (string) $settings['password'];
        return $settings;
    }
}

if (!function_exists('font_auth_mail_from')) {
    function font_auth_mail_from($email) {
        $settings = font_auth_get_mail_settings();
        if (!empty($settings['from_email']) && is_email($settings['from_email'])) {
            return $settings['from_email'];
        }
        return $email;
    }
}
add_filter('wp_mail_from', 'font_auth_mail_from');

if (!function_exists('font_auth_configure_phpmailer')) {
    function font_auth_configure_phpmailer($phpmailer) {
        $settings = font_auth_get_mail_settings();
        if (empty($settings['smtp_enabled']) || empty($settings['host'])) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $settings['host'];
        $phpmailer->Port = max(1, intval($settings['port']));
        $phpmailer->SMTPAuth = !empty($settings['smtp_auth']);
        $phpmailer->Username = (string) $settings['username'];
        $phpmailer->Password = (string) $settings['password'];
        $phpmailer->SMTPSecure = (string) $settings['secure'];
        $phpmailer->CharSet = 'UTF-8';
        $phpmailer->Encoding = 'base64';

        if (!empty($settings['from_email']) && is_email($settings['from_email'])) {
            $from_name = !empty($settings['from_name']) ? $settings['from_name'] : (get_bloginfo('name') ?: 'Font Gallery');
            try {
                $phpmailer->setFrom($settings['from_email'], $from_name, false);
            } catch (Exception $e) {
                // Ignore and let wp_mail handle errors later.
            }
        }
    }
}
add_action('phpmailer_init', 'font_auth_configure_phpmailer');


if (!function_exists('font_auth_register_code_key')) {
    function font_auth_register_code_key($email) {
        return 'font_auth_reg_code_' . md5(strtolower(trim((string) $email)));
    }
}

if (!function_exists('font_auth_register_rate_key')) {
    function font_auth_register_rate_key($email) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        return 'font_auth_reg_rate_' . md5($ip . '|' . strtolower(trim((string) $email)));
    }
}

if (!function_exists('font_auth_store_email_code')) {
    function font_auth_store_email_code($email, $code) {
        $payload = [
            'email'      => strtolower(trim((string) $email)),
            'code'       => (string) $code,
            'created_at' => time(),
            'expires_at' => time() + 600,
        ];
        set_transient(font_auth_register_code_key($email), $payload, 600);
    }
}

if (!function_exists('font_auth_verify_email_code')) {
    function font_auth_verify_email_code($email, $code) {
        $saved = get_transient(font_auth_register_code_key($email));
        if (!is_array($saved) || empty($saved['code']) || empty($saved['expires_at'])) {
            return false;
        }

        if ((int) $saved['expires_at'] < time()) {
            delete_transient(font_auth_register_code_key($email));
            return false;
        }

        return hash_equals((string) $saved['code'], trim((string) $code));
    }
}

if (!function_exists('font_auth_clear_email_code')) {
    function font_auth_clear_email_code($email) {
        delete_transient(font_auth_register_code_key($email));
    }
}

if (!function_exists('font_auth_send_register_code')) {
    function font_auth_send_register_code($email) {
        $email = sanitize_email($email);

        if (empty($email) || !is_email($email)) {
            return new WP_Error('invalid_email', '邮箱格式不正确', ['status' => 400]);
        }

        if (email_exists($email)) {
            return new WP_Error('email_exists', '邮箱已被注册', ['status' => 400]);
        }

        $rate_key = font_auth_register_rate_key($email);
        if (get_transient($rate_key)) {
            return new WP_Error('too_many_requests', '验证码发送过于频繁，请 60 秒后重试', ['status' => 429]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        font_auth_store_email_code($email, $code);
        set_transient($rate_key, 1, 60);

        $site_name = get_bloginfo('name') ?: 'Font Gallery';
        $subject = '【' . $site_name . '】注册验证码';
        $message = "您正在注册 {$site_name} 账号。\n\n验证码：{$code}\n有效期：10 分钟\n\n如果这不是您的操作，请忽略此邮件。";

        $sent = wp_mail($email, $subject, $message);
        if (!$sent) {
            font_auth_clear_email_code($email);
            return new WP_Error('mail_failed', '验证码发送失败，请先检查 WordPress 邮件配置', ['status' => 500]);
        }

        return [
            'success' => true,
            'message' => '验证码已发送，请查收邮箱',
            'expires_in' => 600,
        ];
    }
}

add_action('rest_api_init', function() {

    register_rest_route('font-auth/v1', '/login', [
        'methods'  => 'POST',
        'callback' => function($request) {
            $login_input = sanitize_text_field($request->get_param('username'));
            if ($login_input === '') {
                $login_input = sanitize_text_field($request->get_param('login'));
            }
            if ($login_input === '') {
                $login_input = sanitize_text_field($request->get_param('email'));
            }
            $password = (string) $request->get_param('password');

            if ($login_input === '' || $password === '') {
                return new WP_Error('missing_fields', '用户名/邮箱和密码不能为空', ['status' => 400]);
            }

            $user_obj = font_auth_find_user_by_login_input($login_input);
            if (!$user_obj) {
                return new WP_Error('invalid_login', '用户名、邮箱或密码错误', ['status' => 401]);
            }

            $user = wp_authenticate($user_obj->user_login, $password);
            if (is_wp_error($user)) {
                return new WP_Error('invalid_login', '用户名、邮箱或密码错误', ['status' => 401]);
            }

            wp_clear_auth_cookie();
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true, is_ssl());
            do_action('wp_login', $user->user_login, $user);

            $token = font_auth_issue_token($user->ID);
            return font_auth_user_payload($user, $token);
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('font-auth/v1', '/send-code', [
        'methods'  => 'POST',
        'callback' => function($request) {
            $email = sanitize_email($request->get_param('email'));
            return font_auth_send_register_code($email);
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('font-auth/v1', '/register', [
        'methods'  => 'POST',
        'callback' => function($request) {
            $username = sanitize_user($request->get_param('username'), true);
            $email    = sanitize_email($request->get_param('email'));
            $password = (string) $request->get_param('password');
            $code     = sanitize_text_field($request->get_param('code'));

            if ($username === '' || $email === '' || $password === '' || $code === '') {
                return new WP_Error('missing_fields', '用户名、邮箱、密码和验证码都不能为空', ['status' => 400]);
            }

            if (!validate_username($username)) {
                return new WP_Error('invalid_username', '用户名包含非法字符', ['status' => 400]);
            }

            if (mb_strlen($username) < 3) {
                return new WP_Error('short_username', '用户名至少 3 位', ['status' => 400]);
            }

            if (username_exists($username)) {
                return new WP_Error('username_exists', '用户名已存在', ['status' => 400]);
            }

            if (!is_email($email)) {
                return new WP_Error('invalid_email', '邮箱格式不正确', ['status' => 400]);
            }

            if (email_exists($email)) {
                return new WP_Error('email_exists', '邮箱已被注册', ['status' => 400]);
            }

            if (strlen($password) < 6) {
                return new WP_Error('weak_password', '密码至少 6 位', ['status' => 400]);
            }

            if (!font_auth_verify_email_code($email, $code)) {
                return new WP_Error('invalid_code', '验证码错误或已过期，请重新获取', ['status' => 400]);
            }

            $user_id = wp_create_user($username, $password, $email);
            if (is_wp_error($user_id)) {
                return $user_id;
            }

            font_auth_clear_email_code($email);

            wp_clear_auth_cookie();
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true, is_ssl());

            $user = get_user_by('id', $user_id);
            $token = font_auth_issue_token($user_id);

            return font_auth_user_payload($user, $token);
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('font-auth/v1', '/me', [
        'methods'  => 'GET',
        'callback' => function() {
            $user = font_auth_get_current_user_from_request();
            if (!$user) {
                return ['success' => true, 'logged_in' => false, 'reason' => 'unauthenticated'];
            }

            $token = get_user_meta($user->ID, 'font_auth_token', true);
            if (!$token) {
                $token = font_auth_issue_token($user->ID);
            }

            return font_auth_user_payload($user, $token);
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('font-auth/v1', '/logout', [
        'methods'  => 'POST',
        'callback' => function() {
            $user = font_auth_get_current_user_from_request();
            if ($user instanceof WP_User) {
                delete_user_meta($user->ID, 'font_auth_token');
            }
            wp_logout();
            return ['success' => true, 'logged_in' => false];
        },
        'permission_callback' => '__return_true',
    ]);


    register_rest_route("font-prompts/v1", "/list", [
        "methods" => "GET",
        "callback" => function($request){
            global $wpdb;
            $table = $wpdb->prefix . "font_prompts";
            $per_page = max(1, intval($request->get_param("per_page") ?: 6));
            $page = max(1, intval($request->get_param("page") ?: 1));
            $offset = ($page - 1) * $per_page;
            $category = sanitize_text_field($request->get_param("category") ?: "");
            $search = sanitize_text_field($request->get_param("search") ?: "");

            $where = "1=1";
            if($category && $category !== "全部") $where .= $wpdb->prepare(" AND category=%s", $category);
            if($search) $where .= $wpdb->prepare(" AND prompt LIKE %s", "%" . $wpdb->esc_like($search) . "%");

            $total = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where");
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ), ARRAY_A);

            return [
                "total" => intval($total),
                "page" => $page,
                "per_page" => $per_page,
                "has_more" => (($offset + count($rows)) < $total),
                "items" => $rows
            ];
        },
        "permission_callback" => "__return_true"
    ]);

});

add_action('after_switch_theme', function() {
    flush_rewrite_rules();
});
