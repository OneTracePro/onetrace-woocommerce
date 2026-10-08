<?php

/**
 * Builds dist/onetrace-woocommerce.zip for WordPress: production files only, the onetrace-php library inside the
 * plugin under its own namespace (OneTrace\WooCommerce\Vendor\OneTrace) so another plugin with a different
 * version of the library cannot conflict with it, and a small autoloader instead of Composer's.
 *
 *     composer install && php bin/build.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$build = $root . '/build/onetrace-woocommerce';
$library = $root . '/vendor/onetracepro/onetrace-php';
$prefix = 'OneTrace\\WooCommerce\\Vendor\\';

if (!is_dir($library . '/src')) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(1);
}

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    } elseif (file_exists($path)) {
        unlink($path);
    }
};

$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_dir($from)) {
        @mkdir($to, 0777, true);

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    } else {
        copy($from, $to);
    }
};

// Library references in PHP code: "namespace OneTrace…", "use OneTrace\…", "\OneTrace\…" — but not the plugin's own namespace.
$scope = static function (string $code) use ($prefix): string {
    $code = (string) preg_replace('/\bnamespace OneTrace(?=[\\\;])(?!\\\\WooCommerce\b)/', 'namespace ' . $prefix . 'OneTrace', $code);

    return (string) preg_replace('/(?<![\\\\\w])(\\\\?)OneTrace\\\\(?!WooCommerce\b)/', '$1' . str_replace('\\', '\\\\', $prefix) . 'OneTrace\\\\', $code);
};

$remove($root . '/build');
@mkdir($build . '/vendor/onetrace-php', 0777, true);

foreach (['onetrace-woocommerce.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'src', 'languages'] as $entry) {
    $copy($root . '/' . $entry, $build . '/' . $entry);
}

$copy($library . '/src', $build . '/vendor/onetrace-php/src');
$copy($library . '/LICENSE', $build . '/vendor/onetrace-php/LICENSE');

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($build, FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        file_put_contents($file->getPathname(), $scope((string) file_get_contents($file->getPathname())));
    }
}

file_put_contents($build . '/vendor/autoload.php', <<<'PHP'
<?php

// Autoloader of the plugin build: the plugin classes and the bundled onetrace-php library.
spl_autoload_register(static function (string $class): void {
    $map = [
        'OneTrace\\WooCommerce\\Vendor\\OneTrace\\' => __DIR__ . '/onetrace-php/src/',
        'OneTrace\\WooCommerce\\' => dirname(__DIR__) . '/src/',
    ];

    foreach ($map as $prefix => $directory) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

PHP);

foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        exec('php -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);

        if ($status !== 0) {
            fwrite(STDERR, implode("\n", $output) . "\n");
            exit(1);
        }
    }
}

@mkdir($root . '/dist');
$zipPath = $root . '/dist/onetrace-woocommerce.zip';
@unlink($zipPath);
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($build, FilesystemIterator::SKIP_DOTS)) as $file) {
    $zip->addFile($file->getPathname(), 'onetrace-woocommerce/' . substr($file->getPathname(), strlen($build) + 1));
}

$zip->close();
echo "dist/onetrace-woocommerce.zip\n";
