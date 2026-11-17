<?php

namespace WebPExpress;

$webpExpressAutoloader = dirname(__DIR__) . '/webp-express/wod/autoloader.php';
if (!is_file($webpExpressAutoloader)) {
    error_log('[WebP Express AVIF] WebP Express WOD autoloader was not found.');
    header('HTTP/1.1 500 Internal Server Error');
    exit('WebP Express AVIF could not load WebP Express.');
}

require_once $webpExpressAutoloader;

class AvifRealizer extends WodConfigLoader
{
    private static function removeSpoofableRewriteHeaders()
    {
        foreach (array_keys($_SERVER) as $name) {
            if (strpos($name, 'HTTP_') !== 0) {
                continue;
            }
            if (preg_match('/(?:^|_)(HASH|WPCONTENT|WE_WP_CONTENT_REL_TO_WE_PLUGIN_DIR|WE_WP_CONTENT_REL_TO_PLUGIN_DIR)$/', $name)) {
                unset($_SERVER[$name]);
            }
        }
    }

    private static function rewriteValue($name)
    {
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value) || (strpos($key, 'HTTP_') === 0)) {
                continue;
            }

            while (strpos($key, 'REDIRECT_') === 0 || strpos($key, 'REWRITE_') === 0) {
                if (strpos($key, 'REDIRECT_') === 0) {
                    $key = substr($key, 9);
                } else {
                    $key = substr($key, 7);
                }
            }

            if ($key == $name) {
                return $value;
            }
        }
        return false;
    }

    private static function noCache()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }

    private static function fail($message, $status = 404)
    {
        error_log('[WebP Express AVIF] ' . $message);
        self::noCache();
        header('X-WebP-Express-Error: AVIF realization failed');
        $protocol = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0';
        header($protocol . ' ' . intval($status) . (($status == 404) ? ' Not Found' : ' Internal Server Error'));
        exit('AVIF image could not be generated.');
    }

    private static function redirectToWebp($mingled)
    {
        $requestUri = self::rewriteValue('WE_AVIF_REQUEST_URI');
        if (!is_string($requestUri) || $requestUri == '') {
            $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        }
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('/\.avif$/i', $path) || preg_match('/[\r\n\x00-\x1f]/', $path)) {
            self::fail('Could not determine the original AVIF request URL.');
        }

        $webpPath = $mingled
            ? preg_replace('/\.avif$/i', '', $path)
            : preg_replace('/\.avif$/i', '.webp', $path);
        if (!is_string($webpPath) || strpos($webpPath, '/') !== 0 || strpos($webpPath, '//') === 0) {
            self::fail('Could not construct a safe WebP fallback URL.');
        }

        $query = parse_url($requestUri, PHP_URL_QUERY);
        if (is_string($query) && preg_match('/[\r\n\x00-\x1f]/', $query)) {
            self::fail('The WebP fallback URL contains invalid characters.');
        }
        $location = $webpPath . (is_string($query) && ($query != '') ? '?' . $query : '');
        self::noCache();
        header('Location: ' . $location, true, 302);
        exit;
    }

    private static function sourceIsInRequestedScope($source, $imageRoots, $scope)
    {
        $sourcePath = realpath($source);
        if ($sourcePath === false) {
            return false;
        }

        foreach ($imageRoots->getArray() as $imageRoot) {
            if (!in_array($imageRoot->id, $scope, true)) {
                continue;
            }
            $rootPath = realpath($imageRoot->getAbsPath());
            if ($rootPath === false) {
                continue;
            }
            $rootPrefix = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR;
            if (strpos($sourcePath, $rootPrefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function enabledImageType($source, $imageTypes)
    {
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (($extension == 'jpg') || ($extension == 'jpeg')) {
            return (bool)($imageTypes & 1);
        }
        if ($extension == 'png') {
            return (bool)($imageTypes & 2);
        }
        return false;
    }

    private static function findAvifEncoder($convertOptions)
    {
        if (!isset($convertOptions['converters']) || !is_array($convertOptions['converters'])) {
            return false;
        }

        foreach ($convertOptions['converters'] as $converter) {
            if (!is_array($converter) || !isset($converter['converter'])) {
                continue;
            }
            if (($converter['converter'] == 'imagick') &&
                class_exists('\Imagick') &&
                method_exists('\Imagick', 'queryFormats')) {
                try {
                    if (count(\Imagick::queryFormats('AVIF')) > 0) {
                        return $converter;
                    }
                } catch (\Throwable $e) {
                    error_log('[WebP Express AVIF] Imagick AVIF capability check failed: ' . $e->getMessage());
                }
            }
            if (($converter['converter'] == 'gd') && function_exists('imageavif')) {
                return $converter;
            }
        }
        return false;
    }

    private static function quality($source, $jpegQuality, $pngQuality)
    {
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        return ($extension == 'png') ? $pngQuality : $jpegQuality;
    }

    private static function encodeWithImagick($source, $temporaryFile, $quality, $metadata)
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
            return $image->writeImage($temporaryFile) &&
                is_file($temporaryFile) &&
                (filesize($temporaryFile) > 0);
        } finally {
            $image->clear();
            $image->destroy();
        }
    }

    private static function encodeWithGd($source, $temporaryFile, $quality)
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

    private static function writeAvif(
        $source,
        $destination,
        $convertOptions,
        $converter,
        $preventLarger,
        $jpegQuality,
        $pngQuality
    )
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            error_log('[WebP Express AVIF] Could not create destination directory: ' . $directory);
            return false;
        }

        $temporaryFile = tempnam($directory, '.webp-express-avif-');
        if ($temporaryFile === false || realpath(dirname($temporaryFile)) !== realpath($directory)) {
            if (is_string($temporaryFile) && is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
            error_log('[WebP Express AVIF] Could not create a temporary AVIF in: ' . $directory);
            return false;
        }

        try {
            $quality = self::quality($source, $jpegQuality, $pngQuality);
            $metadata = isset($convertOptions['metadata']) ? $convertOptions['metadata'] : 'none';
            if ($converter['converter'] == 'imagick') {
                $converted = self::encodeWithImagick($source, $temporaryFile, $quality, $metadata);
            } else {
                $converted = self::encodeWithGd($source, $temporaryFile, $quality);
            }
            if (!$converted) {
                return false;
            }
            if ($preventLarger && (filesize($temporaryFile) >= filesize($source))) {
                error_log('[WebP Express AVIF] Skipping AVIF that is larger than its source: ' . $destination);
                return false;
            }
            if (!@chmod($temporaryFile, 0644)) {
                error_log('[WebP Express AVIF] Could not set public read permissions on ' . $temporaryFile);
                return false;
            }
            if (!@rename($temporaryFile, $destination)) {
                error_log('[WebP Express AVIF] Could not move the converted file into place: ' . $destination);
                return false;
            }
            if (!@chmod($destination, 0644)) {
                error_log('[WebP Express AVIF] Could not set public read permissions on ' . $destination);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[WebP Express AVIF] AVIF conversion failed: ' . $e->getMessage());
            return false;
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    private static function serve($destination, $options)
    {
        if (!is_readable($destination)) {
            self::fail('Converted AVIF is not readable: ' . $destination);
        }
        self::noCache();
        header('Content-Type: image/avif');
        header('Content-Length: ' . filesize($destination));
        $modified = filemtime($destination);
        if ($modified !== false) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        }

        $serveOptions = isset($options['webp-convert']['serve-image'])
            ? $options['webp-convert']['serve-image']
            : array();
        if (!empty($serveOptions['headers']['cache-control']) &&
            isset($serveOptions['cache-control-header'])) {
            header('Cache-Control: ' . $serveOptions['cache-control-header']);
        }
        readfile($destination);
        exit;
    }

    public static function processRequest()
    {
        try {
            self::removeSpoofableRewriteHeaders();
            $htaccessId = self::rewriteValue('WE_HTACCESS_ID');
            $destinationRelative = self::rewriteValue('WE_DESTINATION_REL_HTACCESS');
            $scopeValue = self::rewriteValue('WE_AVIF_SCOPE');
            $imageTypes = intval(self::rewriteValue('WE_AVIF_IMAGE_TYPES'));
            $preventLarger = (self::rewriteValue('WE_AVIF_PREVENT_LARGER') == '1');
            $jpegQuality = self::rewriteValue('WE_AVIF_JPEG_QUALITY');
            $pngQuality = self::rewriteValue('WE_AVIF_PNG_QUALITY');
            $jpegQuality = (is_string($jpegQuality) && ctype_digit($jpegQuality) && intval($jpegQuality) <= 100)
                ? intval($jpegQuality)
                : 50;
            $pngQuality = (is_string($pngQuality) && ctype_digit($pngQuality) && intval($pngQuality) <= 100)
                ? intval($pngQuality)
                : 50;

            if (!in_array($htaccessId, array('cache', 'uploads'), true) ||
                !is_string($destinationRelative) ||
                !is_string($scopeValue)) {
                self::fail('Required rewrite settings were not supplied.');
            }

            $destinationRelative = rawurldecode($destinationRelative);
            $destinationRelative = SanityCheck::pathWithoutDirectoryTraversal($destinationRelative);
            if (strpos($destinationRelative, '\\') !== false ||
                strpos($destinationRelative, "\0") !== false ||
                !preg_match('/\.avif$/i', $destinationRelative)) {
                self::fail('The requested AVIF destination is invalid.');
            }

            self::loadConfig();
            $wodOptions = self::$wodOptions;
            $options = self::$options;
            $convertOptions = $options['webp-convert']['convert'];
            if (!isset($wodOptions['image-roots'])) {
                self::fail('WebP Express image roots are not configured.');
            }

            $scope = array_filter(explode('|', $scopeValue), function ($rootId) {
                return preg_match('/^[a-z0-9-]+$/i', $rootId);
            });
            $imageRoots = self::getImageRootsDef();
            if ($htaccessId == 'uploads') {
                if (($wodOptions['destination-folder'] != 'mingled') ||
                    !in_array('uploads', $scope, true) ||
                    !preg_match('/\.webp\.avif$/i', $destinationRelative)) {
                    self::fail('The uploads AVIF destination is not enabled.');
                }
                $destination = $imageRoots->byId('uploads')->getAbsPath() . '/' . $destinationRelative;
            } else {
                $destination = $imageRoots->byId('wp-content')->getAbsPath() .
                    '/webp-express/webp-images/' . $destinationRelative;
            }

            $webpDestination = ($wodOptions['destination-folder'] == 'mingled')
                ? preg_replace('/\.avif$/i', '', $destination)
                : preg_replace('/\.avif$/i', '.webp', $destination);
            $destinationStructure = self::$usingDocRoot ? 'doc-root' : 'image-roots';
            $source = ConvertHelperIndependent::findSource(
                $webpDestination,
                $wodOptions['destination-folder'],
                $wodOptions['destination-extension'],
                $destinationStructure,
                self::$webExpressContentDirAbs,
                $imageRoots
            );
            if (!is_string($source) ||
                !self::sourceIsInRequestedScope($source, $imageRoots, $scope) ||
                !self::enabledImageType($source, $imageTypes)) {
                self::redirectToWebp($wodOptions['destination-folder'] == 'mingled');
            }

            if (is_file($source . '.do-not-convert') || is_file($source . '.dontreplace')) {
                self::redirectToWebp($wodOptions['destination-folder'] == 'mingled');
            }

            if (is_file($destination)) {
                $destinationSize = filesize($destination);
                $sourceSize = filesize($source);
                if ($preventLarger && ($destinationSize !== false) && ($sourceSize !== false) &&
                    ($destinationSize >= $sourceSize)) {
                    error_log('[WebP Express AVIF] Removing cached AVIF that is larger than its source: ' . $destination);
                    if (!@unlink($destination)) {
                        self::redirectToWebp($wodOptions['destination-folder'] == 'mingled');
                    }
                } else {
                    self::serve($destination, $options);
                }
            }

            $converter = self::findAvifEncoder($convertOptions);
            if ($converter === false ||
                !self::writeAvif(
                    $source,
                    $destination,
                    $convertOptions,
                    $converter,
                    $preventLarger,
                    $jpegQuality,
                    $pngQuality
                )) {
                self::redirectToWebp($wodOptions['destination-folder'] == 'mingled');
            }
            self::serve($destination, $options);
        } catch (\Throwable $e) {
            self::fail('Realizer error: ' . $e->getMessage(), 500);
        }
    }
}

AvifRealizer::processRequest();
