<?php
namespace MediaUsageTracker\Core;

class Autoloader {

    public function register() {
        spl_autoload_register( array( $this, 'autoload' ), true, true );
    }

    private function autoload( $class ) {
        if ( strpos( $class, 'MediaUsageTracker\\' ) !== 0 ) {
            return;
        }

        // Remove namespace prefix
        $class_path = str_replace( 'MediaUsageTracker\\', '', $class );
        
        // Convert namespace to path: lowercase with hyphens for file name
        $file_name = strtolower( str_replace( '\\', '-', $class_path ) );
        
        $file_path = MUT_PLUGIN_DIR . 'includes/class-' . $file_name . '.php';

        if ( file_exists( $file_path ) ) {
            require_once $file_path;
            return;
        }

        // Fallback: try the exact class filename pattern
        $parts = explode( '\\', $class_path );
        $class_name = end( $parts );
        $fallback_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
        $file_path2 = MUT_PLUGIN_DIR . 'includes/' . $fallback_name;
        
        if ( file_exists( $file_path2 ) ) {
            require_once $file_path2;
            return;
        }

        // Alternative direct path
        $alt_file = MUT_PLUGIN_DIR . 'includes/' . strtolower( str_replace( '\\', '/', $class_path ) ) . '.php';
        if ( file_exists( $alt_file ) ) {
            require_once $alt_file;
        }
    }
}

// Register autoloader immediately
$autoloader = new Autoloader();
$autoloader->register();
