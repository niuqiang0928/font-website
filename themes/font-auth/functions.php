<?php
/**
 * Font Auth theme functions
 */

if (!function_exists('font_auth_get_token_from_request')) {
    function font_auth_get_token_from_request() {
        $token = '';

        if (!empty($_GET['token'])) {
            $token = sanitize_text_field(wp_unslash($_GET['token']));
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

if (!function_exists('font_auth_validate_token')) {
    function font_auth_validate_token($token) {
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

if (!function_exists('font_auth_get_current_user_from_request')) {
    function font_auth_get_current_user_from_request() {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($user instanceof WP_User && $user->exists()) {
                return $user;
            }
        }

        $token = font_auth_get_token_from_request();
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

add_action('rest_api_init', function() {

    register_rest_route('font-auth/v1', '/login', [
        'methods'  => 'POST',
        'callback' => function($request) {
            $username = sanitize_text_field($request->get_param('username'));
            $password = $request->get_param('password');

            if (empty($username) || empty($password)) {
                return new WP_Error('missing_fields', '用户名和密码不能为空', ['status' => 400]);
            }

            $user = wp_authenticate($username, $password);
            if (is_wp_error($user)) {
                return new WP_Error('invalid_login', '用户名或密码错误', ['status' => 401]);
            }

            wp_set_auth_cookie($user->ID, true);
            $token = font_auth_issue_token($user->ID);

            return [
                'success' => true,
                'token'   => $token,
                'user'    => [
                    'id'       => $user->ID,
                    'username' => $user->user_login,
                    'email'    => $user->user_email,
                ]
            ];
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('font-auth/v1', '/register', [
        'methods'  => 'POST',
        'callback' => function($request) {
            $username = sanitize_text_field($request->get_param('username'));
            $email    = sanitize_email($request->get_param('email'));
            $password = $request->get_param('password');

            if (empty($username) || empty($email) || empty($password)) {
                return new WP_Error('missing_fields', '所有字段都不能为空', ['status' => 400]);
            }

            if (!validate_username($username)) {
                return new WP_Error('invalid_username', '用户名包含非法字符', ['status' => 400]);
            }

            if (username_exists($username)) {
                return new WP_Error('username_exists', '用户名已存在', ['status' => 400]);
            }

            if (email_exists($email)) {
                return new WP_Error('email_exists', '邮箱已被注册', ['status' => 400]);
            }

            if (!is_email($email)) {
                return new WP_Error('invalid_email', '邮箱格式不正确', ['status' => 400]);
            }

            if (strlen($password) < 6) {
                return new WP_Error('weak_password', '密码至少6位', ['status' => 400]);
            }

            $user_id = wp_create_user($username, $password, $email);
            if (is_wp_error($user_id)) {
                return $user_id;
            }

            wp_set_auth_cookie($user_id, true);
            $token = font_auth_issue_token($user_id);
            $user  = get_user_by('id', $user_id);

            return [
                'success' => true,
                'token'   => $token,
                'user'    => [
                    'id'       => $user->ID,
                    'username' => $user->user_login,
                    'email'    => $user->user_email,
                ]
            ];
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('font-auth/v1', '/me', [
        'methods'  => 'GET',
        'callback' => function() {
            $user = font_auth_get_current_user_from_request();
            if (!$user) {
                return ['logged_in' => false, 'reason' => 'unauthenticated'];
            }

            $token = get_user_meta($user->ID, 'font_auth_token', true);
            return font_auth_user_payload($user, $token ?: '');
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
            return ['success' => true];
        },
        'permission_callback' => '__return_true',
    ]);

});

add_action('after_switch_theme', function() {
    flush_rewrite_rules();
});
