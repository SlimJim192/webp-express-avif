<?php

namespace WebPExpress;

final class AvifConversionLogger
{
    public static function log($source, $destination, $success, $enabled, $webPExpressContentDir)
    {
        if (!$enabled ||
            !isset($_SERVER['DOCUMENT_ROOT']) ||
            ($_SERVER['DOCUMENT_ROOT'] == '') ||
            !class_exists('\WebPExpress\ConvertHelperIndependent')) {
            return;
        }

        $logRoot = rtrim($webPExpressContentDir, '/\\') . '/log';
        $logFile = \WebPExpress\ConvertHelperIndependent::getLogFilename($source, $logRoot);
        if (!is_string($logFile)) {
            error_log('[WebP Express AVIF] Could not determine the conversion log file for ' . $source);
            return;
        }

        $logDirectory = dirname($logFile);
        if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
            error_log('[WebP Express AVIF] Could not create conversion log directory: ' . $logDirectory);
            return;
        }

        $denyFile = rtrim($logRoot, '/\\') . '/.htaccess';
        if (!is_file($denyFile)) {
            $denyRules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
            if (file_put_contents($denyFile, $denyRules, LOCK_EX) === false) {
                error_log('[WebP Express AVIF] Could not protect conversion logs in ' . $logRoot);
            }
        }

        $entry = "\n\nAVIF conversion " . ($success ? 'succeeded' : 'failed') .
            " at " . date('Y-m-d H:i:s') . ".\nDestination: " . basename($destination) . "\n";
        if (file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX) === false) {
            error_log('[WebP Express AVIF] Could not write conversion log: ' . $logFile);
        }
    }
}
