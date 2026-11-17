<?php
/**
 * Plugin Name: WebP Express AVIF
 * Plugin URI: https://github.com/SlimJim192/webp-express-avif
 * Description: Adds on-demand AVIF sources to WebP Express picture tags, using its image and cache settings.
 * Version: 1.0.1
 * Author: Ncomp@ss
 * GitHub Plugin URI: https://github.com/SlimJim192/webp-express-avif
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/avif-conversion-logger.php';

final class WebPExpressAvif
{
    private static $config;
    private static $purgeResult;

    public static function bootstrap()
    {
        if (!class_exists('\WebPExpress\Config')) {
            add_action('admin_notices', array(__CLASS__, 'missingWebPExpressNotice'));
            return;
        }

        add_action('init', array(__CLASS__, 'startOutputBuffer'), 0);
        add_action('template_redirect', array(__CLASS__, 'serveAvifRequest'), 0);
        add_action('admin_menu', array(__CLASS__, 'addSettingsPage'));
        add_action('admin_init', array(__CLASS__, 'registerSettings'));
        add_action('admin_init', array(__CLASS__, 'syncRewriteRules'));
        add_filter('wp_redirect', array(__CLASS__, 'syncRewriteRulesBeforeSettingsRedirect'), 10, 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueuePurgeScript'), 1000);
        add_filter('wp_handle_upload', array(__CLASS__, 'handleUpload'), 20, 2);
        add_filter('image_make_intermediate_size', array(__CLASS__, 'handleIntermediateSize'), 20, 1);
        add_filter('wp_delete_file', array(__CLASS__, 'deleteDerivative'), 20, 2);
        add_action('wp_ajax_webpexpress_purge_cache', array(__CLASS__, 'purgeAvifCache'), 1);
        add_action('wp_ajax_convert_file', array(__CLASS__, 'convertAvifDuringBulkConvert'), 1);
    }

    public static function missingWebPExpressNotice()
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>WebP Express AVIF requires WebP Express to be installed and active.</p></div>';
    }

    private static function getConfig()
    {
        if (!isset(self::$config)) {
            self::$config = \WebPExpress\Config::loadConfigAndFix(false);
        }
        return self::$config;
    }

    private static function getAvifQualitySettings()
    {
        $settings = get_option('webpexpress_avif_settings', array());
        if (!is_array($settings)) {
            $settings = array();
        }
        return array(
            'jpeg_quality' => isset($settings['jpeg_quality']) ? max(0, min(100, intval($settings['jpeg_quality']))) : 65,
            'png_quality' => isset($settings['png_quality']) ? max(0, min(100, intval($settings['png_quality']))) : 70
        );
    }

    public static function registerSettings()
    {
        register_setting(
            'webpexpress_avif',
            'webpexpress_avif_settings',
            array(
                'type' => 'array',
                'sanitize_callback' => array(__CLASS__, 'sanitizeSettings'),
                'default' => array('jpeg_quality' => 65, 'png_quality' => 70)
            )
        );

        add_settings_section(
            'webpexpress_avif_quality',
            'AVIF quality',
            array(__CLASS__, 'renderQualitySection'),
            'webpexpress-avif'
        );
        add_settings_field(
            'webpexpress_avif_jpeg_quality',
            'JPEG source quality',
            array(__CLASS__, 'renderJpegQualityField'),
            'webpexpress-avif',
            'webpexpress_avif_quality'
        );
        add_settings_field(
            'webpexpress_avif_png_quality',
            'PNG source quality',
            array(__CLASS__, 'renderPngQualityField'),
            'webpexpress-avif',
            'webpexpress_avif_quality'
        );
    }

    public static function sanitizeSettings($input)
    {
        if (!is_array($input)) {
            return self::getAvifQualitySettings();
        }

        $settings = array();
        foreach (array('jpeg_quality', 'png_quality') as $key) {
            if (!isset($input[$key]) || !is_numeric($input[$key])) {
                $settings[$key] = ($key == 'jpeg_quality') ? 65 : 70;
                continue;
            }
            $settings[$key] = max(0, min(100, intval($input[$key])));
        }
        return $settings;
    }

    public static function addSettingsPage()
    {
        add_options_page(
            'WebP Express AVIF',
            'WebP Express AVIF',
            'manage_options',
            'webpexpress-avif',
            array(__CLASS__, 'renderSettingsPage')
        );
    }

    public static function renderSettingsPage()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>WebP Express AVIF</h1>';
        echo '<p>These quality values are independent of WebP Express quality settings and apply to both on-demand and upload-time AVIF conversion. Lower values generally reduce file size. AVIF output from PNG sources may be lossy. After changing quality, use WebP Express\'s "Delete converted files" button to remove cached AVIFs so they are regenerated with the new values.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('webpexpress_avif');
        do_settings_sections('webpexpress-avif');
        submit_button();
        echo '</form></div>';
    }

    public static function renderQualitySection()
    {
        echo '<p>Choose an AVIF encoder quality from 0 to 100 for each source format. Defaults: JPEG 65 and PNG 70.</p>';
    }

    private static function renderQualityField($key)
    {
        $settings = self::getAvifQualitySettings();
        echo '<input type="number" min="0" max="100" step="1" class="small-text" name="webpexpress_avif_settings[' .
            esc_attr($key) . ']" value="' . esc_attr($settings[$key]) . '">';
    }

    public static function renderJpegQualityField()
    {
        self::renderQualityField('jpeg_quality');
    }

    public static function renderPngQualityField()
    {
        self::renderQualityField('png_quality');
    }

    public static function enqueuePurgeScript()
    {
        if (!current_user_can('manage_options') ||
            !isset($_GET['page']) ||
            ($_GET['page'] != 'webp_express_settings_page')) {
            return;
        }

        $script = plugin_dir_url(__FILE__) . 'purge-cache-avif.js';
        $scriptPath = __DIR__ . '/purge-cache-avif.js';
        wp_enqueue_script(
            'webpexpress-avif-purge-cache',
            $script,
            array('purgecache'),
            is_file($scriptPath) ? (string)filemtime($scriptPath) : '1.0.0',
            true
        );
    }

    private static function activeConverters($config)
    {
        if (!isset($config['converters']) || !is_array($config['converters'])) {
            return array();
        }

        $result = array();
        foreach ($config['converters'] as $converter) {
            if (!is_array($converter) ||
                !isset($converter['converter']) ||
                (isset($converter['deactivated']) && $converter['deactivated']) ||
                (isset($converter['working']) && !$converter['working'])) {
                continue;
            }
            if (!isset($converter['options']) || !is_array($converter['options'])) {
                $converter['options'] = array();
            }
            $result[] = $converter;
        }
        return $result;
    }

    private static function imagickSupportsAvif()
    {
        if (!class_exists('\Imagick') || !method_exists('\Imagick', 'queryFormats')) {
            return false;
        }
        try {
            return count(\Imagick::queryFormats('AVIF')) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function hasActiveAvifEncoder($config)
    {
        foreach (self::activeConverters($config) as $converter) {
            if (($converter['converter'] == 'imagick') && self::imagickSupportsAvif()) {
                return true;
            }
            if (($converter['converter'] == 'gd') && function_exists('imageavif')) {
                return true;
            }
        }
        return false;
    }

    private static function pictureModeEnabled($config)
    {
        return (
            isset($config['operation-mode']) &&
            ($config['operation-mode'] != 'no-conversion') &&
            isset($config['alter-html']['enabled']) &&
            $config['alter-html']['enabled'] &&
            isset($config['alter-html']['replacement']) &&
            ($config['alter-html']['replacement'] == 'picture')
        );
    }

    public static function startOutputBuffer()
    {
        if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return;
        }

        $config = self::getConfig();
        if (self::pictureModeEnabled($config) && self::hasActiveAvifEncoder($config)) {
            ob_start(array(__CLASS__, 'addAvifSources'));
        }
    }

    public static function addAvifSources($html)
    {
        if (!is_string($html) || !preg_match('/^\s*</', $html)) {
            return $html;
        }

        $config = self::getConfig();
        if (!self::pictureModeEnabled($config) || !self::hasActiveAvifEncoder($config)) {
            return $html;
        }

        return preg_replace_callback(
            '#<picture\b[^>]*>.*?</picture>#is',
            array(__CLASS__, 'addAvifSourceToPicture'),
            $html
        );
    }

    public static function addAvifSourceToPicture($matches)
    {
        $picture = $matches[0];
        if (!preg_match('#\bclass\s*=\s*(["\'])[^"\']*\bwebpexpress-processed\b[^"\']*\1#i', $picture)) {
            return $picture;
        }
        if (preg_match('#\btype\s*=\s*(["\'])image/avif\1#i', $picture)) {
            return $picture;
        }

        $config = self::getConfig();
        $added = false;
        $result = preg_replace_callback(
            '#<source\b[^>]*>#i',
            function ($sourceMatch) use (&$added, $config) {
                $tag = $sourceMatch[0];
                if ($added || !preg_match('#\btype\s*=\s*(["\'])image/webp\1#i', $tag)) {
                    return $tag;
                }

                $avifTag = preg_replace_callback(
                    '#\btype\s*=\s*(["\'])image/webp\1#i',
                    function ($typeMatch) {
                        return 'type=' . $typeMatch[1] . 'image/avif' . $typeMatch[1];
                    },
                    $tag,
                    1
                );
                $extension = isset($config['destination-folder']) && ($config['destination-folder'] == 'mingled')
                    ? '.webp.avif'
                    : '.avif';
                $avifTag = preg_replace('/\.webp(?=([?#"\',\s]|$))/i', $extension, $avifTag);
                if ($avifTag === $tag) {
                    return $tag;
                }

                $added = true;
                return $avifTag . $tag;
            },
            $picture
        );

        return $result;
    }

    private static function imageTypeEnabled($source, $config)
    {
        if (!preg_match('/\.(jpe?g|png)$/i', $source)) {
            return false;
        }
        $types = isset($config['image-types']) ? intval($config['image-types']) : 0;
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (($extension == 'jpg') || ($extension == 'jpeg')) {
            return (bool)($types & 1);
        }
        return (bool)($types & 2);
    }

    private static function sourceIsInScope($source, $config)
    {
        if (!isset($config['scope']) || !is_array($config['scope'])) {
            return false;
        }
        return \WebPExpress\Paths::findImageRootOfPath($source, $config['scope']) !== false;
    }

    private static function getDestinationRoot($source, $config)
    {
        $rootId = \WebPExpress\Paths::findImageRootOfPath($source, $config['scope']);
        if ($rootId === false) {
            return false;
        }
        $options = \WebPExpress\DestinationOptions::createFromConfig($config);
        return \WebPExpress\Paths::destinationRoot($rootId, $options);
    }

    private static function mapRequestToSource($requestPath, $config)
    {
        if (!isset($config['scope']) || !is_array($config['scope'])) {
            return false;
        }

        if (strpos($requestPath, "\0") !== false ||
            strpos($requestPath, '\\') !== false ||
            preg_match('#(^|/)\.\.?(/|$)#', $requestPath)) {
            return false;
        }

        $options = \WebPExpress\DestinationOptions::createFromConfig($config);
        $roots = array();
        foreach ($config['scope'] as $rootId) {
            $root = \WebPExpress\Paths::destinationRoot($rootId, $options);
            $rootUrlPath = parse_url($root['url'], PHP_URL_PATH);
            if (!is_string($rootUrlPath)) {
                continue;
            }
            $root['url-path'] = rtrim(rawurldecode($rootUrlPath), '/');
            $root['id'] = $rootId;
            $roots[] = $root;
        }

        usort($roots, function ($a, $b) {
            return strlen($b['url-path']) - strlen($a['url-path']);
        });

        foreach ($roots as $root) {
            $prefix = $root['url-path'] . '/';
            if (strpos($requestPath, $prefix) !== 0) {
                continue;
            }

            $relativePath = substr($requestPath, strlen($prefix));
            if (!preg_match('/\.avif$/i', $relativePath)) {
                continue;
            }
            if (($config['destination-folder'] == 'mingled') &&
                !preg_match('/\.webp\.avif$/i', $relativePath)) {
                continue;
            }
            $webpRelativePath = self::webpPathFromAvifPath($relativePath, $config);
            $webpPath = \wp_normalize_path(rtrim($root['abs-path'], '/\\') . '/' . $webpRelativePath);

            try {
                $source = \WebPExpress\Convert::findSource($webpPath, $config);
            } catch (\Exception $e) {
                error_log('[WebP Express AVIF] Could not resolve requested AVIF source: ' . $e->getMessage());
                return false;
            }

            if (($source === false) ||
                !self::sourceIsInScope($source, $config) ||
                !self::imageTypeEnabled($source, $config)) {
                continue;
            }

            return array(
                'source' => $source,
                'root' => $root,
            );
        }
        return false;
    }

    private static function qualityForSource($source)
    {
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $settings = self::getAvifQualitySettings();
        return ($extension == 'png') ? $settings['png_quality'] : $settings['jpeg_quality'];
    }

    private static function createTemporaryFile($destination)
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            error_log('[WebP Express AVIF] Could not create destination directory: ' . $directory);
            return false;
        }

        $temporaryFile = tempnam($directory, '.webp-express-avif-');
        if ($temporaryFile === false || wp_normalize_path(dirname($temporaryFile)) !== wp_normalize_path($directory)) {
            if (is_string($temporaryFile) && file_exists($temporaryFile)) {
                unlink($temporaryFile);
            }
            error_log('[WebP Express AVIF] Could not create a temporary file in: ' . $directory);
            return false;
        }
        return $temporaryFile;
    }

    private static function convertWithImagick($source, $temporaryFile, $quality, $metadata)
    {
        $image = new \Imagick();
        try {
            $image->readImage($source);
            if ($image->getNumberImages() > 1) {
                $image->setIteratorIndex(0);
            }
            if (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            }
            $image->setImageFormat('AVIF');
            $image->setImageCompressionQuality($quality);
            if ($metadata == 'none') {
                $image->stripImage();
            }
            return $image->writeImage($temporaryFile) && is_file($temporaryFile) && (filesize($temporaryFile) > 0);
        } finally {
            $image->clear();
            $image->destroy();
        }
    }

    private static function convertWithGd($source, $temporaryFile, $quality)
    {
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (($extension == 'jpg') || ($extension == 'jpeg')) {
            $image = imagecreatefromjpeg($source);
        } else {
            $image = imagecreatefrompng($source);
        }
        if ($image === false) {
            throw new \RuntimeException('GD could not read the source image.');
        }

        try {
            return imageavif($image, $temporaryFile, $quality) &&
                is_file($temporaryFile) &&
                (filesize($temporaryFile) > 0);
        } finally {
            imagedestroy($image);
        }
    }

    private static function writeMimeTypeRule($destinationRoot)
    {
        $serverSoftware = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '';
        if (stripos($serverSoftware, 'apache') === false) {
            return true;
        }

        if (!is_dir($destinationRoot) && !wp_mkdir_p($destinationRoot)) {
            error_log('[WebP Express AVIF] Could not create AVIF destination root: ' . $destinationRoot);
            return false;
        }

        $file = rtrim($destinationRoot, '/\\') . '/.htaccess';
        $begin = '# BEGIN WebP Express AVIF MIME';
        $end = '# END WebP Express AVIF MIME';
        $rule = $begin . "\n<IfModule mod_mime.c>\nAddType image/avif .avif\n</IfModule>\n" . $end;
        $content = '';

        if (is_file($file)) {
            $content = file_get_contents($file);
            if ($content === false) {
                error_log('[WebP Express AVIF] Could not read ' . $file . ' to add the AVIF MIME type.');
                return false;
            }
            $pattern = '/^' . preg_quote($begin, '/') . '.*?^' . preg_quote($end, '/') . '\s*/ms';
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, '', $content);
            }
        }

        $content = rtrim($content);
        $content .= ($content === '' ? '' : "\n\n") . $rule . "\n";
        if (file_put_contents($file, $content, LOCK_EX) === false) {
            error_log('[WebP Express AVIF] Could not write the AVIF MIME type to ' . $file);
            return false;
        }
        return true;
    }

    private static function removeMimeTypeRule($filename)
    {
        if (!is_file($filename)) {
            return true;
        }
        if (is_link($filename)) {
            error_log('[WebP Express AVIF] Refusing to edit symlinked .htaccess file: ' . $filename);
            return false;
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            error_log('[WebP Express AVIF] Could not read ' . $filename . ' to remove the AVIF MIME type.');
            return false;
        }

        $pattern = '/^# BEGIN WebP Express AVIF MIME\r?\n.*?^# END WebP Express AVIF MIME\r?\n?/ms';
        if (!preg_match($pattern, $content)) {
            return true;
        }
        $updated = preg_replace($pattern, '', $content);
        if (!is_string($updated)) {
            error_log('[WebP Express AVIF] Could not remove the AVIF MIME type from ' . $filename);
            return false;
        }
        if (file_put_contents($filename, $updated, LOCK_EX) === false) {
            error_log('[WebP Express AVIF] Could not write ' . $filename . ' after removing the AVIF MIME type.');
            return false;
        }
        return true;
    }

    private static function writeAvif($source, $destination, $config, $destinationRoot, $writeLog = true)
    {
        $sourceModified = @filemtime($source);
        $destinationModified = @filemtime($destination);
        $isCurrent = is_file($destination) &&
            ($sourceModified !== false) &&
            ($destinationModified !== false) &&
            ($destinationModified >= $sourceModified);
        if ($isCurrent && !empty($config['prevent-using-webps-larger-than-original'])) {
            $destinationSize = @filesize($destination);
            $sourceSize = @filesize($source);
            $isCurrent = ($destinationSize !== false) &&
                ($sourceSize !== false) &&
                ($destinationSize < $sourceSize);
        }

        $success = self::writeAvifFile($source, $destination, $config, $destinationRoot);
        if ($writeLog && !$isCurrent) {
            self::logAvifConversion($source, $destination, $success);
        }
        return $success;
    }

    private static function writeAvifFile($source, $destination, $config, $destinationRoot)
    {
        if (is_file($destination) &&
            !empty($config['prevent-using-webps-larger-than-original']) &&
            (filesize($destination) >= filesize($source))) {
            error_log('[WebP Express AVIF] Skipping AVIF that is larger than its source: ' . $destination);
            if (!unlink($destination)) {
                error_log('[WebP Express AVIF] Could not remove an AVIF that was larger than its source: ' . $destination);
            }
            return false;
        }
        if (is_file($destination) && (filemtime($destination) >= filemtime($source))) {
            return true;
        }

        $temporaryFile = self::createTemporaryFile($destination);
        if ($temporaryFile === false) {
            return false;
        }

        $success = false;
        try {
            foreach (self::activeConverters($config) as $converter) {
                $quality = self::qualityForSource($source);
                try {
                    if (is_file($temporaryFile) && (file_put_contents($temporaryFile, '') === false)) {
                        error_log('[WebP Express AVIF] Could not clear temporary conversion file: ' . $temporaryFile);
                        continue;
                    }
                    if (($converter['converter'] == 'imagick') && self::imagickSupportsAvif()) {
                        $success = self::convertWithImagick(
                            $source,
                            $temporaryFile,
                            $quality,
                            isset($config['metadata']) ? $config['metadata'] : 'none'
                        );
                    } elseif (($converter['converter'] == 'gd') && function_exists('imageavif')) {
                        $success = self::convertWithGd($source, $temporaryFile, $quality);
                    } else {
                        continue;
                    }
                } catch (\Exception $e) {
                    error_log('[WebP Express AVIF] ' . $converter['converter'] . ' conversion failed: ' . $e->getMessage());
                    $success = false;
                } catch (\Throwable $e) {
                    error_log('[WebP Express AVIF] ' . $converter['converter'] . ' conversion failed: ' . $e->getMessage());
                    $success = false;
                }

                if ($success) {
                    break;
                }
            }

            if (!$success) {
                error_log('[WebP Express AVIF] No active Imagick/GD converter could create AVIF for ' . $source);
                return false;
            }

            if (!@chmod($temporaryFile, 0644)) {
                error_log('[WebP Express AVIF] Could not set public read permissions on ' . $temporaryFile);
            }
            if (!self::writeMimeTypeRule($destinationRoot)) {
                return false;
            }
            if (!@rename($temporaryFile, $destination)) {
                if (!is_file($destination) || (filemtime($destination) < filemtime($source))) {
                    error_log('[WebP Express AVIF] Could not move the converted file into place: ' . $destination);
                    return false;
                }
            }
            if (!empty($config['prevent-using-webps-larger-than-original']) &&
                (filesize($destination) >= filesize($source))) {
                error_log('[WebP Express AVIF] Skipping AVIF that is larger than its source: ' . $destination);
                if (!unlink($destination)) {
                    error_log('[WebP Express AVIF] Could not remove an AVIF that was larger than its source: ' . $destination);
                }
                return false;
            }
            return true;
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    private static function logAvifConversion($source, $destination, $success)
    {
        $config = self::getConfig();
        \WebPExpress\AvifConversionLogger::log(
            $source,
            $destination,
            $success,
            !empty($config['enable-logging']),
            \WebPExpress\Paths::getWebPExpressContentDirAbs()
        );
    }

    private static function destinationForSource($source, $config)
    {
        $webpDestination = \WebPExpress\Convert::getDestination($source, $config);
        if (!is_string($webpDestination) || !preg_match('/\.webp$/i', $webpDestination)) {
            return false;
        }
        if (isset($config['destination-folder']) && ($config['destination-folder'] == 'mingled')) {
            return $webpDestination . '.avif';
        }
        return preg_replace('/\.webp$/i', '.avif', $webpDestination);
    }

    private static function webpPathFromAvifPath($avifPath, $config)
    {
        if (isset($config['destination-folder']) && ($config['destination-folder'] == 'mingled')) {
            return preg_replace('/\.avif$/i', '', $avifPath);
        }
        return preg_replace('/\.avif$/i', '.webp', $avifPath);
    }

    public static function serveAvifRequest()
    {
        if (!isset($_GET['webp-express-avif']) || (sanitize_text_field(wp_unslash($_GET['webp-express-avif'])) != '1')) {
            return;
        }

        $config = self::getConfig();
        if (!isset($config['operation-mode']) ||
            ($config['operation-mode'] == 'no-conversion') ||
            !self::pictureModeEnabled($config) ||
            !self::hasActiveAvifEncoder($config)) {
            return;
        }

        $relativeDestination = self::getRewriteEnvironmentValue('WE_AVIF_DEST_REL');
        $htaccessId = self::getRewriteEnvironmentValue('WE_AVIF_HTACCESS_ID');
        if (!is_string($relativeDestination) || !is_string($htaccessId) ||
            !in_array($htaccessId, array('cache', 'uploads'), true)) {
            self::respondNotFound('AVIF rewrite destination was not supplied.');
        }

        try {
            $relativeDestination = rawurldecode($relativeDestination);
            $relativeDestination = \WebPExpress\SanityCheck::pathWithoutDirectoryTraversal($relativeDestination);
        } catch (\Exception $e) {
            self::respondNotFound('AVIF rewrite destination is invalid.');
        }
        if (!preg_match('/\.avif$/i', $relativeDestination)) {
            self::respondNotFound('AVIF rewrite destination has an invalid extension.');
        }

        $mapping = self::mapRewriteDestinationToSource($relativeDestination, $htaccessId, $config);
        if ($mapping === false) {
            self::respondNotFound('No eligible source image corresponds to the requested AVIF.');
        }

        $source = $mapping['source'];
        if (!self::imageTypeEnabled($source, $config)) {
            self::respondNotFound('The source image type is disabled in WebP Express.');
        }
        $destination = self::destinationForSource($source, $config);
        if ($destination === false) {
            self::respondNotFound('Could not determine the AVIF destination.');
        }

        if (file_exists($source . '.do-not-convert') || file_exists($source . '.dontreplace')) {
            self::redirectToWebp($destination, $config);
        }

        if (!self::writeAvif($source, $destination, $config, $mapping['root']['abs-path'])) {
            self::redirectToWebp($destination, $config);
        }

        if (!is_readable($destination)) {
            error_log('[WebP Express AVIF] Converted AVIF file is not readable: ' . $destination);
            self::redirectToWebp($destination, $config);
        }

        if (function_exists('status_header')) {
            status_header(200);
        } else {
            header('HTTP/1.1 200 OK');
        }
        header('Content-Type: image/avif');
        header('Content-Length: ' . filesize($destination));
        header('X-Content-Type-Options: nosniff');
        $cacheControl = \WebPExpress\Config::getCacheControlHeader($config);
        if ($cacheControl !== '') {
            header('Cache-Control: ' . $cacheControl);
        }
        $modified = filemtime($destination);
        if ($modified !== false) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        }
        readfile($destination);
        exit;
    }

    private static function getRewriteEnvironmentValue($name)
    {
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value) || (strpos($key, 'HTTP_') === 0)) {
                continue;
            }
            while (strpos($key, 'REDIRECT_') === 0 || strpos($key, 'REWRITE_') === 0) {
                $key = (strpos($key, 'REDIRECT_') === 0)
                    ? substr($key, 9)
                    : substr($key, 7);
            }
            if ($key == $name) {
                return $value;
            }
        }
        return false;
    }

    private static function mapRewriteDestinationToSource($relativeDestination, $htaccessId, $config)
    {
        $options = \WebPExpress\DestinationOptions::createFromConfig($config);

        if ($htaccessId == 'uploads') {
            if ($config['destination-folder'] != 'mingled' ||
                !isset($config['scope']) ||
                !in_array('uploads', $config['scope'], true) ||
                !preg_match('/\.webp\.avif$/i', $relativeDestination)) {
                return false;
            }

            $root = \WebPExpress\Paths::destinationRoot('uploads', $options);
            $urlPath = rtrim(rawurldecode(parse_url($root['url'], PHP_URL_PATH)), '/') . '/' . $relativeDestination;
            return self::mapRequestToSource($urlPath, $config);
        }

        $cacheDir = wp_normalize_path(\WebPExpress\Paths::getCacheDirAbs());
        foreach ($config['scope'] as $rootId) {
            $root = \WebPExpress\Paths::destinationRoot($rootId, $options);
            $rootPath = wp_normalize_path($root['abs-path']);
            if (strpos($rootPath, rtrim($cacheDir, '/') . '/') !== 0) {
                continue;
            }

            $relativeRoot = substr($rootPath, strlen(rtrim($cacheDir, '/')) + 1);
            if (($relativeRoot != '') &&
                (strpos($relativeDestination, rtrim($relativeRoot, '/') . '/') !== 0)) {
                continue;
            }

            $relativeImage = ($relativeRoot == '')
                ? $relativeDestination
                : substr($relativeDestination, strlen(rtrim($relativeRoot, '/')) + 1);
            $urlPath = rtrim(rawurldecode(parse_url($root['url'], PHP_URL_PATH)), '/') . '/' . $relativeImage;
            $mapping = self::mapRequestToSource($urlPath, $config);
            if ($mapping !== false) {
                return $mapping;
            }
        }
        return false;
    }

    private static function redirectToWebp($avifDestination, $config)
    {
        $webpDestination = self::webpPathFromAvifPath($avifDestination, $config);
        if (is_file($webpDestination)) {
            $webpUrl = self::urlForDestination($webpDestination, $config);
            if ($webpUrl !== false) {
                wp_safe_redirect($webpUrl, 302);
                exit;
            }
        }
        self::respondNotFound('Could not create an AVIF or find its WebP fallback.');
    }

    private static function urlForDestination($destination, $config)
    {
        $options = \WebPExpress\DestinationOptions::createFromConfig($config);
        foreach ($config['scope'] as $rootId) {
            $root = \WebPExpress\Paths::destinationRoot($rootId, $options);
            $rootPath = rtrim(wp_normalize_path($root['abs-path']), '/') . '/';
            $destinationPath = wp_normalize_path($destination);
            if (strpos($destinationPath, $rootPath) !== 0) {
                continue;
            }
            $relative = substr($destinationPath, strlen($rootPath));
            $urlPath = rtrim($root['url'], '/') . '/' . $relative;
            return $urlPath;
        }
        return false;
    }

    private static function respondNotFound($message)
    {
        error_log('[WebP Express AVIF] ' . $message);
        if (function_exists('status_header')) {
            status_header(404);
        } else {
            header('HTTP/1.1 404 Not Found');
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo 'AVIF image could not be generated.';
        exit;
    }

    public static function syncRewriteRules()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        self::$config = null;
        $config = self::getConfig();
        $enabled = self::pictureModeEnabled($config) &&
            self::hasActiveAvifEncoder($config) &&
            isset($config['scope']) &&
            is_array($config['scope']) &&
            isset($config['image-types']);
        $realizerUrl = plugins_url('avif-realizer.php', __FILE__);
        $realizerPath = parse_url($realizerUrl, PHP_URL_PATH);
        if (!is_string($realizerPath) || $realizerPath == '') {
            error_log('[WebP Express AVIF] Could not determine the AVIF realizer URL.');
            return;
        }

        $cacheRules = array();
        $uploadsRules = array();
        if ($enabled) {
            $cacheRules = self::rewriteRulesFor('cache', $realizerPath, $config);
            if (($config['destination-folder'] == 'mingled') &&
                isset($config['scope']) &&
                in_array('uploads', $config['scope'], true)) {
                $uploadsRules = self::rewriteRulesFor('uploads', $realizerPath, $config);
            }
        }

        self::saveRewriteRules(\WebPExpress\Paths::getCacheDirAbs() . '/.htaccess', $cacheRules, true);
        $uploadDirectory = \WebPExpress\Paths::getUploadDirAbs();
        if (is_dir($uploadDirectory)) {
            self::saveRewriteRules($uploadDirectory . '/.htaccess', $uploadsRules, false);
        }
    }

    public static function syncRewriteRulesBeforeSettingsRedirect($location, $status)
    {
        if (isset($_REQUEST['action']) &&
            is_string($_REQUEST['action']) &&
            ($_REQUEST['action'] == 'webpexpress_settings_submit') &&
            current_user_can('manage_options')) {
            self::syncRewriteRules();
        }
        return $location;
    }

    public static function removeRewriteRules()
    {
        $cacheDir = defined('WP_CONTENT_DIR')
            ? rtrim(WP_CONTENT_DIR, '/\\') . '/webp-express/webp-images'
            : false;
        $mimeFiles = array();
        if (class_exists('\WebPExpress\Paths')) {
            $cacheDir = \WebPExpress\Paths::getCacheDirAbs();
        }
        if (is_string($cacheDir) && is_dir($cacheDir)) {
            self::saveRewriteRules($cacheDir . '/.htaccess', array(), false);
            $mimeFiles[] = $cacheDir . '/.htaccess';
        }

        if (class_exists('\WebPExpress\Paths')) {
            $uploadDirectory = \WebPExpress\Paths::getUploadDirAbs();
        } else {
            $upload = wp_upload_dir(null, false);
            $uploadDirectory = isset($upload['basedir']) ? $upload['basedir'] : false;
        }
        if (is_string($uploadDirectory) && is_dir($uploadDirectory)) {
            $uploadHtaccess = rtrim($uploadDirectory, '/\\') . '/.htaccess';
            self::saveRewriteRules($uploadHtaccess, array(), false);
            $mimeFiles[] = $uploadHtaccess;
        }

        if (class_exists('\WebPExpress\Paths') &&
            class_exists('\WebPExpress\DestinationOptions')) {
            $rootIds = \WebPExpress\Paths::getImageRootIds();
            foreach (array(false, true) as $useDocRoot) {
                $destinationOptions = new \WebPExpress\DestinationOptions(
                    false,
                    $useDocRoot,
                    false,
                    $rootIds
                );
                foreach ($rootIds as $rootId) {
                    try {
                        $destinationRoot = \WebPExpress\Paths::destinationRoot($rootId, $destinationOptions);
                    } catch (\Throwable $e) {
                        error_log('[WebP Express AVIF] Could not resolve a destination root during deactivation: ' . $e->getMessage());
                        continue;
                    }
                    if (isset($destinationRoot['abs-path']) && is_string($destinationRoot['abs-path'])) {
                        $mimeFiles[] = rtrim($destinationRoot['abs-path'], '/\\') . '/.htaccess';
                    }
                }
            }
        }

        foreach (array_unique($mimeFiles) as $mimeFile) {
            self::removeMimeTypeRule($mimeFile);
        }
    }

    private static function rewriteRulesFor($rootId, $realizerPath, $config)
    {
        $extensionPattern = ($rootId == 'uploads') ? '(.+\.webp\.avif)' : '(.+\.avif)';
        $flags = array(
            'E=WE_DESTINATION_REL_HTACCESS:$1',
            'E=WE_HTACCESS_ID:' . $rootId,
            'E=WE_AVIF_SCOPE:' . implode('|', $config['scope']),
            'E=WE_AVIF_IMAGE_TYPES:' . intval($config['image-types']),
            'E=WE_AVIF_PREVENT_LARGER:' . (!empty($config['prevent-using-webps-larger-than-original']) ? '1' : '0'),
            'E=HASH:' . \WebPExpress\Paths::getConfigHash()
        );
        $qualitySettings = self::getAvifQualitySettings();
        $flags[] = 'E=WE_AVIF_JPEG_QUALITY:' . $qualitySettings['jpeg_quality'];
        $flags[] = 'E=WE_AVIF_PNG_QUALITY:' . $qualitySettings['png_quality'];
        if (($config['destination-structure'] == 'doc-root') &&
            \WebPExpress\Paths::canUseDocRootForStructuringCacheDir()) {
            $flags[] = 'E=WPCONTENT:' . \WebPExpress\Paths::getContentDirRel();
        } else {
            $flags[] = 'E=WE_WP_CONTENT_REL_TO_WE_PLUGIN_DIR:' .
                \WebPExpress\Paths::getContentDirRelToWebPExpressPluginDir();
        }
        $flags[] = 'E=WE_AVIF_REQUEST_URI:%{REQUEST_URI}';
        $flags[] = 'NC';
        $flags[] = 'L';

        return array(
            '<IfModule mod_rewrite.c>',
            '  RewriteEngine On',
            '  RewriteCond %{REQUEST_FILENAME} !-f',
            '  RewriteRule (?i)^' . $extensionPattern . '$ ' . $realizerPath .
                ' [' . implode(',', $flags) . ']',
            '</IfModule>',
            '<IfModule mod_mime.c>',
            '  AddType image/avif .avif',
            '</IfModule>'
        );
    }

    private static function saveRewriteRules($filename, $rules, $createDirectory)
    {
        $directory = dirname($filename);
        $marker = '# BEGIN WebP Express AVIF';
        if (empty($rules)) {
            if (!is_file($filename)) {
                return true;
            }
            $content = file_get_contents($filename);
            if ($content === false) {
                error_log('[WebP Express AVIF] Could not read ' . $filename . ' to remove stale rules.');
                return false;
            }
            if (strpos($content, $marker) === false) {
                return true;
            }
        }

        if (!is_dir($directory)) {
            if (!$createDirectory || !wp_mkdir_p($directory)) {
                error_log('[WebP Express AVIF] Could not create rewrite-rule directory: ' . $directory);
                return false;
            }
        }

        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        $result = insert_with_markers($filename, 'WebP Express AVIF', $rules);
        if (!$result) {
            error_log('[WebP Express AVIF] Could not update Apache rewrite rules in ' . $filename);
        }
        return $result;
    }

    private static function maybeConvertOnUpload($source)
    {
        $config = self::getConfig();
        if (empty($config['convert-on-upload']) ||
            !self::pictureModeEnabled($config) ||
            !self::imageTypeEnabled($source, $config) ||
            !self::sourceIsInScope($source, $config) ||
            !self::hasActiveAvifEncoder($config)) {
            return;
        }

        $destination = self::destinationForSource($source, $config);
        $root = self::getDestinationRoot($source, $config);
        if ($destination === false || $root === false) {
            error_log('[WebP Express AVIF] Could not determine the AVIF destination for uploaded image: ' . $source);
            return;
        }
        self::writeAvif($source, $destination, $config, $root['abs-path']);
    }

    public static function convertAvifDuringBulkConvert()
    {
        if (!current_user_can('manage_options') ||
            !check_ajax_referer('webpexpress-ajax-convert-nonce', 'nonce', false) ||
            !isset($_POST['filename']) ||
            !is_string($_POST['filename'])) {
            return;
        }

        $source = realpath(wp_unslash($_POST['filename']));
        if ($source === false || !is_file($source)) {
            return;
        }

        $config = self::getConfig();
        if (!self::pictureModeEnabled($config) ||
            !self::imageTypeEnabled($source, $config) ||
            !self::sourceIsInScope($source, $config) ||
            !self::hasActiveAvifEncoder($config)) {
            return;
        }

        $destination = self::destinationForSource($source, $config);
        $root = self::getDestinationRoot($source, $config);
        if ($destination === false || $root === false) {
            error_log('[WebP Express AVIF] Could not determine the AVIF destination for bulk conversion: ' . $source);
            return;
        }

        $success = self::writeAvif($source, $destination, $config, $root['abs-path'], false);
        register_shutdown_function(function () use ($source, $destination, $success) {
            self::logAvifConversion($source, $destination, $success);
        });
    }

    public static function handleUpload($fileArray, $overrides = false)
    {
        if (isset($fileArray['file']) && is_file($fileArray['file'])) {
            self::maybeConvertOnUpload($fileArray['file']);
        }
        return $fileArray;
    }

    public static function handleIntermediateSize($filename)
    {
        if (is_string($filename) && is_file($filename)) {
            self::maybeConvertOnUpload($filename);
        }
        return $filename;
    }

    public static function deleteDerivative($file, $deprecated = null)
    {
        if (!is_string($file) || !preg_match('/\.(jpe?g|png)$/i', $file)) {
            return $file;
        }

        $config = self::getConfig();
        if (!self::sourceIsInScope($file, $config) || !self::imageTypeEnabled($file, $config)) {
            return $file;
        }

        $destination = self::destinationForSource($file, $config);
        if (is_string($destination) && is_file($destination) && !unlink($destination)) {
            error_log('[WebP Express AVIF] Could not remove derivative after deleting source: ' . $destination);
        }
        return $file;
    }

    public static function purgeAvifCache()
    {
        $nonce = isset($_REQUEST['nonce']) && is_string($_REQUEST['nonce']) ? $_REQUEST['nonce'] : '';
        if (!current_user_can('manage_options') ||
            !wp_verify_nonce($nonce, 'webpexpress-ajax-purge-cache-nonce')) {
            return;
        }

        self::$purgeResult = array('delete-count' => 0, 'fail-count' => 0);
        ob_start(array(__CLASS__, 'appendPurgeCounts'));
        $config = self::getConfig();
        $onlyPng = isset($_POST['only-png']) && is_string($_POST['only-png']) &&
            (sanitize_text_field(wp_unslash($_POST['only-png'])) == 'true');
        if ($config['destination-folder'] == 'mingled') {
            self::purgeAvifFiles(
                \WebPExpress\Paths::getUploadDirAbs(),
                $config,
                $onlyPng,
                true,
                self::$purgeResult
            );
        }
        self::purgeAvifFiles(
            \WebPExpress\Paths::getCacheDirAbs(),
            $config,
            $onlyPng,
            ($config['destination-folder'] == 'mingled'),
            self::$purgeResult
        );
    }

    public static function appendPurgeCounts($response)
    {
        if (!is_string($response) || !is_array(self::$purgeResult)) {
            return $response;
        }
        $result = json_decode($response, true);
        if (!is_array($result) || !isset($result['delete-count'], $result['fail-count'])) {
            return $response;
        }
        $result['avif-delete-count'] = self::$purgeResult['delete-count'];
        $result['avif-fail-count'] = self::$purgeResult['fail-count'];
        return wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
    }

    private static function purgeAvifFiles($directory, $config, $onlyPng, $mingled, &$counts)
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        try {
            $iterator = new \FilesystemIterator($directory);
        } catch (\UnexpectedValueException $e) {
            $counts['fail-count']++;
            error_log('[WebP Express AVIF] Could not scan cache directory ' . $directory . ': ' . $e->getMessage());
            return;
        }
        while ($iterator->valid()) {
            $path = $iterator->getPathname();
            if (is_dir($path) && !is_link($path)) {
                self::purgeAvifFiles($path, $config, $onlyPng, $mingled, $counts);
            } elseif (is_file($path) &&
                ($mingled ? preg_match('/\.webp\.avif$/i', $path) : preg_match('/\.avif$/i', $path))) {
                if ($onlyPng) {
                    $webpPath = self::webpPathFromAvifPath($path, $config);
                    try {
                        $source = \WebPExpress\Convert::findSource($webpPath, $config);
                    } catch (\Exception $e) {
                        error_log('[WebP Express AVIF] Could not resolve AVIF source while purging cache: ' . $e->getMessage());
                        $iterator->next();
                        continue;
                    }
                    if (!is_string($source) || (strtolower(pathinfo($source, PATHINFO_EXTENSION)) != 'png')) {
                        $iterator->next();
                        continue;
                    }
                }

                if (unlink($path)) {
                    $counts['delete-count']++;
                } else {
                    $counts['fail-count']++;
                    error_log('[WebP Express AVIF] Could not remove cached AVIF file: ' . $path);
                }
            }
            $iterator->next();
        }
    }
}

add_action('plugins_loaded', array('WebPExpressAvif', 'bootstrap'), 20);
register_deactivation_hook(__FILE__, array('WebPExpressAvif', 'removeRewriteRules'));
