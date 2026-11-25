<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__FILE__) . '/Converter.php';

class FileManager_ImageTool
{

    const POSITION_LABELS = array(
        'top-left' => '左上', 'top-center' => '上中', 'top-right' => '右上',
        'middle-left' => '左中', 'center' => '居中', 'middle-right' => '右中',
        'bottom-left' => '左下', 'bottom-center' => '下中', 'bottom-right' => '右下'
    );

    const UPLOAD_TARGETS = array(
        'wm_font' => array('ttf', array('ttf', 'ttc', 'otf')),
        'wm_image' => array('img', array('png', 'jpg', 'jpeg', 'gif', 'webp'))
    );

    const ORIGIN_DIR = 'usr/uploads/_backup_img';

    const STATS_KEY = 'plugin:FileManagerStats';

    private static function defaults(): array
    {
        return array(
            'imgEnabled'      => '1',
            'quality'         => '95',
            'max_width'       => '',
            'max_height'      => '',
            'size_policy'     => 'keep',
            'origin_policy'   => 'backup',
            'origin_dir'      => self::ORIGIN_DIR,
            'watermark'       => '0',
            'wm_editor_panel' => '1',
            'wm_type'         => 'text',
            'wm_text'         => '',
            'wm_color'        => '#ff0000',
            'wm_font'         => '',
            'wm_size'         => '24',
            'wm_image'        => '',
            'wm_pos'          => 'bottom-right',
            'wm_angle'        => '0',
            'wm_opacity'      => '80',
            'wm_margin'       => '20',
            'wm_min_width'    => '200',
            'wm_min_height'   => '200'
        );
    }

    public static $settingsFetcher = null;

    public static function processStored(array $result, string $absFile, array $overrides = array()): array
    {
        try {
            $cfg = self::settings();

            $override = self::editorOverride();

            if (!empty($override)) {
                $cfg = array_merge($cfg, $override);
            }

            if (!empty($overrides)) {
                $cfg = array_merge($cfg, $overrides);
            }

            if (!empty($override) || !empty($overrides)) {
                self::note('本次上传参数覆盖：水印 ' . (isset($cfg['watermark']) ? $cfg['watermark'] : '?')
                    . '，位置 ' . (isset($cfg['wm_pos']) ? $cfg['wm_pos'] : '(未指定)'));
            }

            if (!isset($cfg['imgEnabled']) || '1' !== (string) $cfg['imgEnabled']) {
                return $result;
            }

            $ext = strtolower((string) (isset($result['type']) ? $result['type'] : ''));
            $type = self::imageType($absFile);

            if (0 === $type || '' === $ext || !is_file($absFile)) {
                return $result;
            }

            $normExt = 'jpeg' === $ext ? 'jpg' : $ext;
            $target = isset($cfg['to_format']) && in_array($cfg['to_format'], array('webp', 'jpg', 'png', 'keep'), true)
                ? (string) $cfg['to_format'] : 'webp';
            if ('keep' === $target) {
                $target = $normExt;
            }

            if ($normExt === $target) {
                if ('1' != $cfg['watermark']) {
                    return $result;
                }

                $engine = FileManager_Converter::engine();

                if ('' === $engine) {
                    return $result;
                }

                $absDir = dirname($absFile);
                $base = pathinfo($absFile, PATHINFO_FILENAME);
                $absTmp = $absDir . '/' . $base . '.fmtmp.' . $target;

                if (!FileManager_Converter::reencode($absFile, $absTmp, $type, $cfg, $engine)) {
                    @unlink($absTmp);
                    self::note('同格式加水印失败，保持原图：' . (isset($result['name']) ? $result['name'] : $absFile));

                    return $result;
                }

                $newSize = (int) @filesize($absTmp);

                if ($newSize < 1) {
                    @unlink($absTmp);

                    return $result;
                }

                $snapshot = self::captureExifSnapshot($absFile, $ext);

                if (null !== $snapshot) {
                    $result['fm_exif'] = $snapshot;
                }

                self::bumpStats((int) @filesize($absFile), $newSize);

                if (!@rename($absTmp, $absFile)) {
                    @copy($absTmp, $absFile);
                    @unlink($absTmp);
                }

                clearstatcache(true, $absFile);
                $result['size'] = $newSize;
                $result['mime'] = \Typecho\Common::mimeContentType($absFile);

                self::note('同格式加水印完成：' . $target . ' ' . $newSize);

                return $result;
            }

            $engine = FileManager_Converter::engine();

            if ('' === $engine) {
                self::note('Imagick 与 GD 都不可用，保持原图');

                return $result;
            }

            if (!FileManager_Converter::convertible($absFile, $type, $engine, '1' == $cfg['watermark'], $target)) {
                return $result;
            }

            $absDir = dirname($absFile);
            $base = pathinfo($absFile, PATHINFO_FILENAME);
            $absTarget = $absDir . '/' . $base . '.' . $target;

            if (!FileManager_Converter::convertTo($absFile, $absTarget, $type, $cfg, $engine, $target)) {
                @unlink($absTarget);
                self::note('转换失败，保持原图：' . (isset($result['name']) ? $result['name'] : $absFile));

                return $result;
            }

            $newSize = (int) @filesize($absTarget);
            $oldSize = (int) @filesize($absFile);

            if ($newSize < 1) {
                @unlink($absTarget);

                return $result;
            }

            $snapshot = self::captureExifSnapshot($absFile, $ext);

            if (null !== $snapshot) {
                $result['fm_exif'] = $snapshot;
            }

            if ($oldSize > 0 && $newSize >= $oldSize && 'continue' !== $cfg['size_policy']) {
                @unlink($absTarget);

                $keepExt = FileManager_Converter::keepFormat($type);

                if ('1' == $cfg['watermark'] && '' !== $keepExt) {
                    $absTmp = $absDir . '/' . $base . '.fmtmp.' . $keepExt;

                    if (FileManager_Converter::reencode($absFile, $absTmp, $type, $cfg, $engine)) {
                        $keepSize = (int) @filesize($absTmp);

                        self::bumpStats($oldSize, $keepSize);

                        if ('backup' === $cfg['origin_policy']) {
                            try {
                                self::archiveStored($absFile,
                                    self::originDirFor($cfg, (string) $result['path']), $base, $ext);
                            } catch (\Throwable $e) {
                                self::logError($e);
                            }
                        } else {
                            @unlink($absFile);
                        }

                        $dest = $absDir . '/' . $base . '.' . $keepExt;

                        if ($dest !== $absTmp && !@rename($absTmp, $dest)) {
                            @copy($absTmp, $dest);
                            @unlink($absTmp);
                        }

                        if ($keepExt === $ext) {
                            $result['size'] = $keepSize;
                            $result['mime'] = \Typecho\Common::mimeContentType($dest);
                        } else {
                            $result['name'] = self::swapExt((string) $result['name'], $keepExt);
                            $result['path'] = self::swapExtPath((string) $result['path'], $ext, $keepExt);
                            $result['size'] = $keepSize;
                            $result['type'] = $keepExt;
                            $result['mime'] = 'image/' . ('jpg' === $keepExt ? 'jpeg' : $keepExt);
                        }

                        self::note('转换后没比原图小（' . $newSize . ' >= ' . $oldSize
                            . '），已按原格式加水印输出：' . $keepExt . ' ' . $keepSize);

                        return $result;
                    }

                    @unlink($absTmp);
                    self::note('转换后没比原图小，原格式加水印也没成功，保持原图');
                } else {
                    self::note('转换后体积没变小，按设置保留原图：' . $newSize . ' >= ' . $oldSize);
                }

                return $result;
            }

            self::bumpStats($oldSize, $newSize);

            if ('backup' === $cfg['origin_policy']) {
                try {
                    self::archiveStored($absFile,
                        self::originDirFor($cfg, (string) $result['path']), $base, $ext);
                } catch (\Throwable $e) {
                    self::logError($e);
                }
            } else {
                @unlink($absFile);
            }

            self::note('转换完成：引擎 ' . $engine . '，水印开关 ' . $cfg['watermark']);

            $out = array(
                'name' => self::swapExt((string) $result['name'], $target),
                'path' => self::swapExtPath((string) $result['path'], $ext, $target),
                'size' => $newSize,
                'type' => $target,
                'mime' => 'image/' . ('jpg' === $target ? 'jpeg' : $target)
            );

            if (isset($result['fm_exif'])) {
                $out['fm_exif'] = $result['fm_exif'];
            }

            return $out;
        } catch (\Throwable $e) {
            self::logError($e);

            return $result;
        }
    }

    private static function originDirFor(array $cfg, string $relPath): string
    {
        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : \Widget\Upload::UPLOAD_DIR;
        $prefix = rtrim($uploadDir, '/') . '/';
        $inner = 0 === strpos($relPath, $prefix) ? substr($relPath, strlen($prefix)) : ltrim($relPath, '/');
        $slash = strrpos($inner, '/');
        $sub = false === $slash ? '' : substr($inner, 0, $slash);
        $root = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
        $dir = \Typecho\Common::url('/' . self::originDir($cfg), $root);

        return '' !== $sub ? $dir . '/' . $sub : $dir;
    }

    private static function archiveStored(string $absFile, string $dir, string $base, string $ext): void
    {
        if (!is_dir($dir) && !self::makeDir($dir)) {
            self::note('原图备份失败：建不了目录 ' . $dir . '，检查备份路径与目录权限');

            return;
        }

        $dest = $dir . '/' . $base . '.' . $ext;

        if (!@rename($absFile, $dest) && !(@copy($absFile, $dest) && @unlink($absFile))) {
            self::note('原图备份失败：写不进 ' . $dest . '，检查备份路径与目录权限');
        }
    }

    private static function swapExtPath(string $path, string $oldExt, string $newExt): string
    {
        $tail = '.' . $oldExt;
        $len = strlen($tail);

        if (substr($path, -$len) === $tail) {
            return substr($path, 0, strlen($path) - $len) . '.' . $newExt;
        }

        return $path;
    }

    public static function backupOriginalCandidates(string $relPath): array
    {
        try {
            $cfg = self::settings();
        } catch (Throwable $e) {
            return array();
        }

        if ('backup' !== (string) $cfg['origin_policy']) {
            return array();
        }

        $dir = self::originDirFor($cfg, $relPath);
        $base = pathinfo($relPath, PATHINFO_FILENAME);
        if ('' === $dir || '' === $base || !is_dir($dir)) {
            return array();
        }

        $out = array();
        foreach (array('jpg', 'jpeg', 'jpe', 'png', 'tiff', 'tif', 'webp', 'gif', 'bmp') as $ext) {
            $cand = $dir . '/' . $base . '.' . $ext;
            if (is_file($cand)) {
                $out[] = $cand;
            }
        }

        return $out;
    }

    public static function captureExifSnapshot(string $absFile, string $ext): ?array
    {
        $ext = strtolower($ext);

        if (!in_array($ext, array('jpg', 'jpeg', 'jpe', 'tiff', 'tif'), true)
            || !function_exists('exif_read_data') || !is_file($absFile)) {
            return null;
        }

        $ex = @exif_read_data($absFile);

        if (!is_array($ex)) {
            return null;
        }

        $keep = array('Make', 'Model', 'LensModel', 'LensMake', 'UndefinedTag:0x0095',
            'ExposureTime', 'FNumber', 'ISOSpeedRatings', 'FocalLength', 'FocalLengthIn35mmFilm',
            'ExposureBiasValue', 'ExposureProgram', 'MeteringMode', 'Flash', 'WhiteBalance',
            'DateTimeOriginal', 'Software', 'GPSLatitude', 'GPSLatitudeRef',
            'GPSLongitude', 'GPSLongitudeRef');

        $out = array();
        foreach ($keep as $k) {
            if (isset($ex[$k])) {
                $out[$k] = $ex[$k];
            }
        }
        if (isset($ex['COMPUTED']['Width'], $ex['COMPUTED']['Height'])) {
            $out['COMPUTED'] = array('Width' => $ex['COMPUTED']['Width'], 'Height' => $ex['COMPUTED']['Height']);
        }

        return empty($out) ? null : $out;
    }

    public static function configSection(\Typecho\Widget\Helper\Form $form): void
    {
        self::handleRequest();

        $cfg = self::settings();

        $card = new \Typecho\Widget\Helper\Layout('div');
        $card->html(self::engineCard(FileManager_Converter::probe($cfg)));
        $form->addItem($card);

        $stats = new \Typecho\Widget\Helper\Layout('div');
        $stats->html(self::statsCard());
        $form->addItem($stats);

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Radio(
            'imgEnabled',
            array('1' => '开启', '0' => '关闭'),
            $cfg['imgEnabled'],
            '上传图片自动处理',
            '开启后上传的图片自动转换 WebP 并按下方设置添加水印；关闭则保持原图'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'quality', null, $cfg['quality'], '转换WebP的图片质量'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'max_width', null, $cfg['max_width'], '转换后图片最大宽度'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'max_height', null, $cfg['max_height'], '转换后图片最大高度'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Radio(
            'size_policy',
            array('continue' => '继续转换', 'keep' => '取消转换用原图'),
            $cfg['size_policy'],
            '转换后文件大小超过原图是否继续转换'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Radio(
            'origin_policy',
            array('backup' => '备份原图文件', 'delete' => '删除原图文件'),
            $cfg['origin_policy'],
            '转换完成后是否备份原图文件'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'origin_dir', null, $cfg['origin_dir'], '原图备份路径'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Radio(
            'watermark',
            array('1' => '开启', '0' => '关闭'),
            $cfg['watermark'],
            '水印'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Radio(
            'wm_editor_panel',
            array('1' => '显示', '0' => '隐藏'),
            $cfg['wm_editor_panel'],
            '文章编辑页显示水印开关悬浮球'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Radio(
            'wm_type',
            array('text' => '文字', 'image' => '图片'),
            $cfg['wm_type'],
            '添加水印方式'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Textarea(
            'wm_text', null, $cfg['wm_text'], '水印文字'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'wm_color', null, $cfg['wm_color'], '文字颜色'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Select(
            'wm_font', self::assetOptions('wm_font', $cfg['wm_font']), $cfg['wm_font'], '文字字体'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'wm_size', null, $cfg['wm_size'], '文字大小'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Select(
            'wm_image', self::assetOptions('wm_image', $cfg['wm_image']), $cfg['wm_image'], '水印图片文件名'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Select(
            'wm_pos', self::POSITION_LABELS, $cfg['wm_pos'], '水印位置'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'wm_angle', null, $cfg['wm_angle'], '水印角度'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'wm_opacity', null, $cfg['wm_opacity'], '水印不透明度'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'wm_margin', null, $cfg['wm_margin'], '水印边距'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'wm_min_width', null, $cfg['wm_min_width'], '低于此宽度不加水印'
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'wm_min_height', null, $cfg['wm_min_height'], '低于此高度不加水印'
        ));

        $script = new \Typecho\Widget\Helper\Layout('script');
        $script->html(self::pageScript());
        $form->addItem($script);
    }

    public static function settings(): array
    {
        $cfg = self::defaults();

        try {
            $saved = null !== self::$settingsFetcher
                ? call_user_func(self::$settingsFetcher)
                : \Utils\Helper::options()->plugin('FileManager');

            if ($saved) {
                foreach ($cfg as $key => $default) {
                    $value = $saved->$key;

                    if (null !== $value) {
                        $cfg[$key] = $value;
                    }
                }
            }
        } catch (Throwable $e) {
        }

        return $cfg;
    }

    public static function modifyHandle(array $content, array $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $oldPath = self::attachPath($content);

        if ('' === $oldPath) {
            return false;
        }

        $oldName = self::attachField($content, 'name');
        $oldType = strtolower(self::attachField($content, 'type'));
        $ext = self::safeExt($file['name']);

        $absFile = __TYPECHO_ROOT_DIR__ . '/' . ltrim($oldPath, '/');
        $dir = dirname($absFile);

        if (!is_dir($dir) && !self::makeDir($dir)) {
            return false;
        }

        try {
            if ('webp' === $oldType) {
                $tmp = self::tmpFile($file);
                $type = self::imageType($tmp);
                $engine = FileManager_Converter::engine();
                $cfg = self::settings();

                if (!isset($cfg['imgEnabled']) || '1' !== (string) $cfg['imgEnabled']) {
                    $type = 0;
                }

                if (0 !== $type && FileManager_Converter::convertible($tmp, $type, $engine, '1' == $cfg['watermark'])
                    && FileManager_Converter::convert($tmp, $absFile, $type, $cfg, $engine)) {
                    self::bumpStats((int)@filesize($tmp), (int)@filesize($absFile));

                    return array(
                        'name' => $oldName,
                        'path' => $oldPath,
                        'size' => (int)@filesize($absFile),
                        'type' => 'webp',
                        'mime' => 'image/webp'
                    );
                }
            }
        } catch (Throwable $e) {
            self::logError($e);
        }

        if ($oldType !== $ext) {
            self::note('替换被拒：附件现有类型 ' . $oldType . ' 与新文件 ' . $ext . ' 不一致');

            return false;
        }

        if (!self::store($file, $absFile)) {
            self::note('替换失败：写不进文件 ' . $absFile);

            return false;
        }

        return array(
            'name' => $oldName,
            'path' => $oldPath,
            'size' => isset($file['size']) && $file['size'] > 0 ? $file['size'] : (int)@filesize($absFile),
            'type' => $ext,
            'mime' => \Typecho\Common::mimeContentType($absFile)
        );
    }

    public static function deleteHandle(array $content): bool
    {
        $path = self::attachPath($content);

        if ('' === $path) {
            return false;
        }

        $absFile = __TYPECHO_ROOT_DIR__ . '/' . ltrim($path, '/');

        if (is_file($absFile)) {
            @unlink($absFile);
        }

        self::dropArchive($path);

        return true;
    }

    public static function editorPanel($post = null): void
    {
        $cfg = self::settings();

        if ('1' !== (string)$cfg['wm_editor_panel']) {
            return;
        }

        $on = '1' === $cfg['watermark'];
        $pos = isset(self::POSITION_LABELS[$cfg['wm_pos']]) ? $cfg['wm_pos'] : 'bottom-right';

        $css = <<<'CSS'
#fm-wm-fab{position:fixed;right:18px;bottom:64px;z-index:9990;width:56px;height:56px;border-radius:50%;border:none;cursor:pointer;background:#9aa4af;color:#fff;font-size:12px;line-height:1.3;box-shadow:0 2px 10px rgba(16,24,40,.28);transition:background .2s}
#fm-wm-fab.fm-on{background:#2f6f9f}
#fm-wm-fab:hover{background:#7b8794}
#fm-wm-fab.fm-on:hover{background:#27618d}
#fm-wm-pop{position:fixed;right:18px;bottom:132px;z-index:9990;width:125px;background:#fff;border:1px solid #d5dde5;border-radius:8px;box-shadow:0 6px 20px rgba(16,24,40,.18);padding:10px 12px;display:none;font-size:13px;color:#4a5763}
#fm-wm-pop.fm-open{display:block}
#fm-wm-pop .typecho-label{padding:0;margin:10px 0 6px;font-size:13px}
#fm-wm-pop .typecho-label:first-child{margin-top:0}
#fm-wm-pop .description{margin:4px 0 0}
#fm-wm-pop select{width:100%;box-sizing:border-box}
#fm-wm-pop .wc-wm-switch{display:inline-flex;align-items:center;cursor:pointer;margin:0}
#fm-wm-pop .wc-wm-switch input{display:none}
#fm-wm-pop .wc-wm-track{flex:0 0 auto;width:36px;height:20px;border-radius:10px;background:#D4D4D4;position:relative;transition:background .2s}
#fm-wm-pop .wc-wm-knob{position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#FFFFFF;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .2s}
#fm-wm-pop .wc-wm-switch input:checked + .wc-wm-track{background:#409eff}
#fm-wm-pop .wc-wm-switch input:checked + .wc-wm-track .wc-wm-knob{left:18px}
#fm-wm-pop .wc-wm-text{margin-left:8px}
CSS;

        echo '<style>' . $css . '</style>';
        echo '<button type="button" id="fm-wm-fab" class="' . ($on ? 'fm-on' : '') . '">水印<br>开关</button>';
        echo '<div id="fm-wm-pop">';
        echo '<p style="margin:6px 0"><label class="wc-wm-switch"><input type="checkbox" id="filemanager-wm-toggle" value="1"'
            . ($on ? ' checked' : '') . ' /><span class="wc-wm-track"><span class="wc-wm-knob"></span></span>'
            . '<span class="wc-wm-text">添加水印</span></label></p>';
        echo '<label class="typecho-label" for="filemanager-wm-pos">水印添加位置</label>';
        echo '<p><select id="filemanager-wm-pos" class="w-100">';

        foreach (self::POSITION_LABELS as $value => $label) {
            echo '<option value="' . $value . '"' . ($value === $pos ? ' selected' : '') . '>' . $label . '</option>';
        }

        echo '</select></p></div>';

        $js = <<<'JS'
(function () {
    var fab = document.getElementById('fm-wm-fab');
    var pop = document.getElementById('fm-wm-pop');
    if (!fab || !pop) { return; }
    document.body.appendChild(fab);
    document.body.appendChild(pop);
    var tgl = document.getElementById('filemanager-wm-toggle');
    function sync() { if (tgl) { fab.classList[tgl.checked ? 'add' : 'remove']('fm-on'); } }
    if (tgl) { tgl.addEventListener('change', sync); sync(); }
    fab.addEventListener('click', function (e) { e.stopPropagation(); pop.classList.toggle('fm-open'); });
    pop.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { pop.classList.remove('fm-open'); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { pop.classList.remove('fm-open'); } });
})();
JS;
        echo '<script>' . $js . '</script>';
    }

    public static function editorScript($post = null): void
    {
        $js = <<<'JS'
(function () {
    var toggle = document.getElementById('filemanager-wm-toggle');
    var select = document.getElementById('filemanager-wm-pos');

    if (!toggle) {
        return;
    }

    var KEY_ON = 'FileManagerEditorWm';
    var KEY_POS = 'FileManagerEditorWmPos';

    try {
        var savedOn = window.localStorage.getItem(KEY_ON);

        if (null !== savedOn) {
            toggle.checked = '1' === savedOn;
        }

        if (select) {
            var savedPos = window.localStorage.getItem(KEY_POS);

            if (savedPos) {
                for (var i = 0; i < select.options.length; i++) {
                    if (select.options[i].value === savedPos) {
                        select.value = savedPos;
                        break;
                    }
                }
            }
        }
    } catch (e) {
    }

    toggle.dispatchEvent(new Event('change'));

    toggle.addEventListener('change', function () {
        try {
            window.localStorage.setItem(KEY_ON, toggle.checked ? '1' : '0');
        } catch (e) {
        }
    });

    if (select) {
        select.addEventListener('change', function () {
            try {
                window.localStorage.setItem(KEY_POS, select.value);
            } catch (e) {
            }
        });
    }

    function marked(body) {
        if (body instanceof FormData) {
            body.append('fm_editor', toggle.checked ? '1' : '0');
            body.append('fm_editor_pos', select ? select.value : '');
        }

        return body;
    }

    var originOpen = XMLHttpRequest.prototype.open;
    var originSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
        this.__fmImgUp = 'string' === typeof url && (-1 !== url.indexOf('action/upload') || -1 !== url.indexOf('action/filemanager'));

        return originOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function (body) {
        if (this.__fmImgUp) {
            marked(body);
        }

        return originSend.apply(this, arguments);
    };

    if (window.fetch) {
        var originFetch = window.fetch;

        window.fetch = function (input, init) {
            try {
                var url = 'string' === typeof input ? input : (input && input.url ? input.url : '');

                if ((-1 !== String(url).indexOf('action/upload') || -1 !== String(url).indexOf('action/filemanager')) && init && init.body instanceof FormData) {
                    marked(init.body);
                }
            } catch (e) {
            }

            return originFetch.apply(this, arguments);
        };
    }
})();
JS;

        $script = new \Typecho\Widget\Helper\Layout('script');
        $script->html($js);
        $script->render();
    }

    private static function editorOverride(): array
    {
        if (!isset($_POST['fm_editor'])) {
            return array();
        }

        if ('1' !== (string)$_POST['fm_editor']) {
            return array('watermark' => '0');
        }

        $override = array('watermark' => '1');
        $pos = isset($_POST['fm_editor_pos']) ? (string)$_POST['fm_editor_pos'] : '';

        if (isset(self::POSITION_LABELS[$pos])) {
            $override['wm_pos'] = $pos;
        }

        return $override;
    }

    private static function handleRequest(): void
    {
        if (isset($_POST['fm_img_probe'])) {
            self::probeRequest();
        }

        if (isset($_POST['fm_img_stats_reset'])) {
            self::statsResetRequest();
        }

        if (isset($_POST['fm_img_clear'])) {
            self::clearRequest((string)$_POST['fm_img_clear']);
        }

        if (!isset($_POST['fm_img_upload'])) {
            return;
        }

        $key = (string)$_POST['fm_img_upload'];

        if (!isset(self::UPLOAD_TARGETS[$key])) {
            self::respond(array('ok' => false, 'message' => '上传类型不对'));
        }

        if (!self::ajax()) {
            self::respond(array('ok' => false, 'message' => '请从插件设置页上传'));
        }

        list($dir, $exts) = self::UPLOAD_TARGETS[$key];
        $file = isset($_FILES['fm_img_file']) ? $_FILES['fm_img_file'] : null;

        if (!is_array($file) || !empty($file['error'])) {
            self::respond(array('ok' => false, 'message' => '没有收到文件，或体积超过服务器限制'));
        }

        $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';

        if ('' === $tmp || !is_uploaded_file($tmp)) {
            self::respond(array('ok' => false, 'message' => '上传的临时文件不可用'));
        }

        $name = self::assetName(isset($file['name']) ? (string)$file['name'] : '', $exts);

        if ('' === $name) {
            self::respond(array('ok' => false, 'message' => '只支持 ' . implode(' / ', $exts) . ' 文件'));
        }

        $target = __DIR__ . '/' . $dir;

        if (!is_dir($target) && !self::makeDir($target)) {
            self::respond(array('ok' => false, 'message' => '目录不可写：' . $dir));
        }

        if (!@move_uploaded_file($tmp, $target . '/' . $name)) {
            self::respond(array('ok' => false, 'message' => '写入失败：' . $dir . '/' . $name));
        }

        @chmod($target . '/' . $name, 0644);

        try {
            \Widget\Plugins\Edit::configPlugin('FileManager', array($key => $name));
        } catch (Throwable $e) {
            self::logError($e);
        }

        self::note('素材上传成功：' . $dir . '/' . $name);

        self::respond(array('ok' => true, 'name' => $name));
    }

    private static function clearRequest(string $key): void
    {
        if (!isset(self::UPLOAD_TARGETS[$key])) {
            self::respond(array('ok' => false, 'message' => '上传类型不对'));
        }

        if (!self::ajax()) {
            self::respond(array('ok' => false, 'message' => '请从插件设置页操作'));
        }

        list($dir) = self::UPLOAD_TARGETS[$key];
        $removed = self::clearAssets(__DIR__ . '/' . $dir);

        try {
            \Widget\Plugins\Edit::configPlugin('FileManager', array($key => ''));
        } catch (Throwable $e) {
            self::logError($e);
        }

        self::note('素材目录已清空：' . $dir . '，删掉 ' . $removed . ' 个文件');

        self::respond(array('ok' => true, 'removed' => $removed));
    }

    private static function clearAssets(string $dir): int
    {
        $items = @scandir($dir);

        if (!is_array($items)) {
            return 0;
        }

        $removed = 0;

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir . '/' . $item;

            if (!is_file($path)) {
                continue;
            }

            if (@unlink($path)) {
                $removed++;
            } else {
                self::note('素材清空失败：删不掉 ' . $dir . '/' . $item);
            }
        }

        return $removed;
    }

    private static function stats(): array
    {
        $stats = array('count' => 0, 'orig' => 0, 'saved' => 0);

        try {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow($db->select()->from('table.options')->where('name = ?', self::STATS_KEY));

            if (is_array($row) && isset($row['value'])) {
                $data = is_array($row['value']) ? $row['value'] : @unserialize((string)$row['value']);

                if (is_array($data)) {
                    foreach ($stats as $key => $default) {
                        if (isset($data[$key]) && is_numeric($data[$key])) {
                            $stats[$key] = (int)$data[$key];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
        }

        return $stats;
    }

    private static function bumpStats(int $origSize, int $newSize): void
    {
        try {
            $stats = self::stats();
            $stats['count'] += 1;

            if ($origSize > 0) {
                $stats['orig'] += $origSize;
                $stats['saved'] += $origSize - $newSize;
            }

            self::saveStats($stats);
        } catch (Throwable $e) {
            self::logError($e);
        }
    }

    private static function saveStats(array $stats): void
    {
        $db = \Typecho\Db::get();
        $value = serialize($stats);

        $affected = $db->query($db->update('table.options')
            ->rows(array('value' => $value))->where('name = ?', self::STATS_KEY));

        if ($affected < 1) {
            $db->query($db->insert('table.options')
                ->rows(array('name' => self::STATS_KEY, 'user' => 0, 'value' => $value)));
        }
    }

    private static function resetStats(): void
    {
        self::saveStats(array('count' => 0, 'orig' => 0, 'saved' => 0));
    }

    private static function statsResetRequest(): void
    {
        if (!self::ajax()) {
            self::respond(array('ok' => false, 'message' => '请从插件设置页操作'));
        }

        self::resetStats();
        self::note('转换统计已清零');

        self::respond(array('ok' => true, 'html' => self::statsCard()));
    }

    private static function backupRoot(): string
    {
        try {
            $root = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;

            return \Typecho\Common::url('/' . self::originDir(self::settings()), $root);
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function dirSize(string $dir, int &$size, int &$files): void
    {
        if ('' === $dir || !is_dir($dir)) {
            return;
        }

        $items = @scandir($dir);

        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                self::dirSize($path, $size, $files);
            } elseif (is_file($path)) {
                $size += (int)@filesize($path);
                $files++;
            }
        }
    }

    private static function statsCard(): string
    {
        $stats = self::stats();
        $size = 0;
        $files = 0;
        self::dirSize(self::backupRoot(), $size, $files);

        $saved = self::sizeText($stats['saved']);

        if ($stats['orig'] > 0) {
            $saved .= '（' . number_format($stats['saved'] / $stats['orig'] * 100, 1) . '%）';
        }

        $lines = array(
            '转换图片数量' => $stats['count'] . ' 张',
            '节省空间' => $saved,
            '原图备份目录' => self::sizeText($size) . ' · ' . $files . ' 个文件'
        );

        $html = '<div id="fm-img-stats" style="border:1px solid #E4E4E4;border-radius:4px;'
            . 'background:#FFFFFF;margin:0 0 20px;overflow:hidden;font-size:12px;line-height:1.7">'
            . '<div style="display:flex;align-items:center;justify-content:space-between;'
            . 'padding:9px 14px;background:#FAFAFA;border-bottom:1px solid #EFEFEF">'
            . '<span style="color:#3C3C3C">转换统计</span>'
            . '<button type="button" id="fm-img-stats-reset" style="font-family:inherit;font-size:12px;'
            . 'line-height:1.8;height:26px;padding:0 12px;border:1px solid #E5BEBE;border-radius:3px;'
            . 'background:#FFFFFF;color:#B5302D;cursor:pointer">清零统计</button></div>';

        $index = 0;

        foreach ($lines as $label => $value) {
            $html .= '<div style="display:flex;align-items:center;padding:7px 14px;'
                . (0 === $index ? '' : 'border-top:1px solid #F4F4F4;') . '">'
                . '<span style="flex:0 0 76px;color:#8A8A8A">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
                . '<span style="flex:1 1 auto;color:#3C3C3C;word-break:break-all">'
                . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</span></div>';

            $index++;
        }

        return $html . '</div>';
    }

    private static function ajax(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && 'xmlhttprequest' === strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    private static function probeRequest(): void
    {
        if (!self::ajax()) {
            self::respond(array('ok' => false, 'message' => '请从插件设置页检测'));
        }

        $cfg = self::settings();
        $probe = FileManager_Converter::probe($cfg);
        $test = FileManager_Converter::selftest($cfg);

        self::note('引擎检测：' . ('' === $probe['engine'] ? '无可用引擎' : $probe['engine'])
            . '，WebP 编码 ' . ($test['ok'] ? '通过 ' . $test['bytes'] . ' 字节' : '未通过：' . $test['message'])
            . '，内存 ' . $probe['memory']
            . '，Imagick ' . ($probe['imagick'] ? $probe['imagickVersion'] . ' / ' . $probe['magickVersion'] : '无')
            . '，GD ' . ($probe['gd'] ? $probe['gdVersion'] : '无')
            . '，字体 ' . $probe['fonts'] . ' 个，水印图 ' . $probe['marks'] . ' 个');

        self::respond(array('ok' => true, 'html' => self::engineCard($probe, $test)));
    }

    private static function assetName(string $raw, array $exts): string
    {
        $clean = preg_replace('~[^\p{L}\p{N}._-]+~u', '-', basename(str_replace('\\', '/', $raw)));

        if (null === $clean) {
            self::note('文件名不是合法 UTF-8，已丢弃：' . $raw);

            return '';
        }

        $clean = trim($clean, '.-');
        $ext = strtolower((string)pathinfo($clean, PATHINFO_EXTENSION));

        if ('' === $clean || !in_array($ext, $exts, true)) {
            return '';
        }

        $base = trim(str_replace('.', '-', substr($clean, 0, strlen($clean) - strlen($ext) - 1)), '-');

        return '' === $base ? '' : substr($base, 0, 60) . '.' . $ext;
    }

    private static function assetOptions(string $key, string $value): array
    {
        list($dir, $exts) = self::UPLOAD_TARGETS[$key];

        $options = array('' => '未选择');

        foreach (FileManager_Converter::assets(__DIR__ . '/' . $dir, $exts) as $name) {
            $options[$name] = $name;
        }

        $value = trim($value);

        if ('' !== $value && !isset($options[$value])) {
            $options[$value] = $value;
        }

        return $options;
    }

    private static function respond(array $data): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        @header('Content-Type: application/json; charset=UTF-8');

        echo json_encode($data, JSON_UNESCAPED_UNICODE);

        exit;
    }

    private static function engineCard(array $probe, array $test = array()): string
    {
        $imagick = 'imagick' === $probe['engine'];
        $gd = 'gd' === $probe['engine'];
        $lines = array();

        if ($imagick) {
            $name = 'Imagick' . ('' === $probe['imagickVersion'] ? '' : ' ' . $probe['imagickVersion']);

            if ('' !== $probe['magickVersion']) {
                $name .= ' · ImageMagick ' . $probe['magickVersion'];
            }

            $lines[] = array('当前引擎', $name, '可用', 'ok');
        } elseif ($gd) {
            $lines[] = array('当前引擎',
                'GD' . ('' === $probe['gdVersion'] ? '' : ' ' . $probe['gdVersion']), '可用', 'ok');
        } else {
            $lines[] = array('当前引擎', '没有可用的图像引擎，上传不会转换', '不可用', 'bad');
        }

        $codec = $imagick ? $probe['imagickWebp'] : ($gd && $probe['gdWebp']);

        $lines[] = array('WebP 编码',
            $imagick ? 'ImageMagick' : ($gd ? 'GD' : '无'),
            $codec ? '支持' : '不支持',
            $codec ? 'ok' : 'bad');

        if ('' === $probe['engine']) {
            $lines[] = array('文字水印', '没有可用的图像引擎', '不可用', 'bad');
            $lines[] = array('图片水印', '没有可用的图像引擎', '不可用', 'bad');
        } else {
            if ($gd && !$probe['gdFreetype']) {
                $lines[] = array('文字水印', 'GD 未编译 FreeType', '不支持', 'bad');
            } elseif ('missing' === $probe['font']) {
                $lines[] = array('文字水印', '找不到 ' . $probe['fontName'],
                    $probe['fonts'] > 0 ? '会改用其他字体' : '没有字体', $probe['fonts'] > 0 ? 'warn' : 'bad');
            } elseif ('' === $probe['font']) {
                $lines[] = array('文字水印', 'ttf 目录 ' . $probe['fonts'] . ' 个字体',
                    $probe['fonts'] > 0 ? '未选择字体' : '没有字体', $probe['fonts'] > 0 ? 'warn' : 'bad');
            } else {
                $lines[] = array('文字水印', $probe['fontName'], '支持', 'ok');
            }

            if ('missing' === $probe['image']) {
                $lines[] = array('图片水印', '找不到 ' . $probe['imageName'], '图片缺失', 'bad');
            } elseif ('' === $probe['image']) {
                $lines[] = array('图片水印', 'img 目录 ' . $probe['marks'] . ' 个图片',
                    $probe['marks'] > 0 ? '未选择图片' : '没有图片', $probe['marks'] > 0 ? 'warn' : 'bad');
            } else {
                $lines[] = array('图片水印', $probe['imageName'], '支持', 'ok');
            }
        }

        $lines[] = array('内存上限', $probe['memory'], $probe['memoryLow'] ? '偏低' : '正常',
            $probe['memoryLow'] ? 'warn' : 'ok');

        $writable = $probe['fontWritable'] && $probe['imageWritable'];

        $lines[] = array('素材目录', 'ttf ' . $probe['fonts'] . ' 个 · img ' . $probe['marks'] . ' 个',
            $writable ? '可写' : '不可写', $writable ? 'ok' : 'bad');

        if (isset($test['ok'])) {
            $lines[] = $test['ok']
                ? array('实测转换', '64×64 测试图', '通过 · ' . self::sizeText((int)$test['bytes']), 'ok')
                : array('实测转换', (string)$test['message'], '未通过', 'bad');
        } else {
            $lines[] = array('实测转换', '64×64 测试图', '未检测', 'idle');
        }

        $html = '<div id="fm-img-engine" style="border:1px solid #E4E4E4;border-radius:4px;'
            . 'background:#FFFFFF;margin:0 0 20px;overflow:hidden;font-size:12px;line-height:1.7">'
            . '<div style="display:flex;align-items:center;justify-content:space-between;'
            . 'padding:9px 14px;background:#FAFAFA;border-bottom:1px solid #EFEFEF">'
            . '<span style="color:#3C3C3C">图像处理引擎</span>'
            . '<button type="button" id="fm-img-probe" style="font-family:inherit;font-size:12px;'
            . 'line-height:1.8;height:26px;padding:0 12px;border:1px solid #D8D8D8;border-radius:3px;'
            . 'background:#FFFFFF;color:#555555;cursor:pointer">重新检测</button></div>';

        foreach ($lines as $index => $line) {
            $html .= self::cardRow($line[0], $line[1], $line[2], $line[3], 0 === $index);
        }

        return $html . '</div>';
    }

    private static function cardRow(string $label, string $value, string $state, string $level, bool $first): string
    {
        $colors = array('ok' => '#3F7A22', 'warn' => '#8A6D00', 'bad' => '#B5302D', 'idle' => '#8A8A8A');
        $color = isset($colors[$level]) ? $colors[$level] : $colors['idle'];

        return '<div style="display:flex;align-items:center;padding:7px 14px;'
            . ($first ? '' : 'border-top:1px solid #F4F4F4;') . '">'
            . '<span style="flex:0 0 76px;color:#8A8A8A">' . $label . '</span>'
            . '<span style="flex:1 1 auto;color:#3C3C3C;word-break:break-all">'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span style="flex:0 0 auto;color:' . $color . ';white-space:nowrap;padding-left:12px">'
            . '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:' . $color
            . ';margin-right:5px"></span>' . htmlspecialchars($state, ENT_QUOTES, 'UTF-8') . '</span></div>';
    }

    private static function sizeText(int $bytes): string
    {
        $abs = abs($bytes);
        $sign = $bytes < 0 ? '-' : '';

        if ($abs < 1024) {
            return $sign . $abs . ' B';
        }

        if ($abs < 1048576) {
            return $sign . number_format($abs / 1024, 1) . ' KB';
        }

        if ($abs < 1073741824) {
            return $sign . number_format($abs / 1048576, 2) . ' MB';
        }

        return $sign . number_format($abs / 1073741824, 2) . ' GB';
    }

    private static function pageScript(): string
    {
        $fields = array();

        foreach (self::UPLOAD_TARGETS as $key => $target) {
            $fields[$key] = array(
                'accept' => '.' . implode(',.', $target[1]),
                'dir' => $target[0]
            );
        }

        $js = <<<'JS'
(function () {
    var FIELDS = __FIELDS__;
    var WATERMARK = ['wm_editor_panel', 'wm_type', 'wm_text', 'wm_color', 'wm_font', 'wm_size', 'wm_image',
        'wm_pos', 'wm_angle', 'wm_opacity', 'wm_margin', 'wm_min_width', 'wm_min_height'];
    var TEXT_ONLY = ['wm_text', 'wm_color', 'wm_font', 'wm_size'];
    var BACKUP_ONLY = ['origin_dir'];

    function item(name) {
        return document.querySelector('ul[id^="typecho-option-item-' + name + '-"]');
    }

    function endpoint() {
        var form = document.querySelector('form[action*="plugins-edit"]');

        return form && form.getAttribute('action') ? form.getAttribute('action') : window.location.href;
    }

    function value(name) {
        var list = document.querySelectorAll('[name="' + name + '"]');

        if (!list.length) {
            return '';
        }

        if ('radio' === list[0].type) {
            var checked = document.querySelector('[name="' + name + '"]:checked');

            return checked ? checked.value : '';
        }

        return list[0].value;
    }

    function show(name, visible) {
        var box = item(name);

        if (box) {
            box.style.display = visible ? '' : 'none';
        }
    }

    function sync() {
        var on = '1' === value('watermark');
        var image = 'image' === value('wm_type');

        BACKUP_ONLY.forEach(function (name) {
            show(name, 'backup' === value('origin_policy'));
        });

        WATERMARK.forEach(function (name) {
            show(name, on);
        });

        if (!on) {
            return;
        }

        TEXT_ONLY.forEach(function (name) {
            show(name, !image);
        });

        show('wm_image', image);
    }

    ['watermark', 'wm_type', 'origin_policy'].forEach(function (name) {
        var box = item(name);

        if (box) {
            box.addEventListener('change', sync);
        }
    });

    var textarea = document.querySelector('textarea[name="wm_text"]');

    if (textarea) {
        textarea.rows = 4;
        textarea.style.minHeight = '88px';
    }

    Object.keys(FIELDS).forEach(function (key) {
        var input = document.querySelector('[name="' + key + '"]');

        if (!input) {
            return;
        }

        var picker = document.createElement('input');
        picker.type = 'file';
        picker.accept = FIELDS[key].accept;
        picker.style.display = 'none';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn';
        button.textContent = '上传';
        button.style.flex = '0 0 auto';
        button.style.whiteSpace = 'nowrap';

        var clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'btn';
        clear.textContent = '清空';
        clear.style.flex = '0 0 auto';
        clear.style.whiteSpace = 'nowrap';
        clear.style.marginLeft = '6px';
        clear.style.borderColor = '#E5BEBE';
        clear.style.color = '#B5302D';

        var row = document.createElement('div');
        row.style.display = 'flex';
        row.style.alignItems = 'center';
        row.style.width = '100%';

        input.parentNode.insertBefore(row, input);
        row.appendChild(input);
        row.appendChild(button);
        row.appendChild(clear);
        row.appendChild(picker);

        input.style.flex = '1 1 auto';
        input.style.width = 'auto';
        input.style.minWidth = '0';
        input.style.marginRight = '8px';

        button.onclick = function () {
            picker.click();
        };

        picker.onchange = function () {
            if (!picker.files || !picker.files.length) {
                return;
            }

            var url = endpoint();
            var body = new FormData();
            var token = url.match(/[?&]_=([^&]*)/);

            body.append('fm_img_upload', key);
            body.append('fm_img_file', picker.files[0]);

            if (token) {
                body.append('_', decodeURIComponent(token[1]));
            }

            button.disabled = true;
            button.textContent = '上传中';

            fetch(url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.text();
            }).then(function (text) {
                var data;

                try {
                    data = JSON.parse(text);
                } catch (e) {
                    alert('上传失败：请刷新页面重新登录后再试');
                    return;
                }

                if (data && data.ok) {
                    if ('SELECT' === input.tagName) {
                        var exists = false;
                        var i;

                        for (i = 0; i < input.options.length; i++) {
                            if (input.options[i].value === data.name) {
                                exists = true;
                                break;
                            }
                        }

                        if (!exists) {
                            var option = document.createElement('option');
                            option.value = data.name;
                            option.textContent = data.name;
                            input.appendChild(option);
                        }
                    }

                    input.value = data.name;
                } else {
                    alert(data && data.message ? data.message : '上传失败');
                }
            }).catch(function (error) {
                alert('上传失败：' + (error && error.message ? error.message : '网络异常'));
            }).then(function () {
                button.disabled = false;
                button.textContent = '上传';
                picker.value = '';
            });
        };

        clear.onclick = function () {
            if (!window.confirm('确定清空 ' + FIELDS[key].dir + ' 目录里的所有文件？')) {
                return;
            }

            var url = endpoint();
            var token = url.match(/[?&]_=([^&]*)/);
            var body = new FormData();

            body.append('fm_img_clear', key);

            if (token) {
                body.append('_', decodeURIComponent(token[1]));
            }

            clear.disabled = true;
            clear.textContent = '清空中';

            fetch(url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.text();
            }).then(function (text) {
                var data;

                try {
                    data = JSON.parse(text);
                } catch (e) {
                    alert('清空失败：请刷新页面重新登录后再试');
                    return;
                }

                if (!data || !data.ok) {
                    alert(data && data.message ? data.message : '清空失败');
                    return;
                }

                if ('SELECT' === input.tagName) {
                    var i;

                    for (i = input.options.length - 1; i >= 0; i--) {
                        if ('' !== input.options[i].value) {
                            input.remove(i);
                        }
                    }
                }

                input.value = '';
            }).catch(function (error) {
                alert('清空失败：' + (error && error.message ? error.message : '网络异常'));
            }).then(function () {
                clear.disabled = false;
                clear.textContent = '清空';
            });
        };
    });

    var engineCard = document.getElementById('fm-img-engine');

    if (engineCard) {
        document.addEventListener('click', function (event) {
            var button = event.target;

            if (!button || 'fm-img-probe' !== button.id) {
                return;
            }

            var url = endpoint();
            var token = url.match(/[?&]_=([^&]*)/);
            var body = new FormData();

            body.append('fm_img_probe', '1');

            if (token) {
                body.append('_', decodeURIComponent(token[1]));
            }

            button.disabled = true;
            button.textContent = '检测中';

            var restore = function () {
                button.disabled = false;
                button.textContent = '重新检测';
            };

            fetch(url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.text();
            }).then(function (text) {
                var data;

                try {
                    data = JSON.parse(text);
                } catch (e) {
                    alert('检测失败：请刷新页面重新登录后再试');
                    return;
                }

                if (data && data.ok && data.html) {
                    engineCard.outerHTML = data.html;
                    engineCard = document.getElementById('fm-img-engine');
                } else {
                    alert(data && data.message ? data.message : '检测失败');
                    restore();
                }
            }).catch(function (error) {
                alert('检测失败：' + (error && error.message ? error.message : '网络异常'));
                restore();
            });
        });
    }

    var statsCard = document.getElementById('fm-img-stats');

    if (statsCard) {
        document.addEventListener('click', function (event) {
            var button = event.target;

            if (!button || 'fm-img-stats-reset' !== button.id) {
                return;
            }

            if (!window.confirm('确定清零转换统计？')) {
                return;
            }

            var url = endpoint();
            var token = url.match(/[?&]_=([^&]*)/);
            var body = new FormData();

            body.append('fm_img_stats_reset', '1');

            if (token) {
                body.append('_', decodeURIComponent(token[1]));
            }

            button.disabled = true;
            button.textContent = '清零中';

            var restore = function () {
                button.disabled = false;
                button.textContent = '清零统计';
            };

            fetch(url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.text();
            }).then(function (text) {
                var data;

                try {
                    data = JSON.parse(text);
                } catch (e) {
                    alert('清零失败：请刷新页面重新登录后再试');
                    restore();
                    return;
                }

                if (data && data.ok && data.html) {
                    statsCard.outerHTML = data.html;
                    statsCard = document.getElementById('fm-img-stats');
                } else {
                    alert(data && data.message ? data.message : '清零失败');
                    restore();
                }
            }).catch(function (error) {
                alert('清零失败：' + (error && error.message ? error.message : '网络异常'));
                restore();
            });
        });
    }

    sync();
})();
JS;

        return str_replace('__FIELDS__', (string)json_encode($fields), $js);
    }

    private static function attachField(array $content, string $field): string
    {
        if (!isset($content['attachment'])) {
            return '';
        }

        $attachment = $content['attachment'];

        if (is_object($attachment)) {
            $value = $attachment->$field;
        } elseif (is_array($attachment)) {
            $value = isset($attachment[$field]) ? $attachment[$field] : '';
        } else {
            return '';
        }

        return null === $value || is_array($value) ? '' : (string)$value;
    }

    private static function attachPath(array $content): string
    {
        return self::attachField($content, 'path');
    }

    private static function imageType(string $src): int
    {
        if ('' === $src || !is_file($src)) {
            return 0;
        }

        if (function_exists('exif_imagetype')) {
            $type = @exif_imagetype($src);

            if (false !== $type) {
                return (int)$type;
            }
        }

        $info = @getimagesize($src);

        return is_array($info) && !empty($info[2]) ? (int)$info[2] : 0;
    }

    private static function logError(Throwable $e): void
    {
        @error_log('[FileManager] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }

    private static function note(string $message): void
    {
        @error_log('[FileManager] ' . $message);
    }

    private static function safeExt(string $name): string
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);

        $info = pathinfo($name);

        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    private static function swapExt(string $name, string $ext): string
    {
        $name = str_replace(array('\\', '/'), '', $name);
        $dot = strrpos($name, '.');

        return (false === $dot ? $name : substr($name, 0, $dot)) . '.' . $ext;
    }

    private static function tmpFile(array $file): string
    {
        if (!empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
            return $file['tmp_name'];
        }

        return '';
    }

    private static function store(array $file, string $dest): bool
    {
        if (isset($file['tmp_name'])) {
            return @move_uploaded_file($file['tmp_name'], $dest);
        }

        if (isset($file['bytes'])) {
            return false !== @file_put_contents($dest, $file['bytes']);
        }

        if (isset($file['bits'])) {
            return false !== @file_put_contents($dest, $file['bits']);
        }

        return false;
    }

    private static function originDir(array $cfg): string
    {
        $raw = isset($cfg['origin_dir']) ? (string)$cfg['origin_dir'] : '';
        $dir = preg_replace('~/+~', '/', str_replace('\\', '/', trim($raw)));
        $dir = trim((string)$dir, '/');

        if ('' === $dir || false !== strpos($dir, '..')) {
            return self::ORIGIN_DIR;
        }

        return $dir;
    }

    private static function makeDir(string $dir): bool
    {
        return is_dir($dir) || @mkdir($dir, 0755, true);
    }

    public static function dropArchive(string $path): void
    {
        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : \Widget\Upload::UPLOAD_DIR;
        $prefix = rtrim($uploadDir, '/') . '/';

        if (0 !== strpos($path, $prefix)) {
            return;
        }

        $inner = substr($path, strlen($prefix));
        $slash = strrpos($inner, '/');
        $dot = strrpos($inner, '.');

        if (false === $slash || false === $dot || $dot < $slash) {
            return;
        }

        $base = substr($inner, $slash + 1, $dot - $slash - 1);
        $base = preg_replace('~[^0-9A-Za-z_-]~', '', $base);

        if ('' === $base) {
            return;
        }

        $root = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
        $dir = \Typecho\Common::url('/' . self::originDir(self::settings()), $root) . '/' . substr($inner, 0, $slash);

        foreach ((array)@glob($dir . '/' . $base . '.*') as $item) {
            if (is_file($item)) {
                @unlink($item);
            }
        }

        if (is_dir($dir) && !@glob($dir . '/*')) {
            @rmdir($dir);
        }
    }

}
