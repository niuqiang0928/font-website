<?php
require_once('/www/wwwroot/wordpress/wp-load.php');
$stored = get_user_meta(8, 'font_auth_token', true);
echo "stored=[$stored]
";
