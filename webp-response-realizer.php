<?php

class WebPExpressAvifWebPResponseDiagnostics
{
    private static function diagnosticsEnabled()
    {
        $envName = 'WE_AVIF_WEBP_DIAG_HEADERS';
        foreach ($_SERVER as $key => $value) {
            if ((substr($key, -strlen($envName)) == $envName) && $value === '1') {
                return true;
            }
        }
        return false;
    }

    public static function route()
    {
        $envName = 'WE_AVIF_WEBP_DIAG_ROUTE';
        foreach ($_SERVER as $key => $value) {
            if ((substr($key, -strlen($envName)) == $envName) &&
                is_string($value) &&
                in_array($value, array('on-demand', 'realizer'), true)) {
                return $value;
            }
        }
        return false;
    }

    public static function addHeaders($route)
    {
        if (!self::diagnosticsEnabled()) {
            return;
        }
        if (headers_sent($sentFile, $sentLine)) {
            error_log(
                '[WebP Express AVIF] Cannot add WebP diagnostics after headers were sent at ' .
                $sentFile . ':' . $sentLine
            );
            return;
        }

        $contentType = self::headerValue('Content-Type');
        if (!is_string($contentType) || stripos($contentType, 'image/webp') !== 0) {
            return;
        }

        try {
            $context = self::wodContext();
            $paths = self::sourceAndDestination($route, $context);
        } catch (\Throwable $e) {
            error_log('[WebP Express AVIF] Could not calculate WebP diagnostics: ' . $e->getMessage());
            return;
        }

        $source = $paths['source'];
        $destination = $paths['destination'];
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if ($extension == 'png') {
            $imageType = 'png';
        } elseif (in_array($extension, array('jpg', 'jpeg'), true)) {
            $imageType = 'jpeg';
        } else {
            $imageType = false;
        }
        $convertOptions = $context['options']['webp-convert']['convert'];
        $typeOptions = ($imageType !== false) &&
            isset($convertOptions[$imageType]) &&
            is_array($convertOptions[$imageType])
            ? $convertOptions[$imageType]
            : array();

        if (isset($typeOptions['quality'])) {
            $quality = $typeOptions['quality'];
            if (($quality === 'auto') ||
                (is_numeric($quality) && intval($quality) >= 0 && intval($quality) <= 100)) {
                header(
                    'X-WebP-Express-WebP-Quality: ' .
                    (($quality === 'auto') ? 'auto' : intval($quality)),
                    true
                );
            }
        }
        if (($imageType === 'png') &&
            isset($typeOptions['alpha-quality']) &&
            is_numeric($typeOptions['alpha-quality']) &&
            intval($typeOptions['alpha-quality']) >= 0 &&
            intval($typeOptions['alpha-quality']) <= 100) {
            header('X-WebP-Express-WebP-Alpha-Quality: ' . intval($typeOptions['alpha-quality']), true);
        }

        $sourceSize = is_file($source) ? @filesize($source) : false;
        $outputSize = is_file($destination) ? @filesize($destination) : false;
        if ($sourceSize !== false) {
            header('X-WebP-Express-WebP-Source-Bytes: ' . $sourceSize, true);
        } else {
            error_log('[WebP Express AVIF] Could not read the source size for a WebP response.');
        }
        if ($outputSize !== false) {
            header('X-WebP-Express-WebP-Output-Bytes: ' . $outputSize, true);
        } else {
            $contentLength = self::headerValue('Content-Length');
            if (is_string($contentLength) && ctype_digit($contentLength)) {
                $outputSize = intval($contentLength);
                header('X-WebP-Express-WebP-Output-Bytes: ' . $outputSize, true);
            } else {
                error_log('[WebP Express AVIF] Could not read the output size for a WebP response.');
            }
        }
        if (($sourceSize !== false) && ($sourceSize > 0) && ($outputSize !== false)) {
            $savings = (($sourceSize - $outputSize) / $sourceSize) * 100;
            header(
                'X-WebP-Express-WebP-Savings: ' . number_format($savings, 1, '.', '') . '%',
                true
            );
        }
    }

    private static function headerValue($name)
    {
        foreach (headers_list() as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return false;
    }

    private static function wodContext()
    {
        $reflection = new \ReflectionClass('\WebPExpress\WodConfigLoader');
        $context = array();
        foreach (array('options', 'wodOptions', 'usingDocRoot', 'webExpressContentDirAbs') as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $context[$propertyName] = $property->getValue();
        }
        if (!is_array($context['options']) ||
            !isset($context['options']['webp-convert']['convert']) ||
            !is_array($context['options']['webp-convert']['convert']) ||
            !is_array($context['wodOptions']) ||
            !isset($context['wodOptions']['image-roots'])) {
            throw new \RuntimeException('WebP Express did not load the expected conversion settings.');
        }
        return $context;
    }

    private static function invokePrivateStatic($className, $methodName)
    {
        $method = new \ReflectionMethod($className, $methodName);
        $method->setAccessible(true);
        return $method->invoke(null);
    }

    private static function sourceAndDestination($route, $context)
    {
        $wodOptions = $context['wodOptions'];
        $imageRoots = new \WebPExpress\ImageRoots($wodOptions['image-roots']);
        if ($route == 'on-demand') {
            $source = self::invokePrivateStatic('\WebPExpress\WebPOnDemand', 'getSource');
            if (!is_string($source) || $source == '') {
                throw new \RuntimeException('WebP Express did not expose a valid source path.');
            }
            $uploadRoot = $imageRoots->byId('uploads')->getAbsPath();
            $destination = \WebPExpress\ConvertHelperIndependent::getDestination(
                $source,
                $wodOptions['destination-folder'],
                $wodOptions['destination-extension'],
                $context['webExpressContentDirAbs'],
                $uploadRoot,
                $context['usingDocRoot'],
                $imageRoots
            );
        } else {
            $destination = self::invokePrivateStatic('\WebPExpress\WebPRealizer', 'getDestination');
            if (!is_string($destination) || $destination == '') {
                throw new \RuntimeException('WebP Express did not expose a valid destination path.');
            }
            $source = \WebPExpress\ConvertHelperIndependent::findSource(
                $destination,
                $wodOptions['destination-folder'],
                $wodOptions['destination-extension'],
                $context['usingDocRoot'] ? 'doc-root' : 'image-roots',
                $context['webExpressContentDirAbs'],
                $imageRoots
            );
        }

        if (!is_string($source) || $source == '' ||
            !is_string($destination) || $destination == '') {
            throw new \RuntimeException('WebP Express did not calculate valid source and destination paths.');
        }
        return array('source' => $source, 'destination' => $destination);
    }
}

$webpExpressWodDir = dirname(__DIR__) . '/webp-express/wod';
$webpExpressWodLoader = $webpExpressWodDir . '/autoloader.php';
if (!is_file($webpExpressWodLoader)) {
    error_log('[WebP Express AVIF] WebP Express WOD autoloader is unavailable.');
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'WebP Express is unavailable.';
    exit;
}

require_once $webpExpressWodLoader;
\WebPExpress\WodConfigLoader::preventDirectAccess(basename(__FILE__));

$webpRoute = WebPExpressAvifWebPResponseDiagnostics::route();
if ($webpRoute === false) {
    error_log('[WebP Express AVIF] The WebP diagnostics wrapper received no valid route.');
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid WebP diagnostics route.';
    exit;
}

$coreScriptName = ($webpRoute == 'on-demand') ? 'webp-on-demand.php' : 'webp-realizer.php';
if (!is_file($webpExpressWodDir . '/' . $coreScriptName)) {
    error_log('[WebP Express AVIF] WebP Express WOD script is unavailable: ' . $coreScriptName);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'WebP Express is unavailable.';
    exit;
}

if ($webpRoute == 'on-demand') {
    \WebPExpress\WebPOnDemand::preventDirectAccess($coreScriptName);
    $processRequest = array('\WebPExpress\WebPOnDemand', 'processRequest');
} else {
    \WebPExpress\WebPRealizer::preventDirectAccess($coreScriptName);
    $processRequest = array('\WebPExpress\WebPRealizer', 'processRequest');
}

$diagnosticsAdded = false;
$initialBufferLevel = ob_get_level();
$bufferStarted = ob_start(
    function ($buffer) use ($webpRoute, &$diagnosticsAdded) {
        if (!$diagnosticsAdded) {
            $diagnosticsAdded = true;
            WebPExpressAvifWebPResponseDiagnostics::addHeaders($webpRoute);
        }
        return $buffer;
    },
    8192
);
if (!$bufferStarted) {
    error_log('[WebP Express AVIF] Could not start output buffering for WebP diagnostics.');
}

call_user_func($processRequest);
while ($bufferStarted && ob_get_level() > $initialBufferLevel) {
    ob_end_flush();
}
