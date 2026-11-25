<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class FileManager_Converter
{

    const MAX_MARK_PIXELS_GD = 12000000;

    const MAX_MARK_PIXELS_IMAGICK = 40000000;

    const FADE_PIXELS = 2000000;

    const PALETTE_PIXELS = 4000000;

    const POSITIONS = array(
        'top-left', 'top-center', 'top-right',
        'middle-left', 'center', 'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right'
    );

    private static $marked = false;

    public static function engine(): string
    {
        if (class_exists('Imagick')) {
            try {
                if (Imagick::queryFormats('WEBP')) {
                    return 'imagick';
                }
            } catch (Throwable $e) {
            }
        }

        if (function_exists('imagewebp')) {
            return 'gd';
        }

        return '';
    }

    public static function probe(array $cfg = array()): array
    {
        $probe = array(
            'engine' => self::engine(),
            'imagick' => false,
            'imagickVersion' => '',
            'magickVersion' => '',
            'imagickWebp' => false,
            'gd' => false,
            'gdVersion' => '',
            'gdWebp' => false,
            'gdFreetype' => false,
            'memory' => '',
            'memoryLow' => false,
            'fonts' => 0,
            'marks' => 0,
            'fontWritable' => false,
            'imageWritable' => false,
            'fontName' => '',
            'imageName' => '',
            'font' => '',
            'image' => ''
        );

        if (class_exists('Imagick')) {
            $probe['imagick'] = true;
            $probe['imagickVersion'] = (string)phpversion('imagick');

            try {
                if (method_exists('Imagick', 'getVersion')) {
                    $version = Imagick::getVersion();
                    $string = isset($version['versionString']) ? (string)$version['versionString'] : '';

                    if (preg_match('~ImageMagick\s+([^\s]+)~i', $string, $match)) {
                        $probe['magickVersion'] = $match[1];
                    }
                }
            } catch (Throwable $e) {
            }

            try {
                $probe['imagickWebp'] = (bool)Imagick::queryFormats('WEBP');
            } catch (Throwable $e) {
            }
        }

        if (extension_loaded('gd')) {
            $probe['gd'] = true;
            $info = function_exists('gd_info') ? gd_info() : array();

            if (isset($info['GD Version']) && preg_match('~([\d.]+)~', (string)$info['GD Version'], $match)) {
                $probe['gdVersion'] = $match[1];
            }

            $probe['gdWebp'] = !empty($info['WebP Support']);
            $probe['gdFreetype'] = !empty($info['FreeType Support']) && function_exists('imagettftext');
        }

        $probe['memory'] = self::memoryText();
        $probe['memoryLow'] = self::memoryLow();

        $fontDir = __DIR__ . '/ttf';
        $imageDir = __DIR__ . '/img';

        $probe['fonts'] = count(self::assets($fontDir, array('ttf', 'ttc', 'otf')));
        $probe['marks'] = count(self::assets($imageDir, array('png', 'jpg', 'jpeg', 'gif', 'webp')));
        $probe['fontWritable'] = self::writable($fontDir);
        $probe['imageWritable'] = self::writable($imageDir);
        $probe['fontName'] = basename(trim((string)(isset($cfg['wm_font']) ? $cfg['wm_font'] : '')));
        $probe['imageName'] = basename(trim((string)(isset($cfg['wm_image']) ? $cfg['wm_image'] : '')));
        $probe['font'] = self::assetState($fontDir, $probe['fontName']);
        $probe['image'] = self::assetState($imageDir, $probe['imageName']);

        return $probe;
    }

    public static function selftest(array $cfg): array
    {
        $engine = self::engine();

        if ('' === $engine) {
            return array('ok' => false, 'bytes' => 0, 'message' => '没有可用的图像引擎');
        }

        $cfg['watermark'] = '0';

        $src = @tempnam(sys_get_temp_dir(), 'wcprobe');

        if (false === $src || '' === $src) {
            return array('ok' => false, 'bytes' => 0, 'message' => '临时目录不可写');
        }

        $dst = $src . '.webp';

        try {
            if (!self::probeImage($src, $engine)) {
                return array('ok' => false, 'bytes' => 0, 'message' => '测试图写不出来');
            }

            if (!self::convert($src, $dst, IMAGETYPE_PNG, $cfg, $engine) || !is_file($dst)) {
                return array('ok' => false, 'bytes' => 0, 'message' => 'WebP 编码失败');
            }

            $head = (string)@file_get_contents($dst, false, null, 0, 12);

            if (0 !== strpos($head, 'RIFF') || 'WEBP' !== substr($head, 8, 4)) {
                return array('ok' => false, 'bytes' => 0, 'message' => '产物不是 WebP');
            }

            return array('ok' => true, 'bytes' => (int)@filesize($dst), 'message' => '');
        } catch (Throwable $e) {
            return array('ok' => false, 'bytes' => 0, 'message' => '检测中断');
        } finally {
            @unlink($src);
            @unlink($dst);
        }
    }

    private static function probeImage(string $dst, string $engine): bool
    {
        try {
            if ('imagick' === $engine) {
                $image = new Imagick();
                $image->newImage(64, 64, new ImagickPixel('#336699'), 'png');
                $done = $image->writeImage($dst);
                $image->clear();
                $image->destroy();

                return (bool)$done;
            }

            $image = @imagecreatetruecolor(64, 64);

            if (!$image) {
                return false;
            }

            imagefill($image, 0, 0, imagecolorallocate($image, 51, 102, 153));
            $done = imagepng($image, $dst);
            @imagedestroy($image);

            return (bool)$done;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function memoryText(): string
    {
        $value = trim((string)ini_get('memory_limit'));

        if ('' === $value) {
            return '未知';
        }

        return '-1' === $value ? '不限' : strtoupper($value);
    }

    private static function memoryLow(): bool
    {
        $bytes = self::bytesOf((string)ini_get('memory_limit'));

        return $bytes > 0 && $bytes < 134217728;
    }

    private static function bytesOf(string $value): int
    {
        $value = trim($value);

        if ('' === $value || '-1' === $value) {
            return 0;
        }

        $number = (int)$value;

        switch (strtolower(substr($value, -1))) {
            case 'g':
                return $number * 1073741824;
            case 'm':
                return $number * 1048576;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    public static function assets(string $dir, array $exts): array
    {
        $items = @scandir($dir);
        $list = array();

        if (!$items) {
            return $list;
        }

        foreach ($items as $item) {
            if ('.' === $item[0]) {
                continue;
            }

            $ext = strtolower((string)pathinfo($item, PATHINFO_EXTENSION));

            if (in_array($ext, $exts, true) && is_file($dir . '/' . $item)) {
                $list[] = $item;
            }
        }

        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    private static function writable(string $dir): bool
    {
        return is_dir($dir) ? is_writable($dir) : is_writable(__DIR__);
    }

    private static function assetState(string $dir, $name): string
    {
        $name = basename(trim((string)$name));

        if ('' === $name) {
            return '';
        }

        return is_file($dir . '/' . $name) ? 'ok' : 'missing';
    }

    public static function convertible(string $src, int $type, string $engine, bool $watermark = false, string $target = 'webp'): bool
    {
        if ('' === $engine || '' === $src || !is_file($src)) {
            return false;
        }

        $loaders = array(
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_BMP => 'imagecreatefrombmp',
            IMAGETYPE_WEBP => 'imagecreatefromwebp'
        );

        if (!isset($loaders[$type])) {
            $ext = @image_type_to_extension($type, false);

            self::log('跳过转换：' . ($ext ? $ext : 'type ' . $type) . ' 不在处理范围内，原图保留');

            return false;
        }

        if (IMAGETYPE_WEBP === $type && 'webp' === $target && !$watermark) {
            self::log('跳过转换：webp 且未开水印，原图保留');

            return false;
        }

        if ('gd' === $engine && !function_exists($loaders[$type])) {
            return false;
        }

        $info = @getimagesize($src);

        if (!$info || $info[0] < 1 || $info[1] < 1) {
            return false;
        }

        return true;
    }

    public static function convert(string $src, string $dst, int $type, array $cfg, string $engine): bool
    {
        return self::write($src, $dst, $type, $cfg, $engine, 'webp', false);
    }

    public static function convertTo(string $src, string $dst, int $type, array $cfg, string $engine, string $format): bool
    {
        return self::write($src, $dst, $type, $cfg, $engine, $format, false);
    }

    public static function reencode(string $src, string $dst, int $type, array $cfg, string $engine): bool
    {
        return self::write($src, $dst, $type, $cfg, $engine, 'auto', true);
    }

    public static function watermarkReady(array $cfg): bool
    {
        try {
            if ('image' === (isset($cfg['wm_type']) ? $cfg['wm_type'] : 'text')) {
                return '' !== self::asset('img', isset($cfg['wm_image']) ? $cfg['wm_image'] : '');
            }

            return !empty(self::watermarkLines($cfg))
                && '' !== self::asset('ttf', isset($cfg['wm_font']) ? $cfg['wm_font'] : '');
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function keepFormat(int $type): string
    {
        switch ($type) {
            case IMAGETYPE_PNG:
                return 'png';
            case IMAGETYPE_JPEG:
                return 'jpg';
            case IMAGETYPE_WEBP:
                return 'webp';
            default:
                return '';
        }
    }

    private static function write(string $src, string $dst, int $type, array $cfg, string $engine, string $format, bool $requireMark): bool
    {
        if ('' === $engine) {
            return false;
        }

        $target = 'auto' === $format ? self::keepFormat($type) : $format;

        if ('' === $target) {
            return false;
        }

        self::$marked = false;

        try {
            if ('imagick' === $engine) {
                $image = self::imagickImage($src, $cfg);

                if (!$image) {
                    return false;
                }

                if ($requireMark && !self::$marked) {
                    self::log('水印没画上，' . $target . ' 不做重编码，保持原图');

                    $image->clear();
                    $image->destroy();

                    return false;
                }

                $done = self::imagickEncode($image, $dst, $target, $cfg);

                $image->clear();
                $image->destroy();

                return $done;
            }

            $image = self::gdImage($src, $type, $cfg);

            if (!$image) {
                return false;
            }

            if ($requireMark && !self::$marked) {
                self::log('水印没画上，' . $target . ' 不做重编码，保持原图');

                @imagedestroy($image);

                return false;
            }

            $done = self::gdEncode($image, $dst, $target, $cfg);

            @imagedestroy($image);

            return $done;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function imagickImage(string $src, array $cfg)
    {
        $image = new Imagick();
        $image->readImage($src);

        if (method_exists($image, 'autoOrient')) {
            try {
                $image->autoOrient();
            } catch (Throwable $e) {
            }
        }

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $ratio = self::ratio($width, $height, $cfg);

        if ($ratio < 1) {
            $image->resizeImage(
                max(1, (int)round($width * $ratio)),
                max(1, (int)round($height * $ratio)),
                Imagick::FILTER_LANCZOS,
                1
            );
        }

        self::$marked = self::imagickWatermark($image, $cfg);

        return $image;
    }

    private static function imagickEncode($image, string $dst, string $format, array $cfg): bool
    {
        $image->setImageCompressionQuality(self::range($cfg['quality'], 1, 100, 90));

        if ('png' === $format) {
            $image->setImageFormat('png');
            /* level 9 编 10MP 要十几秒，容易撞执行时限；level 6 体积只大 2~3% */
            $image->setOption('png:compression-level', '6');
        } elseif ('jpg' === $format) {
            if ($image->getImageAlphaChannel()) {
                $image->setImageBackgroundColor(new ImagickPixel('#ffffff'));
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            }

            $image->setImageFormat('jpeg');
        } else {
            $image->setImageFormat('webp');
        }

        return (bool)$image->writeImage($dst);
    }

    private static function gdImage(string $src, int $type, array $cfg)
    {
        $image = self::gdLoad($src, $type);

        if (!$image) {
            return false;
        }

        $image = self::gdOrient($image, $src, $type);

        $ratio = self::ratio(imagesx($image), imagesy($image), $cfg);

        if ($ratio < 1) {
            $image = self::gdScale($image, $ratio);
        }

        self::$marked = self::gdWatermark($image, $cfg);

        return $image;
    }

    private static function gdEncode($image, string $dst, string $format, array $cfg): bool
    {
        $quality = self::range($cfg['quality'], 1, 100, 90);

        if ('jpg' === $format) {
            $flat = self::gdFlat($image);
            $done = (bool)@imagejpeg($flat, $dst, $quality);

            @imagedestroy($flat);

            return $done;
        }

        @imagealphablending($image, false);
        @imagesavealpha($image, true);

        if ('png' === $format) {
            return self::gdPng($image, $dst);
        }

        return (bool)@imagewebp($image, $dst, $quality);
    }

    private static function gdFlat($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        return $canvas;
    }

    /** PNG 输出直接落盘：旧实现把整张编进内存字符串，大图会顶爆 memory_limit；压缩级别 9 → 6 */
    private static function gdPng($image, string $dst): bool
    {
        @imagealphablending($image, false);
        @imagesavealpha($image, true);

        if (!@imagepng($image, $dst, 6)) {
            @unlink($dst);

            return false;
        }

        $plainSize = (int) @filesize($dst);

        if ($plainSize < 1) {
            @unlink($dst);

            return false;
        }

        $palette = self::gdPalette($image);

        if (!$palette) {
            return true;
        }

        $tmp = $dst . '.pal.png';
        $done = @imagepng($palette, $tmp, 6);

        @imagedestroy($palette);

        $smallSize = $done ? (int) @filesize($tmp) : 0;

        if ($smallSize > 0 && $smallSize < $plainSize) {
            @unlink($dst);

            if (!@rename($tmp, $dst)) {
                @copy($tmp, $dst);
                @unlink($tmp);
            }

            self::log('PNG 改用调色板输出：' . $plainSize . ' → ' . $smallSize . ' 字节');

            return true;
        }

        @unlink($tmp);

        return true;
    }

    private static function gdPalette($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width * $height > self::PALETTE_PIXELS) {
            return false;
        }

        $canvas = imagecreate($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $colors = array();

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $raw = imagecolorat($image, $x, $y);

                if (!isset($colors[$raw])) {
                    if (count($colors) >= 256) {
                        @imagedestroy($canvas);

                        return false;
                    }

                    $colors[$raw] = imagecolorallocatealpha(
                        $canvas,
                        ($raw >> 16) & 0xFF,
                        ($raw >> 8) & 0xFF,
                        $raw & 0xFF,
                        ($raw >> 24) & 0x7F
                    );
                }

                imagesetpixel($canvas, $x, $y, $colors[$raw]);
            }
        }

        return $canvas;
    }

    private static function gdLoad(string $src, int $type)
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                $image = @imagecreatefromjpeg($src);
                break;
            case IMAGETYPE_PNG:
                $image = @imagecreatefrompng($src);
                break;
            case IMAGETYPE_BMP:
                $image = @imagecreatefrombmp($src);
                break;
            case IMAGETYPE_WEBP:
                $image = @imagecreatefromwebp($src);
                break;
            default:
                $image = false;
        }

        if (!$image) {
            return false;
        }

        if (function_exists('imagepalettetotruecolor') && !imageistruecolor($image)) {
            @imagepalettetotruecolor($image);
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    private static function gdOrient($image, string $src, int $type)
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        if (IMAGETYPE_JPEG !== $type && IMAGETYPE_TIFF_II !== $type && IMAGETYPE_TIFF_MM !== $type) {
            return $image;
        }

        $exif = @exif_read_data($src);

        if (empty($exif['Orientation'])) {
            return $image;
        }

        switch ((int)$exif['Orientation']) {
            case 3:
                $angle = 180;
                break;
            case 6:
                $angle = -90;
                break;
            case 8:
                $angle = 90;
                break;
            default:
                return $image;
        }

        $rotated = @imagerotate($image, $angle, 0);

        if (!$rotated) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private static function gdScale($image, float $ratio)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $newWidth = max(1, (int)round($width * $ratio));
        $newHeight = max(1, (int)round($height * $ratio));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        imagedestroy($image);

        return $canvas;
    }

    private static function imagickWatermark($image, array $cfg): bool
    {
        if ('1' != $cfg['watermark']) {
            return false;
        }

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();

        if (!self::watermarkAllowed($width, $height, $cfg)) {
            self::log('水印未添加：图片 ' . $width . 'x' . $height . ' 低于设定的最小尺寸');

            return false;
        }

        $margin = self::number($cfg['wm_margin'], 20, 0);
        $opacity = self::range($cfg['wm_opacity'], 0, 100, 70);
        $angle = self::degrees($cfg['wm_angle']);

        if ('image' === $cfg['wm_type']) {
            return self::imagickMark($image, $cfg, $width, $height, $margin, $opacity, $angle);
        }

        $lines = self::watermarkLines($cfg);
        $font = self::asset('ttf', $cfg['wm_font']);

        if (empty($lines)) {
            self::log('水印未添加：水印文字为空，且站点标题也是空的');

            return false;
        }

        if ('' === $font) {
            return false;
        }

        try {
            $size = self::fontSize($cfg['wm_size'], $width);

            if (!self::drawableLines('imagick', $font, $size, $lines)) {
                self::log('水印未添加：' . basename($font) . ' 里没有这些字的字形，换个中文字体');

                return false;
            }

            $draw = new ImagickDraw();
            $draw->setFont($font);
            $draw->setFontSize($size);

            $color = new ImagickPixel(self::color($cfg));
            $color->setColorValue(Imagick::COLOR_ALPHA, $opacity / 100);
            $draw->setFillColor($color);

            if (1 === count($lines)) {
                $metrics = $image->queryFontMetrics($draw, $lines[0]);
                $textWidth = (int)ceil($metrics['textWidth']);
                $textHeight = (int)ceil($metrics['textHeight']);
                $baseline = isset($metrics['ascender']) ? (int)ceil($metrics['ascender']) : $textHeight;

                $offset = self::offset($width, $height, $textWidth, $textHeight, $cfg['wm_pos'], $margin);

                $image->annotateImage($draw, $offset[0], $offset[1] + $baseline, $angle, $lines[0]);
            } else {

                $block = self::imagickTextBlock($image, $draw, $lines, $angle);
                $blockWidth = $block->getImageWidth();
                $blockHeight = $block->getImageHeight();
                $offset = self::offset($width, $height, $blockWidth, $blockHeight, $cfg['wm_pos'], $margin);

                $image->compositeImage($block, Imagick::COMPOSITE_OVER, $offset[0], $offset[1]);

                $block->clear();
                $block->destroy();
            }

            self::log('水印已添加：imagick 文字 ' . count($lines) . ' 行 / ' . basename($font) . ' '
                . $size . 'px / ' . self::color($cfg) . ' / ' . implode(' / ', $lines));

            return true;
        } catch (Throwable $e) {
            self::log('水印未添加：' . $e->getMessage());

            return false;
        }
    }

    private static function imagickTextBlock($image, ImagickDraw $draw, array $lines, float $angle)
    {
        $blocks = array();
        $lineHeight = 0;
        $blockWidth = 0;

        foreach ($lines as $line) {
            $metrics = $image->queryFontMetrics($draw, $line);
            $width = (int)ceil($metrics['textWidth']);
            $height = (int)ceil($metrics['textHeight']);
            $baseline = isset($metrics['ascender']) ? (int)ceil($metrics['ascender']) : $height;

            $blocks[] = array($baseline, $width, $height);
            $blockWidth = max($blockWidth, $width);
            $lineHeight = max($lineHeight, $height);
        }

        $gap = (int)round($lineHeight * 0.2);
        $blockHeight = $lineHeight * count($lines) + $gap * (count($lines) - 1);

        $canvas = new Imagick();
        $canvas->newImage(max(1, $blockWidth), max(1, $blockHeight), new ImagickPixel('none'));
        $canvas->setImageFormat('png');

        foreach ($lines as $index => $line) {
            $canvas->annotateImage($draw, 0, $index * ($lineHeight + $gap) + $blocks[$index][0], 0, $line);
        }

        if (0 != $angle) {
            $canvas->rotateImage(new ImagickPixel('none'), $angle);
        }

        return $canvas;
    }

    private static function imagickMark($image, array $cfg, int $width, int $height, int $margin, int $opacity, float $angle): bool
    {
        $file = self::asset('img', $cfg['wm_image']);

        if ('' === $file) {
            self::log('水印未添加：img 目录里没有可用的水印图片');

            return false;
        }

        if (!self::markReadable($file, self::MAX_MARK_PIXELS_IMAGICK)) {
            self::log('水印未添加：水印图片太大或读不出尺寸 ' . basename($file));

            return false;
        }

        try {
            $mark = new Imagick($file);
            $scale = self::markScale($width, $height, $mark->getImageWidth(), $mark->getImageHeight());

            if ($scale < 1) {
                $mark->resizeImage(
                    max(1, (int)round($mark->getImageWidth() * $scale)),
                    max(1, (int)round($mark->getImageHeight() * $scale)),
                    Imagick::FILTER_LANCZOS,
                    1
                );
            }

            if (0 != $angle) {
                $mark->rotateImage(new ImagickPixel('none'), $angle);
            }

            if ($opacity < 100) {
                if (!$mark->getImageAlphaChannel()) {
                    $mark->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
                }

                $mark->evaluateImage(Imagick::EVALUATE_MULTIPLY, max(0.01, $opacity / 100), Imagick::CHANNEL_ALPHA);
            }

            $markWidth = $mark->getImageWidth();
            $markHeight = $mark->getImageHeight();
            $offset = self::offset($width, $height, $markWidth, $markHeight, $cfg['wm_pos'], $margin);

            $image->compositeImage($mark, Imagick::COMPOSITE_OVER, $offset[0], $offset[1]);

            $mark->clear();
            $mark->destroy();

            self::log('水印已添加：imagick 图片 / ' . basename($file) . ' ' . $markWidth . 'x' . $markHeight
                . ' / 不透明度 ' . $opacity);

            return true;
        } catch (Throwable $e) {
            self::log('水印未添加：' . $e->getMessage());

            return false;
        }
    }

    private static function gdWatermark($image, array $cfg): bool
    {
        if ('1' != $cfg['watermark']) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if (!self::watermarkAllowed($width, $height, $cfg)) {
            self::log('水印未添加：图片 ' . $width . 'x' . $height . ' 低于设定的最小尺寸');

            return false;
        }

        $margin = self::number($cfg['wm_margin'], 20, 0);
        $opacity = self::range($cfg['wm_opacity'], 0, 100, 70);
        $angle = (int)self::degrees($cfg['wm_angle']);

        if ('image' === $cfg['wm_type']) {
            return self::gdMark($image, $cfg, $width, $height, $margin, $opacity, $angle);
        }

        $lines = self::watermarkLines($cfg);
        $font = self::asset('ttf', $cfg['wm_font']);

        if (empty($lines)) {
            self::log('水印未添加：水印文字为空，且站点标题也是空的');

            return false;
        }

        if (!function_exists('imagettftext')) {
            self::log('水印未添加：GD 未启用 freetype');

            return false;
        }

        if ('' === $font) {
            return false;
        }

        $size = self::fontSize($cfg['wm_size'], $width);

        if (!self::drawableLines('gd', $font, $size, $lines)) {
            self::log('水印未添加：' . basename($font) . ' 里没有这些字的字形，换个中文字体');

            return false;
        }

        if (1 === count($lines)) {
            $box = @imagettfbbox($size, $angle, $font, $lines[0]);

            if (!$box) {
                self::log('水印未添加：字体解析不了该文字（' . basename($font) . '）');

                return false;
            }

            $left = min($box[0], $box[2], $box[4], $box[6]);
            $right = max($box[0], $box[2], $box[4], $box[6]);
            $top = min($box[1], $box[3], $box[5], $box[7]);
            $bottom = max($box[1], $box[3], $box[5], $box[7]);

            $textWidth = $right - $left;
            $textHeight = $bottom - $top;

            if ($textWidth < 1 || $textHeight < 1) {
                self::log('水印未添加：文字排版尺寸为 0（' . basename($font) . '）');

                return false;
            }

            $canvas = imagecreatetruecolor($textWidth, $textHeight);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

            $alpha = (int)round(127 - 127 * $opacity / 100);
            $rgb = self::rgb(self::color($cfg));
            $color = imagecolorallocatealpha($canvas, $rgb[0], $rgb[1], $rgb[2], $alpha);

            @imagettftext($canvas, $size, $angle, -$left, -$top, $color, $font, $lines[0]);
        } else {

            $canvas = self::gdTextBlock($font, $size, $lines, $angle, $opacity, self::color($cfg));

            if (!$canvas) {
                return false;
            }
        }

        $canvasWidth = imagesx($canvas);
        $canvasHeight = imagesy($canvas);
        $offset = self::offset($width, $height, $canvasWidth, $canvasHeight, $cfg['wm_pos'], $margin);

        imagealphablending($image, true);
        @imagecopy($image, $canvas, $offset[0], $offset[1], 0, 0, $canvasWidth, $canvasHeight);

        imagedestroy($canvas);

        self::log('水印已添加：gd 文字 ' . count($lines) . ' 行 / ' . basename($font) . ' '
            . $size . 'px / ' . self::color($cfg) . ' / ' . implode(' / ', $lines));

        return true;
    }

    private static function gdTextBlock(string $font, int $size, array $lines, float $angle, int $opacity, string $hex)
    {
        $boxes = array();
        $lineHeight = 0;
        $blockWidth = 0;

        foreach ($lines as $line) {
            $box = @imagettfbbox($size, 0, $font, $line);

            if (!$box) {
                self::log('水印未添加：字体解析不了该文字（' . basename($font) . '）');

                return false;
            }

            $left = (int)min($box[0], $box[2], $box[4], $box[6]);
            $right = (int)max($box[0], $box[2], $box[4], $box[6]);
            $top = (int)min($box[1], $box[3], $box[5], $box[7]);
            $bottom = (int)max($box[1], $box[3], $box[5], $box[7]);

            $lineWidth = $right - $left;
            $textHeight = $bottom - $top;

            if ($lineWidth < 1 || $textHeight < 1) {
                self::log('水印未添加：文字排版尺寸为 0（' . basename($font) . '）');

                return false;
            }

            $boxes[] = array($left, $top);
            $blockWidth = max($blockWidth, $lineWidth);
            $lineHeight = max($lineHeight, $textHeight);
        }

        $gap = (int)round($lineHeight * 0.2);
        $blockHeight = $lineHeight * count($lines) + $gap * (count($lines) - 1);

        $canvas = imagecreatetruecolor($blockWidth, $blockHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        $alpha = (int)round(127 - 127 * $opacity / 100);
        $rgb = self::rgb($hex);
        $color = imagecolorallocatealpha($canvas, $rgb[0], $rgb[1], $rgb[2], $alpha);

        foreach ($lines as $index => $line) {
            @imagettftext($canvas, $size, 0, -$boxes[$index][0], $index * ($lineHeight + $gap) - $boxes[$index][1], $color, $font, $line);
        }

        if (0 != $angle) {
            $canvas = self::gdRotateMark($canvas, $angle);
        }

        return $canvas;
    }

    private static function gdMark($image, array $cfg, int $width, int $height, int $margin, int $opacity, float $angle): bool
    {
        $file = self::asset('img', $cfg['wm_image']);

        if ('' === $file) {
            self::log('水印未添加：img 目录里没有可用的水印图片');

            return false;
        }

        if (!self::markReadable($file, self::MAX_MARK_PIXELS_GD)) {
            self::log('水印未添加：水印图片太大或读不出尺寸 ' . basename($file));

            return false;
        }

        $mark = @imagecreatefromstring((string)@file_get_contents($file));

        if (!$mark) {
            self::log('水印未添加：水印图片读不出来 ' . basename($file));

            return false;
        }

        if (function_exists('imagepalettetotruecolor') && !imageistruecolor($mark)) {
            @imagepalettetotruecolor($mark);
        }

        $scale = self::markScale($width, $height, imagesx($mark), imagesy($mark));

        if ($scale < 1) {
            $mark = self::gdScale($mark, $scale);
        }

        if (0 != $angle) {
            $mark = self::gdRotateMark($mark, $angle);
        }

        if ($opacity < 100) {
            $mark = self::gdFade($mark, $opacity);
        }

        $markWidth = imagesx($mark);
        $markHeight = imagesy($mark);
        $offset = self::offset($width, $height, $markWidth, $markHeight, $cfg['wm_pos'], $margin);

        imagealphablending($image, true);
        imagesavealpha($image, true);
        @imagecopy($image, $mark, $offset[0], $offset[1], 0, 0, $markWidth, $markHeight);

        imagedestroy($mark);

        self::log('水印已添加：gd 图片 / ' . basename($file) . ' ' . $markWidth . 'x' . $markHeight
            . ' / 不透明度 ' . $opacity);

        return true;
    }

    private static function gdFade($mark, int $opacity)
    {
        $width = imagesx($mark);
        $height = imagesy($mark);

        if ($width * $height > self::FADE_PIXELS) {
            $mark = self::gdScale($mark, sqrt(self::FADE_PIXELS / ($width * $height)));
            $width = imagesx($mark);
            $height = imagesy($mark);
        }

        $factor = 1 - $opacity / 100;

        imagealphablending($mark, false);
        imagesavealpha($mark, true);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($mark, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;

                if ($alpha >= 127) {
                    continue;
                }

                $newAlpha = (int)round($alpha + (127 - $alpha) * $factor);

                if ($newAlpha > 127) {
                    $newAlpha = 127;
                }

                imagesetpixel($mark, $x, $y, ($newAlpha << 24) | ($rgba & 0xFFFFFF));
            }
        }

        return $mark;
    }

    private static function gdRotateMark($mark, float $angle)
    {
        $width = imagesx($mark);
        $height = imagesy($mark);

        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $clear);
        imagecopy($canvas, $mark, 0, 0, 0, 0, $width, $height);

        $rotated = @imagerotate($canvas, $angle, $clear);

        imagedestroy($canvas);

        if (!$rotated) {
            return $mark;
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        imagedestroy($mark);

        return $rotated;
    }

    private static function markScale(int $width, int $height, int $markWidth, int $markHeight): float
    {
        if ($markWidth < 1 || $markHeight < 1) {
            return 1.0;
        }

        if ($markWidth > $width) {
            return $width / $markWidth;
        }

        if ($markHeight > $height) {
            return $height / $markHeight;
        }

        return 1.0;
    }

    private static function markReadable(string $file, int $max): bool
    {
        $info = @getimagesize($file);

        if (!$info || $info[0] < 1 || $info[1] < 1) {
            return false;
        }

        return $info[0] * $info[1] <= $max;
    }

    private static function ratio(int $width, int $height, array $cfg): float
    {
        $maxWidth = self::number($cfg['max_width'], 0, 0);
        $maxHeight = self::number($cfg['max_height'], 0, 0);
        $ratio = 1.0;

        if ($maxWidth > 0 && $width > $maxWidth) {
            $ratio = min($ratio, $maxWidth / $width);
        }

        if ($maxHeight > 0 && $height > $maxHeight) {
            $ratio = min($ratio, $maxHeight / $height);
        }

        return $ratio;
    }

    private static function watermarkAllowed(int $width, int $height, array $cfg): bool
    {
        $minWidth = self::number($cfg['wm_min_width'], 0, 0);
        $minHeight = self::number($cfg['wm_min_height'], 0, 0);

        if ($minWidth > 0 && $width < $minWidth) {
            return false;
        }

        if ($minHeight > 0 && $height < $minHeight) {
            return false;
        }

        return true;
    }

    private static function offset(int $width, int $height, int $markWidth, int $markHeight, $pos, int $margin): array
    {
        if (!in_array($pos, self::POSITIONS, true)) {
            $pos = 'bottom-right';
        }

        $right = max($margin, $width - $markWidth - $margin);
        $bottom = max($margin, $height - $markHeight - $margin);
        $centerX = max($margin, (int)round(($width - $markWidth) / 2));
        $centerY = max($margin, (int)round(($height - $markHeight) / 2));

        switch ($pos) {
            case 'top-left':
                return array($margin, $margin);
            case 'top-center':
                return array($centerX, $margin);
            case 'top-right':
                return array($right, $margin);
            case 'middle-left':
                return array($margin, $centerY);
            case 'center':
                return array($centerX, $centerY);
            case 'middle-right':
                return array($right, $centerY);
            case 'bottom-left':
                return array($margin, $bottom);
            case 'bottom-center':
                return array($centerX, $bottom);
            default:
                return array($right, $bottom);
        }
    }

    private static function watermarkLines(array $cfg): array
    {
        $lines = self::splitLines(isset($cfg['wm_text']) ? (string)$cfg['wm_text'] : '');

        if (!empty($lines)) {
            return $lines;
        }

        try {
            return self::splitLines((string)\Utils\Helper::options()->title);
        } catch (Throwable $e) {
            return array();
        }
    }

    private static function splitLines(string $text): array
    {
        $lines = array();

        foreach (preg_split('~\r\n|\n|\r~', $text) as $line) {
            $line = trim($line);

            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function fontSize($value, int $imageWidth): int
    {
        $raw = trim((string)$value);
        $size = 0;

        if ('' !== $raw && '%' === substr($raw, -1)) {
            $percent = (float)$raw;

            if ($percent > 0) {
                $size = (int)round($imageWidth * $percent / 100);
            }
        } else {
            $size = self::number($raw, 0, 0);
        }

        if ($size < 1) {
            $size = (int)round($imageWidth * 0.03);
        }

        return max(8, min($size, max(8, $imageWidth)));
    }

    private static function color(array $cfg): string
    {
        $raw = strtoupper(trim((string)(isset($cfg['wm_color']) ? $cfg['wm_color'] : '')));

        if ('' !== $raw && '#' !== $raw[0]) {
            $raw = '#' . $raw;
        }

        if (preg_match('~^#[0-9A-F]{3}$~', $raw)) {
            return '#' . $raw[1] . $raw[1] . $raw[2] . $raw[2] . $raw[3] . $raw[3];
        }

        if (preg_match('~^#[0-9A-F]{6}$~', $raw)) {
            return $raw;
        }

        if ('' !== $raw) {
            self::log('水印颜色格式不对，改用白色：' . $raw);
        }

        return '#FFFFFF';
    }

    private static function rgb(string $hex): array
    {
        return array(
            (int)hexdec(substr($hex, 1, 2)),
            (int)hexdec(substr($hex, 3, 2)),
            (int)hexdec(substr($hex, 5, 2))
        );
    }

    private static function asset(string $dir, $name): string
    {
        $base = __DIR__ . '/' . $dir;
        $name = basename(trim((string)$name));

        if ('' !== $name && '.' !== $name && '..' !== $name) {
            $file = $base . '/' . $name;

            if (is_file($file)) {
                return $file;
            }

            self::log($dir . ' 目录下没有这个文件：' . $name . '，现有 ' . self::listing($base));
        }

        return 'ttf' === $dir ? self::firstFont($base) : '';
    }

    private static function listing(string $dir): string
    {
        $items = @scandir($dir);

        if (!$items) {
            return '(目录不存在)';
        }

        $names = array();

        foreach ($items as $item) {
            if ('.' !== $item[0] && is_file($dir . '/' . $item)) {
                $names[] = $item;
            }
        }

        return empty($names) ? '(空)' : implode('、', $names);
    }

    private static function firstFont(string $dir): string
    {
        $items = @scandir($dir);

        if (!$items) {
            self::log('字体目录读不到：' . $dir);

            return '';
        }

        foreach ($items as $item) {
            if ('.' === $item[0]) {
                continue;
            }

            $file = $dir . '/' . $item;
            $ext = strtolower((string)pathinfo($item, PATHINFO_EXTENSION));

            if (in_array($ext, array('ttf', 'ttc', 'otf'), true) && is_file($file)) {
                self::log('字体名没对上，改用 ' . $item);

                return $file;
            }
        }

        self::log('字体目录里没有 ttf / ttc / otf 文件：' . $dir);

        return '';
    }

    private static function drawable(string $engine, string $font, int $size, string $text): bool
    {
        try {
            if ('imagick' === $engine) {
                $probe = new Imagick();
                $probe->newImage(480, 140, new ImagickPixel('#000000'));
                $probe->setImageFormat('png');

                $blank = clone $probe;

                $draw = new ImagickDraw();
                $draw->setFont($font);
                $draw->setFontSize($size);
                $draw->setFillColor(new ImagickPixel('#ffffff'));

                $probe->annotateImage($draw, 10, 100, 0, $text);

                $cmp = $probe->compareImages($blank, Imagick::METRIC_MEANABSOLUTEERROR);
                $distortion = isset($cmp[1]) ? (float)$cmp[1] : 1.0;

                $probe->clear();
                $blank->clear();

                return $distortion > 0.0001;
            }

            $probe = imagecreatetruecolor(480, 140);
            imagefill($probe, 0, 0, imagecolorallocate($probe, 0, 0, 0));

            @imagettftext($probe, $size, 0, 10, 100, imagecolorallocate($probe, 255, 255, 255), $font, $text);

            $painted = 0;

            for ($y = 0; $y < 140 && $painted < 1; $y++) {
                for ($x = 0; $x < 480; $x++) {
                    if ((imagecolorat($probe, $x, $y) & 0xFFFFFF) > 0x111111) {
                        $painted++;
                        break;
                    }
                }
            }

            imagedestroy($probe);

            return $painted > 0;
        } catch (Throwable $e) {
            return true;
        }
    }

    private static function drawableLines(string $engine, string $font, int $size, array $lines): bool
    {
        foreach ($lines as $index => $line) {
            if (!self::drawable($engine, $font, $size, $line)) {
                self::log('水印未添加：第 ' . ($index + 1) . ' 行「' . $line . '」画不出来');

                return false;
            }
        }

        return true;
    }

    private static function log(string $message): void
    {
        @error_log('[FileManager] ' . $message);
    }

    private static function number($value, int $default, int $min): int
    {
        if (null === $value || '' === trim((string)$value)) {
            return $default;
        }

        $number = (int)$value;

        return $number < $min ? $min : $number;
    }

    private static function range($value, int $min, int $max, int $default): int
    {
        if (null === $value || '' === trim((string)$value)) {
            return $default;
        }

        $number = (int)$value;

        if ($number < $min) {
            return $default;
        }

        return $number > $max ? $max : $number;
    }

    private static function degrees($value): float
    {
        if (null === $value || '' === trim((string)$value)) {
            return 0.0;
        }

        return (float)$value;
    }
}
