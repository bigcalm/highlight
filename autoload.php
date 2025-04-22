<?php
// autoload.php

spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'Tempest\\Highlight\\';

    // Base directory for the namespace prefix
    $base_dir = __DIR__ . "/src/";

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // No, move to the next registered autoloader
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace namespace separators with directory separators, append with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});
