<?php
/**
 * Plugin Name: WebP Express AVIF
 * Plugin URI: https://github.com/SlimJim192/webp-express-avif
 * Description: Adds AVIF support to WebP Express picture tags and varied image responses.
 * Version: 1.0.4
 * Author: Ncomp@ss
 * GitHub Plugin URI: https://github.com/SlimJim192/webp-express-avif
 * Primary Branch: main
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
        add_action('admin_notices', array(__CLASS__, 'renderQualityCacheNotice'));
        add_action('admin_init', array(__CLASS__, 'syncRewriteRules'));
        add_filter('wp_redirect', array(__CLASS__, 'syncRewriteRulesBeforeSettingsRedirect'), 10, 2);
        add_action('update_option_webpexpress_avif_settings', array(__CLASS__, 'qualitySettingsUpdated'), 10, 3);
        add_action('add_option_webpexpress_avif_settings', array(__CLASS__, 'qualitySettingsAdded'), 10, 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueuePurgeScript'), 1000);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueueLiveTestScript'), 1000);
        add_filter('wp_handle_upload', array(__CLASS__, 'handleUpload'), 20, 2);
        add_filter('image_make_intermediate_size', array(__CLASS__, 'handleIntermediateSize'), 20, 1);
        add_filter('wp_delete_file', array(__CLASS__, 'deleteDerivative'), 20, 2);
        add_action('wp_ajax_webpexpress_purge_cache', array(__CLASS__, 'purgeAvifCache'), 1);
        add_action('wp_ajax_webpexpress_avif_live_test', array(__CLASS__, 'runLiveTestAjax'));
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
        return self::normalizeQualitySettings(get_option('webpexpress_avif_settings', array()));
    }

    private static function additionalHeadersEnabled($settings = null)
    {
        if ($settings === null) {
            $settings = get_option('webpexpress_avif_settings', array());
        }
        return is_array($settings) &&
            isset($settings['additional_headers']) &&
            in_array($settings['additional_headers'], array(true, 1, '1'), true);
    }

    private static function normalizeQualitySettings($settings)
    {
        if (!is_array($settings)) {
            $settings = array();
        }
        $jpegQuality = isset($settings['jpeg_quality']) && is_numeric($settings['jpeg_quality'])
            ? intval($settings['jpeg_quality'])
            : 65;
        $pngQuality = isset($settings['png_quality']) && is_numeric($settings['png_quality'])
            ? intval($settings['png_quality'])
            : 70;
        return array(
            'jpeg_quality' => max(0, min(100, $jpegQuality)),
            'png_quality' => max(0, min(100, $pngQuality))
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
                'default' => array(
                    'jpeg_quality' => 65,
                    'png_quality' => 70,
                    'additional_headers' => false
                )
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
        add_settings_section(
            'webpexpress_avif_headers',
            'Response headers',
            array(__CLASS__, 'renderHeadersSection'),
            'webpexpress-avif'
        );
        add_settings_field(
            'webpexpress_avif_additional_headers',
            'Additional diagnostic headers',
            array(__CLASS__, 'renderAdditionalHeadersField'),
            'webpexpress-avif',
            'webpexpress_avif_headers'
        );
    }

    public static function sanitizeSettings($input)
    {
        if (!is_array($input)) {
            return array_merge(
                self::getAvifQualitySettings(),
                array('additional_headers' => self::additionalHeadersEnabled())
            );
        }
        return array_merge(
            self::normalizeQualitySettings($input),
            array(
                'additional_headers' => self::additionalHeadersEnabled($input)
            )
        );
    }

    public static function qualitySettingsUpdated($oldValue, $newValue, $option)
    {
        self::purgeAvifCacheIfQualityChanged($oldValue, $newValue);
        if (self::additionalHeadersEnabled($oldValue) !== self::additionalHeadersEnabled($newValue)) {
            self::syncRewriteRules();
        }
    }

    public static function qualitySettingsAdded($option, $value)
    {
        self::purgeAvifCacheIfQualityChanged(
            array('jpeg_quality' => 65, 'png_quality' => 70),
            $value
        );
        self::syncRewriteRules();
    }

    private static function purgeAvifCacheIfQualityChanged($oldValue, $newValue)
    {
        if (self::normalizeQualitySettings($oldValue) === self::normalizeQualitySettings($newValue)) {
            return;
        }

        $counts = array('delete-count' => 0, 'fail-count' => 0);
        $config = self::getConfig();
        if (!is_array($config) || !isset($config['destination-folder'])) {
            error_log('[WebP Express AVIF] Cannot purge AVIF files after a quality change: WebP Express destination settings are unavailable.');
            $counts['fail-count']++;
        } else {
            $mingled = ($config['destination-folder'] == 'mingled');
            if ($mingled) {
                self::purgeAvifFiles(
                    \WebPExpress\Paths::getUploadDirAbs(),
                    $config,
                    false,
                    true,
                    $counts
                );
            }
            self::purgeAvifFiles(
                \WebPExpress\Paths::getCacheDirAbs(),
                $config,
                false,
                $mingled,
                $counts
            );
        }

        if ($counts['fail-count'] > 0) {
            error_log(
                '[WebP Express AVIF] Quality changed; ' . $counts['delete-count'] .
                ' cached AVIF file(s) removed and ' . $counts['fail-count'] .
                ' could not be removed.'
            );
        }

        self::syncRewriteRules();
        $noticeKey = 'webpexpress_avif_quality_purge_' . get_current_user_id();
        set_transient($noticeKey, $counts, 5 * MINUTE_IN_SECONDS);
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
        echo '<p>These quality values are independent of WebP Express quality settings and apply to both on-demand and upload-time AVIF conversion. Lower values generally reduce file size. AVIF output from PNG sources may be lossy. Saving changed quality values clears cached AVIFs so they are regenerated with the new values on request.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('webpexpress_avif');
        do_settings_sections('webpexpress-avif');
        submit_button();
        echo '</form>';
        self::renderLiveTests();
        echo '</div>';
    }

    public static function renderQualityCacheNotice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $noticeKey = 'webpexpress_avif_quality_purge_' . get_current_user_id();
        $counts = get_transient($noticeKey);
        if (!is_array($counts)) {
            return;
        }
        delete_transient($noticeKey);

        $deleted = isset($counts['delete-count']) ? intval($counts['delete-count']) : 0;
        $failed = isset($counts['fail-count']) ? intval($counts['fail-count']) : 0;
        $class = ($failed > 0) ? 'notice-warning' : 'notice-success';
        $message = 'AVIF quality settings saved; ' . $deleted . ' cached AVIF file(s) removed.';
        if ($failed > 0) {
            $message .= ' ' . $failed . ' file(s) could not be removed; check the PHP error log.';
        } else {
            $message .= ' They will be regenerated on request.';
        }
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' .
            esc_html($message) . '</p></div>';
    }

    private static function renderLiveTests()
    {
        echo '<hr><h2>AVIF live tests</h2>';
        echo '<p>Run a request against your site to check each AVIF rewrite route. Tests create and remove a temporary JPEG and AVIF in the uploads/cache folders. They verify the HTTP response because a load-balanced site may create the cache file on another server.</p>';
        echo '<p>';
        echo '<button type="button" class="button" data-webpexpress-avif-test="existing">Test existing AVIF response</button> ';
        echo '<button type="button" class="button" data-webpexpress-avif-test="converter">Test source conversion</button> ';
        echo '<button type="button" class="button" data-webpexpress-avif-test="realizer">Test picture-tag realizer</button>';
        echo '</p>';
        echo '<pre id="webpexpress-avif-live-test-output" aria-live="polite" style="white-space:pre-wrap"></pre>';
    }

    public static function renderQualitySection()
    {
        echo '<p>Choose an AVIF encoder quality from 0 to 100 for each source format. Defaults: JPEG 65 and PNG 70.</p>';
    }

    public static function renderHeadersSection()
    {
        echo '<p>Status headers remain available. Enable this option to add configured quality, encoder, source/output size, and savings headers to PHP-served AVIF and WebP responses.</p>';
    }

    public static function renderAdditionalHeadersField()
    {
        $settings = get_option('webpexpress_avif_settings', array());
        echo '<label><input type="checkbox" name="webpexpress_avif_settings[additional_headers]" value="1" ' .
            checked(self::additionalHeadersEnabled($settings), true, false) .
            '> Enable additional diagnostic headers (disabled by default)</label>';
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

    public static function enqueueLiveTestScript()
    {
        if (!current_user_can('manage_options') ||
            !isset($_GET['page']) ||
            ($_GET['page'] != 'webpexpress-avif')) {
            return;
        }

        $scriptPath = __DIR__ . '/avif-live-tests.js';
        wp_enqueue_script(
            'webpexpress-avif-live-tests',
            plugin_dir_url(__FILE__) . 'avif-live-tests.js',
            array(),
            is_file($scriptPath) ? (string)filemtime($scriptPath) : '1.0.0',
            true
        );
        wp_localize_script(
            'webpexpress-avif-live-tests',
            'WebPExpressAvifLiveTests',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('webpexpress_avif_live_test')
            )
        );
    }

    public static function runLiveTestAjax()
    {
        check_ajax_referer('webpexpress_avif_live_test', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You are not allowed to run AVIF live tests.'), 403);
        }

        $test = isset($_POST['test']) ? sanitize_key(wp_unslash($_POST['test'])) : '';
        $log = array();
        try {
            self::runLiveTest($test, $log);
            wp_send_json_success(array('log' => $log));
        } catch (\Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage(), 'log' => $log), 400);
        }
    }

    private static function runLiveTest($test, &$log)
    {
        $config = self::getConfig();
        $testRequirements = array(
            'existing' => array(
                'name' => 'Existing AVIF varied response',
                'required-setting' => 'redirect-to-existing-in-htaccess',
                'setting-description' => 'Enable redirection to existing WebP files in WebP Express'
            ),
            'converter' => array(
                'name' => 'AVIF source conversion',
                'required-setting' => 'enable-redirection-to-converter',
                'setting-description' => 'Enable redirection to the WebP converter'
            ),
            'realizer' => array(
                'name' => 'Picture-tag AVIF realizer',
                'required-setting' => 'enable-redirection-to-webp-realizer',
                'setting-description' => 'Enable redirection to the WebP realizer'
            )
        );
        if (!isset($testRequirements[$test])) {
            throw new \RuntimeException('Unknown AVIF live test.');
        }
        $requirement = $testRequirements[$test];
        $log = array($requirement['name']);

        if (empty($config[$requirement['required-setting']])) {
            throw new \RuntimeException($requirement['setting-description'] . ' in WebP Express before running this test.');
        }
        if ($test == 'realizer') {
            if (!self::pictureModeEnabled($config)) {
                throw new \RuntimeException('Select picture-tag HTML replacement in WebP Express before running this test.');
            }
        } elseif (!self::variedImageModeEnabled($config)) {
            throw new \RuntimeException('Select Varied image responses in WebP Express before running this test.');
        }
        if (!self::hasActiveAvifEncoder($config)) {
            throw new \RuntimeException('No active WebP Express Imagick or GD converter can encode AVIF.');
        }
        if (!isset($config['scope']) ||
            !is_array($config['scope']) ||
            !in_array('uploads', $config['scope'], true) ||
            !self::imageTypeEnabled('live-test.jpg', $config)) {
            throw new \RuntimeException('Include JPEG images in the uploads scope in WebP Express to run these tests.');
        }

        $uploadsPath = \WebPExpress\Paths::getAbsDirById('uploads');
        $uploadsUrl = \WebPExpress\Paths::getUrlById('uploads');
        if (!is_string($uploadsPath) || !is_dir($uploadsPath) ||
            !is_string($uploadsUrl) || $uploadsUrl == '') {
            throw new \RuntimeException('Could not locate the WordPress uploads directory and URL.');
        }

        $token = bin2hex(random_bytes(8));
        $testDirectory = rtrim($uploadsPath, '/\\') . '/webp-express-avif-live-tests/' . $token;
        $source = $testDirectory . '/live-test.jpg';
        $sourceCreated = false;
        $destinationOwned = false;
        $destination = false;
        $existingAvifHash = false;

        if (file_exists($testDirectory)) {
            throw new \RuntimeException('The temporary AVIF test directory already exists. Please run the test again.');
        }
        if (!wp_mkdir_p($testDirectory)) {
            throw new \RuntimeException('Could not create the temporary AVIF test directory in uploads.');
        }

        try {
            $sourceCreated = true;
            self::createLiveTestImage($source);
            $destinationRoot = self::getDestinationRoot($source, $config);
            if ($destinationRoot === false ||
                !isset($destinationRoot['abs-path']) ||
                !isset($destinationRoot['url'])) {
                throw new \RuntimeException('Could not determine the WebP Express destination for the test image.');
            }
            $destination = self::destinationForSource($source, $config);
            if (!is_string($destination) || file_exists($destination)) {
                throw new \RuntimeException('Could not determine an unused AVIF destination for the test image.');
            }
            $destinationOwned = true;
            if ($test == 'existing') {
                if (!self::writeAvif($source, $destination, $config, $destinationRoot['abs-path'], false)) {
                    throw new \RuntimeException('Could not create the AVIF fixture. Check the active AVIF encoder and the "prevent using larger images" setting.');
                }
                $existingAvifHash = hash_file('sha256', $destination);
                $originalSourceHash = hash_file('sha256', $source);
                if (!is_string($existingAvifHash) || !is_string($originalSourceHash)) {
                    throw new \RuntimeException('Could not read the generated AVIF and JPEG fixtures.');
                }
                self::createLiveTestImage($source);
                $changedSourceHash = hash_file('sha256', $source);
                if (!is_string($changedSourceHash) ||
                    ($changedSourceHash === $originalSourceHash) ||
                    !touch($source, time() + 5)) {
                    throw new \RuntimeException('Could not prepare a changed source image for the existing-AVIF route check.');
                }
                $requestUrl = self::liveTestUrl($uploadsUrl, $uploadsPath, $source);
            } elseif ($test == 'converter') {
                $requestUrl = self::liveTestUrl($uploadsUrl, $uploadsPath, $source);
            } else {
                $requestUrl = self::liveTestUrl($destinationRoot['url'], $destinationRoot['abs-path'], $destination);
            }

            $log[] = 'Request URL: ' . $requestUrl;
            $log[] = 'Expected AVIF cache file: ' . $destination;
            $response = wp_remote_get(
                $requestUrl,
                array(
                    'headers' => array(
                        'Accept' => 'image/avif',
                        'Cache-Control' => 'no-cache'
                    ),
                    'timeout' => 30,
                    'redirection' => 0,
                    'sslverify' => !(defined('WP_DEBUG') && WP_DEBUG)
                )
            );
            if (is_wp_error($response)) {
                throw new \RuntimeException('The live request failed: ' . $response->get_error_message());
            }

            $status = wp_remote_retrieve_response_code($response);
            $contentType = wp_remote_retrieve_header($response, 'content-type');
            $body = wp_remote_retrieve_body($response);
            $log[] = 'Response: ' . $status . ' ' . wp_remote_retrieve_response_message($response);
            $log[] = 'Content-Type: ' . ($contentType ? $contentType : '(missing)');
            if ($status != 200) {
                throw new \RuntimeException('The test request returned HTTP ' . $status . '. Check the AVIF rewrite rules and server error log.');
            }
            if (stripos((string)$contentType, 'image/avif') !== 0) {
                throw new \RuntimeException('The test request returned Content-Type "' . (string)$contentType . '", not image/avif.');
            }
            if (!is_string($body) || $body === '') {
                throw new \RuntimeException('The test request returned an empty response body.');
            }
            if ($test == 'existing') {
                if (hash('sha256', $body) !== $existingAvifHash) {
                    throw new \RuntimeException('The request did not serve the pre-existing AVIF; it may have converted the changed source instead.');
                }
            }
            $log[] = 'PASS: received a non-empty AVIF response.';
        } finally {
            if ($destinationOwned && is_string($destination) && is_file($destination) && !unlink($destination)) {
                error_log('[WebP Express AVIF] Could not remove live-test AVIF fixture: ' . $destination);
            }
            if ($sourceCreated && is_file($source) && !unlink($source)) {
                error_log('[WebP Express AVIF] Could not remove live-test source fixture: ' . $source);
            }
            if (is_dir($testDirectory) && !rmdir($testDirectory)) {
                error_log('[WebP Express AVIF] Could not remove live-test directory: ' . $testDirectory);
            }
        }
    }

    private static function createLiveTestImage($filename)
    {
        $width = 256;
        $height = 256;
        if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
            $image = imagecreatetruecolor($width, $height);
            if ($image === false) {
                throw new \RuntimeException('Could not allocate the temporary JPEG image.');
            }
            try {
                for ($y = 0; $y < $height; $y += 4) {
                    for ($x = 0; $x < $width; $x += 4) {
                        $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
                        if ($color === false || !imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $color)) {
                            throw new \RuntimeException('Could not draw the temporary JPEG image.');
                        }
                    }
                }
                if (!imagejpeg($image, $filename, 95) || !is_file($filename) || filesize($filename) === 0) {
                    throw new \RuntimeException('Could not write the temporary JPEG image.');
                }
            } finally {
                imagedestroy($image);
            }
            return;
        }

        if (class_exists('\Imagick')) {
            $image = new \Imagick();
            try {
                $image->newImage($width, $height, new \ImagickPixel('white'));
                $draw = new \ImagickDraw();
                for ($y = 0; $y < $height; $y += 4) {
                    for ($x = 0; $x < $width; $x += 4) {
                        $draw->setFillColor(sprintf(
                            'rgb(%d,%d,%d)',
                            random_int(0, 255),
                            random_int(0, 255),
                            random_int(0, 255)
                        ));
                        $draw->rectangle($x, $y, $x + 3, $y + 3);
                    }
                }
                $image->drawImage($draw);
                $image->setImageFormat('JPEG');
                $image->setImageCompressionQuality(95);
                if (!$image->writeImage($filename) || !is_file($filename) || filesize($filename) === 0) {
                    throw new \RuntimeException('Could not write the temporary JPEG image.');
                }
            } finally {
                $image->clear();
                $image->destroy();
            }
            return;
        }

        throw new \RuntimeException('The active AVIF encoder is available, but neither GD nor Imagick can create a JPEG test image.');
    }

    private static function liveTestUrl($rootUrl, $rootPath, $filePath)
    {
        if (!is_string($rootUrl) || !is_string($rootPath) ||
            !is_string($filePath) || $rootUrl == '') {
            throw new \RuntimeException('Could not construct a URL for the temporary AVIF test file.');
        }
        $rootPath = rtrim(wp_normalize_path($rootPath), '/') . '/';
        $filePath = wp_normalize_path($filePath);
        if (strpos($filePath, $rootPath) !== 0) {
            throw new \RuntimeException('The temporary AVIF test file is outside its expected image root.');
        }
        $relativePath = substr($filePath, strlen($rootPath));
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
        return trailingslashit($rootUrl) . $encodedPath;
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

    private static function activeAvifConverters($config)
    {
        $converters = self::activeConverters($config);
        $ordered = array();
        // Prefer GD because Imagick may ignore its quality setting for AVIF.
        foreach (array('gd', 'imagick') as $encoderName) {
            foreach ($converters as $converter) {
                if ($converter['converter'] == $encoderName) {
                    $ordered[] = $converter;
                }
            }
        }
        return $ordered;
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
        foreach (self::activeAvifConverters($config) as $converter) {
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

    private static function variedImageModeEnabled($config)
    {
        return isset($config['operation-mode']) &&
            ($config['operation-mode'] == 'varied-image-responses');
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
        $begin = '# BEGIN AVIF WebP Express MIME';
        $end = '# END AVIF WebP Express MIME';
        $rule = $begin . "\n<IfModule mod_mime.c>\nAddType image/avif .avif\n</IfModule>\n" . $end;
        $content = '';

        if (is_file($file)) {
            $content = file_get_contents($file);
            if ($content === false) {
                error_log('[WebP Express AVIF] Could not read ' . $file . ' to add the AVIF MIME type.');
                return false;
            }
            $pattern = '/^# BEGIN (WebP Express AVIF MIME|AVIF WebP Express MIME)\r?\n.*?^# END \1\r?\n?/ms';
            $content = preg_replace($pattern, '', $content);
            if (!is_string($content)) {
                error_log('[WebP Express AVIF] Could not parse AVIF MIME rules in ' . $file);
                return false;
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

        $pattern = '/^# BEGIN (WebP Express AVIF MIME|AVIF WebP Express MIME)\r?\n.*?^# END \1\r?\n?/ms';
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
            foreach (self::activeAvifConverters($config) as $converter) {
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
            if (substr($key, -strlen($name)) == $name) {
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
            !empty($config['enable-redirection-to-webp-realizer']) &&
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

        $variedEnabled = self::variedImageModeEnabled($config) &&
            self::hasActiveAvifEncoder($config) &&
            (!empty($config['enable-redirection-to-converter']) ||
                !empty($config['redirect-to-existing-in-htaccess'])) &&
            isset($config['scope']) &&
            is_array($config['scope']) &&
            !empty($config['image-types']);
        foreach (\WebPExpress\Paths::getImageRootIds() as $rootId) {
            $sourceDirectory = \WebPExpress\Paths::getAbsDirById($rootId);
            if (!is_string($sourceDirectory) || !is_dir($sourceDirectory)) {
                continue;
            }
            $sourceRules = ($variedEnabled && in_array($rootId, $config['scope'], true))
                ? self::variedRewriteRulesFor($rootId, $realizerPath, $config)
                : array();
            self::saveNegotiationRules($sourceDirectory . '/.htaccess', $sourceRules);
        }

        self::patchUploadOnDemandSourceRule();
        self::syncWebPDiagnosticsRules();
    }

    private static function patchUploadOnDemandSourceRule()
    {
        $uploadDirectory = \WebPExpress\Paths::getUploadDirAbs();
        $filename = rtrim($uploadDirectory, '/\\') . '/.htaccess';
        if (!is_file($filename)) {
            return true;
        }
        if (is_link($filename)) {
            error_log('[WebP Express AVIF] Refusing to update a symlinked uploads .htaccess file: ' . $filename);
            return false;
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            error_log('[WebP Express AVIF] Could not read uploads .htaccess to adjust the WebP source path.');
            return false;
        }

        $uploadRootPattern = preg_quote(
            rtrim(str_replace('\\', '/', $uploadDirectory), '/'),
            '#'
        );
        $ruleFound = false;
        $conditionFound = false;
        $changed = false;
        $coreBlockPattern = '/^# BEGIN WebP Express\r?\n.*?^# END WebP Express\r?\n?/ms';
        $updated = preg_replace_callback(
            $coreBlockPattern,
            function ($matches) use (
                $uploadRootPattern,
                &$ruleFound,
                &$conditionFound,
                &$changed
            ) {
                $lines = preg_split('/(?<=\n)/', $matches[0]);
                foreach ($lines as $index => $line) {
                    $rule = rtrim($line, "\r\n");
                    if ((strpos($rule, 'webp-on-demand.php') === false) ||
                        (strpos($rule, 'E=WE_SOURCE_REL_HTACCESS:$0') === false) ||
                        (strpos($rule, 'E=WE_HTACCESS_ID:uploads') === false)) {
                        continue;
                    }

                    $ruleFound = true;
                    if ($index === 0) {
                        continue;
                    }

                    $conditionLine = $lines[$index - 1];
                    $condition = rtrim($conditionLine, "\r\n");
                    $trimmedCondition = ltrim($condition);
                    $conditionPrefix = 'RewriteCond %{REQUEST_FILENAME} (?i)(.*)';
                    if (strpos($trimmedCondition, $conditionPrefix) !== 0) {
                        continue;
                    }

                    $suffix = substr($trimmedCondition, strlen($conditionPrefix));
                    if (!preg_match('/^\((.*)\)\$$/', $suffix, $suffixMatches)) {
                        continue;
                    }

                    $indent = substr($condition, 0, strlen($condition) - strlen($trimmedCondition));
                    $lineEnding = substr($conditionLine, strlen($condition));
                    $lines[$index - 1] = $indent . 'RewriteCond %{REQUEST_FILENAME} (?i)^' .
                        $uploadRootPattern . '/((?:.*)(?:' . $suffixMatches[1] . '))$' . $lineEnding;
                    $lines[$index] = str_replace(
                        'E=WE_SOURCE_REL_HTACCESS:$0',
                        'E=WE_SOURCE_REL_HTACCESS:%1',
                        $lines[$index]
                    );
                    $conditionFound = true;
                    $changed = true;
                }

                return implode('', $lines);
            },
            $content
        );

        if (!is_string($updated)) {
            error_log('[WebP Express AVIF] Could not parse the WebP Express uploads rules.');
            return false;
        }
        if (!$ruleFound || !$changed) {
            if ($ruleFound && !$conditionFound) {
                error_log('[WebP Express AVIF] Could not locate the image condition for the uploads converter rule.');
                return false;
            }
            return true;
        }
        if (file_put_contents($filename, $updated, LOCK_EX) === false) {
            error_log('[WebP Express AVIF] Could not write the adjusted WebP source rule to uploads .htaccess.');
            return false;
        }
        return true;
    }

    private static function getWebPDiagnosticsHtaccessFiles()
    {
        $directories = array();
        if (class_exists('\WebPExpress\Paths')) {
            $directories[] = \WebPExpress\Paths::getCacheDirAbs();
            foreach (\WebPExpress\Paths::getImageRootIds() as $rootId) {
                $directory = \WebPExpress\Paths::getAbsDirById($rootId);
                if (is_string($directory)) {
                    $directories[] = $directory;
                }
            }
        } else {
            if (defined('WP_CONTENT_DIR')) {
                $directories[] = rtrim(WP_CONTENT_DIR, '/\\') . '/webp-express/webp-images';
                $directories[] = rtrim(WP_CONTENT_DIR, '/\\');
                $directories[] = rtrim(WP_CONTENT_DIR, '/\\') . '/themes';
                $directories[] = rtrim(WP_CONTENT_DIR, '/\\') . '/plugins';
            }
            if (function_exists('wp_upload_dir')) {
                $upload = wp_upload_dir(null, false);
                if (isset($upload['basedir']) && is_string($upload['basedir'])) {
                    $directories[] = $upload['basedir'];
                }
            }
            if (defined('ABSPATH')) {
                $directories[] = ABSPATH;
            }
        }

        $files = array();
        foreach (array_unique($directories) as $directory) {
            if (is_string($directory) && is_dir($directory)) {
                $files[] = rtrim($directory, '/\\') . '/.htaccess';
            }
        }
        return array_values(array_unique($files));
    }

    private static function webPDiagnosticRoutePaths()
    {
        $wrapperUrl = plugins_url('webp-response-realizer.php', __FILE__);
        $wrapperPath = parse_url($wrapperUrl, PHP_URL_PATH);
        $corePluginFile = dirname(__DIR__) . '/webp-express/webp-express.php';
        $routes = array();
        foreach (array('on-demand', 'realizer') as $route) {
            $filename = ($route == 'on-demand') ? 'webp-on-demand.php' : 'webp-realizer.php';
            $coreUrl = plugins_url('wod/' . $filename, $corePluginFile);
            $corePath = parse_url($coreUrl, PHP_URL_PATH);
            if (!is_string($corePath) || $corePath == '') {
                error_log('[WebP Express AVIF] Could not determine the WebP Express ' . $route . ' URL.');
                return false;
            }
            $routes[$route] = $corePath;
        }

        if (!is_string($wrapperPath) || $wrapperPath == '') {
            error_log('[WebP Express AVIF] Could not determine the WebP diagnostics wrapper URL.');
            return false;
        }
        return array('wrapper' => $wrapperPath, 'core' => $routes);
    }

    private static function syncWebPDiagnosticsRules()
    {
        $routePaths = self::webPDiagnosticRoutePaths();
        if ($routePaths === false) {
            return;
        }
        $remove = !self::additionalHeadersEnabled();
        foreach (self::getWebPDiagnosticsHtaccessFiles() as $filename) {
            self::updateWebPDiagnosticsRules($filename, $routePaths, $remove);
        }
    }

    private static function rewriteWebPDiagnosticRuleLine($line, $routePaths, $remove, &$changed)
    {
        if (!preg_match(
            '/^(\s*RewriteRule\s+.*?\s)(\S+?)(\?[^\s]*)?(\s+\[[^\]\r\n]*\])(\r?\n)?$/i',
            $line,
            $matches
        )) {
            return $line;
        }

        $target = $matches[2];
        $route = false;
        if (preg_match('#(?:^|/)webp-on-demand\.php$#i', $target)) {
            $route = 'on-demand';
        } elseif (preg_match('#(?:^|/)webp-realizer\.php$#i', $target)) {
            $route = 'realizer';
        } elseif ($target == $routePaths['wrapper']) {
            if (preg_match('/E=WE_AVIF_WEBP_DIAG_ROUTE:(on-demand|realizer)/i', $matches[4], $routeMatch)) {
                $route = strtolower($routeMatch[1]);
            }
        }

        if ($route === false) {
            return $line;
        }

        $newTarget = $target;
        $flags = $matches[4];
        if ($remove) {
            if ($target == $routePaths['wrapper']) {
                $newTarget = $routePaths['core'][$route];
            } else {
                return $line;
            }
        } else {
            $newTarget = $routePaths['wrapper'];
        }

        $updatedFlags = preg_replace(
            '/,?E=WE_AVIF_WEBP_DIAG_ROUTE:(?:on-demand|realizer)/i',
            '',
            $flags
        );
        $updatedFlags = preg_replace('/,?E=WE_AVIF_WEBP_DIAG_HEADERS:1/i', '', $updatedFlags);
        $updatedFlags = str_replace('[,', '[', $updatedFlags);
        if (!$remove) {
            $closingBracket = strrpos($updatedFlags, ']');
            if ($closingBracket === false) {
                return $line;
            }
            $updatedFlags = substr($updatedFlags, 0, $closingBracket) .
                ',E=WE_AVIF_WEBP_DIAG_ROUTE:' . $route .
                ',E=WE_AVIF_WEBP_DIAG_HEADERS:1' .
                substr($updatedFlags, $closingBracket);
        }

        $updatedLine = $matches[1] . $newTarget .
            (isset($matches[3]) ? $matches[3] : '') .
            $updatedFlags .
            (isset($matches[5]) ? $matches[5] : '');
        if ($updatedLine != $line) {
            $changed = true;
        }
        return $updatedLine;
    }

    private static function updateWebPDiagnosticsRules($filename, $routePaths, $remove)
    {
        if (!is_file($filename)) {
            return true;
        }
        if (is_link($filename)) {
            error_log('[WebP Express AVIF] Refusing to update a symlinked WebP diagnostics rules file: ' . $filename);
            return false;
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            error_log('[WebP Express AVIF] Could not read WebP diagnostics rules file: ' . $filename);
            return false;
        }

        $diagnosticsBlockPattern = '/^# BEGIN WebP Express AVIF WebP Diagnostics\r?\n.*?^# END WebP Express AVIF WebP Diagnostics\r?\n?/ms';
        $contentWithoutDiagnostics = preg_replace($diagnosticsBlockPattern, '', $content, -1, $removedBlocks);
        if (!is_string($contentWithoutDiagnostics)) {
            error_log('[WebP Express AVIF] Could not parse WebP diagnostics rules in ' . $filename);
            return false;
        }

        $changed = ($contentWithoutDiagnostics !== $content);
        $coreBlockPattern = '/^# BEGIN WebP Express\r?\n.*?^# END WebP Express\r?\n?/ms';
        $updated = preg_replace_callback(
            $coreBlockPattern,
            function ($matches) use ($routePaths, $remove, &$changed) {
                $lines = preg_split('/(?<=\n)/', $matches[0]);
                foreach ($lines as $index => $line) {
                    $lines[$index] = self::rewriteWebPDiagnosticRuleLine(
                        $line,
                        $routePaths,
                        $remove,
                        $changed
                    );
                }
                return implode('', $lines);
            },
            $contentWithoutDiagnostics
        );
        if (!is_string($updated)) {
            error_log('[WebP Express AVIF] Could not update WebP Express rewrite rules in ' . $filename);
            return false;
        }

        if ($updated === $content) {
            return true;
        }
        if (file_put_contents($filename, $updated, LOCK_EX) === false) {
            error_log('[WebP Express AVIF] Could not write WebP diagnostics rules to ' . $filename);
            return false;
        }
        return true;
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
        $routePaths = self::webPDiagnosticRoutePaths();
        if ($routePaths !== false) {
            foreach (self::getWebPDiagnosticsHtaccessFiles() as $filename) {
                self::updateWebPDiagnosticsRules($filename, $routePaths, true);
            }
        }

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
            foreach ($rootIds as $rootId) {
                $sourceDirectory = \WebPExpress\Paths::getAbsDirById($rootId);
                if (is_string($sourceDirectory) && is_dir($sourceDirectory)) {
                    self::saveNegotiationRules($sourceDirectory . '/.htaccess', array());
                }
            }
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
        $flags = array_merge(array(
            'E=WE_DESTINATION_REL_HTACCESS:$1',
            'E=WE_HTACCESS_ID:' . $rootId,
        ), self::rewriteEnvironmentFlags($config));
        $flags[] = 'E=WE_AVIF_REQUEST_URI:%{REQUEST_URI}';
        $flags[] = 'NC';
        $flags[] = 'L';

        return array(
            '<IfModule mod_rewrite.c>',
            '  RewriteEngine On',
            '  RewriteCond %{REQUEST_FILENAME} -f',
            '  RewriteRule (?i)\.avif$ - [E=WE_AVIF_EXISTING:1,L]',
            '  RewriteCond %{REQUEST_FILENAME} !-f',
            '  RewriteRule (?i)^' . $extensionPattern . '$ ' . $realizerPath .
                ' [' . implode(',', $flags) . ']',
            '</IfModule>',
            '<IfModule mod_headers.c>',
            '  Header set X-WebP-Express-AVIF-Status "existing" env=WE_AVIF_EXISTING',
            '</IfModule>',
            '<IfModule mod_mime.c>',
            '  AddType image/avif .avif',
            '</IfModule>'
        );
    }

    private static function variedRewriteRulesFor($rootId, $realizerPath, $config)
    {
        $extensions = array();
        if (intval($config['image-types']) & 1) {
            $extensions[] = 'jpe?g';
        }
        if (intval($config['image-types']) & 2) {
            $extensions[] = 'png';
        }
        if (empty($extensions)) {
            return array();
        }

        $flags = array_merge(array(
            'E=WE_AVIF_MODE:varied',
            'E=WE_AVIF_SOURCE_REL:$0',
            'E=WE_AVIF_SOURCE_ROOT:' . $rootId,
            'E=WE_AVIF_ALLOW_ON_DEMAND:' .
                (!empty($config['enable-redirection-to-converter']) ? '1' : '0'),
        ), self::rewriteEnvironmentFlags($config));
        $flags[] = 'NC';
        $flags[] = 'L';

        return array(
            '<IfModule mod_rewrite.c>',
            '  RewriteEngine On',
            '  RewriteCond %{QUERY_STRING} !dontreplace$',
            '  RewriteCond %{QUERY_STRING} !original$',
            '  RewriteCond %{HTTP_ACCEPT} image/avif [NC]',
            '  RewriteCond %{REQUEST_FILENAME} -f',
            '  RewriteCond %{REQUEST_FILENAME} (?i)\.(?:' . implode('|', $extensions) . ')$',
            '  RewriteCond %{REQUEST_FILENAME}.dontreplace !-f',
            '  RewriteCond %{REQUEST_FILENAME}.do-not-convert !-f',
            '  RewriteRule ^.*$ ' . $realizerPath .
                ' [' . implode(',', $flags) . ']',
            '</IfModule>'
        );
    }

    private static function rewriteEnvironmentFlags($config)
    {
        $flags = array(
            'E=WE_AVIF_SCOPE:' . implode('|', $config['scope']),
            'E=WE_AVIF_IMAGE_TYPES:' . intval($config['image-types']),
            'E=WE_AVIF_PREVENT_LARGER:' . (!empty($config['prevent-using-webps-larger-than-original']) ? '1' : '0'),
            'E=HASH:' . \WebPExpress\Paths::getConfigHash()
        );
        $qualitySettings = self::getAvifQualitySettings();
        $flags[] = 'E=WE_AVIF_JPEG_QUALITY:' . $qualitySettings['jpeg_quality'];
        $flags[] = 'E=WE_AVIF_PNG_QUALITY:' . $qualitySettings['png_quality'];
        if (self::additionalHeadersEnabled()) {
            $flags[] = 'E=WE_AVIF_ADDITIONAL_HEADERS:1';
        }
        if (($config['destination-structure'] == 'doc-root') &&
            \WebPExpress\Paths::canUseDocRootForStructuringCacheDir()) {
            $flags[] = 'E=WPCONTENT:' . \WebPExpress\Paths::getContentDirRel();
        } else {
            $flags[] = 'E=WE_WP_CONTENT_REL_TO_WE_PLUGIN_DIR:' .
                \WebPExpress\Paths::getContentDirRelToWebPExpressPluginDir();
        }
        return $flags;
    }

    private static function saveNegotiationRules($filename, $rules)
    {
        $directory = dirname($filename);
        if (!is_dir($directory)) {
            error_log('[WebP Express AVIF] Cannot update negotiation rules in a missing directory: ' . $directory);
            return false;
        }
        if (is_link($filename)) {
            error_log('[WebP Express AVIF] Refusing to update a symlinked negotiation rules file: ' . $filename);
            return false;
        }

        if (is_file($filename)) {
            $content = file_get_contents($filename);
            if ($content === false) {
                error_log('[WebP Express AVIF] Could not read ' . $filename . ' to update negotiation rules.');
                return false;
            }
        } else {
            $content = '';
        }

        $markerPattern = '/^# BEGIN (WebP Express AVIF Negotiation|AVIF WebP Express Negotiation)\r?\n.*?^# END \1\r?\n?/ms';
        $contentWithoutRules = preg_replace($markerPattern, '', $content, -1, $removed);
        if (!is_string($contentWithoutRules)) {
            error_log('[WebP Express AVIF] Could not parse negotiation rules in ' . $filename);
            return false;
        }
        if (empty($rules) && ($removed === 0)) {
            return true;
        }

        $updated = $contentWithoutRules;
        if (!empty($rules)) {
            $block = "# BEGIN AVIF WebP Express Negotiation\n" .
                implode("\n", $rules) .
                "\n# END AVIF WebP Express Negotiation\n";
            if (preg_match('/^# BEGIN WebP Express\r?$/m', $contentWithoutRules, $matches, PREG_OFFSET_CAPTURE)) {
                $offset = $matches[0][1];
                $prefix = substr($contentWithoutRules, 0, $offset);
                if (($prefix != '') && !preg_match('/(?:\r\n|\r|\n)$/', $prefix)) {
                    $prefix .= "\n";
                }
                $updated = $prefix . $block . substr($contentWithoutRules, $offset);
            } else {
                $separator = ($contentWithoutRules == '' || preg_match('/(?:\r\n|\r|\n)$/', $contentWithoutRules))
                    ? ''
                    : "\n";
                $updated = $contentWithoutRules . $separator . $block;
            }
        }

        if ($updated === $content) {
            return true;
        }
        if (file_put_contents($filename, $updated, LOCK_EX) === false) {
            error_log('[WebP Express AVIF] Could not write negotiation rules in ' . $filename);
            return false;
        }
        return true;
    }

    private static function saveRewriteRules($filename, $rules, $createDirectory)
    {
        $directory = dirname($filename);
        if (empty($rules) && !is_file($filename)) {
            return true;
        }
        if (!is_dir($directory)) {
            if (!$createDirectory || !wp_mkdir_p($directory)) {
                error_log('[WebP Express AVIF] Could not create rewrite-rule directory: ' . $directory);
                return false;
            }
        }
        if (is_link($filename)) {
            error_log('[WebP Express AVIF] Refusing to update a symlinked rewrite rules file: ' . $filename);
            return false;
        }

        if (is_file($filename)) {
            $content = file_get_contents($filename);
            if ($content === false) {
                error_log('[WebP Express AVIF] Could not read ' . $filename . ' to update rewrite rules.');
                return false;
            }
        } else {
            $content = '';
        }

        $markerPattern = '/^# BEGIN (WebP Express AVIF|AVIF WebP Express Rewrite)\r?\n.*?^# END \1\r?\n?/ms';
        $contentWithoutRules = preg_replace($markerPattern, '', $content, -1, $removed);
        if (!is_string($contentWithoutRules)) {
            error_log('[WebP Express AVIF] Could not parse rewrite rules in ' . $filename);
            return false;
        }
        if (empty($rules) && ($removed === 0)) {
            return true;
        }

        $updated = $contentWithoutRules;
        if (!empty($rules)) {
            $block = "# BEGIN AVIF WebP Express Rewrite\n" .
                implode("\n", $rules) .
                "\n# END AVIF WebP Express Rewrite\n";
            if (preg_match('/^# BEGIN WebP Express\r?$/m', $contentWithoutRules, $matches, PREG_OFFSET_CAPTURE)) {
                $offset = $matches[0][1];
                $prefix = substr($contentWithoutRules, 0, $offset);
                if (($prefix != '') && !preg_match('/(?:\r\n|\r|\n)$/', $prefix)) {
                    $prefix .= "\n";
                }
                $updated = $prefix . $block . substr($contentWithoutRules, $offset);
            } else {
                $separator = ($contentWithoutRules == '' || preg_match('/(?:\r\n|\r|\n)$/', $contentWithoutRules))
                    ? ''
                    : "\n";
                $updated = $contentWithoutRules . $separator . $block;
            }
        }

        if ($updated === $content) {
            return true;
        }
        if (file_put_contents($filename, $updated, LOCK_EX) === false) {
            error_log('[WebP Express AVIF] Could not write Apache rewrite rules in ' . $filename);
            return false;
        }
        return true;
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
