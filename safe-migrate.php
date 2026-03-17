<?php
/**
 * Plugin Name: Safe Migrate
 * Plugin URI: https://kamilskicki.com/
 * Description: Reliable WordPress migrations and restores with preflight diagnostics, checkpoints, and readable recovery workflows.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: Kamil Skicki
 * Author URI: https://kamilskicki.com/
 * Text Domain: safe-migrate
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('SAFE_MIGRATE_VERSION', '1.0.0');
define('SAFE_MIGRATE_FILE', __FILE__);
define('SAFE_MIGRATE_PATH', plugin_dir_path(__FILE__));
define('SAFE_MIGRATE_URL', plugin_dir_url(__FILE__));

$autoload = SAFE_MIGRATE_PATH . 'vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(
        static function (string $class): void {
            $prefix = 'SafeMigrate\\';

            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = SAFE_MIGRATE_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';

            if (file_exists($path)) {
                require_once $path;
            }
        }
    );
}

register_activation_hook(
    SAFE_MIGRATE_FILE,
    static function (): void {
        SafeMigrate\Plugin::instance()->activate();
    }
);

register_deactivation_hook(
    SAFE_MIGRATE_FILE,
    static function (): void {
        SafeMigrate\Plugin::instance()->deactivate();
    }
);

add_action(
    'init',
    static function (): void {
        load_plugin_textdomain(
            'safe-migrate',
            false,
            dirname(plugin_basename(SAFE_MIGRATE_FILE)) . '/languages'
        );

        SafeMigrate\Plugin::instance()->boot();
    }
);
