<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Typecho文件管家：文件分类/批量整理/文件分析/图片格式转换/压缩解压/文件分享/对接图床对象存储/编辑器集成等众多功能。
 * 
 * @package 文件管家
 * @version 1.0.0
 * @author 不凡博客
 * @link https://www.bufanz.com
 */
class FileManager_Plugin implements Typecho_Plugin_Interface
{

    const VERSION = '1.0.0';

    const DEFAULT_CATEGORIES = '图片|jpg,jpeg,png,bmp,gif,webp,ico,svg
视频|mp4,webm,mov,m4v,flv
音频|mp3,wav,ogg,aac,m4a,flac
文档|doc,docx,xls,xlsx,csv,ppt,pptx,pdf,txt
程序|exe,msi,deb,rpm,appimage,apk
压缩包|rar,zip,7z,tar.gz
字体|ttf,otf,woff,woff2,eot
电子书|epub,mobi,azw3,pdf
镜像|iso,img';

    const PREVIOUS_DEFAULT_CATEGORIES = '图片|jpg,jpeg,png,bmp,gif,webp,ico,svg
视频|mp4,webm,mov,m4v,flv
音频|mp3,wav,ogg,aac,m4a,flac
文档|doc,docx,xls,xlsx,ppt,pptx,pdf,txt
程序|exe,msi,deb,rpm,appimage,apk
压缩包|rar,zip,7z,tar.gz';

    public static $categories = array(
        'image'   => array('name' => '图片', 'exts' => array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif', 'ico', 'tif', 'tiff', 'heic')),
        'audio'   => array('name' => '音频', 'exts' => array('mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'ape', 'mid', 'midi')),
        'video'   => array('name' => '视频', 'exts' => array('mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp', 'rmvb', 'rm', 'ts')),
        'doc'     => array('name' => '文档', 'exts' => array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv', 'rtf', 'odt', 'ods', 'odp', 'wps', 'et', 'dps', 'epub', 'mobi', 'azw3')),
        'code'    => array('name' => '程序', 'exts' => array('php', 'js', 'mjs', 'css', 'html', 'htm', 'json', 'xml', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'go', 'rs', 'sh', 'bat', 'cmd', 'ps1', 'sql', 'ts', 'jsx', 'tsx', 'vue', 'jar', 'rb', 'pl', 'swift', 'kt', 'yml', 'yaml', 'ini', 'conf', 'lua', 'gradle', 'ipynb')),
        'archive' => array('name' => '压缩包', 'exts' => array('zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz', 'iso', 'cab', 'dmg', 'apk', 'jar.pack')),
    );

    public static function activate()
    {
        self::createTables();
        self::ensurePrefsTable();
        self::ensureShareLogs();

        try {
            $fmDb = \Typecho\Db::get();
            $fmRow = $fmDb->fetchRow($fmDb->select('value')->from('table.options')
                ->where('name = ?', 'plugin:FileManager')->limit(1));
            if ($fmRow && !empty($fmRow['value'])) {

                $fmRaw = (string) $fmRow['value'];
                $fmTrim = ltrim($fmRaw);
                $fmIsJson = ('' !== $fmTrim && ('{' === $fmTrim[0] || '[' === $fmTrim[0]));
                $fmConfig = $fmIsJson ? json_decode($fmRaw, true) : @unserialize($fmRaw);
                if (is_array($fmConfig)) {
                    $fmKnown = array(
                        'defaultExpire', 'defaultMaxDownloads', 'customCategories',
                        'shareHeaderHtml', 'shareFooterHtml', 'editorVideoHtml',
                        'zipEncryption', 'uploadPathStyle', 'autoMapPrefix',
                        'recycleEnabled', 'recycleDays',

                        'imgEnabled', 'quality', 'max_width', 'max_height',
                        'size_policy', 'origin_policy', 'origin_dir',
                        'watermark', 'wm_editor_panel', 'wm_type', 'wm_text',
                        'wm_color', 'wm_font', 'wm_size', 'wm_image', 'wm_pos',
                        'wm_angle', 'wm_opacity', 'wm_margin', 'wm_min_width', 'wm_min_height',
                    );
                    $fmPruned = array();
                    foreach ($fmConfig as $fmKey => $fmVal) {
                        if (in_array($fmKey, $fmKnown, true)) {
                            $fmPruned[$fmKey] = $fmVal;
                        }
                    }
                    if (count($fmPruned) !== count($fmConfig)) {
                        $fmOut = $fmIsJson ? json_encode($fmPruned) : serialize($fmPruned);
                        $fmDb->query($fmDb->update('table.options')
                            ->rows(array('value' => $fmOut))
                            ->where('name = ?', 'plugin:FileManager'));
                    }
                }
            }
        } catch (\Throwable $e) {

        }

        $existing = array();
        try {
            $config = \Widget\Options::alloc()->plugin('FileManager');
            foreach (array('defaultExpire', 'defaultMaxDownloads', 'customCategories', 'zipEncryption', 'uploadPathStyle', 'autoMapPrefix', 'recycleDays',
                           'imgEnabled', 'quality', 'size_policy', 'origin_policy', 'origin_dir',
                           'watermark', 'wm_editor_panel', 'wm_type', 'wm_color', 'wm_size',
                           'wm_pos', 'wm_angle', 'wm_opacity', 'wm_margin', 'wm_min_width', 'wm_min_height') as $key) {
                $existing[$key] = isset($config->{$key}) ? trim((string) $config->{$key}) : '';
            }
        } catch (\Throwable $e) {
            $existing = array('defaultExpire' => '', 'defaultMaxDownloads' => '', 'customCategories' => '', 'zipEncryption' => '', 'uploadPathStyle' => '', 'autoMapPrefix' => '', 'recycleDays' => '',
                              'imgEnabled' => '', 'quality' => '', 'size_policy' => '', 'origin_policy' => '', 'origin_dir' => '',
                              'watermark' => '', 'wm_editor_panel' => '', 'wm_type' => '', 'wm_color' => '', 'wm_size' => '',
                              'wm_pos' => '', 'wm_angle' => '', 'wm_opacity' => '', 'wm_margin' => '', 'wm_min_width' => '', 'wm_min_height' => '');
        }

        $defaults = array(
            'defaultExpire'       => '0',
            'defaultMaxDownloads' => '0',
            'customCategories'    => self::DEFAULT_CATEGORIES,
            'zipEncryption'       => '0',
            'uploadPathStyle'     => '0',
            'autoMapPrefix'       => '',
            'recycleDays'         => '30',

            'imgEnabled'          => '1',
            'quality'             => '95',
            'size_policy'         => 'keep',
            'origin_policy'       => 'backup',
            'origin_dir'          => 'usr/uploads/_backup_img',
            'watermark'           => '0',
            'wm_editor_panel'     => '1',
            'wm_type'             => 'text',
            'wm_color'            => '#ff0000',
            'wm_size'             => '24',
            'wm_pos'              => 'bottom-right',
            'wm_angle'            => '0',
            'wm_opacity'          => '80',
            'wm_margin'           => '20',
            'wm_min_width'        => '200',
            'wm_min_height'       => '200',
        );

        $missing = array();
        foreach ($defaults as $key => $value) {
            if (!isset($existing[$key])) {
                $existing[$key] = '';
            }
            if ('' === $existing[$key]) {
                $missing[$key] = $value;
            }
        }

        if (self::PREVIOUS_DEFAULT_CATEGORIES !== self::DEFAULT_CATEGORIES
            && $existing['customCategories'] === trim(self::PREVIOUS_DEFAULT_CATEGORIES)) {
            $missing['customCategories'] = self::DEFAULT_CATEGORIES;
        }

        if (!empty($missing)) {
            Helper::configPlugin('FileManager', $missing);
        }

        foreach (array('ttf', 'img') as $assetDir) {
            $dir = __DIR__ . '/' . $assetDir;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        Helper::addRoute('filemanager_share', '/file/s/[token:alpha]', 'FileManager_Share_Action', 'action');

        /* 下载走 /download/<文件名> 子路径，绕开 WAF 对根目录 .zip 直链的拦截；路由未注册时退回旧 URL */
        Helper::addRoute('filemanager_download', '/download/[name]', 'FileManager_Share_Action', 'action');

        Helper::addAction('filemanager', 'FileManager_Action');

        \Typecho\Plugin::factory('Widget\Upload')->uploadHandle = array('FileManager_Plugin', 'uploadHandle');

        \Typecho\Plugin::factory('Widget\Upload')->modifyHandle = array('FileManager_ImageTool', 'modifyHandle');
        \Typecho\Plugin::factory('Widget\Upload')->deleteHandle = array('FileManager_ImageTool', 'deleteHandle');

        Helper::addPanel(3, 'FileManager/manage.php', '文件管家', '文件管家', 'administrator');

        return _t('FileManager 已启用，请前往后台「管理 → 文件管理」使用');
    }

    public static function deactivate()
    {
        Helper::removeRoute('filemanager_share');
        Helper::removeRoute('filemanager_download');
        Helper::removeAction('filemanager');
        Helper::removePanel(3, 'FileManager/manage.php');
    }

    public static function config(\Typecho\Widget\Helper\Form $form)
    {
        $custom = new \Typecho\Widget\Helper\Form\Element\Textarea(
            'customCategories',
            null,
            self::DEFAULT_CATEGORIES,
            _t('自定义文件类型分类（每行一条）'),
            _t('格式：分类名称|后缀1,后缀2,后缀3，例如：图片|jpg,jpeg,png,bmp,webp,svg<br>'
                . '支持中文竖线｜；# 开头的行忽略；兼容旧格式（分类名称:后缀…）<br>'
                . '留空则使用内置默认分类：图片、视频、音频、文档、程序、压缩包、字体、电子书、镜像九类<br>'
                . '填写后下拉框出现对应分类，用于类型筛选和一键归类')
        );
        $form->addInput($custom);

        $videoHtml = new \Typecho\Widget\Helper\Form\Element\Checkbox(
            'editorVideoHtml',
            array(1 => _t('启用')),
            array(),
            _t('文章编辑器插入视频类文件采用 HTML 代码'),
            _t('关闭：插入 Markdown 链接（Typecho 原生行为）；开启：插入 &lt;video&gt; 播放器代码，'
                . '适用于 mp4、webm、m4v、mov、ogv 等浏览器可直接播放的视频格式')
        );
        $form->addInput($videoHtml);

        $expire = new \Typecho\Widget\Helper\Form\Element\Select(
            'defaultExpire',
            array('0' => _t('永久有效'), '1' => _t('1 天'), '7' => _t('7 天'), '30' => _t('30 天')),
            '0',
            _t('分享链接默认有效期'),
            _t('创建分享链接时的默认有效期，创建时可单独修改')
        );
        $form->addInput($expire);

        $max = new \Typecho\Widget\Helper\Form\Element\Text(
            'defaultMaxDownloads',
            null,
            '0',
            _t('分享默认下载次数上限'),
            _t('0 表示不限制，创建分享链接时可单独修改')
        );
        $max->addRule('isInteger', _t('请填写非负整数'));
        $form->addInput($max);

        $shareHeader = new \Typecho\Widget\Helper\Form\Element\Textarea(
            'shareHeaderHtml',
            null,
            '',
            _t('分享页顶部自定义 HTML'),
            _t('显示在分享卡片上方的页面顶部区域，支持任意 HTML，可放站点 Logo、图片或广告位代码')
        );
        $form->addInput($shareHeader);

        $shareFooter = new \Typecho\Widget\Helper\Form\Element\Textarea(
            'shareFooterHtml',
            null,
            '',
            _t('分享页底部自定义 HTML'),
            _t('显示在分享卡片下方的页面底部区域，支持任意 HTML，可放版权信息或广告位代码')
        );
        $form->addInput($shareFooter);

        $pathStyle = new \Typecho\Widget\Helper\Form\Element\Select(
            'uploadPathStyle',
            array(
                '0'          => _t('不启用（默认按 年/月 子目录存放）'),
                'uid'        => _t('/{用户ID}'),
                'name'       => _t('/{用户名}'),
                'screenName' => _t('/{用户昵称}'),
            ),
            '0',
            _t('站点用户上传文件存放位置'),
            _t('启用后，后台上传的附件将存放在「上传目录/{用户ID}」「上传目录/{用户名}」或「上传目录/{用户昵称}」下，替代默认的 年/月 子目录<br>'
                . '站点管理员（用户ID 1）不受影响，始终按 年/月 子目录存放<br>'
                . '同时启用自动归档：文件管理页文件夹树中会按上传者自动创建对应用户文件夹并归入其上传的文件（在指定文件夹上传时归入所选文件夹），无需手动整理<br>'
                . '通过文件管理页或编辑器选择器在指定文件夹上传时，物理目录会附带文件夹树路径（如 /{用户名}/摄影/风景/），未选文件夹或原生上传则直接存于用户目录下<br>'
                . '用户名/昵称中的空格与特殊字符会自动转为短横线；均为空或清理后无效时回退为用户ID<br>'
                . '物理路径在上传时确定：之后移动文件夹、重命名文件夹或修改昵称均不改变已上传文件的位置与链接')
        );
        $form->addInput($pathStyle);

        $autoMapPrefix = new \Typecho\Widget\Helper\Form\Element\Text(
            'autoMapPrefix',
            null,
            '',
            _t('自动归档前缀文件夹'),
            _t('填写后，文件夹树中会先创建这个一级文件夹，各用户的文件夹建在它下面，例如填「用户投稿文件」时：<br>'
                . '用户ID 2 上传的文件在文件夹树中的路径为 /用户投稿文件/2/，物理目录同样存放在 上传目录/用户投稿文件/2/ 下<br>'
                . '留空则不建前缀，用户文件夹直接位于文件夹树顶级（/2/）<br>'
                . '仅在上方「站点用户上传文件存放位置」启用任一方案时生效；文件夹树路径在上传时确定，之后修改本项不影响已归档文件')
        );
        $form->addInput($autoMapPrefix);

        $recycleEnabled = new \Typecho\Widget\Helper\Form\Element\Checkbox(
            'recycleEnabled',
            array(1 => _t('启用')),
            array(1),
            _t('删除文件移入回收站'),
            _t('启用后，文件管理页删除、批量删除、清理未归档文件、压缩/解压后删除原文件时，附件先移入回收站而非直接物理删除，保留期满前可一键还原或彻底删除<br>'
                . '回收站内的文件不占用文章附件，外部无法访问；保留期满后自动物理清除')
        );
        $form->addInput($recycleEnabled);

        $recycleDays = new \Typecho\Widget\Helper\Form\Element\Text(
            'recycleDays',
            null,
            '30',
            _t('回收站保留天数'),
            _t('回收站内的文件超过该天数后自动物理清除，最小 1 天；仅对之后删除的文件生效，已在回收站中的文件按其入站时的天数计算')
        );
        $form->addInput($recycleDays);

        $zipEncryption = new \Typecho\Widget\Helper\Form\Element\Hidden('zipEncryption', null, '0');
        $form->addInput($zipEncryption);

        FileManager_ImageTool::configSection($form);

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Checkbox(
            'purgeTables',
            array(1 => _t('删除插件生成的数据表')),
            array(),
            _t('数据表维护'),
            _t('勾选后点击下方「保存设置」，立即删除本插件创建的数据表（不会删除上传的附件）')
        ));
    }

    public static function configHandle(array $settings, bool $isInit)
    {
        $purge = !$isInit && !empty($settings['purgeTables']);
        unset($settings['purgeTables']);

        \Widget\Plugins\Edit::configPlugin(basename(__DIR__), $settings);

        if ($purge) {
            self::purgeDataTables();
        }
    }

    private static function purgeDataTables()
    {
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();

        foreach (array('folders', 'map', 'prefs', 'shares', 'share_logs', 'trash') as $table) {
            try {
                $db->query('DROP TABLE IF EXISTS `' . $prefix . 'filemanager_' . $table . '`');
            } catch (\Throwable $e) {
            }
        }
    }

    public static function personalConfig(\Typecho\Widget\Helper\Form $form)
    {
    }

    public static function categories()
    {
        $settings = self::settings();
        $custom = isset($settings['customCategories']) ? trim((string) $settings['customCategories']) : '';

        if ('' === $custom) {
            $custom = self::DEFAULT_CATEGORIES;
        }

        $cats = array();
        $lines = preg_split('/[\r\n]+/u', $custom);

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || 0 === strpos($line, '#')) {
                continue;
            }

            $parts = preg_split('/[|｜]/u', $line, 2);
            if (false === $parts || count($parts) !== 2) {
                $parts = preg_split('/[:：]/u', $line, 2);
            }
            if (false === $parts || count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $exts = array();

            foreach (preg_split('/[,，;；\s]+/u', strtolower($parts[1])) as $ext) {

                $ext = preg_replace('/[^a-z0-9.]/', '', strtolower(trim($ext, " .\t\n\r")));
                if ('' !== $ext && strlen($ext) <= 16) {
                    $exts[] = $ext;

                    if (false !== strpos($ext, '.')) {
                        $tail = substr($ext, strrpos($ext, '.') + 1);
                        if ('' !== $tail) {
                            $exts[] = $tail;
                        }
                    }
                }
            }

            if ('' !== $name && !empty($exts) && !isset($cats[$name])) {
                $cats[$name] = array('name' => $name, 'exts' => array_values(array_unique($exts)));
            }
        }

        return $cats;
    }

    public static function ensureShareSchema()
    {
        static $done = false;

        if ($done) {
            return;
        }
        $done = true;

        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();
        $adapter = strtolower($db->getAdapterName());

        $columns = array(
            'login_required' => 'INTEGER NOT NULL DEFAULT 0',
            'display_name'   => "TEXT NOT NULL DEFAULT ''",
            'note'           => "TEXT NOT NULL DEFAULT ''",
            'password_plain' => "TEXT NOT NULL DEFAULT ''",
            'views'          => 'INTEGER NOT NULL DEFAULT 0',
            'preview'        => 'INTEGER NOT NULL DEFAULT 0',
            'mysql:login_required' => 'tinyint unsigned NOT NULL DEFAULT 0',
            'mysql:display_name'   => "varchar(255) NOT NULL DEFAULT ''",
            'mysql:note'           => "varchar(2000) NOT NULL DEFAULT ''",
            'mysql:password_plain' => "varchar(255) NOT NULL DEFAULT ''",
            'mysql:views'          => 'int unsigned NOT NULL DEFAULT 0',
            'mysql:preview'        => 'tinyint unsigned NOT NULL DEFAULT 0',
        );

        try {
            if (false !== strpos($adapter, 'sqlite')) {
                $cols = array();
                foreach ((array) $db->fetchAll('PRAGMA table_info(`' . $prefix . 'filemanager_shares`)') as $col) {
                    $cols[] = isset($col['name']) ? $col['name'] : '';
                }
                foreach (array('login_required', 'display_name', 'note', 'password_plain', 'views', 'preview') as $name) {
                    if (!in_array($name, $cols, true)) {
                        $db->query('ALTER TABLE `' . $prefix . 'filemanager_shares`'
                            . ' ADD COLUMN ' . $name . ' ' . $columns[$name]);
                    }
                }
            } else {
                foreach (array('login_required', 'display_name', 'note', 'password_plain', 'views', 'preview') as $name) {
                    $exists = $db->fetchRow("SHOW COLUMNS FROM `{$prefix}filemanager_shares` LIKE '{$name}'");
                    if (!$exists) {
                        $db->query("ALTER TABLE `{$prefix}filemanager_shares`"
                            . " ADD COLUMN `{$name}` " . $columns['mysql:' . $name]);
                    }
                }
            }
        } catch (\Typecho\Db\Exception $e) {

        }

        self::ensureShareLogs();
    }

    public static function ensureShareLogs()
    {
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();
        $adapter = strtolower($db->getAdapterName());

        try {
            if (false !== strpos($adapter, 'sqlite')) {
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}filemanager_share_logs` (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    share_id INTEGER NOT NULL DEFAULT 0,
                    action TEXT NOT NULL DEFAULT '',
                    ip TEXT NOT NULL DEFAULT '',
                    ua TEXT NOT NULL DEFAULT '',
                    created INTEGER NOT NULL DEFAULT 0
                )");
                $db->query("CREATE INDEX IF NOT EXISTS `{$prefix}filemanager_share_logs_share`"
                    . " ON `{$prefix}filemanager_share_logs` (share_id)");
            } else {
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}filemanager_share_logs` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `share_id` int unsigned NOT NULL DEFAULT 0,
                    `action` varchar(16) NOT NULL DEFAULT '',
                    `ip` varchar(64) NOT NULL DEFAULT '',
                    `ua` varchar(255) NOT NULL DEFAULT '',
                    `created` int unsigned NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `share_id` (`share_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        } catch (\Typecho\Db\Exception $e) {

        }
    }

    public static function settings()
    {
        $defaults = array(
            'defaultExpire'       => '0',
            'defaultMaxDownloads' => '0',
            'customCategories'    => '',
            'shareHeaderHtml'     => '',
            'shareFooterHtml'     => '',
            'editorVideoHtml'     => 0,
            'zipEncryption'       => '0',
            'uploadPathStyle'     => '0',
            'autoMapPrefix'       => '',
            'recycleEnabled'      => 0,
            'recycleDays'         => '30',
        );

        try {
            $config = \Widget\Options::alloc()->plugin('FileManager');
            foreach ($defaults as $key => $value) {
                if ('editorVideoHtml' === $key) {

                    $defaults[$key] = isset($config->{$key}) && !empty($config->{$key}) ? 1 : 0;
                } elseif ('recycleEnabled' === $key) {

                    $defaults[$key] = !isset($config->{$key}) ? 1 : (!empty($config->{$key}) ? 1 : 0);
                } else {
                    $defaults[$key] = isset($config->{$key}) ? (string) $config->{$key} : $value;
                }
            }
        } catch (\Throwable $e) {

        }

        return $defaults;
    }

    public static function encryptionSupport()
    {
        $zipExt = phpversion('zip');
        $libzip = defined('ZipArchive::LIBZIP_VERSION') ? ZipArchive::LIBZIP_VERSION : '';
        $hasCrypto = defined('ZipArchive::EM_ZIPCRYPTO');
        $hasAes = defined('ZipArchive::EM_AES256');

        $env = 'PHP ' . PHP_VERSION . ' / zip 扩展 ' . ($zipExt ? $zipExt : '未加载')
            . ('' !== $libzip ? ' / libzip ' . $libzip : '');

        if ($hasCrypto && $hasAes) {
            $label = $env . '；加密写入：支持 ZipCrypto 和 AES-256（以实际压缩为准）';
        } elseif ($hasCrypto) {
            $label = $env . '；加密写入：仅支持 ZipCrypto';
        } elseif ($hasAes) {
            $label = $env . '；加密写入：仅支持 AES-256';
        } else {
            $label = $env . '；加密写入：不支持（当前 zip 扩展的 libzip 未启用加密，设置解压密码将无法打包）';
        }

        return array(
            'crypto' => $hasCrypto,
            'aes'    => $hasAes,
            'label'  => $label,
            'env'    => $env,
        );
    }

    public static function sanitizeUserDir($name)
    {
        $name = trim((string) $name);
        if ('' === $name) {
            return '';
        }

        $name = str_replace(array('\\', '/'), ' ', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);

        $name = str_replace(array(':', '*', '?', '"', '<', '>', '|', '#', '%'), '', $name);
        $name = preg_replace('/\s+/u', '-', $name);
        $name = preg_replace('/-{2,}/u', '-', $name);
        $name = trim($name, "-. \t\n\r\0\x0B");

        if (preg_match('/^.{0,48}/us', $name, $m)) {
            $name = $m[0];
        }

        return rtrim($name, '-.');
    }

    private static function uploadUserSubDir($style)
    {
        try {
            $user = \Widget\User::alloc();
        } catch (\Throwable $e) {
            return '';
        }

        if (!$user || empty($user->uid)) {
            return '';
        }

        if (1 === (int) $user->uid) {
            return '';
        }

        if ('uid' === $style) {
            return (string) $user->uid;
        }

        $candidates = ('name' === $style)
            ? array($user->name, $user->screenName)
            : array($user->screenName, $user->name);

        foreach ($candidates as $candidate) {
            $dir = self::sanitizeUserDir($candidate);
            if ('' !== $dir) {
                return $dir;
            }
        }

        return (string) $user->uid;
    }

    public static $folderRowFetcher = null;

    private static function fetchFolderRow($fid)
    {
        if (null !== self::$folderRowFetcher) {
            return call_user_func(self::$folderRowFetcher, $fid);
        }

        try {
            $db = \Typecho\Db::get();
        } catch (\Throwable $e) {
            return null;
        }

        return $db->fetchRow($db->select('fid', 'name', 'parent')
            ->from('table.filemanager_folders')
            ->where('fid = ?', $fid)
            ->limit(1));
    }

    public static function uploadFolderDirSeg($fid)
    {
        $fid = (int) $fid;
        if ($fid <= 0) {
            return '';
        }

        $segs = array();
        $cur = $fid;
        $depth = 0;

        while ($cur > 0 && $depth < 32) {
            $row = self::fetchFolderRow($cur);
            if (!$row || empty($row['name'])) {
                break;
            }
            $seg = self::sanitizeUserDir($row['name']);
            if ('' !== $seg) {
                array_unshift($segs, $seg);
            }
            $cur = isset($row['parent']) ? (int) $row['parent'] : 0;
            $depth++;
        }

        return implode('/', $segs);
    }

    public static $userRowFetcher = null;

    private static function fetchUserRow($uid)
    {
        if (null !== self::$userRowFetcher) {
            return call_user_func(self::$userRowFetcher, $uid);
        }

        try {
            $db = \Typecho\Db::get();
        } catch (\Throwable $e) {
            return null;
        }

        try {
            return $db->fetchRow($db->select('name', 'screenName')->from('table.users')
                ->where('uid = ?', (int) $uid)->limit(1));
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function userDisplayDir($uid, $style)
    {
        $uid = (int) $uid;
        if ($uid <= 0 || 1 === $uid) {
            return '';
        }

        $row = self::fetchUserRow($uid);
        if (!$row) {
            return (string) $uid;
        }

        if ('uid' === $style) {
            return (string) $uid;
        }

        $candidates = ('screenName' === $style)
            ? array(isset($row['screenName']) ? $row['screenName'] : '', isset($row['name']) ? $row['name'] : '')
            : array(isset($row['name']) ? $row['name'] : '', isset($row['screenName']) ? $row['screenName'] : '');

        foreach ($candidates as $candidate) {
            $dir = self::sanitizeUserDir($candidate);
            if ('' !== $dir) {
                return $dir;
            }
        }

        return (string) $uid;
    }

    private static function autoMapPrefixName($settings)
    {
        $raw = isset($settings['autoMapPrefix']) ? trim((string) $settings['autoMapPrefix']) : '';
        if ('' === $raw) {
            return '';
        }

        return self::sanitizeUserDir($raw);
    }

    private static function ensureUserFolder($db, $name, $ownerUid, $parent = 0)
    {
        $parent = (int) $parent;
        try {
            $exist = $db->fetchRow($db->select('fid')->from('table.filemanager_folders')
                ->where('name = ? AND parent = ?', $name, $parent)->limit(1));
            if ($exist) {
                return (int) $exist['fid'];
            }

            $newFid = (int) $db->query($db->insert('table.filemanager_folders')->rows(array(
                'name'    => $name,
                'parent'  => $parent,
                'sort'    => 0,
                'owner'   => (int) $ownerUid,
                'created' => time(),
            )));

            if ($newFid <= 0) {
                $again = $db->fetchRow($db->select('fid')->from('table.filemanager_folders')
                    ->where('name = ? AND parent = ?', $name, $parent)
                    ->order('fid', \Typecho\Db::SORT_DESC)->limit(1));
                $newFid = $again ? (int) $again['fid'] : 0;
            }

            return $newFid;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function autoMapUserFolder($cid, $uid)
    {
        $cid = (int) $cid;
        $uid = (int) $uid;
        if ($cid <= 0 || $uid <= 1) {
            return false;
        }

        $settings = self::settings();
        $style = isset($settings['uploadPathStyle']) ? $settings['uploadPathStyle'] : '0';
        if (!in_array($style, array('uid', 'name', 'screenName'), true)) {
            return false;
        }

        $name = self::userDisplayDir($uid, $style);
        if ('' === $name) {
            return false;
        }

        try {
            $db = \Typecho\Db::get();
        } catch (\Throwable $e) {
            return false;
        }

        $parent = 0;
        $prefix = self::autoMapPrefixName($settings);
        if ('' !== $prefix) {
            $parent = self::ensureUserFolder($db, $prefix, $uid, 0);
            if ($parent <= 0) {
                return false;
            }
        }

        $fid = self::ensureUserFolder($db, $name, $uid, $parent);
        if ($fid <= 0) {
            return false;
        }

        try {
            $db->query($db->update('table.filemanager_map')
                ->rows(array('fid' => $fid))
                ->where('cid = ? AND fid = 0', $cid));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function autoOrganizeUnmapped()
    {
        $settings = self::settings();
        $style = isset($settings['uploadPathStyle']) ? $settings['uploadPathStyle'] : '0';
        if (!in_array($style, array('uid', 'name', 'screenName'), true)) {
            return 0;
        }

        try {
            $db = \Typecho\Db::get();
        } catch (\Throwable $e) {
            return 0;
        }

        try {
            $rows = $db->fetchAll($db->select('table.contents.cid', 'table.contents.authorId')
                ->from('table.contents')
                ->join('table.filemanager_map', 'table.contents.cid = table.filemanager_map.cid', \Typecho\Db::INNER_JOIN)
                ->where('table.contents.type = ?', 'attachment')
                ->where('table.filemanager_map.fid = ?', 0));
        } catch (\Throwable $e) {
            return 0;
        }

        if (!$rows) {
            return 0;
        }

        $prefixParent = 0;
        $prefix = self::autoMapPrefixName($settings);
        if ('' !== $prefix) {
            $prefixParent = self::ensureUserFolder($db, $prefix, 0, 0);
        }

        $folders = array();
        $count = 0;

        foreach ($rows as $row) {
            $uid = (int) $row['authorId'];
            if ($uid <= 1) {
                continue;
            }

            if (!isset($folders[$uid])) {
                $name = self::userDisplayDir($uid, $style);
                $folders[$uid] = ('' !== $name)
                    ? self::ensureUserFolder($db, $name, $uid, $prefixParent)
                    : 0;
            }

            $fid = isset($folders[$uid]) ? (int) $folders[$uid] : 0;
            if ($fid <= 0) {
                continue;
            }

            try {
                $db->query($db->update('table.filemanager_map')
                    ->rows(array('fid' => $fid))
                    ->where('cid = ? AND fid = 0', (int) $row['cid']));
                $count++;
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $count;
    }

    public static function getUserPrefs($uid = 0)
    {
        $uid = (int) $uid;
        if ($uid <= 0) {
            try {
                $uid = (int) \Widget\User::alloc()->uid;
            } catch (\Throwable $e) {
                return array();
            }
        }
        if ($uid <= 0) {
            return array();
        }

        try {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow($db->select('prefs')->from('table.filemanager_prefs')
                ->where('uid = ?', $uid)->limit(1));
            $prefs = $row ? json_decode($row['prefs'], true) : array();
            return is_array($prefs) ? $prefs : array();
        } catch (\Throwable $e) {
            return array();
        }
    }

    public static function prefUploadFid($uid = 0)
    {
        $prefs = self::getUserPrefs($uid);
        $fid = isset($prefs['uploadFid']) ? (int) $prefs['uploadFid'] : 0;
        if (0 === $fid) {
            return 0;
        }

        try {
            $db = \Typecho\Db::get();

            if (-1 === $fid) {
                $prefix = $db->getPrefix();
                $row = $db->fetchRow("SELECT m.fid FROM `{$prefix}filemanager_map` m"
                    . " INNER JOIN `{$prefix}contents` c ON c.cid = m.cid"
                    . " WHERE c.type = 'attachment'"
                    . " ORDER BY c.created DESC, c.cid DESC LIMIT 1");
                return ($row && (int) $row['fid'] > 0) ? (int) $row['fid'] : 0;
            }

            $row = $db->fetchRow($db->select('fid')->from('table.filemanager_folders')
                ->where('fid = ?', $fid)->limit(1));
            return $row ? $fid : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function ensurePrefsTable()
    {
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();
        $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}filemanager_prefs` ("
            . "`uid` INT UNSIGNED NOT NULL PRIMARY KEY, "
            . "`prefs` TEXT NOT NULL)");
    }

    public static $lastUploadResult = null;

    public static $uploadOverrides = null;

    public static function uploadHandle(array $file)
    {

        if (null !== self::$lastUploadResult) {
            return self::$lastUploadResult;
        }

        if (empty($file['name'])) {
            return false;
        }

        $name = str_replace(array('"', '<', '>'), '', $file['name']);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $ext = isset($info['extension']) ? strtolower($info['extension']) : '';

        if (!\Widget\Upload::checkFileType($ext)) {
            return false;
        }

        $settings = self::settings();
        $style = isset($settings['uploadPathStyle']) ? $settings['uploadPathStyle'] : '0';

        $sub = ('uid' === $style || 'name' === $style || 'screenName' === $style)
            ? self::uploadUserSubDir($style)
            : '';
        if ('' === $sub) {

            $date = new \Typecho\Date();
            $sub = $date->year . '/' . $date->month;
        } elseif (isset($_REQUEST['fid'])) {

            $seg = self::uploadFolderDirSeg($_REQUEST['fid']);
            if ('' !== $seg) {
                $sub .= '/' . $seg;
            }
        } else {

            $prefix = self::autoMapPrefixName($settings);
            if ('' !== $prefix) {
                $sub = $prefix . '/' . $sub;
            }
        }

        $base = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : '/usr/uploads';
        $root = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
        $dir = \Typecho\Common::url($base, $root) . '/' . $sub;

        if (!is_dir($dir) && !self::makeUploadDir($dir)) {
            return false;
        }

        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $dir . '/' . $fileName;

        if (isset($file['tmp_name'])) {
            if (!@move_uploaded_file($file['tmp_name'], $path)) {
                return false;
            }
        } elseif (isset($file['bytes'])) {
            if (!file_put_contents($path, $file['bytes'])) {
                return false;
            }
        } elseif (isset($file['bits'])) {
            if (!file_put_contents($path, $file['bits'])) {
                return false;
            }
        } else {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($path);
        }

        $result = array(
            'name' => $file['name'],
            'path' => $base . '/' . $sub . '/' . $fileName,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => \Typecho\Common::mimeContentType($path),
        );

        $result = FileManager_ImageTool::processStored($result, $path,
            is_array(self::$uploadOverrides) ? self::$uploadOverrides : array());

        self::$lastUploadResult = $result;

        return $result;
    }

    private static function makeUploadDir(string $path): bool
    {
        $path = preg_replace("/\\\+/", '/', $path);
        $current = rtrim($path, '/');
        $last = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last = $current;
            $current = dirname($current);
        }

        if ($last == $current) {
            return true;
        }

        if (!@mkdir($last, 0755)) {
            return false;
        }

        return self::makeUploadDir($path);
    }

    private static function createTables()
    {
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();
        $adapter = strtolower($db->getAdapterName());

        try {
            if (false !== strpos($adapter, 'sqlite')) {
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}filemanager_folders` (
                    fid INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL DEFAULT '',
                    parent INTEGER NOT NULL DEFAULT 0,
                    sort INTEGER NOT NULL DEFAULT 0,
                    owner INTEGER NOT NULL DEFAULT 0,
                    created INTEGER NOT NULL DEFAULT 0
                )");
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}filemanager_map` (
                    cid INTEGER NOT NULL DEFAULT 0,
                    fid INTEGER NOT NULL DEFAULT 0,
                    size INTEGER NOT NULL DEFAULT 0,
                    ext TEXT NOT NULL DEFAULT '',
                    PRIMARY KEY (cid)
                )");
                $db->query("CREATE INDEX IF NOT EXISTS `{$prefix}filemanager_map_fid` ON `{$prefix}filemanager_map` (fid)");
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}filemanager_shares` (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    cid INTEGER NOT NULL DEFAULT 0,
                    token TEXT NOT NULL DEFAULT '',
                    password TEXT NOT NULL DEFAULT '',
                    password_plain TEXT NOT NULL DEFAULT '',
                    expire INTEGER NOT NULL DEFAULT 0,
                    max_downloads INTEGER NOT NULL DEFAULT 0,
                    login_required INTEGER NOT NULL DEFAULT 0,
                    display_name TEXT NOT NULL DEFAULT '',
                    note TEXT NOT NULL DEFAULT '',
                    downloads INTEGER NOT NULL DEFAULT 0,
                    views INTEGER NOT NULL DEFAULT 0,
                    preview INTEGER NOT NULL DEFAULT 0,
                    created INTEGER NOT NULL DEFAULT 0,
                    owner INTEGER NOT NULL DEFAULT 0
                )");
                $db->query("CREATE UNIQUE INDEX IF NOT EXISTS `{$prefix}filemanager_shares_token` ON `{$prefix}filemanager_shares` (token)");
                $db->query("CREATE INDEX IF NOT EXISTS `{$prefix}filemanager_shares_cid` ON `{$prefix}filemanager_shares` (cid)");
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}filemanager_trash` (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    cid INTEGER NOT NULL DEFAULT 0,
                    name TEXT NOT NULL DEFAULT '',
                    path TEXT NOT NULL DEFAULT '',
                    size INTEGER NOT NULL DEFAULT 0,
                    ext TEXT NOT NULL DEFAULT '',
                    mime TEXT NOT NULL DEFAULT '',
                    folder_fid INTEGER NOT NULL DEFAULT 0,
                    trash_name TEXT NOT NULL DEFAULT '',
                    data TEXT,
                    owner INTEGER NOT NULL DEFAULT 0,
                    created INTEGER NOT NULL DEFAULT 0,
                    expire INTEGER NOT NULL DEFAULT 0
                )");
                $db->query("CREATE INDEX IF NOT EXISTS `{$prefix}filemanager_trash_created` ON `{$prefix}filemanager_trash` (created)");
            } elseif (false !== strpos($adapter, 'pgsql')) {
                throw new \Typecho\Plugin\Exception(_t('FileManager 暂不支持 PostgreSQL 数据库'), 500);
            } else {
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}filemanager_folders` (
                    `fid` int unsigned NOT NULL AUTO_INCREMENT,
                    `name` varchar(200) NOT NULL DEFAULT '',
                    `parent` int unsigned NOT NULL DEFAULT 0,
                    `sort` int unsigned NOT NULL DEFAULT 0,
                    `owner` int unsigned NOT NULL DEFAULT 0,
                    `created` int unsigned NOT NULL DEFAULT 0,
                    PRIMARY KEY (`fid`),
                    KEY `parent` (`parent`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}filemanager_map` (
                    `cid` int unsigned NOT NULL DEFAULT 0,
                    `fid` int unsigned NOT NULL DEFAULT 0,
                    `size` bigint unsigned NOT NULL DEFAULT 0,
                    `ext` varchar(64) NOT NULL DEFAULT '',
                    PRIMARY KEY (`cid`),
                    KEY `fid` (`fid`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}filemanager_shares` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `cid` int unsigned NOT NULL DEFAULT 0,
                    `token` varchar(32) NOT NULL DEFAULT '',
                    `password` varchar(255) NOT NULL DEFAULT '',
                    `password_plain` varchar(255) NOT NULL DEFAULT '',
                    `expire` int unsigned NOT NULL DEFAULT 0,
                    `max_downloads` int unsigned NOT NULL DEFAULT 0,
                    `login_required` tinyint unsigned NOT NULL DEFAULT 0,
                    `display_name` varchar(255) NOT NULL DEFAULT '',
                    `note` varchar(2000) NOT NULL DEFAULT '',
                    `downloads` int unsigned NOT NULL DEFAULT 0,
                    `views` int unsigned NOT NULL DEFAULT 0,
                    `preview` tinyint unsigned NOT NULL DEFAULT 0,
                    `created` int unsigned NOT NULL DEFAULT 0,
                    `owner` int unsigned NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `token` (`token`),
                    KEY `cid` (`cid`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}filemanager_trash` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `cid` int unsigned NOT NULL DEFAULT 0,
                    `name` varchar(500) NOT NULL DEFAULT '',
                    `path` varchar(500) NOT NULL DEFAULT '',
                    `size` bigint unsigned NOT NULL DEFAULT 0,
                    `ext` varchar(64) NOT NULL DEFAULT '',
                    `mime` varchar(200) NOT NULL DEFAULT '',
                    `folder_fid` int unsigned NOT NULL DEFAULT 0,
                    `trash_name` varchar(600) NOT NULL DEFAULT '',
                    `data` mediumtext,
                    `owner` int unsigned NOT NULL DEFAULT 0,
                    `created` int unsigned NOT NULL DEFAULT 0,
                    `expire` int unsigned NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `created` (`created`),
                    KEY `expire` (`expire`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        } catch (\Typecho\Db\Exception $e) {
            throw new \Typecho\Plugin\Exception(_t('FileManager 数据表创建失败：%s', $e->getMessage()), 500);
        }
    }

    const SHARE_BTN_CSS = <<<'CSS'
a.fm-share-btn{display:inline-block!important;margin:2px 4px!important;border:0!important;background:#d9534f!important;color:#fff!important;font-size:14px!important;line-height:1.7!important;text-decoration:none!important;box-shadow:none!important;cursor:pointer;padding:8px 24px!important;border-radius:4px!important;vertical-align:middle}
a.fm-share-btn:hover{background:#c9302c!important;color:#fff!important}
a.fm-share-btn.fm-c-red{--sb-c:#d9534f;--sb-d:#c9302c;--sb-l:#fcebeb}
a.fm-share-btn.fm-c-blue{--sb-c:#185fa5;--sb-d:#0c447c;--sb-l:#e6f1fb}
a.fm-share-btn.fm-c-teal{--sb-c:#0f6e56;--sb-d:#085041;--sb-l:#e1f5ee}
a.fm-share-btn.fm-c-green{--sb-c:#3b6d11;--sb-d:#27500a;--sb-l:#eaf3de}
a.fm-share-btn.fm-c-amber{--sb-c:#ba7517;--sb-d:#854f0b;--sb-l:#faeeda}
a.fm-share-btn.fm-c-purple{--sb-c:#534ab7;--sb-d:#3c3489;--sb-l:#eeedfe}
a.fm-share-btn.fm-c-gray{--sb-c:#2c2c2a;--sb-d:#161615;--sb-l:#f1efe8}
a.fm-share-btn.fm-sb-a{background:var(--sb-c,#d9534f)!important;color:#fff!important;border:0!important;border-radius:4px!important;padding:8px 24px!important}
a.fm-share-btn.fm-sb-b{background:var(--sb-c,#d9534f)!important;color:#fff!important;border:0!important;border-radius:6px!important;padding:8px 24px!important}
a.fm-share-btn.fm-sb-c{background:transparent!important;color:var(--sb-c,#185fa5)!important;border:1.5px solid var(--sb-c,#185fa5)!important;border-radius:6px!important;padding:7px 22px!important}
a.fm-share-btn.fm-sb-c:hover{background:var(--sb-c,#185fa5)!important;color:#fff!important}
a.fm-share-btn.fm-sb-d{background:var(--sb-c,#d9534f)!important;color:#fff!important;border:0!important;border-radius:999px!important;padding:8px 26px!important}
a.fm-share-btn.fm-sb-e{background:var(--sb-d,#2c2c2a)!important;color:#fff!important;border:0!important;border-radius:4px!important;padding:8px 24px!important}
a.fm-share-btn.fm-sb-f{background:var(--sb-l,#e6f1fb)!important;color:var(--sb-c,#185fa5)!important;border:0!important;border-radius:6px!important;padding:8px 24px!important}
a.fm-share-btn.fm-sb-g{display:inline-flex!important;align-items:center!important;gap:10px!important;background:#fff!important;color:#2c2c2a!important;border:1px solid #d6dde4!important;border-radius:10px!important;padding:9px 14px!important}
a.fm-share-btn.fm-sb-g:hover{background:#fbfcfd!important;border-color:#b9c4d0!important}
a.fm-share-btn.fm-sb-g .fm-sb-ico{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:26px;height:26px;border-radius:50%;background:var(--sb-l,#e6f1fb);color:var(--sb-c,#185fa5);font-size:15px;line-height:1}
a.fm-share-btn.fm-sb-g .fm-sb-x{color:var(--sb-c,#185fa5);font-size:12px}
a.fm-share-btn.fm-sb-h{background:transparent!important;color:var(--sb-c,#185fa5)!important;border:0!important;border-bottom:1px solid var(--sb-c,#185fa5)!important;border-radius:0!important;padding:3px 1px!important}
a.fm-share-btn.fm-sb-h:hover{color:var(--sb-d,#0c447c)!important;border-bottom-color:var(--sb-d,#0c447c)!important;background:transparent!important}
a.fm-share-btn.fm-sb-a:hover,a.fm-share-btn.fm-sb-b:hover,a.fm-share-btn.fm-sb-d:hover,a.fm-share-btn.fm-sb-e:hover{background:var(--sb-d,#c9302c)!important;color:#fff!important}
a.fm-share-btn.fm-sb-f:hover{background:var(--sb-l,#e6f1fb)!important;color:var(--sb-d,#0c447c)!important}
.fmw-sb-sh a.fm-share-btn{padding:6px 12px!important;font-size:12px!important;margin:0!important}
.fmw-sb-sh a.fm-share-btn.fm-sb-g{padding:6px 9px!important;gap:6px!important}
.fmw-sb-sh a.fm-share-btn.fm-sb-g .fm-sb-ico{width:20px;height:20px;font-size:12px}
CSS;

    const PICKER_CSS = <<<'CSS'
#upload-panel { display: none !important; }
#fmw-panel { font-size: 13px; }
#fmw-panel .fmw-upload { border: 2px dashed #cfd8e1; border-radius: 8px; padding: 12px 10px; text-align: center; color: #7d8b99; cursor: pointer; transition: border-color .15s, background .15s; background: #fbfcfd; margin: 10px 0 14px; }
#fmw-panel .fmw-upload:hover, #fmw-panel .fmw-upload.drag { border-color: #2a6496; color: #2a6496; background: #f2f8fc; }
#fmw-panel .fmw-up-main { font-size: 13px; font-weight: 600; color: #516b83; }
#fmw-panel .fmw-upload:hover .fmw-up-main, #fmw-panel .fmw-upload.drag .fmw-up-main { color: #2a6496; }
#fmw-panel .fmw-up-row { margin-top: 6px; display: flex; align-items: center; justify-content: center; gap: 6px; color: #98a2ad; font-size: 12px; }
#fmw-panel .fmw-up-row #fmw-up-cur { color: #7d8b99; max-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#fmw-panel h4 { margin: 14px 0 8px; font-size: 13px; color: #516b83; }
#fmw-panel h4 .fmw-cnt { font-weight: normal; color: #98a2ad; font-size: 12px; }
.fmw-tabs { display: flex; list-style: none; margin: 14px 0 10px; padding: 0; border: 1px solid #d9e0e7; border-radius: 6px; overflow: hidden; background: #eef1f4; }
.fmw-tabs li { flex: 1; text-align: center; padding: 7px 0; font-size: 13px; color: #7d8b99; cursor: pointer; user-select: none; }
.fmw-tabs li.current { background: #fff; color: #333; font-weight: 600; }
.fmw-tabs li:hover:not(.current) { color: #2a6496; background: #f4f8fb; }
.fmw-bound-tools { display: flex; justify-content: flex-end; margin-bottom: 6px; }
.fmw-bound-toggle { float: right; border: 1px solid #d9e0e7; background: #fff; border-radius: 4px; font-size: 11px; padding: 1px 8px; cursor: pointer; color: #516b83; line-height: 1.7; }
.fmw-bound-toggle:hover { color: #2a6496; border-color: #7fb0d8; }
#fmw-bound { border: 1px solid #e5ebf1; border-radius: 8px; overflow: hidden; }
.fmw-chip { display: flex; align-items: center; gap: 8px; padding: 7px 9px; border-bottom: 1px solid #eef2f6; background: #fff; }
.fmw-chip:last-child { border-bottom: none; }
.fmw-chip:hover { background: #f7fafc; }
.fmw-chip .fmw-th { width: 34px; height: 34px; flex: none; border-radius: 5px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #eef2f6; }
.fmw-chip .fmw-th img { width: 100%; height: 100%; object-fit: cover; }
.fmw-chip .fmw-th span { font-size: 10px; font-weight: 600; color: #516b83; text-transform: uppercase; }
#fmw-bound.fmw-cards .fmw-th span { font-size: 20px; color: #fff; background: #8aa5bd; border-radius: 6px; padding: 10px 14px; letter-spacing: 1px; }
.fmw-chip .fmw-meta { flex: 1; min-width: 0; }
.fmw-chip .fmw-name { font-size: 12px; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fmw-chip .fmw-acts { display: flex; gap: 4px; margin-top: 3px; flex-wrap: wrap; }
.fmw-chip .fmw-acts a, .fmw-chip .fmw-acts button { font-size: 11px; border: 1px solid #d9e0e7; background: #fff; border-radius: 4px; padding: 1px 7px; cursor: pointer; color: #516b83; text-decoration: none; line-height: 1.7; }
.fmw-chip .fmw-acts a:hover, .fmw-chip .fmw-acts button:hover { color: #2a6496; border-color: #7fb0d8; }
.fmw-chip .fmw-acts button.fmw-danger { color: #d9534f; border-color: #e2a0a0; }
.fmw-chip .fmw-acts button.fmw-danger:hover { color: #fff; border-color: #d9534f; background: #d9534f; }
#fmw-bound.fmw-cards { display: block; border: none; overflow: visible; }
#fmw-bound.fmw-cards .fmw-chip { display: block; border: 1px solid #e2e8ee; border-bottom: 1px solid #e2e8ee; border-radius: 8px; margin-bottom: 12px; padding: 0; overflow: hidden; }
#fmw-bound.fmw-cards .fmw-chip:last-child { border-bottom: 1px solid #e2e8ee; margin-bottom: 0; }
#fmw-bound.fmw-cards .fmw-th { width: 100%; height: auto; min-height: 110px; border-radius: 0; }
#fmw-bound.fmw-cards .fmw-th img { display: block; width: 100%; height: auto; max-height: 300px; object-fit: contain; }
#fmw-bound.fmw-cards .fmw-meta { padding: 8px 10px 9px; }
#fmw-bound.fmw-cards .fmw-name { font-size: 13px; white-space: normal; word-break: break-all; }
#fmw-bound.fmw-cards .fmw-acts { margin-top: 8px; }
#fmw-lib-tools { display: flex; gap: 6px; margin-bottom: 8px; }
#fmw-folder { flex: 1; min-width: 0; height: 28px; line-height: 1.5; border: 1px solid #d9e0e7; border-radius: 6px; font-size: 12px; background: #fff; padding: 0 6px; }
#fmw-kw { flex: 1; min-width: 0; height: 28px; border: 1px solid #d9e0e7; border-radius: 6px; font-size: 12px; padding: 0 8px; }
#fmw-search-btn { border: 1px solid #d9e0e7; background: #f7f9fb; border-radius: 6px; padding: 0 10px; cursor: pointer; font-size: 12px; color: #516b83; }
#fmw-search-btn:hover { color: #2a6496; border-color: #7fb0d8; }
#fmw-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(88px, 1fr)); gap: 8px; }
.fmw-item { position: relative; border: 1px solid #e2e8ee; border-radius: 8px; padding: 6px; cursor: pointer; text-align: center; background: #fff; transition: border-color .15s, box-shadow .15s; }
.fmw-item:hover { border-color: #2a6496; box-shadow: 0 2px 8px rgba(42,100,150,.15); }
.fmw-item .fmw-thumb { height: 44px; display: flex; align-items: center; justify-content: center; margin-bottom: 4px; }
.fmw-item .fmw-thumb img { max-width: 100%; max-height: 44px; border-radius: 3px; }
.fmw-item .fmw-ext { font-size: 11px; font-weight: 600; color: #516b83; background: #eef2f6; border-radius: 4px; padding: 3px 7px; text-transform: uppercase; }
.fmw-item .fmw-name { font-size: 11px; color: #444; line-height: 1.35; max-height: 2.7em; overflow: hidden; word-break: break-all; }
.fmw-item .fmw-x { position: absolute; top: 3px; right: 4px; width: 17px; height: 17px; line-height: 16px; border-radius: 50%; border: none; background: rgba(0,0,0,.1); color: #777; font-size: 11px; cursor: pointer; display: none; padding: 0; z-index: 3; /* 高于 position:relative 的缩略图层（缩略图会盖住按钮下半），保证整圆可点 */ }
.fmw-item:hover .fmw-x { display: block; }
.fmw-item .fmw-x:hover { background: #d9534f; color: #fff; }
.fmw-thumb, .fmw-th { position: relative; }
.fmw-ih-badge { position: absolute; top: 3px; right: 3px; z-index: 2; pointer-events: none; background: rgba(17, 24, 32, .72); color: #fff; font-size: 10px; line-height: 1; padding: 2px 5px; border-radius: 3px; letter-spacing: 1px; }
.fmw-chip .fmw-th .fmw-ih-badge { display: none; }
#fmw-bound.fmw-cards .fmw-th .fmw-ih-badge { display: block; }
.fmw-ih-inline { display: inline-block; vertical-align: 1px; margin-left: 6px; background: rgba(17, 24, 32, .72); color: #fff; font-size: 10px; line-height: 1; padding: 2px 5px; border-radius: 3px; letter-spacing: 1px; }
#fmw-bound.fmw-cards .fmw-ih-inline { display: none; }
.fmw-item .fmw-ih-badge { top: 3px; left: 3px; right: auto; }
#fmw-lib-empty { text-align: center; color: #98a2ad; font-size: 12px; padding: 16px 0; }
#fmw-pager { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; }
#fmw-pageinfo { font-size: 11px; color: #98a2ad; }
#fmw-pager button { border: 1px solid #d9e0e7; background: #fff; border-radius: 5px; padding: 2px 9px; cursor: pointer; font-size: 12px; }
#fmw-pager button:disabled { color: #c3cbd3; cursor: default; }
#fmw-toast { position: fixed; left: 50%; bottom: 40px; transform: translateX(-50%); background: rgba(30,40,50,.92); color: #fff; padding: 8px 18px; border-radius: 6px; font-size: 13px; z-index: 10000; }
.fmw-lightbox { position: fixed; inset: 0; z-index: 9950; display: flex; align-items: center; justify-content: center; }
.fmw-lightbox[hidden] { display: none; }
.fmw-lightbox .fmw-lb-mask { position: absolute; inset: 0; background: rgba(0,0,0,.85); }
.fmw-lightbox img { position: relative; max-width: 92vw; max-height: 92vh; border-radius: 4px; box-shadow: 0 8px 40px rgba(0,0,0,.5); }
.fmw-lightbox .fmw-lb-close { position: absolute; top: 16px; right: 20px; width: 34px; height: 34px; line-height: 32px; border-radius: 50%; border: none; background: rgba(255,255,255,.15); color: #fff; font-size: 18px; cursor: pointer; z-index: 1; padding: 0; }
.fmw-lightbox .fmw-lb-close:hover { background: rgba(255,255,255,.3); }
.fmw-edit-url { font-size: 12px; color: #5f6f7e; background: #f4f6f9; border-radius: 4px; padding: 6px 10px; word-break: break-all; margin: 0 0 12px; }
.fmw-modal .fmw-field input[type=text] { width: 100%; box-sizing: border-box; height: 30px; border: 1px solid #d9e0e7; border-radius: 6px; font-size: 12px; padding: 0 8px; }
#fmw-remark { margin: 8px 0 0; border: 1px solid #e5ebf1; border-radius: 8px; background: #fff; overflow: hidden; }
#fmw-remark .fmw-remark-head { padding: 6px 10px; font-size: 12px; color: #516b83; border-bottom: 1px solid #eef2f6; background: #fbfcfd; }
#fmw-remark .fmw-remark-head .fmw-cnt { color: #98a2ad; }
#fmw-remark .fmw-remark-body { display: flex; flex-wrap: wrap; gap: 8px; padding: 8px 10px; }
#fmw-remark .fmw-remark-body img { height: 72px; max-width: 120px; object-fit: cover; border-radius: 4px; border: 1px solid #e2e8ee; cursor: zoom-in; background: #f4f6f9; }
.fmw-hover-preview { position: fixed; z-index: 9800; pointer-events: none; background: #fff; border: 1px solid #d9e0e7; border-radius: 6px; padding: 4px; box-shadow: 0 4px 16px rgba(15,30,50,.22); }
.fmw-hover-preview img { display: block; max-width: 62vw; max-height: 74vh; border-radius: 3px; }
.fmw-hl { position: fixed; left: 0; top: 0; z-index: 9700; pointer-events: none; }
.fmw-hl i { position: absolute; display: block; background: rgba(250,198,117,.42); border: 1px solid rgba(239,159,39,.6); border-radius: 2px; }
.fmw-mirror { position: absolute; top: -99999px; left: -99999px; visibility: hidden; white-space: pre-wrap; word-wrap: break-word; overflow-wrap: break-word; padding: 0; margin: 0; border: none; box-sizing: content-box; }
.fmw-modal { position: fixed; inset: 0; z-index: 9900; display: flex; align-items: center; justify-content: center; }
.fmw-modal[hidden] { display: none; }
.fmw-modal .fmw-modal-mask { position: absolute; inset: 0; background: rgba(15,25,35,.45); }
.fmw-modal .fmw-modal-card { position: relative; width: 340px; max-width: calc(100vw - 32px); background: #fff; border-radius: 10px; box-shadow: 0 12px 40px rgba(0,0,0,.25); padding: 16px 18px; font-size: 13px; }
#fmw-up-modal .fmw-modal-card { width: 420px; padding: 14px 22px 20px; border-radius: 8px; font-size: 13px; }
#fmw-up-modal h3 { margin: 0 0 14px; font-size: 15px; text-align: center; color: #333; }
#fmw-up-modal .fmw-field { margin-bottom: 14px; }
#fmw-up-modal .fmw-field label { font-size: 13px; color: #516b83; margin-bottom: 6px; }
#fmw-up-modal .fmw-field label.fmw-flabel { font-weight: 700; color: #3f5265; }
#fmw-up-modal .fmw-field select { height: auto; line-height: 1.5; padding: 8px 10px; font-size: 13px; border-radius: 4px; -webkit-appearance: menulist; appearance: menulist; }
#fmw-up-modal .fmw-field input[type=checkbox] { width: 14px; height: 14px; vertical-align: -2px; margin-right: 2px; }
#fmw-up-modal .fmw-field.fmw-dim { opacity: .55; }
#fmw-up-modal .fmw-up-drop { padding: 22px 10px 18px; margin-bottom: 18px; }
#fmw-up-modal .fmw-up-info { margin-top: 10px; }
#fmw-up-modal .fmw-up-hint { margin: 12px 0 0; font-size: 12px; color: #98a2ad; }
#fmw-up-modal .fmw-btn { font-size: 13px; padding: 5px 16px; }
.fmw-modal .fmw-modal-card h3 { margin: 0 0 12px; font-size: 14px; color: #516b83; border-bottom: 1px solid #eef2f6; padding-bottom: 9px; }
.fmw-modal .fmw-field { margin-bottom: 12px; }
.fmw-modal .fmw-field label { display: block; margin-bottom: 5px; color: #516b83; font-size: 12px; }
.fmw-modal .fmw-field label.fmw-flabel { font-size: 13px; font-weight: 600; color: #3f5265; }
.fmw-modal .fmw-field select { width: 100%; height: 30px; line-height: 1.5; border: 1px solid #d9e0e7; border-radius: 6px; font-size: 12px; background: #fff; padding: 0 6px; }
.fmw-modal .fmw-up-drop { border: 2px dashed #cfd8e1; border-radius: 8px; padding: 16px 10px; text-align: center; color: #7d8b99; background: #fbfcfd; transition: border-color .15s, background .15s; }
.fmw-modal .fmw-up-drop.drag, .fmw-modal .fmw-up-drop:hover { border-color: #2a6496; background: #f2f8fc; }
.fmw-modal .fmw-up-info { margin: 10px 0 0; font-size: 12px; color: #98a2ad; word-break: break-all; }
.fmw-btn { border: 1px solid #d9e0e7; background: #fff; border-radius: 6px; padding: 4px 14px; cursor: pointer; font-size: 12px; color: #516b83; line-height: 1.7; }
.fmw-btn:hover { color: #2a6496; border-color: #7fb0d8; }
.fmw-btn-primary { background: #2a6496; border-color: #2a6496; color: #fff; }
.fmw-btn-primary:hover { background: #215381; color: #fff; }
.fmw-btn:disabled { color: #c3cbd3; border-color: #e5ebf1; background: #f7f9fb; cursor: default; }
.fmw-modal-btns { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
#fmw-editorbar { display: flex; align-items: center; gap: 8px; margin: 0 0 8px; }
#fmw-editorbar .fmw-edbtn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #b9d2e6; background: linear-gradient(180deg, #f8fbfd, #eaf3fa); color: #2a6496; border-radius: 6px; padding: 5px 16px; font-size: 13px; cursor: pointer; transition: all .15s; }
#fmw-editorbar .fmw-edbtn:hover { background: linear-gradient(180deg, #eaf3fa, #dcebfa); border-color: #7fb0d8; }
#fmw-editorbar .fmw-edbtn .fmw-edbtn-ic { font-size: 12px; opacity: .85; }
.fmw-modal-card.fmw-wide { width: 620px; max-width: calc(100vw - 32px); }
.fmw-share-row { display: flex; align-items: center; gap: 10px; padding: 9px 4px; border-bottom: 1px solid #eef2f6; }
.fmw-share-row:last-child { border-bottom: none; }
.fmw-share-info { flex: 1; min-width: 0; }
.fmw-share-name { font-size: 13px; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fmw-share-url { font-size: 11px; color: #98a2ad; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fmw-share-side { display: flex; align-items: center; gap: 8px; flex: none; }
.fmw-share-badge { font-size: 11px; line-height: 1.8; color: #fff; border-radius: 10px; padding: 0 9px; }
.fmw-share-tip { text-align: center; color: #98a2ad; font-size: 12px; padding: 16px 0; }
.fmw-sb-pick { margin: 0 0 12px; padding: 10px 12px; background: #f7f9fb; border: 1px solid #e8edf3; border-radius: 8px; }
.fmw-sb-row { display: flex; align-items: flex-start; gap: 10px; }
.fmw-sb-row + .fmw-sb-row { margin-top: 10px; }
.fmw-sb-rl { flex: none; width: 56px; font-size: 12px; color: #7d8b99; line-height: 30px; }
.fmw-sb-opts { display: flex; flex-wrap: wrap; gap: 8px; }
.fmw-sb-sh { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 8px 4px; background: #fff; border: 1px solid #e2e8ef; border-radius: 8px; cursor: pointer; line-height: 1; }
.fmw-sb-sh:hover { border-color: #b9c8d6; }
.fmw-sb-sh[data-on] { border-color: #467b96; box-shadow: 0 0 0 1px #467b96 inset; }
.fmw-sb-sh i { font-style: normal; font-size: 11px; color: #8a96a3; }
.fmw-sb-cl { width: 24px; height: 24px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px #dde3ea; cursor: pointer; padding: 0; }
.fmw-sb-cl[data-on] { box-shadow: 0 0 0 2px #467b96; }
CSS;

    const PICKER_HTML = <<<'HTML'
<div id="fmw-panel">
    <div class="fmw-upload" id="fmw-drop" title="点击上传文件">
        <div class="fmw-up-main">上传文件</div>
        <div class="fmw-up-row">上传到：<span id="fmw-up-cur">未分类</span></div>
    </div>
    <ul class="fmw-tabs" id="fmw-tabs">
        <li class="current" data-tab="bound">本文附件 <span class="fmw-cnt" id="fmw-bound-cnt"></span></li>
        <li data-tab="lib">文件库</li>
    </ul>
    <div id="fmw-tab-bound">
        <div class="fmw-bound-tools"><button type="button" class="fmw-bound-toggle" id="fmw-bound-view" title="在列表视图与卡片视图之间切换">卡片视图</button></div>
        <div id="fmw-bound"></div>
        <div id="fmw-bound-empty" style="color:#98a2ad;font-size:12px;padding:4px 0;">暂无附件，可上传或从文件库点击添加</div>
    </div>
    <div id="fmw-tab-lib" hidden>
        <div id="fmw-lib-tools">
            <select id="fmw-folder"></select>
            <input type="text" id="fmw-kw" placeholder="搜索文件名">
            <button type="button" id="fmw-search-btn">搜索</button>
        </div>
        <div id="fmw-grid"></div>
        <div id="fmw-lib-empty" hidden>没有匹配的文件</div>
        <div id="fmw-pager">
            <span id="fmw-pageinfo"></span>
            <span>
                <button type="button" id="fmw-prev">上一页</button>
                <button type="button" id="fmw-next">下一页</button>
            </span>
        </div>
    </div>
</div>
<div class="fmw-modal" id="fmw-up-modal" hidden>
    <div class="fmw-modal-mask" id="fmw-up-close"></div>
    <div class="fmw-modal-card">
        <h3>上传文件</h3>
        <div class="fmw-field" id="fmw-up-dest-field">
            <label class="fmw-flabel">存放位置</label>
            <select id="fmw-up-dest" style="width:100%">
                <option value="site">本站（服务器本地）</option>
                <option value="imagehost">图床·对象存储（外链）</option>
            </select>
        </div>
        <div class="fmw-field" id="fmw-up-folder-field">
            <label class="fmw-flabel">存放文件夹</label>
            <select id="fmw-up-folder"></select>
        </div>
        <div class="fmw-up-drop" id="fmw-up-dropzone">
            <button type="button" class="fmw-btn" id="fmw-up-pick">选择文件</button>
            <input type="file" id="fmw-file" multiple hidden>
            <p class="fmw-up-hint">或拖拽文件到此处</p>
            <p class="fmw-up-hint">也可以直接在正文编辑区按 Ctrl+V 粘贴截图上传</p>
            <p class="fmw-up-info" id="fmw-up-info">未选择文件</p>
        </div>
        <div class="fmw-field" id="fmw-up-conv-field">
            <label class="fmw-flabel">上传图片转换到</label>
            <select id="fmw-up-conv" style="width:100%">
                <option value="">不转换</option>
                <option value="webp">WebP（体积最小，推荐）</option>
                <option value="jpg">JPG（体积小，不保留透明背景）</option>
                <option value="png">PNG（无损，支持透明，体积可能变大）</option>
            </select>
        </div>
        <div class="fmw-field" id="fmw-up-wm-field">
            <label><input type="checkbox" id="fmw-up-wm"> 添加水印</label>
            <p class="fmw-up-hint" id="fmw-up-wm-tip" hidden>插件设置中还没有可用的水印（水印文字 + 字体，或水印图片），勾选也不会生效</p>
        </div>
        <div class="fmw-field" id="fmw-up-dt-field">
            <label><input type="checkbox" id="fmw-up-renamedt"> 按上传时间对文件重命名（格式：年月日时分秒）</label>
        </div>
        <div class="fmw-field" id="fmw-up-md-field">
            <label><input type="checkbox" id="fmw-up-insertmd"> 插入到文章正文</label>
        </div>
        <div class="fmw-field" id="fmw-up-keep-field" hidden>
            <label class="fmw-flabel">上传完成后原图处理（原图 = 转换格式/加水印后的成品图）</label>
            <select id="fmw-up-keep" style="width:100%">
                <option value="del">删除原图（使用图床域名）</option>
                <option value="keep-site">保留原图（使用本站域名）</option>
                <option value="keep-ih">保留原图（使用图床域名）</option>
            </select>
        </div>
        <div class="fmw-modal-btns">
            <button type="button" class="fmw-btn" id="fmw-up-cancel">取消</button>
            <button type="button" class="fmw-btn fmw-btn-primary" id="fmw-up-start" disabled>开始上传</button>
        </div>
    </div>
</div>
<div class="fmw-modal" id="fmw-share-modal" hidden>
    <div class="fmw-modal-mask" data-fmw-share-close></div>
    <div class="fmw-modal-card fmw-wide">
        <h3>插入分享链接</h3>
        <div class="fmw-sb-pick">
            <div class="fmw-sb-row"><span class="fmw-sb-rl">按钮样式</span><span class="fmw-sb-opts" id="fmw-sb-shapes"></span></div>
            <div class="fmw-sb-row"><span class="fmw-sb-rl">配色</span><span class="fmw-sb-opts" id="fmw-sb-colors"></span></div>
        </div>
        <div id="fmw-share-list"></div>
        <div class="fmw-modal-btns">
            <button type="button" class="fmw-btn" data-fmw-share-close>关闭</button>
        </div>
    </div>
</div>
<div class="fmw-lightbox" id="fmw-lightbox" hidden>
    <div class="fmw-lb-mask" id="fmw-lb-close-mask"></div>
    <img id="fmw-lb-img" alt="">
    <button type="button" class="fmw-lb-close" id="fmw-lb-close" title="关闭">×</button>
</div>
<div class="fmw-modal" id="fmw-edit-modal" hidden>
    <div class="fmw-modal-mask" data-fmw-edit-close></div>
    <div class="fmw-modal-card">
        <h3>文件编辑</h3>
        <p class="fmw-edit-url" id="fmw-edit-url"></p>
        <div class="fmw-field">
            <label>文件名（修改后自动替换全站引用）</label>
            <input type="text" id="fmw-edit-name" maxlength="190" placeholder="仅限字母、数字、点、下划线、短横线，不含扩展名">
        </div>
        <div class="fmw-field">
            <label>文件标题</label>
            <input type="text" id="fmw-edit-title" maxlength="150">
        </div>
        <div class="fmw-modal-btns">
            <button type="button" class="fmw-btn" data-fmw-edit-close>取消</button>
            <button type="button" class="fmw-btn fmw-btn-primary" id="fmw-edit-save">保存修改</button>
        </div>
    </div>
</div>
<div id="fmw-toast" hidden></div>
HTML;

    const PICKER_JS = <<<'JS'
(function () {
    'use strict';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function extOf(name) {
        var m = String(name).match(/\.([a-z0-9]+)$/i);
        return m ? m[1] : '?';
    }

    var nativePanel = $('#upload-panel');
    if (!nativePanel) { return; }
    var nativeList = nativePanel.querySelector('#file-list');
    if (!nativeList) { return; }

    var state = { fid: 0, keywords: '', page: 1, pages: 1, uploadFid: (typeof FMW_UP_FID === 'number' && FMW_UP_FID > 0 ? FMW_UP_FID : 0), foldersLoaded: false, uploading: false };

    function api(action, params) {
        var body = new URLSearchParams();
        body.append('do', action);
        if (params) { for (var k in params) { if (params.hasOwnProperty(k)) { body.append(k, params[k]); } } }
        return fetch(FMW_URL, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) {
                return res.text().then(function (text) {
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) {}
                    if (!data || typeof data.code === 'undefined') {
                        var snippet = String(text).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 100);
                        console.error('[FileManager] 接口返回异常 action=' + action, text);
                        throw new Error('接口返回异常（' + action + '）' + (snippet ? '：' + snippet : ''));
                    }
                    if (data.code !== 200) { throw new Error(data.message || '请求失败'); }
                    return data;
                });
            });
    }

    function toast(msg) {
        var el = $('#fmw-toast');
        el.textContent = msg;
        el.hidden = false;
        clearTimeout(el._t);
        el._t = setTimeout(function () { el.hidden = true; }, 2200);
    }

    function mediaUrl(cid) { return FMW_ADMIN + 'media.php?cid=' + cid; }

    function titleOf(li) {
        var a = li.querySelector('a.insert');
        return a ? a.textContent : (li.getAttribute('data-title') || '');
    }

    function addLi(f) {
        var exist = nativeList.querySelector('li[data-cid="' + f.cid + '"]');
        if (exist) { return exist; }
        if (f.isIh) { markIhCid(f.cid, f.url); }
        var li = document.createElement('li');
        li.setAttribute('data-cid', f.cid);
        li.setAttribute('data-url', f.url || '');
        li.setAttribute('data-image', f.isImage ? 1 : 0);
        li.setAttribute('data-ih', f.isIh ? 1 : 0);
        li.setAttribute('data-ih-st', f.ihSt || '');
        li.innerHTML = '<input type="hidden" name="attachment[]" value="' + f.cid + '">'
            + '<a class="insert" title="点击插入文件">' + esc(f.title) + '</a>'
            + '<div class="info">' + Math.ceil((Number(f.size) || 0) / 1024) + ' Kb'
            + ' <a class="file" target="_blank" href="' + esc(mediaUrl(f.cid)) + '" title="编辑"><i class="i-edit"></i></a>'
            + ' <a class="delete" title="删除"><i class="i-delete"></i></a></div>';
        nativeList.appendChild(li);
        return li;
    }

    var VIDEO_EXTS = ['mp4', 'webm', 'm4v', 'mov', 'ogv', 'ogg'];

    function urlExt(url) {
        var path = String(url || '').split('?')[0].split('#')[0];
        var dot = path.lastIndexOf('.');
        return dot < 0 ? '' : path.slice(dot + 1).toLowerCase();
    }

    function insertAtCursor(ta, text, padBoth) {
        var len = ta.value.length;
        var start = typeof ta.selectionStart === 'number' ? ta.selectionStart : len;
        var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : start;
        var pad = (padBoth && start > 0 && ta.value.charAt(start - 1) !== '\n') ? '\n\n' : '';
        var padEnd = (padBoth && end < len && ta.value.charAt(end) !== '\n') ? '\n\n' : '';
        if (typeof ta.setRangeText === 'function') {
            ta.setRangeText(pad + text + padEnd, start, end, 'end');
        } else {
            ta.value = ta.value.slice(0, start) + pad + text + padEnd + ta.value.slice(end);
        }
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        ta.focus();
    }

    function insertFile(f) {
        if (f.isImage && f.url) {
            var taImg = document.getElementById('text');
            if (taImg) {
                insertAtCursor(taImg, '![](' + f.url + ')', false);
                return true;
            }
        }
        if (FMW_VIDEO_HTML === '1' && !f.isImage && VIDEO_EXTS.indexOf(urlExt(f.url)) >= 0) {
            var ta = document.getElementById('text');
            if (ta) {
                var html = '<video src="' + f.url + '" controls="controls" preload="metadata" style="max-width:100%"></video>';
                insertAtCursor(ta, html, true);
                return true;
            }
        }
        if (!(window.Typecho && typeof window.Typecho.insertFileToEditor === 'function')) {
            toast('编辑器插入接口不可用');
            return false;
        }
        window.Typecho.insertFileToEditor(f.title, f.url, f.isImage ? 1 : 0);
        return true;
    }

    function insertUploaded(url, isImage, title) {
        var ta = document.getElementById('text');
        if (!ta || !url) { return; }
        if (isImage) {
            insertAtCursor(ta, '![](' + url + ')', false);
            return;
        }
        if (VIDEO_EXTS.indexOf(urlExt(url)) >= 0) {
            if (FMW_VIDEO_HTML === '1') {
                insertAtCursor(ta, '<video src="' + url + '" controls="controls" preload="metadata" style="max-width:100%"></video>', true);
            } else {
                insertAtCursor(ta, '[' + (title || '') + '](' + url + ')', false);
            }
            return;
        }
        insertAtCursor(ta, '[](' + url + ')', false);
    }

    function updateBalloon() {
        var count = $all('li .insert', nativeList).length;
        var btn = $('#tab-files-btn');
        if (btn) {
            var balloon = btn.querySelector('.balloon');
            if (count > 0) {
                if (!balloon) {
                    balloon = document.createElement('span');
                    balloon.className = 'balloon';
                    btn.appendChild(balloon);
                }
                balloon.textContent = count;
            } else if (balloon) {
                balloon.parentNode.removeChild(balloon);
            }
        }
        $('#fmw-bound-cnt').textContent = count ? '(' + count + ')' : '';
        $('#fmw-bound-empty').hidden = count > 0;
    }

    function liCid(li) {
        var cid = li.getAttribute('data-cid');
        if (!cid) {
            var inp = li.querySelector('input[name="attachment[]"]');
            if (inp && inp.value) {
                cid = String(inp.value);
                li.setAttribute('data-cid', cid);
            }
        }
        return cid;
    }

    var knownCids = {};
    var fmwInitDone = false;
    var fmwLibTimer = null;

    var ihCids = {};
    try { ihCids = JSON.parse(localStorage.getItem('fmwIhCids') || '{}') || {}; } catch (err) { ihCids = {}; }
    var ihStCids = {};
    try { ihStCids = JSON.parse(localStorage.getItem('fmwIhStCids') || '{}') || {}; } catch (err) { ihStCids = {}; }
    var fmwIhFetching = {}; /* 回查去重 */
    function markIhCid(cid, url, st) {
        if (!cid) { return; }
        ihCids[String(cid)] = url || 1;
        if (st) { ihStCids[String(cid)] = st; }
        try { localStorage.setItem('fmwIhCids', JSON.stringify(ihCids)); } catch (err) {}
        try { localStorage.setItem('fmwIhStCids', JSON.stringify(ihStCids)); } catch (err) {}
    }
    function ihBadgeText(st) { return st === 's3' ? '存储' : '图床'; }
    function isIhUrl(u) {
        try {
            return /^https?:\/\//i.test(String(u || '')) && new URL(u, location.href).origin !== location.origin;
        } catch (err) { return false; }
    }
    function refreshLibraryForNew() {
        clearTimeout(fmwLibTimer);
        fmwLibTimer = setTimeout(function () {
            state.fid = -1;
            $('#fmw-folder').value = '-1';
            state.page = 1;
            loadFiles();
        }, 200);
    }

    function renderBound() {
        var lis = $all('li', nativeList);
        var hasNew = false;
        var newLis = [];
        lis.forEach(function (li) {
            var cid = liCid(li);
            if (cid && !knownCids[cid]) {
                knownCids[cid] = 1;
                if (fmwInitDone) { hasNew = true; newLis.push(li); }
            }
        });
        var html = lis.map(function (li) {
            var cid = li.getAttribute('data-cid');
            var url = li.getAttribute('data-url') || '';
            var isImage = li.getAttribute('data-image') === '1';
            var isIh = li.getAttribute('data-ih') === '1' || !!ihCids[String(cid)] || isIhUrl(url);
            var ihSt = li.getAttribute('data-ih-st') || ihStCids[String(cid)] || '';
            var title = titleOf(li);
            var showUrl = url;
            if (isIh && url && !isIhUrl(url)) {
                if (typeof ihCids[String(cid)] === 'string' && ihCids[String(cid)]) {
                    showUrl = ihCids[String(cid)];
                    li.setAttribute('data-url', showUrl);
                } else if (!fmwIhFetching[cid]) {
                    fmwIhFetching[cid] = true;
                    (function (liRef, cidRef) {
                        api('fileInfo', { cid: cidRef }).then(function (df) {
                            fmwIhFetching[cidRef] = false;
                            if (df && df.isIh && df.url) {
                                markIhCid(cidRef, df.url, df.ihSt);
                                liRef.setAttribute('data-url', df.url);
                                liRef.setAttribute('data-ih', 1);
                                liRef.setAttribute('data-ih-st', df.ihSt || '');
                                renderBound();
                            }
                        }).catch(function () { fmwIhFetching[cidRef] = false; });
                    })(li, cid);
                }
            }
            var th = isImage && showUrl
                ? '<img src="' + esc(showUrl) + '" loading="lazy" alt="">'
                : '<span>' + esc(extOf(title)) + '</span>';
            return '<div class="fmw-chip" data-cid="' + cid + '" data-url="' + esc(showUrl) + '" data-image="' + (isImage ? 1 : 0) + '">'
                + '<div class="fmw-th">' + th + (isIh ? '<span class="fmw-ih-badge">' + ihBadgeText(ihSt) + '</span>' : '') + '</div>'
                + '<div class="fmw-meta"><div class="fmw-name">' + esc(title) + (isIh ? '<span class="fmw-ih-inline">' + ihBadgeText(ihSt) + '</span>' : '') + '</div>'
                + '<div class="fmw-acts">'
                + '<a href="###" data-act="insert">插入</a>'
                + '<a href="###" data-act="unbind">移除</a>'
                + '<a href="###" data-act="edit">编辑</a>'
                + '<button type="button" class="fmw-danger" data-act="del">删除</button>'
                + '</div></div></div>';
        }).join('');
        var bound = $('#fmw-bound');
        bound.innerHTML = html;
        var cards = false;
        try { cards = window.localStorage && localStorage.getItem('fmwBoundView') === 'cards'; } catch (err) {}
        bound.classList.toggle('fmw-cards', cards);
        var viewBtn = $('#fmw-bound-view');
        if (viewBtn) { viewBtn.textContent = cards ? '列表视图' : '卡片视图'; }
        updateBalloon();
        lis.forEach(function (li) {
            if (!titleOf(li) || !li.getAttribute('data-url')) { patchNativeLi(li); }
        });
        if (hasNew) { refreshLibraryForNew(); }
    }

    if (window.MutationObserver) {
        var fmwSyncBound = null;
        new MutationObserver(function () {
            clearTimeout(fmwSyncBound);
            fmwSyncBound = setTimeout(renderBound, 80);
        }).observe(nativeList, { childList: true, subtree: true, attributes: true, characterData: true });
    }

    var fmwPasteUntil = 0, fmwPastePos = null;
    (function () {
        var ta = document.getElementById('text');
        if (!ta) { return; }
        ta.addEventListener('paste', function () {
            var s = typeof ta.selectionStart === 'number' ? ta.selectionStart : null;
            if (s === null) { return; }
            fmwPastePos = { start: s, end: typeof ta.selectionEnd === 'number' ? ta.selectionEnd : s };
            fmwPasteUntil = Date.now() + 30000;
        });
    })();
    function tryAutoInsertImage(f) {
        if (!f.isImage || !f.url) { return; }
        var ta = document.getElementById('text');
        if (!ta) { return; }
        var md = '![](' + f.url + ')';
        if (Date.now() > fmwPasteUntil || !fmwPastePos) {
            insertAtCursor(ta, md, false);
            return;
        }
        ta.focus();
        ta.selectionStart = fmwPastePos.start;
        ta.selectionEnd = fmwPastePos.end;
        insertAtCursor(ta, md, false);
        fmwPastePos.start = fmwPastePos.end = fmwPastePos.start + md.length; /* 连续多图按顺序追加 */
    }

    var fmwOrigUploadComplete = (window.Typecho && window.Typecho.uploadComplete) || null;
    if (window.Typecho) {
        try {
            Object.defineProperty(window.Typecho, 'uploadComplete', {
                configurable: true,
                get: function () { return fmwUploadHook; },
                set: function (fn) { fmwOrigUploadComplete = fn; }
            });
        } catch (err) { /* 环境不支持时降级：保持编辑器原行为 */ }
    }
    function fmwUploadHook(file) {
        if (file && file.isImage && file.url) {
            tryAutoInsertImage(file);
            return;
        }
        if (typeof fmwOrigUploadComplete === 'function') { fmwOrigUploadComplete(file); }
    }

    var fmwPatching = {};
    function patchNativeLi(li) {
        var cid = liCid(li);
        if (!cid || fmwPatching[cid]) { return; }
        fmwPatching[cid] = true;
        api('fileInfo', { cid: cid }).then(function (data) {
            var f = data && data.file ? data.file : data;
            if (!f || (!f.title && !f.name)) { return; }
            if (!li.getAttribute('data-url') && f.url) { li.setAttribute('data-url', f.url); }
            if (li.getAttribute('data-image') === null) { li.setAttribute('data-image', f.isImage ? 1 : 0); }
            if (f.isIh) { li.setAttribute('data-ih', 1); li.setAttribute('data-ih-st', f.ihSt || ''); markIhCid(cid, f.url, f.ihSt); }
            var a = li.querySelector('a.insert');
            if (!a) {
                a = document.createElement('a');
                a.className = 'insert';
                a.setAttribute('href', 'javascript:;');
                var inp = li.querySelector('input[type="hidden"]');
                if (inp && inp.parentNode === li) { inp.parentNode.insertBefore(a, inp.nextSibling); }
                else { li.insertBefore(a, li.firstChild); }
            }
            if (!a.textContent) { a.textContent = f.title || f.name; }
            renderBound();
        }).catch(function () {});
    }

    function loadFolders() {
        return api('folders').then(function (data) {
            var folders = data.folders || [];
            var treeLib = '', treeUp = '';
            (function walk(parentFid, depth) {
                folders.forEach(function (f2) {
                    if (f2.parent !== parentFid) { return; }
                    var pad = new Array(depth + 1).join('　');
                    treeLib += '<option value="' + f2.fid + '">' + pad + esc(f2.name) + '</option>';
                    treeUp += '<option value="' + f2.fid + '">' + pad + esc(f2.name) + '</option>';
                    walk(f2.fid, depth + 1);
                });
            })(0, 0);
            $('#fmw-folder').innerHTML = '<option value="0">全部文件</option><option value="-1">未分类</option>' + treeLib;
            $('#fmw-folder').value = String(state.fid);
            $('#fmw-up-folder').innerHTML = '<option value="0">未分类</option>' + treeUp;
            $('#fmw-up-folder').value = String(state.uploadFid);
            updateUpCur();
            state.foldersLoaded = true;
        }).catch(function (e) {
            state.foldersLoaded = false;
            if (!$('#fmw-up-folder').options.length) {
                $('#fmw-up-folder').innerHTML = '<option value="0">未分类</option>';
                $('#fmw-up-folder').value = '0';
            }
            toast('文件夹列表加载失败：' + (e.message || '未知错误'));
            throw e;
        });
    }

    function loadFiles() {
        $('#fmw-lib-empty').hidden = true;
        api('list', { fid: state.fid, keywords: state.keywords, page: state.page, pageSize: 10, type: 'all', sort: 'time', dir: 'DESC' })
            .then(function (data) {
                state.pages = data.pages || 1;
                var page = data.page || 1;
                var files = data.files || [];
                $('#fmw-lib-empty').hidden = files.length > 0;
                $('#fmw-grid').innerHTML = files.map(function (f) {
                    var thumb = f.isImage && f.url
                        ? '<img src="' + esc(f.url) + '" loading="lazy" alt="">'
                        : '<span class="fmw-ext">' + esc(f.ext || '?') + '</span>';
                    return '<div class="fmw-item" data-cid="' + f.cid + '" data-title="' + esc(f.title) + '" data-url="' + esc(f.url) + '" data-image="' + (f.isImage ? 1 : 0) + '" data-ih="' + (f.isIh ? 1 : 0) + '" data-ih-st="' + (f.ihSt || '') + '" title="点击插入到文章">'
                        + '<button type="button" class="fmw-x" data-x="1" title="删除文件">×</button>'
                        + '<div class="fmw-thumb">' + thumb + (f.isIh ? '<span class="fmw-ih-badge">' + ihBadgeText(f.ihSt) + '</span>' : '') + '</div>'
                        + '<div class="fmw-name">' + esc(f.title) + '</div>'
                        + '</div>';
                }).join('');
                $('#fmw-pageinfo').textContent = '第 ' + page + ' / ' + state.pages + ' 页（共 ' + data.total + ' 个）';
                $('#fmw-prev').disabled = page <= 1;
                $('#fmw-next').disabled = page >= state.pages;
            })
            .catch(function (e) {
                $('#fmw-lib-empty').hidden = false;
                $('#fmw-lib-empty').textContent = e.message || '加载失败';
            });
    }

    function deleteFile(cid, title, isIh) {
        var tip = isIh ? '删除图床图片会同时删除物理文件，已插入文章的链接会失效。'
                       : '将同时删除物理文件，已插入文章的链接会失效。';
        if (!window.confirm('确定删除文件「' + title + '」吗？\n' + tip)) { return; }
        api('fileDelete', { cids: cid }).then(function () {
            var li = nativeList.querySelector('li[data-cid="' + cid + '"]');
            if (li && li.parentNode) { li.parentNode.removeChild(li); }
            renderBound();
            loadFiles();
            toast('文件已删除');
        }).catch(function (err) { toast(err.message || '删除失败'); });
    }

    function switchFmwTab(tab) {
        var isBound = tab !== 'lib';
        $('#fmw-tabs').querySelectorAll('li').forEach(function (li) {
            li.classList.toggle('current', li.getAttribute('data-tab') === (isBound ? 'bound' : 'lib'));
        });
        $('#fmw-tab-bound').hidden = !isBound;
        $('#fmw-tab-lib').hidden = isBound;
        try {
            if (window.localStorage) { localStorage.setItem('fmwTab', isBound ? 'bound' : 'lib'); }
        } catch (err) {}
    }
    $('#fmw-tabs').addEventListener('click', function (e) {
        var li = e.target && e.target.closest ? e.target.closest('li[data-tab]') : null;
        if (li && !li.classList.contains('current')) { switchFmwTab(li.getAttribute('data-tab')); }
    });
    (function () {
        var saved = '';
        try { saved = window.localStorage ? (localStorage.getItem('fmwTab') || '') : ''; } catch (err) {}
        if (saved === 'lib') { switchFmwTab('lib'); }
    })();

    $('#fmw-bound-view').addEventListener('click', function () {
        var bound = $('#fmw-bound');
        var toCards = !bound.classList.contains('fmw-cards');
        bound.classList.toggle('fmw-cards', toCards);
        try {
            if (window.localStorage) { localStorage.setItem('fmwBoundView', toCards ? 'cards' : 'list'); }
        } catch (err) {}
        this.textContent = toCards ? '列表视图' : '卡片视图';
    });

    $('#fmw-bound').addEventListener('click', function (e) {
        var t = e.target;
        var th = t.closest ? t.closest('.fmw-th') : null;
        if (th) {
            var thChip = th.closest('.fmw-chip');
            if (thChip && thChip.getAttribute('data-image') === '1') {
                var imgUrl = thChip.getAttribute('data-url') || '';
                if (imgUrl) { e.preventDefault(); openFmwLightbox(imgUrl); }
            }
            return;
        }
        var act = t.getAttribute ? t.getAttribute('data-act') : null;
        if (!act) { return; }
        var chip = t.closest('.fmw-chip');
        if (!chip) { return; }
        var cid = chip.getAttribute('data-cid');
        var li = nativeList.querySelector('li[data-cid="' + cid + '"]');
        if (!li) { return; }
        var f = { cid: cid, title: titleOf(li), url: li.getAttribute('data-url') || '', isImage: li.getAttribute('data-image') === '1' };
        f.isIh = li.getAttribute('data-ih') === '1' || !!ihCids[cid] || isIhUrl(f.url);
        if (act === 'insert') {
            insertFile(f);
        } else if (act === 'unbind') {
            li.parentNode.removeChild(li);
            renderBound();
            toast('已从本文移除（文件仍在文件库中）');
        } else if (act === 'edit') {
            openFmwEdit(f);
        } else if (act === 'del') {
            deleteFile(cid, f.title, f.isIh);
        }
        return;
    });

    function openFmwLightbox(url) {
        $('#fmw-lb-img').src = url;
        $('#fmw-lightbox').hidden = false;
    }
    function closeFmwLightbox() {
        $('#fmw-lightbox').hidden = true;
        $('#fmw-lb-img').src = '';
    }
    $('#fmw-lb-close').addEventListener('click', closeFmwLightbox);
    $('#fmw-lb-close-mask').addEventListener('click', closeFmwLightbox);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !$('#fmw-lightbox').hidden) { closeFmwLightbox(); }
    });

    (function () {
        var ta = document.getElementById('text');
        if (!ta) { return; }
        var box = null, body = null, cnt = null, timer = null;
        var hover = null, hoverImg = null;
        var mirror = null;
        var hl = null; /* URL 高亮覆盖层（textarea 无法给部分文字着色） */
        var hItems = []; /* {y1, y2, rects, url}：URL 的内容坐标区间与逐行像素矩形 */
        var hPadTop = 0, hBorderTop = 0, hPadLeft = 0, hBorderLeft = 0; /* 命中换算参数，buildMirrorIndex 时缓存 */
        var hoverOn = (typeof FMW_HOVER_PREVIEW === 'undefined') || FMW_HOVER_PREVIEW === 1;

        function ensureBox() {
            if (box) { return; }
            box = document.createElement('div');
            box.id = 'fmw-remark';
            box.hidden = true;
            box.innerHTML = '<div class="fmw-remark-head">正文图片预览 <span class="fmw-cnt"></span></div>'
                + '<div class="fmw-remark-body"></div>';
            body = box.querySelector('.fmw-remark-body');
            cnt = box.querySelector('.fmw-cnt');
            if (ta.nextSibling) { ta.parentNode.insertBefore(box, ta.nextSibling); }
            else { ta.parentNode.appendChild(box); }
            body.addEventListener('click', function (e) {
                var img = e.target && e.target.closest ? e.target.closest('img[data-url]') : null;
                if (img) { openFmwLightbox(img.getAttribute('data-url')); }
            });
        }

        function ensureHover() {
            if (hover) { return; }
            hover = document.createElement('div');
            hover.className = 'fmw-hover-preview';
            hover.hidden = true;
            hoverImg = document.createElement('img');
            hover.appendChild(hoverImg);
            document.body.appendChild(hover);
            hl = document.createElement('div');
            hl.className = 'fmw-hl';
            hl.hidden = true;
            document.body.appendChild(hl);
        }

        function getMirror() {
            if (mirror) { return mirror; }
            mirror = document.createElement('div');
            mirror.className = 'fmw-mirror';
            document.body.appendChild(mirror);
            return mirror;
        }

        function buildMirrorIndex(text, found) {
            hItems = [];
            if (!found.length) { return; }
            var cs = getComputedStyle(ta);
            if (cs.lineHeight === 'normal') { ta.style.lineHeight = '1.6'; cs = getComputedStyle(ta); }
            var m = getMirror();
            var pi, pname;
            for (pi = 0; pi < cs.length; pi++) {
                pname = cs[pi];
                if (/^(font|letter|word|text|white|overflow-|hyphens|line-|tab-|direction|zoom)/.test(pname)) {
                    try { m.style[pname] = cs[pname]; } catch (ex) { /* 不支持的属性忽略 */ }
                }
            }
            m.style.width = Math.max(50, ta.clientWidth
                - (parseFloat(cs.paddingLeft) || 0) - (parseFloat(cs.paddingRight) || 0)) + 'px';
            var html = '', last = 0;
            for (var i = 0; i < found.length; i++) {
                html += esc(text.slice(last, found[i].off))
                    + esc(found[i].pre)
                    + '<span class="fmw-mk">' + esc(found[i].raw) + '</span>'
                    + esc(found[i].post);
                last = found[i].off + found[i].mLen;
            }
            html += esc(text.slice(last));
            m.innerHTML = html;
            var marks = m.querySelectorAll('.fmw-mk');
            var minH = parseFloat(cs.lineHeight) || 0;
            var mRect = m.getBoundingClientRect();
            var contentW = ta.clientWidth - (parseFloat(cs.paddingLeft) || 0) - (parseFloat(cs.paddingRight) || 0);
            for (var k = 0; k < marks.length; k++) {
                var rects = [];
                try {
                    var rng = document.createRange();
                    rng.selectNodeContents(marks[k]);
                    var frag = rng.getClientRects();
                    for (var j = 0; j < frag.length; j++) {
                        if (frag[j].width <= 0 || frag[j].height <= 0) { continue; }
                        var rx1 = frag[j].left - mRect.left;
                        var rx2 = Math.min(frag[j].right - mRect.left, contentW + 6);
                        if (rx2 - rx1 < 2) { continue; }
                        rects.push({ x1: rx1, y1: frag[j].top - mRect.top,
                            x2: rx2, y2: frag[j].bottom - mRect.top });
                    }
                } catch (ex) { /* Range 不可用时退回整行命中 */ }
                var y1, y2;
                if (rects.length) {
                    y1 = rects[0].y1;
                    y2 = rects[rects.length - 1].y2;
                } else {
                    y1 = marks[k].offsetTop;
                    y2 = y1 + Math.max(marks[k].offsetHeight, minH);
                }
                hItems.push({ y1: y1, y2: y2, rects: rects, url: found[k].url });
            }
            hPadTop = parseFloat(cs.paddingTop) || 0;
            hBorderTop = parseFloat(cs.borderTopWidth) || 0;
            hPadLeft = parseFloat(cs.paddingLeft) || 0;
            hBorderLeft = parseFloat(cs.borderLeftWidth) || 0;
        }

        function hideHover() {
            if (hover) { hover.hidden = true; }
            if (hl) { hl.hidden = true; }
        }

        function render() {
            var text = ta.value || '';
            var found = [], seen = {};
            var re = /!\[[^\]]*\]\(\s*([^)\s]+)[^)]*\)|<img[^>]*src=["']([^"']+)["']/gi, m;
            while ((m = re.exec(text)) !== null) {
                var raw = m[1] || m[2] || '';
                if (!raw || seen[raw]) { continue; }
                seen[raw] = 1;
                var m0 = m[0];
                var idx = m0.indexOf(raw);
                found.push({ url: raw.replace(/&amp;/g, '&'), raw: raw, off: m.index, mLen: m0.length,
                    pre: m0.slice(0, idx), post: m0.slice(idx + raw.length) });
                if (found.length >= 60) { break; }
            }
            ensureBox();
            ensureHover();
            lastText = text;
            lastFound = found;
            if (hoverOn) { buildMirrorIndex(text, found); }
            if (!found.length) {
                box.hidden = true; body.innerHTML = ''; hideHover();
                return;
            }
            var html = '';
            found.forEach(function (it) { html += '<img src="' + esc(it.url) + '" data-url="' + esc(it.url) + '" loading="lazy" alt="">'; });
            body.innerHTML = html;
            cnt.textContent = '(' + found.length + ')';
            box.hidden = false;
        }

        function showHover(hit, e) {
            hoverImg.src = hit.url;
            hover.hidden = false;
            var gap = 18, mX = 8, mY = 8, capW = 900, capH = 700, minW = 160, minH = 120;
            var spaceR = window.innerWidth - e.clientX - gap - mX;
            var spaceL = e.clientX - gap - mX;
            var spaceB = window.innerHeight - e.clientY - gap - mY;
            var spaceT = e.clientY - gap - mY;
            var sideR = spaceR >= spaceL;
            var sideB = spaceB >= spaceT;
            hoverImg.style.maxWidth = Math.min(Math.max(sideR ? spaceR : spaceL, minW), capW) + 'px';
            hoverImg.style.maxHeight = Math.min(Math.max(sideB ? spaceB : spaceT, minH), capH) + 'px';
            var hw = hover.offsetWidth || 250, hh = hover.offsetHeight || 180;
            var x = sideR ? e.clientX + gap : e.clientX - gap - hw;
            var y = sideB ? e.clientY + gap : e.clientY - gap - hh;
            if (x < mX) { x = mX; } else if (x + hw > window.innerWidth - mX) { x = window.innerWidth - hw - mX; }
            if (y < mY) { y = mY; } else if (y + hh > window.innerHeight - mY) { y = window.innerHeight - hh - mY; }
            hover.style.left = x + 'px';
            hover.style.top = y + 'px';
        }

        function showHighlight(hit, rect) {
            if (!hl) { return; }
            var html = '';
            for (var i = 0; i < hit.rects.length; i++) {
                var r = hit.rects[i];
                var vx = rect.left + hBorderLeft + hPadLeft + (r.x1 - ta.scrollLeft);
                var vy = rect.top + hBorderTop + hPadTop + (r.y1 - ta.scrollTop);
                html += '<i style="left:' + vx.toFixed(1) + 'px;top:' + vy.toFixed(1)
                    + 'px;width:' + Math.max(r.x2 - r.x1, 2).toFixed(1)
                    + 'px;height:' + Math.max(r.y2 - r.y1, 2).toFixed(1) + 'px"></i>';
            }
            hl.innerHTML = html;
            hl.hidden = false;
        }

        if (hoverOn) {
            ta.addEventListener('mousemove', function (e) {
                if (!hItems.length) { hideHover(); return; }
                var rect = ta.getBoundingClientRect();
                var x = e.clientX - rect.left - hBorderLeft - hPadLeft + ta.scrollLeft;
                var y = e.clientY - rect.top - hBorderTop - hPadTop + ta.scrollTop;
                var hit = null;
                for (var i = 0; i < hItems.length; i++) {
                    var it = hItems[i];
                    if (y < it.y1 - 2 || y > it.y2 + 2) { continue; }
                    if (!it.rects.length) { hit = it; break; }
                    for (var j = 0; j < it.rects.length; j++) {
                        if (x >= it.rects[j].x1 - 4 && x <= it.rects[j].x2 + 4) { hit = it; break; }
                    }
                    if (hit) { break; }
                }
                if (hit) { showHighlight(hit, rect); showHover(hit, e); } else { hideHover(); }
            });
            ta.addEventListener('mouseleave', hideHover);
            ta.addEventListener('scroll', hideHover);
            var lastText = '', lastFound = null;
            var refreshMirror = function () {
                if (lastFound && lastFound.length) { buildMirrorIndex(lastText, lastFound); }
            };
            ta.addEventListener('mouseenter', refreshMirror);
            if (document.fonts && document.fonts.ready) { document.fonts.ready.then(refreshMirror); }
            setTimeout(refreshMirror, 1200);
        }
        window.addEventListener('resize', function () {
            clearTimeout(timer);
            timer = setTimeout(render, 300);
        });
        ta.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(render, 300);
        });
        render();
    })();

    function fmwApi(params) {
        var body = new FormData();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(FMW_URL, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.code !== 200) { throw new Error((data && data.message) || '请求失败'); }
                return data;
            });
    }

    var fmwEditCid = 0;
    function openFmwEdit(f) {
        fmwEditCid = String(f.cid);
        $('#fmw-edit-url').textContent = f.url || '';
        $('#fmw-edit-url').title = f.url || '';
        $('#fmw-edit-name').value = ((f.url || '').split('/').pop() || '').replace(/\.[^.]+$/, '');
        $('#fmw-edit-title').value = f.title || '';
        var saveBtn = $('#fmw-edit-save');
        saveBtn.disabled = true;
        $('#fmw-edit-modal').hidden = false;
        fmwApi({ do: 'fileGet', cid: f.cid }).then(function (data) {
            $('#fmw-edit-name').value = (data.name || $('#fmw-edit-name').value).replace(/\.[^.]+$/, '');
            $('#fmw-edit-title').value = data.title || f.title || '';
        }).catch(function (e) {
            toast(e.message || '读取文件信息失败');
            $('#fmw-edit-modal').hidden = true;
        }).then(function () { saveBtn.disabled = false; });
    }

    $('#fmw-edit-save').addEventListener('click', function () {
        if (!fmwEditCid) { return; }
        var title = $('#fmw-edit-title').value.trim();
        var newName = $('#fmw-edit-name').value.trim();
        if (!title) { toast('文件标题不能为空'); return; }
        var btn = this;
        btn.disabled = true;
        var cid = fmwEditCid;
        fmwApi({ do: 'fileUpdate', cid: cid, title: title, name: newName }).then(function (data) {
            var li = nativeList.querySelector('li[data-cid="' + cid + '"]');
            if (li) {
                var a = li.querySelector('a.insert');
                if (a) { a.textContent = title; }
            }
            try {
                var ta2 = document.getElementById('text');
                if (ta2 && data.oldUrl && data.newUrl) {
                    var v = ta2.value, changed = false;
                    if (v.indexOf(data.oldUrl) > -1) { v = v.split(data.oldUrl).join(data.newUrl); changed = true; }
                    if (data.oldPath && data.newPath && v.indexOf(data.oldPath) > -1) {
                        v = v.split(data.oldPath).join(data.newPath); changed = true;
                    }
                    if (changed) { ta2.value = v; ta2.dispatchEvent(new Event('input')); }
                }
            } catch (ex) { /* 忽略正文同步失败 */ }
            renderBound();
            loadFiles();
            toast(data.message || '文件信息已保存');
            $('#fmw-edit-modal').hidden = true;
        }).catch(function (e) {
            toast(e.message || '保存失败');
        }).then(function () { btn.disabled = false; });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-fmw-edit-close]'), function (el) {
        el.addEventListener('click', function () { $('#fmw-edit-modal').hidden = true; });
    });

    $('#fmw-grid').addEventListener('click', function (e) {
        var item = e.target && e.target.closest ? e.target.closest('.fmw-item') : null;
        if (!item) { return; }
        var f = {
            cid: item.getAttribute('data-cid'),
            title: item.getAttribute('data-title'),
            url: item.getAttribute('data-url'),
            isImage: item.getAttribute('data-image') === '1',
            isIh: item.getAttribute('data-ih') === '1'
        };
        if (e.target.getAttribute && e.target.getAttribute('data-x')) {
            deleteFile(f.cid, f.title, f.isIh);
            return;
        }
        knownCids[String(f.cid)] = 1; /* 文件库点击只插入绑定，无需刷新列表 */
        addLi(f);
        renderBound();
        if (insertFile(f)) { toast('已插入：' + f.title); }
    });

    var drop = $('#fmw-drop'), fileInput = $('#fmw-file');
    var upModal = $('#fmw-up-modal'), upDropzone = $('#fmw-up-dropzone');
    var pendingFiles = [];

    function folderName(fid) {
        var sel = $('#fmw-up-folder');
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === String(fid)) {
                return sel.options[i].textContent.replace(/^[\u3000\s]+/, '');
            }
        }
        return '未分类';
    }

    function updateUpCur() {
        $('#fmw-up-cur').textContent = folderName(state.uploadFid);
    }

    function setPending(files) {
        pendingFiles = Array.prototype.slice.call(files || []);
        var names = pendingFiles.slice(0, 3).map(function (f) { return f.name; }).join('、');
        $('#fmw-up-info').textContent = pendingFiles.length
            ? '已选择 ' + pendingFiles.length + ' 个文件：' + names + (pendingFiles.length > 3 ? ' 等' : '')
            : '未选择文件';
        $('#fmw-up-start').disabled = pendingFiles.length === 0 || state.uploading;
    }

    function openUpModal(files) {
        if (state.uploading) { return; }
        if (!state.foldersLoaded) { loadFolders().catch(function () {}); }
        $('#fmw-up-dest-field').hidden = !FMW_IMGHOST;
        $('#fmw-up-dest').value = (FMW_IMGHOST && FMW_UPLOAD_DEST === 'imagehost') ? 'imagehost' : 'site';
        fmwUpApplyDest();
        $('#fmw-up-conv').value = FMW_UP_CONV;
        if ($('#fmw-up-conv').selectedIndex === -1) { $('#fmw-up-conv').value = ''; }
        $('#fmw-up-renamedt').checked = FMW_UP_DT === '1';
        var mdOn = '0';
        try { mdOn = window.localStorage.getItem('FileManagerUploadInsert') || '0'; } catch (e) {}
        $('#fmw-up-insertmd').checked = mdOn === '1';
        var wmBox = $('#fmw-up-wm');
        var wmReady = FMW_WM_READY === '1';
        var wmOn = FMW_WM;
        try {
            var wmSaved = document.getElementById('filemanager-wm-toggle')
                ? window.localStorage.getItem('FileManagerEditorWm') : null;
            if (wmSaved === '1' || wmSaved === '0') { wmOn = wmSaved; }
        } catch (e) {}
        wmBox.checked = wmReady && wmOn === '1';
        wmBox.disabled = !wmReady;
        $('#fmw-up-wm-field').classList[wmReady ? 'remove' : 'add']('fmw-dim');
        $('#fmw-up-wm-tip').hidden = wmReady;
        $('#fmw-up-folder').value = String(state.uploadFid);
        if (files) { setPending(files); } else { setPending(pendingFiles); }
        upModal.hidden = false;
    }

    function fmwUpApplyDest() {
        var isIh = $('#fmw-up-dest').value === 'imagehost';
        $('#fmw-up-keep-field').hidden = !isIh;
        $('#fmw-file').accept = (isIh && FMW_IH_S3 !== 1) ? 'image/*' : '';
    }
    $('#fmw-up-dest').addEventListener('change', fmwUpApplyDest);

    function closeUpModal() {
        if (state.uploading) { return; }
        upModal.hidden = true;
        setPending([]);
    }

    drop.addEventListener('click', function () { openUpModal(); });
    drop.addEventListener('dragover', function (e) { e.preventDefault(); e.stopPropagation(); drop.classList.add('drag'); });
    drop.addEventListener('dragleave', function () { drop.classList.remove('drag'); });
    drop.addEventListener('drop', function (e) {
        e.preventDefault(); e.stopPropagation();
        drop.classList.remove('drag');
        var files = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files : null;
        if (files && files.length) { openUpModal(files); }
    });

    $('#fmw-up-close').addEventListener('click', closeUpModal);
    $('#fmw-up-cancel').addEventListener('click', closeUpModal);
    $('#fmw-up-pick').addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
        var files = Array.prototype.slice.call(this.files);
        this.value = '';
        setPending(files);
    });
    ['dragenter', 'dragover'].forEach(function (ev) {
        upDropzone.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); upDropzone.classList.add('drag'); });
    });
    ['dragleave', 'dragend'].forEach(function (ev) {
        upDropzone.addEventListener(ev, function () { upDropzone.classList.remove('drag'); });
    });
    upDropzone.addEventListener('drop', function (e) {
        e.preventDefault(); e.stopPropagation();
        upDropzone.classList.remove('drag');
        if (e.dataTransfer && e.dataTransfer.files) { setPending(e.dataTransfer.files); }
    });
    $('#fmw-up-folder').addEventListener('change', function () {
        var v = parseInt(this.value, 10);
        state.uploadFid = isNaN(v) ? 0 : v;
        updateUpCur();
    });
    $('#fmw-up-start').addEventListener('click', function () { uploadFiles(pendingFiles); });
    $('#fmw-up-wm').addEventListener('change', function () {
        var on = this.checked;
        try { window.localStorage.setItem('FileManagerEditorWm', on ? '1' : '0'); } catch (e) {}
        var ball = document.getElementById('filemanager-wm-toggle');
        if (ball) {
            ball.checked = on;
            ball.dispatchEvent(new Event('change'));
        }
    });

    $('#fmw-up-insertmd').addEventListener('change', function () {
        try { window.localStorage.setItem('FileManagerUploadInsert', this.checked ? '1' : '0'); } catch (e) {}
    });

    function uploadFiles(files) {
        if (!files.length || state.uploading) { return; }
        state.uploading = true;
        $('#fmw-up-start').disabled = true;
        var toImageHost = $('#fmw-up-dest').value === 'imagehost';
        var keep = toImageHost ? $('#fmw-up-keep').value : '';
        var insertMd = $('#fmw-up-insertmd').checked;
        var ok = 0, fail = 0, ihOk = 0, total = files.length;
        toast('正在上传 1 / ' + total + ' …');

        function next(i) {
            if (i >= files.length) {
                state.uploading = false;
                var msg;
                if (fail) {
                    msg = '上传完成：成功 ' + ok + ' 个，失败 ' + fail + ' 个';
                } else if (toImageHost && keep === 'del' && ihOk > 0 && ihOk === ok) {
                    msg = '已上传到图床/对象存储 ' + ihOk + ' 个文件，Markdown 已插入正文（原图已删除，文件记录保留在列表）';
                } else {
                    msg = '已成功上传 ' + ok + ' 个文件，并添加到本文附件'
                        + (toImageHost && ihOk < ok ? '（非图片文件仅上传到本站）' : '')
                        + (insertMd ? '，已插入正文' : '');
                }
                $('#fmw-up-info').textContent = msg;
                toast(msg);
                pendingFiles = [];
                setTimeout(function () {
                    upModal.hidden = true;
                    setPending([]);
                }, fail ? 1500 : 700);
                state.fid = state.uploadFid === 0 ? -1 : state.uploadFid;
                $('#fmw-folder').value = String(state.fid);
                state.page = 1;
                loadFiles();
                return;
            }
            $('#fmw-up-info').textContent = '正在上传 ' + (i + 1) + ' / ' + total + ' …';
            var f = files[i];
            var isImg = FMW_IH_S3 === 1 || /^image\//.test(f.type || '');
            var body = new FormData();
            body.append('do', 'upload');
            body.append('fid', String(state.uploadFid));
            body.append('renameDt', $('#fmw-up-renamedt').checked ? '1' : '0');
            body.append('uploadTo', $('#fmw-up-conv').value);
            body.append('uploadWm', $('#fmw-up-wm').checked ? '1' : '0');
            body.append('imgCtl', '1'); /* 明确按弹窗选项执行，取消勾选水印也要覆盖插件设置 */
            body.append('file', f);
            fetch(FMW_URL, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (d1) {
                    if (!(d1 && d1.code === 200 && d1.cid)) {
                        fail++;
                        toast((f.name || '文件') + '：' + ((d1 && d1.message) || '本站上传失败'));
                        return;
                    }
                    if (!toImageHost || !isImg) {
                        knownCids[String(d1.cid)] = 1; /* 插件上传流程自己会刷新文件库，这里只登记 */
                        addLi({ cid: d1.cid, title: d1.title, url: d1.url, isImage: d1.isImage, size: d1.size });
                        renderBound();
                        ok++;
                        if (insertMd) { insertUploaded(d1.url, d1.isImage, d1.title); }
                        return;
                    }
                    var body2 = new FormData();
                    body2.append('do', 'imageHostUpload');
                    body2.append('cid', String(d1.cid));
                    body2.append('delLocal', keep === 'del' ? '1' : '0');
                    body2.append('useUrl', keep === 'keep-site' ? 'site' : 'ih');
                    return fetch(FMW_URL, { method: 'POST', body: body2, credentials: 'same-origin' })
                        .then(function (res2) { return res2.json(); })
                        .then(function (d2) {
                            if (d2 && d2.code === 200 && d2.url) {
                                ok++; ihOk++;
                                knownCids[String(d1.cid)] = 1;
                                addLi({ cid: d1.cid, title: d1.title,
                                    url: (keep === 'keep-site' ? d1.url : d2.url),
                                    isImage: d1.isImage, size: d1.size, isIh: true, ihSt: d2.ihSt });
                                renderBound();
                                if (insertMd || keep === 'del') {
                                    insertUploaded(keep === 'keep-site' ? d1.url : d2.url,
                                        d1.isImage, d1.title || d2.name || '');
                                }
                            } else {
                                fail++;
                                toast((f.name || '文件') + '：' + ((d2 && d2.message) || '转发图床失败'));
                                knownCids[String(d1.cid)] = 1;
                                addLi({ cid: d1.cid, title: d1.title, url: d1.url, isImage: d1.isImage, size: d1.size });
                                renderBound();
                            }
                        });
                })
                .catch(function () { fail++; toast('上传失败（网络或服务器错误）'); })
                .then(function () { next(i + 1); });
        }
        next(0);
    }

    $('#fmw-search-btn').addEventListener('click', function () {
        state.keywords = $('#fmw-kw').value.trim();
        state.page = 1;
        loadFiles();
    });
    $('#fmw-kw').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); $('#fmw-search-btn').click(); }
    });
    $('#fmw-folder').addEventListener('change', function () {
        state.fid = parseInt(this.value, 10);
        if (isNaN(state.fid)) { state.fid = 0; }
        state.uploadFid = Math.max(0, state.fid);
        $('#fmw-up-folder').value = String(state.uploadFid);
        updateUpCur();
        state.page = 1;
        loadFiles();
    });
    $('#fmw-prev').addEventListener('click', function () { if (state.page > 1) { state.page--; loadFiles(); } });
    $('#fmw-next').addEventListener('click', function () { if (state.page < state.pages) { state.page++; loadFiles(); } });

    var ST_MAP = {
        active:    { text: '有效',     bg: '#43a047' },
        expired:   { text: '已过期',   bg: '#9e9e9e' },
        exhausted: { text: '已达上限', bg: '#ef6c00' }
    };

    var SB_SHAPES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
    var SB_SNAMES = { a: '方角', b: '圆角', c: '描边', d: '胶囊', e: '深色', f: '浅色', g: '卡片', h: '文字链' };
    var SB_COLORS = [['red', '红'], ['blue', '蓝'], ['teal', '青'], ['green', '绿'], ['amber', '橙'], ['purple', '紫'], ['gray', '黑']];
    var SB_HEX = {
        red: '#d9534f', blue: '#185fa5', teal: '#0f6e56', green: '#3b6d11',
        amber: '#ba7517', purple: '#534ab7', gray: '#2c2c2a'
    };
    var SB_SHAPE = 'a';
    var SB_COLOR = 'red';
    (function () {
        var s = null, c = null;
        try {
            s = localStorage.getItem('fmwSbShape');
            c = localStorage.getItem('fmwSbColor');
        } catch (e) { /* 隐私模式等 */ }
        if (SB_SHAPES.indexOf(s) > -1) { SB_SHAPE = s; }
        var names = SB_COLORS.map(function (x) { return x[0]; });
        if (names.indexOf(c) > -1) { SB_COLOR = c; }
    })();

    function saveSbPick() {
        try {
            localStorage.setItem('fmwSbShape', SB_SHAPE);
            localStorage.setItem('fmwSbColor', SB_COLOR);
        } catch (e) { /* 忽略 */ }
    }

    function sbInner(title) {
        return 'g' === SB_SHAPE
            ? '<span class="fm-sb-ico">↓</span><span class="fm-sb-t">' + esc(title) + '</span><span class="fm-sb-x">下载</span>'
            : esc(title);
    }

    function renderSbPicker() {
        var box = $('#fmw-sb-shapes');
        if (!box) { return; }
        box.innerHTML = SB_SHAPES.map(function (k) {
            return '<button type="button" class="fmw-sb-sh" data-sh="' + k + '"' + (k === SB_SHAPE ? ' data-on="1"' : '') + '>'
                + '<a class="fm-share-btn fm-sb-' + k + ' fm-c-' + SB_COLOR + ' fmw-mini" tabindex="-1">'
                + sbInner('示例') + '</a>'
                + '<i>' + SB_SNAMES[k] + '</i></button>';
        }).join('');
        $('#fmw-sb-colors').innerHTML = SB_COLORS.map(function (c) {
            return '<button type="button" class="fmw-sb-cl" data-cl="' + c[0] + '" title="' + c[1] + '色"'
                + (c[0] === SB_COLOR ? ' data-on="1"' : '') + ' style="background:' + SB_HEX[c[0]] + '"></button>';
        }).join('');
    }

    function shareBtnHtml(url, title) {
        return '<a class="fm-share-btn fm-sb-' + SB_SHAPE + ' fm-c-' + SB_COLOR
            + '" href="' + esc(url) + '" target="_blank" rel="noopener">' + sbInner(title) + '</a>';
    }

    function insertShareHtml(url, title) {
        var ta = document.getElementById('text');
        if (!ta) { toast('找不到文章编辑器'); return; }
        var html = shareBtnHtml(url, title);
        insertAtCursor(ta, html, true);
        toast('已插入「' + title + '」');
    }

    function loadShareList() {
        var box = $('#fmw-share-list');
        box.innerHTML = '<div class="fmw-share-tip">加载中…</div>';
        api('shareList').then(function (data) {
            var shares = data.shares || [];
            if (!shares.length) {
                box.innerHTML = '<div class="fmw-share-tip">还没有分享链接<br>可在 文件管理 → 分享管理 中创建</div>';
                return;
            }
            box.innerHTML = shares.map(function (s) {
                var st = ST_MAP[s.status] || ST_MAP.active;
                var name = s.displayName || s.title || ('分享 #' + s.id);
                var disabled = s.status !== 'active';
                return '<div class="fmw-share-row">'
                    + '<div class="fmw-share-info">'
                    + '<div class="fmw-share-name" title="' + esc(name) + '">' + esc(name)
                    + (s.hasPwd ? ' 🔒' : '') + '</div>'
                    + '<div class="fmw-share-url" title="' + esc(s.url) + '">' + esc(s.url) + '</div>'
                    + '</div>'
                    + '<div class="fmw-share-side">'
                    + '<span class="fmw-share-badge" style="background:' + st.bg + '">' + st.text + '</span>'
                    + '<button type="button" class="fmw-btn fmw-btn-primary" data-ins="1"'
                    + ' data-url="' + esc(s.url) + '" data-title="' + esc(name) + '"'
                    + (disabled ? ' disabled title="该分享已' + st.text + '，无法插入"' : ' title="插入到文章正文"')
                    + '>插入</button>'
                    + '</div></div>';
            }).join('');
        }).catch(function (e) {
            box.innerHTML = '<div class="fmw-share-tip">加载失败：' + esc(e.message || e) + '</div>';
        });
    }

    function openShareModal() {
        $('#fmw-share-modal').hidden = false;
        renderSbPicker();
        loadShareList();
    }

    $('#fmw-share-modal').addEventListener('click', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-fmw-share-close')) {
            this.hidden = true;
            return;
        }
        var sh = e.target.closest ? e.target.closest('[data-sh]') : null;
        if (sh) {
            SB_SHAPE = sh.getAttribute('data-sh');
            saveSbPick();
            renderSbPicker();
            return;
        }
        var cl = e.target.closest ? e.target.closest('[data-cl]') : null;
        if (cl) {
            SB_COLOR = cl.getAttribute('data-cl');
            saveSbPick();
            renderSbPicker();
            return;
        }
        var btn = e.target.closest ? e.target.closest('[data-ins]') : null;
        if (btn && !btn.disabled) {
            insertShareHtml(btn.getAttribute('data-url'), btn.getAttribute('data-title'));
        }
    });

    (function buildEditorBar() {
        var ta = document.getElementById('text');
        if (!ta || document.getElementById('fmw-editorbar')) { return; }
        var bar = document.createElement('div');
        bar.id = 'fmw-editorbar';
        bar.innerHTML = '<button type="button" class="fmw-edbtn" id="fmw-share-open"'
            + ' title="从分享链接列表中选择一个插入文章正文">'
            + '<span class="fmw-edbtn-ic">🔗</span>插入分享链接</button>';
        var p = ta.parentNode;
        if (p && p.parentNode) { p.parentNode.insertBefore(bar, p); }
        bar.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('#fmw-share-open')) { openShareModal(); }
        });
    })();

    try {
        var panelEl = $('#fmw-panel');
        if (panelEl && nativePanel.parentNode) {
            nativePanel.insertAdjacentElement('afterend', panelEl);
        }
        renderBound();
        fmwInitDone = true;
        loadFolders().catch(function () {});
        loadFiles();
    } catch (e) {
        toast('附件面板初始化失败：' + (e.message || e));
    }
})();
JS;

    public static function frontShareStyle()
    {
        echo '<style>', self::SHARE_BTN_CSS, '</style>', "\n";
    }

    public static function writeBottom($post = null)
    {
        if (!defined('__TYPECHO_ADMIN__')) {
            return;
        }

        $apiUrl = \Widget\Security::alloc()->getIndex('/action/filemanager');
        $adminUrl = \Widget\Options::alloc()->adminUrl;
        $videoHtml = empty(self::settings()['editorVideoHtml']) ? '0' : '1';

        $fmwHover = '1';
        try {
            $fmwPrefs = self::getUserPrefs();
            if (isset($fmwPrefs['hoverPreview']) && '0' === (string) $fmwPrefs['hoverPreview']) {
                $fmwHover = '0';
            }
        } catch (\Throwable $e) {

        }

        $fmwImgHost = '0';
        $fmwUploadDest = 'site';
        $fmwIhS3 = false;
        try {
            $fmwPrefs = self::getUserPrefs();
            if (isset($fmwPrefs['imageHost']) && is_array($fmwPrefs['imageHost'])
                && '1' === (string) (isset($fmwPrefs['imageHost']['enabled']) ? $fmwPrefs['imageHost']['enabled'] : '0')) {
                $ihCfg = $fmwPrefs['imageHost'];
                $fmwIhS3 = ('s3' === (string) (isset($ihCfg['type']) ? $ihCfg['type'] : ''));
                if ('s3' === (string) (isset($ihCfg['type']) ? $ihCfg['type'] : '')) {
                    $ihOk = !empty($ihCfg['s3Endpoint']) && !empty($ihCfg['s3Bucket'])
                        && !empty($ihCfg['s3AccessKey']) && !empty($ihCfg['s3SecretKey']);
                } else {
                    $ihOk = !empty($ihCfg['api']) && !empty($ihCfg['token']);
                }
                if ($ihOk) {
                    $fmwImgHost = '1';

                    if (isset($fmwPrefs['defaultUploadDest']) && 'imagehost' === (string) $fmwPrefs['defaultUploadDest']) {
                        $fmwUploadDest = 'imagehost';
                    }
                }
            }
        } catch (\Throwable $e) {
            $fmwImgHost = '0';
        }
        $prefUploadFid = 0;
        try {
            $prefUploadFid = self::prefUploadFid();
        } catch (\Throwable $e) {
            $prefUploadFid = 0;
        }

        $fmwWm = '0';
        try {
            $fmwWmCfg = \Widget\Options::alloc()->plugin('FileManager');
            $fmwWm = (isset($fmwWmCfg->watermark) && '1' === (string) $fmwWmCfg->watermark) ? '1' : '0';
        } catch (\Throwable $e) {
            $fmwWm = '0';
        }
        $fmwWmReady = '0';
        try {
            $fmwWmReady = \FileManager_Converter::watermarkReady(\FileManager_ImageTool::settings()) ? '1' : '0';
        } catch (\Throwable $e) {
            $fmwWmReady = '0';
        }
        $fmwUpConv = '';
        $fmwUpDt = '0';
        try {
            $fmwUpPrefs = self::getUserPrefs();
            if (isset($fmwUpPrefs['uploadConv']) && in_array((string) $fmwUpPrefs['uploadConv'], array('', 'webp', 'jpg', 'png'), true)) {
                $fmwUpConv = (string) $fmwUpPrefs['uploadConv'];
            }
            if (isset($fmwUpPrefs['uploadRenameDt']) && '1' === (string) $fmwUpPrefs['uploadRenameDt']) {
                $fmwUpDt = '1';
            }
        } catch (\Throwable $e) {

        }

        echo '<style>', self::PICKER_CSS, self::SHARE_BTN_CSS, '</style>';
        echo self::PICKER_HTML;
        echo '<script>(function(){ var FMW_URL = ', json_encode($apiUrl),
            ', FMW_ADMIN = ', json_encode($adminUrl),
            ', FMW_VIDEO_HTML = ', json_encode($videoHtml),
            ', FMW_UP_FID = ', (int) $prefUploadFid,
            ', FMW_IMGHOST = ', (int) $fmwImgHost,
            ', FMW_IH_S3 = ', (int) $fmwIhS3,
            ', FMW_UPLOAD_DEST = ', json_encode($fmwUploadDest),
            ', FMW_WM = ', json_encode($fmwWm),
            ', FMW_WM_READY = ', json_encode($fmwWmReady),
            ', FMW_UP_CONV = ', json_encode($fmwUpConv),
            ', FMW_UP_DT = ', json_encode($fmwUpDt),
            ', FMW_HOVER_PREVIEW = ', (int) $fmwHover, '; ',
            self::PICKER_JS, ' })();</script>';
    }
}

require_once dirname(__FILE__) . '/ImageTool.php';

if (class_exists('\Typecho\Plugin')) {
    \Typecho\Plugin::factory('admin/write-post.php')->bottom = array('FileManager_Plugin', 'writeBottom');
    \Typecho\Plugin::factory('admin/write-page.php')->bottom = array('FileManager_Plugin', 'writeBottom');

    \Typecho\Plugin::factory('Widget\Archive')->header = array('FileManager_Plugin', 'frontShareStyle');

    \Typecho\Plugin::factory('admin/write-post.php')->option = array('FileManager_ImageTool', 'editorPanel');
    \Typecho\Plugin::factory('admin/write-page.php')->option = array('FileManager_ImageTool', 'editorPanel');
    \Typecho\Plugin::factory('admin/write-post.php')->bottom = array('FileManager_ImageTool', 'editorScript');
    \Typecho\Plugin::factory('admin/write-page.php')->bottom = array('FileManager_ImageTool', 'editorScript');

    try {
        $fmExport = \Typecho\Plugin::export();
        $fmHandles = isset($fmExport['handles']) && is_array($fmExport['handles']) ? $fmExport['handles'] : array();
    } catch (\Throwable $e) {
        $fmHandles = array();
    }

    $fmHasHook = function (string $hookKey, array $classNames) use ($fmHandles) {
        foreach ($fmHandles as $fmKey => $fmCallbacks) {
            if (!is_string($fmKey) || false === strpos(strtolower($fmKey), $hookKey)) {
                continue;
            }
            foreach ((array) $fmCallbacks as $fmCb) {
                $fmName = is_array($fmCb) && isset($fmCb[0]) ? $fmCb[0] : (is_string($fmCb) ? $fmCb : '');
                foreach ($classNames as $fmCls) {
                    if (is_string($fmName) && false !== strpos($fmName, $fmCls)) {
                        return true;
                    }
                }
            }
        }

        return false;
    };

    if (!$fmHasHook('upload:uploadhandle', array('FileManager_Plugin'))) {
        \Typecho\Plugin::factory('Widget\Upload')->uploadHandle = array('FileManager_Plugin', 'uploadHandle');
    }
    if (!$fmHasHook('upload:modifyhandle', array('FileManager_ImageTool'))) {
        \Typecho\Plugin::factory('Widget\Upload')->modifyHandle = array('FileManager_ImageTool', 'modifyHandle');
    }
    if (!$fmHasHook('upload:deletehandle', array('FileManager_ImageTool'))) {
        \Typecho\Plugin::factory('Widget\Upload')->deleteHandle = array('FileManager_ImageTool', 'deleteHandle');
    }

    try {
        $fmDedupe = \Closure::bind(function () {
            foreach (self::$plugin['handles'] as $fmKey => $fmCbs) {
                if (!is_string($fmKey)) {
                    continue;
                }
                $fmKeyLow = strtolower($fmKey);

                if (false === strpos($fmKeyLow, 'upload:uploadhandle')
                    && false === strpos($fmKeyLow, 'upload:modifyhandle')
                    && false === strpos($fmKeyLow, 'upload:deletehandle')) {
                    continue;
                }
                $fmSeen = array();
                $fmRemoved = false;
                foreach ($fmCbs as $fmW => $fmCb) {
                    $fmSig = is_array($fmCb) ? implode('::', (array) $fmCb) : (string) $fmCb;
                    if (isset($fmSeen[$fmSig])) {
                        unset($fmCbs[$fmW]);
                        $fmRemoved = true;
                        continue;
                    }
                    $fmSeen[$fmSig] = true;
                }
                if ($fmRemoved) {
                    self::$plugin['handles'][$fmKey] = array_values($fmCbs);
                }
            }
        }, null, '\Typecho\Plugin');
        $fmDedupe();
    } catch (\Throwable $e) {

    }
}
