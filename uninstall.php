<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$pluginPath = __DIR__;
$autoload = $pluginPath . '/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(
        static function (string $class) use ($pluginPath): void {
            $prefix = 'SafeMigrate\\';

            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = $pluginPath . '/src/' . str_replace('\\', '/', $relative) . '.php';

            if (file_exists($path)) {
                require_once $path;
            }
        }
    );
}

global $wpdb;

if ($wpdb instanceof wpdb) {
    SafeMigrate\Support\PluginCleanup::uninstall($wpdb);
}
