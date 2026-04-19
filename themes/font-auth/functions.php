<?php
/**
 * Font Auth theme functions
 */

// Register REST API endpoints for login/register
add_action('rest_api_init', function() {

    // Login endpoint
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

            // Set auth cookie
            wp_set_auth_cookie($user->ID, true);

            // Generate a simple token: user_id|expiry|random
            $token = $user->ID . '|' . (time() + 86400 * 30) . '|' . bin2hex(random_bytes(16));
            update_user_meta($user->ID, 'font_auth_token', $token);

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

    // Register endpoint
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

            // Auto login after register
            wp_set_auth_cookie($user_id, true);

            // Generate token
            $token = $user_id . '|' . (time() + 86400 * 30) . '|' . bin2hex(random_bytes(16));
            update_user_meta($user_id, 'font_auth_token', $token);

            $user = get_user_by('id', $user_id);
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

    // Auth check endpoint
    register_rest_route('font-auth/v1', '/me', [
        'methods'  => 'GET',
        'callback' => function() {
            $user = null;

            // 1) Prefer Bearer token from localStorage-based login
            $token = '';
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $auth = trim($_SERVER['HTTP_AUTHORIZATION']);
                if (strpos($auth, 'Bearer ') === 0) {
                    $token = substr($auth, 7);
                }
            }

            if (!empty($token)) {
                $parts = explode('|', $token);
                if (count($parts) === 3) {
                    $user_id = intval($parts[0]);
                    $expiry = intval($parts[1]);
                    $stored = get_user_meta($user_id, 'font_auth_token', true);
                    if ($expiry >= time() && $stored && hash_equals($stored, $token)) {
                        $user = get_user_by('id', $user_id);
                    }
                }
            }

            // 2) Fallback to normal WordPress auth cookie
            if (!$user && is_user_logged_in()) {
                $user = wp_get_current_user();
            }

            if (!$user || empty($user->ID)) {
                return ['logged_in' => false];
            }

            return [
                'logged_in' => true,
                'user' => [
                    'id' => $user->ID,
                    'username' => $user->user_login,
                    'email' => $user->user_email,
                ]
            ];
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('font-auth/v1', '/logout', [
        'methods'  => 'POST',
        'callback' => function() {
            wp_logout();
            return ['success' => true];
        },
        'permission_callback' => '__return_true',
    ]);

});

// Flush rewrite rules on theme activation
add_action('after_switch_theme', function() {
    flush_rewrite_rules();
});
