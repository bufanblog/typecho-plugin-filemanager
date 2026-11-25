<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class FileManager_Action extends \Typecho\Widget implements \Widget\ActionInterface
{

    const PAGE_SIZE = 30;

    const EXTRACT_MAX_ENTRIES = 2000;

    const EXTRACT_MAX_BYTES = 1073741824;

    const ZIP_WARN_BYTES = 209715200;

    const EXTRACT_SCRIPT_EXTS = array(
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'phar',
        'cgi', 'pl', 'sh', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd',
    );

    const EXTRACT_JUNK_NAMES = array('.ds_store', 'thumbs.db', 'desktop.ini');

    /** 归零到 0° 时是否直接用母版字节还原；false = 照常重编码，体积与其它角度一致 */
    const RESTORE_ON_ZERO = false;

    /** 入口处记录的输出缓冲层级，用于丢弃响应前混进来的 warning/notice */
    private static $obBase = null;

    /** 本次旋转实际用的编码参数，随响应回给前端（console 可见） */
    private static $lastEnc = array();

    public function execute()
    {
    }

    public function action()
    {
        /* 统一开输出缓冲：warning/notice 先落缓冲，否则会污染 JSON 或图片字节 */
        self::$obBase = ob_get_level();
        @ob_start();

        $user = \Widget\User::alloc();
        if (!$user->hasLogin() || !$user->pass('administrator', true)) {
            $this->json(403, '无权访问，请以管理员身份登录');
        }

        $do = (string) $this->request->get('do');
        self::registerFatalGuard();

        /* 缩略图是只读资源，登录已在上面校验；不校验页面令牌，URL 才能稳定命中缓存 */
        $token = (string) $this->request->get('_');
        if ('thumb' !== $do) {
            $ok = '' !== $token
                && hash_equals(\Widget\Security::alloc()->getToken($this->request->getReferer()), $token);
            if (!$ok) {
                $this->json(403, '安全校验失败，请刷新页面后重试');
            }
        }
        $methods = array(
            'folders', 'list', 'folderCreate', 'folderRename', 'folderMove', 'folderDelete',
            'move', 'fileDelete', 'fileGet', 'fileUpdate', 'fileTextGet', 'fileTextSave', 'fileTextConvert',
            'fileInfo', 'contentSearch', 'imageRotateSave', 'rotProbe',
            'classifyPreview', 'classifyRun', 'upload',
            'shareCreate', 'shareList', 'shareUpdate', 'shareDelete', 'shareLogs',
            'unattachedCount', 'cleanUnattached',
            'cleanEmptyFolders',
            'storageStats',
            'trashList', 'trashRestore', 'trashDelete', 'trashClear',
            'healthCheck', 'healthClean',
            'zipCreate', 'extract', 'folderZip', 'exif',
            'batchWebpScan', 'batchWebpOne',
            'prefsGet', 'prefsSave',
            'thumb', 'thumbClear',
            'imageHostTest', 'imageHostUpload', 'migrateList',
        );

        if (!in_array($do, $methods)) {
            $this->json(400, '未知操作：' . htmlspecialchars($do));
        }

        try {
            $this->{'do' . ucfirst($do)}();
        } catch (\Throwable $e) {

            $this->json(500, '服务器内部错误：' . $e->getMessage()
                . '（' . basename(str_replace('\\', '/', get_class($e))) . ' @ line ' . $e->getLine() . '）');
        }
    }

    private function doFolders()
    {
        $db = $this->db();
        $this->syncMap();

        $folders = $db->fetchAll($db->select()
            ->from('table.filemanager_folders')
            ->order('sort', \Typecho\Db::SORT_ASC)
            ->order('fid', \Typecho\Db::SORT_ASC));

        $counts = array();
        foreach ($db->fetchAll($db->select('fid', 'COUNT(cid) AS cnt')
            ->from('table.filemanager_map')->group('fid')) as $row) {
            $counts[$row['fid']] = (int) $row['cnt'];
        }

        $totalRow = $db->fetchRow($db->select('COUNT(cid) AS cnt')->from('table.filemanager_map'));

        $list = array();
        foreach ($folders as $folder) {
            $list[] = array(
                'fid'    => (int) $folder['fid'],
                'parent' => (int) $folder['parent'],
                'name'   => $folder['name'],
                'count'  => isset($counts[$folder['fid']]) ? (int) $counts[$folder['fid']] : 0,
            );
        }

        $this->json(200, '', array(
            'folders'       => $list,
            'total'         => isset($totalRow['cnt']) ? (int) $totalRow['cnt'] : 0,
            'uncategorized' => isset($counts[0]) ? (int) $counts[0] : 0,
        ));
    }

    private function doFolderCreate()
    {
        $name = trim((string) $this->request->get('name'));
        $parent = max(0, (int) $this->request->get('parent'));
        $this->assertFolderName($name);

        $db = $this->db();
        if ($parent > 0 && !$this->folderExists($parent)) {
            $this->json(400, '父文件夹不存在');
        }

        $user = \Widget\User::alloc();
        $db->query($db->insert('table.filemanager_folders')->rows(array(
            'name'    => $name,
            'parent'  => $parent,
            'sort'    => 0,
            'owner'   => (int) $user->uid,
            'created' => time(),
        )));

        $newRow = $db->fetchRow($db->select('fid')->from('table.filemanager_folders')
            ->where('name = ?', $name)
            ->order('fid', \Typecho\Db::SORT_DESC)->limit(1));

        $this->json(200, '', array('fid' => $newRow ? (int) $newRow['fid'] : 0));
    }

    private function doFolderRename()
    {
        $fid = (int) $this->request->get('fid');
        $name = trim((string) $this->request->get('name'));
        $this->assertFolderName($name);

        if (!$this->folderExists($fid)) {
            $this->json(400, '文件夹不存在');
        }

        $this->db()->query($this->db()->update('table.filemanager_folders')
            ->rows(array('name' => $name))
            ->where('fid = ?', $fid));

        $this->json(200, '已重命名');
    }

    private function doFolderMove()
    {
        $fid = (int) $this->request->get('fid');
        $parent = max(0, (int) $this->request->get('parent'));

        if ($fid <= 0 || !$this->folderExists($fid)) {
            $this->json(400, '文件夹不存在');
        }
        if ($parent === $fid) {
            $this->json(400, '不能将文件夹移动到自身');
        }
        if ($parent > 0 && !$this->folderExists($parent)) {
            $this->json(400, '目标文件夹不存在');
        }

        $db = $this->db();
        $all = array();
        foreach ($db->fetchAll($db->select('fid', 'parent')->from('table.filemanager_folders')) as $row) {
            $all[(int) $row['fid']] = (int) $row['parent'];
        }
        $queue = array($fid);
        while (!empty($queue)) {
            $current = array_pop($queue);
            foreach ($all as $childFid => $childParent) {
                if ($childParent === $current) {
                    if ($childFid === $parent) {
                        $this->json(400, '不能将文件夹移动到它自己的子文件夹中');
                    }
                    $queue[] = $childFid;
                }
            }
        }

        $db->query($db->update('table.filemanager_folders')
            ->rows(array('parent' => $parent))
            ->where('fid = ?', $fid));

        $this->json(200, '文件夹已移动');
    }

    private function doFolderDelete()
    {
        $fid = (int) $this->request->get('fid');
        if ($fid <= 0 || !$this->folderExists($fid)) {
            $this->json(400, '文件夹不存在');
        }

        $db = $this->db();

        $all = array();
        foreach ($db->fetchAll($db->select('fid', 'parent')->from('table.filemanager_folders')) as $row) {
            $all[(int) $row['fid']] = (int) $row['parent'];
        }
        $ids = array($fid);
        $queue = array($fid);
        while (!empty($queue)) {
            $current = array_pop($queue);
            foreach ($all as $childFid => $parent) {
                if ($parent === $current && !in_array($childFid, $ids)) {
                    $ids[] = $childFid;
                    $queue[] = $childFid;
                }
            }
        }

        $idList = implode(',', array_map('intval', $ids));
        $prefix = $db->getPrefix();

        $db->query("UPDATE `{$prefix}filemanager_map` SET fid = 0 WHERE fid IN ({$idList})");
        $db->query("DELETE FROM `{$prefix}filemanager_folders` WHERE fid IN ({$idList})");

        $this->json(200, '文件夹已删除，其中的文件已移动到「未分类」');
    }

    private function doCleanEmptyFolders()
    {
        $db = $this->db();

        $folders = array();
        foreach ($db->fetchAll($db->select('fid', 'parent')->from('table.filemanager_folders')) as $row) {
            $folders[(int) $row['fid']] = (int) $row['parent'];
        }
        if (empty($folders)) {
            $this->json(200, '没有文件夹，无需清理', array('found' => 0, 'deleted' => 0));
        }

        $counts = array();
        foreach ($db->fetchAll($db->select('fid', 'COUNT(cid) AS cnt')
            ->from('table.filemanager_map')->group('table.filemanager_map.fid')) as $row) {
            $counts[(int) $row['fid']] = (int) $row['cnt'];
        }

        $empty = array();
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($folders as $fid => $parent) {
                if (isset($empty[$fid]) || ($counts[$fid] ?? 0) > 0) {
                    continue;
                }
                $hasAliveChild = false;
                foreach ($folders as $childFid => $childParent) {
                    if ($childParent === $fid && !isset($empty[$childFid])) {
                        $hasAliveChild = true;
                        break;
                    }
                }
                if (!$hasAliveChild) {
                    $empty[$fid] = true;
                    $changed = true;
                }
            }
        }

        $found = count($empty);
        if (!$found) {
            $this->json(200, '没有空文件夹', array('found' => 0, 'deleted' => 0));
        }

        if ((int) $this->request->get('confirm', 0) !== 1) {
            $this->json(200, '发现 ' . $found . ' 个空文件夹', array('found' => $found, 'deleted' => 0));
        }

        $idList = implode(',', array_map('intval', array_keys($empty)));
        $prefix = $db->getPrefix();
        $db->query("DELETE FROM `{$prefix}filemanager_folders` WHERE fid IN ({$idList})");

        $this->json(200, '已删除 ' . $found . ' 个空文件夹', array('found' => $found, 'deleted' => $found));
    }

    private function doStorageStats()
    {
        $db = $this->db();
        $this->syncMap();

        try {
            $rows = $db->fetchAll($db->select('cid', 'fid', 'ext', 'size')
                ->from('table.filemanager_map'));
        } catch (\Throwable $e) {
            $rows = array();
        }

        $totalCount = 0;
        $totalSize = 0;
        $groups = array();
        $folderGroups = array();
        $extGroups = array();

        foreach ($rows as $row) {
            $size = (int) $row['size'];
            $totalCount++;
            $totalSize += $size;

            $cat = $this->categoryOfExt((string) $row['ext']);
            if (null === $cat || '' === $cat) {
                $cat = '其它';
            }
            if (!isset($groups[$cat])) {
                $groups[$cat] = array('count' => 0, 'size' => 0);
            }
            $groups[$cat]['count']++;
            $groups[$cat]['size'] += $size;

            $fid = (int) $row['fid'];
            if (!isset($folderGroups[$fid])) {
                $folderGroups[$fid] = array('count' => 0, 'size' => 0);
            }
            $folderGroups[$fid]['count']++;
            $folderGroups[$fid]['size'] += $size;

            $extKey = strtolower(trim((string) $row['ext']));
            if ('' === $extKey) {
                $extKey = '未知';
            }
            if (!isset($extGroups[$extKey])) {
                $extGroups[$extKey] = array('count' => 0, 'size' => 0);
            }
            $extGroups[$extKey]['count']++;
            $extGroups[$extKey]['size'] += $size;
        }

        $groupList = array();
        foreach ($groups as $name => $g) {
            $groupList[] = array('name' => (string) $name, 'count' => (int) $g['count'], 'size' => (int) $g['size']);
        }
        usort($groupList, function ($a, $b) { return $b['size'] - $a['size']; });

        $folderNames = array();
        $folderParents = array();
        try {
            foreach ($db->fetchAll($db->select('fid', 'name', 'parent')->from('table.filemanager_folders')) as $fRow) {
                $folderNames[(int) $fRow['fid']] = (string) $fRow['name'];
                $folderParents[(int) $fRow['fid']] = (int) $fRow['parent'];
            }
        } catch (\Throwable $e) {

        }
        $folderList = array();
        foreach ($folderGroups as $fid => $g) {
            if ($fid > 0) {
                $path = self::folderPathLabel($fid, $folderNames, $folderParents);
                $name = '' !== $path ? $path : '文件夹 #' . $fid;
            } else {
                $name = '未分类';
            }
            $folderList[] = array('fid' => $fid, 'name' => $name, 'count' => (int) $g['count'], 'size' => (int) $g['size']);
        }
        usort($folderList, function ($a, $b) { return $b['size'] - $a['size']; });

        $extList = array();
        foreach ($extGroups as $ext => $g) {
            $extList[] = array('ext' => (string) $ext, 'count' => (int) $g['count'], 'size' => (int) $g['size']);
        }
        usort($extList, function ($a, $b) { return $b['size'] - $a['size']; });

        usort($rows, function ($a, $b) { return (int) $b['size'] - (int) $a['size']; });
        $topRows = array_slice($rows, 0, 10);

        $rootUrl = \Widget\Options::alloc()->rootUrl;
        $topItems = array();

        foreach ($topRows as $row) {
            $cid = (int) $row['cid'];
            $item = array(
                'cid'    => $cid,
                'size'   => (int) $row['size'],
                'name'   => '',
                'url'    => '',
                'folder' => '',
            );

            try {
                $cRow = $db->fetchRow($db->select('text')->from('table.contents')
                    ->where('cid = ?', $cid)->limit(1));
                if ($cRow) {
                    $parsed = self::parseAttachmentText($cRow['text']);
                    $item['name'] = $parsed['name'];
                    $item['url'] = '' !== $parsed['path']
                        ? \Typecho\Common::url($parsed['path'], $rootUrl)
                        : '';
                }
            } catch (\Throwable $e) {

            }

            if ((int) $row['fid'] > 0) {
                $item['folder'] = self::folderPathLabel($row['fid'], $folderNames, $folderParents);
            }

            $topItems[] = $item;
        }

        $this->json(200, 'ok', array(
            'total'   => array('count' => $totalCount, 'size' => $totalSize),
            'groups'  => $groupList,
            'folders' => $folderList,
            'exts'    => $extList,
            'top'     => $topItems,
        ));
    }

    /** 向上拼出文件夹完整路径「一级/二级」，最多 10 级防环 */
    private static function folderPathLabel($fid, array $names, array $parents)
    {
        $fid = (int) $fid;
        if ($fid <= 0) {
            return '';
        }

        $chain = array();
        $cur = $fid;
        while ($cur > 0 && isset($names[$cur]) && count($chain) < 10) {
            $chain[] = '' !== $names[$cur] ? $names[$cur] : '文件夹 #' . $cur;
            $cur = isset($parents[$cur]) ? (int) $parents[$cur] : 0;
        }

        return $chain ? implode('/', array_reverse($chain)) : '';
    }

    private static function uploadBaseDir()
    {
        if (!defined('__TYPECHO_ROOT_DIR__')) {
            return null;
        }
        $base = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : '/usr/uploads';
        $root = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
        return \Typecho\Common::url($base, $root);
    }

    private static function pathReferenced($path, $blob)
    {
        $path = str_replace('\\', '/', trim((string) $path));
        if ('' === $path) {
            return false;
        }

        $needles = array(ltrim($path, '/'));
        $stripped = $path;
        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__') ? (string) __TYPECHO_UPLOAD_DIR__ : 'usr/uploads';
        foreach (array('usr/uploads', trim($uploadDir, '/')) as $pfx) {
            $pfx = trim((string) $pfx, '/');
            if ('' !== $pfx && 0 === stripos($stripped, $pfx . '/')) {
                $stripped = substr($stripped, strlen($pfx) + 1);
                break;
            }
        }
        $needles[] = $stripped;

        foreach (array_unique($needles) as $n) {
            if ('' !== $n && false !== stripos($blob, $n)) {
                return true;
            }
        }
        return false;
    }

    private function doHealthCheck()
    {
        $db = $this->db();
        $prefix = $db->getPrefix();

        $attRows = $db->fetchAll("SELECT cid, parent, created, text FROM `{$prefix}contents`"
            . " WHERE type = 'attachment'");
        $infos = array();
        foreach ($attRows as $row) {
            $info = self::parseAttachmentText((string) $row['text']);
            $info['cid'] = (int) $row['cid'];
            $info['parent'] = (int) $row['parent'];
            $info['created'] = (int) $row['created'];
            $infos[(int) $row['cid']] = $info;
        }

        $lost = array();
        foreach ($infos as $info) {
            $file = self::resolveAttachmentFile((string) $info['path']);
            if (null === $file || !is_file($file)) {
                $lost[] = array(
                    'cid'  => $info['cid'],
                    'name' => (string) $info['name'],
                    'path' => (string) $info['path'],
                    'size' => (int) $info['size'],
                );
            }
        }

        $blob = '';
        foreach ($db->fetchAll("SELECT text FROM `{$prefix}contents` WHERE type <> 'attachment'") as $r) {
            $blob .= "\n" . (string) $r['text'];
        }
        try {
            foreach ($db->fetchAll("SELECT text FROM `{$prefix}comments`") as $r) {
                $blob .= "\n" . (string) $r['text'];
            }
        } catch (\Throwable $e) {

        }
        foreach ($db->fetchAll("SELECT `value` FROM `{$prefix}options`") as $r) {
            $blob .= "\n" . (string) $r['value'];
        }
        $sharedCids = array();
        try {
            foreach ($db->fetchAll($db->select('cid')->from('table.filemanager_shares')) as $r) {
                $sharedCids[(int) $r['cid']] = true;
            }
        } catch (\Throwable $e) {

        }
        $unused = array();
        foreach ($infos as $info) {
            if ($info['parent'] > 0) {
                continue;
            }
            if (isset($sharedCids[$info['cid']])) {
                continue;
            }
            if (self::pathReferenced((string) $info['path'], $blob)) {
                continue;
            }
            $unused[] = array(
                'cid'     => $info['cid'],
                'name'    => (string) $info['name'],
                'size'    => (int) $info['size'],
                'created' => $info['created'],
            );
        }

        $base = self::uploadBaseDir();
        $unregistered = array();
        if (null !== $base && is_dir($base)) {
            $baseReal = realpath($base);
            $baseNorm = (false !== $baseReal)
                ? rtrim(str_replace('\\', '/', $baseReal), '/')
                : rtrim(str_replace('\\', '/', (string) $base), '/');

            $known = array();
            foreach ($infos as $info) {
                $f = self::computeAttachmentFile((string) $info['path']);
                if (null === $f) {
                    continue;
                }
                $known[strtolower(self::normalizePathForCompare($f))] = $info['cid'];
                $real = realpath($f);
                if (false !== $real) {
                    $known[strtolower(self::normalizePathForCompare($real))] = $info['cid'];
                }
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isDir()) {
                    continue;
                }
                $path = str_replace('\\', '/', (string) $f->getPathname());
                $rel = ('' !== $baseNorm && 0 === strpos(strtolower($path), strtolower($baseNorm . '/')))
                    ? substr($path, strlen($baseNorm) + 1)
                    : $path;
                $segs = explode('/', strtolower(ltrim($rel, '/')));
                if (in_array('.trash', $segs, true) || in_array('_backup_img', $segs, true)) {
                    continue;
                }
                if (isset($known[strtolower(self::normalizePathForCompare($path))])) {
                    continue;
                }

                $uploadPrefix = rtrim(defined('__TYPECHO_UPLOAD_DIR__') ? (string) __TYPECHO_UPLOAD_DIR__ : 'usr/uploads', '/');
                $ref = self::pathReferenced($uploadPrefix . '/' . $rel, $blob)
                    || self::pathReferenced('usr/uploads/' . $rel, $blob);
                $unregistered[] = array(
                    'path'  => $rel,
                    'size'  => (int) $f->getSize(),
                    'mtime' => (int) $f->getMTime(),
                    'ref'   => $ref ? 1 : 0,
                );
            }

            usort($unregistered, function ($a, $b) {
                return $a['ref'] === $b['ref']
                    ? strcmp($a['path'], $b['path'])
                    : ($a['ref'] < $b['ref'] ? -1 : 1);
            });

            $bySize = array();
            $it2 = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it2 as $f) {
                if ($f->isDir()) {
                    continue;
                }
                $path = str_replace('\\', '/', (string) $f->getPathname());
                $rel2 = ('' !== $baseNorm && 0 === strpos(strtolower($path), strtolower($baseNorm . '/')))
                    ? substr($path, strlen($baseNorm) + 1)
                    : $path;
                $segs2 = explode('/', strtolower(ltrim($rel2, '/')));
                if (in_array('.trash', $segs2, true) || in_array('_backup_img', $segs2, true)) {
                    continue;
                }
                $bySize[(int) $f->getSize()][] = array(
                    'path'  => $path,
                    'rel'   => $rel2,
                    'size'  => (int) $f->getSize(),
                    'mtime' => (int) $f->getMTime(),
                );
            }

            $hashGroups = array();
            foreach ($bySize as $files2) {
                if (count($files2) < 2) {
                    continue;
                }
                foreach ($files2 as $fe) {
                    $h = @md5_file($fe['path']);
                    if (false === $h || '' === $h) {
                        continue;
                    }
                    $hashGroups[$h][] = $fe;
                }
            }

            $duplicates = array();
            $gid = 0;
            foreach ($hashGroups as $files2) {
                if (count($files2) < 2) {
                    continue;
                }
                $gid++;
                foreach ($files2 as $fe) {
                    $normKey = strtolower(self::normalizePathForCompare($fe['path']));
                    $real2 = realpath($fe['path']);
                    $cid2 = 0;
                    if (isset($known[$normKey])) {
                        $cid2 = (int) $known[$normKey];
                    } elseif (false !== $real2 && isset($known[strtolower(self::normalizePathForCompare($real2))])) {
                        $cid2 = (int) $known[strtolower(self::normalizePathForCompare($real2))];
                    }
                    $duplicates[] = array(
                        'group' => $gid,
                        'path'  => $fe['rel'],
                        'size'  => $fe['size'],
                        'mtime' => $fe['mtime'],
                        'reg'   => $cid2 > 0 ? 1 : 0,
                    );
                }
            }

            usort($duplicates, function ($a, $b) {
                if ($a['group'] !== $b['group']) {
                    return $a['group'] < $b['group'] ? -1 : 1;
                }
                if ($a['reg'] !== $b['reg']) {
                    return $a['reg'] > $b['reg'] ? -1 : 1;
                }
                return strcmp($a['path'], $b['path']);
            });
        }

        $this->json(200, '', array(
            'unregistered' => $unregistered,
            'lost'         => $lost,
            'unused'       => $unused,
            'duplicates'   => isset($duplicates) ? $duplicates : array(),
        ));
    }

    private function doHealthClean()
    {
        $type = (string) $this->request->get('type', '');
        $raw = (string) $this->request->get('items', '');
        $items = json_decode($raw, true);
        if (!is_array($items)) {
            $items = ('' === trim($raw)) ? array() : explode(',', $raw);
        }
        if (!$items) {
            $this->json(400, '未选择任何要清理的项');
        }

        $db = $this->db();

        if ('unregistered' === $type) {
            $base = self::uploadBaseDir();
            if (null === $base || !is_dir($base)) {
                $this->json(400, '上传目录不可用');
            }
            $baseReal = realpath($base);
            $baseAbs = rtrim(str_replace('\\', '/', (string) $baseReal), '/');
            $n = 0;
            $size = 0;
            foreach ($items as $rel) {
                $rel = str_replace('\\', '/', trim((string) $rel));
                if ('' === $rel || false !== strpos($rel, '..')) {
                    continue;
                }
                $abs = $baseAbs . '/' . ltrim($rel, '/');
                if (!self::pathUnderBase($abs, (string) $baseReal)) {
                    continue;
                }
                $segs = explode('/', strtolower(ltrim($rel, '/')));
                if (in_array('.trash', $segs, true) || in_array('_backup_img', $segs, true)) {
                    continue;
                }
                if (is_file($abs)) {
                    $size += (int) @filesize($abs);
                    if (@unlink($abs)) {
                        $n++;
                    }
                }
            }
            $this->json(200, '已删除 ' . $n . ' 个未登记文件，释放 '
                . ($size >= 1048576 ? round($size / 1048576, 2) . ' MB' : round($size / 1024, 1) . ' KB'),
                array('cleaned' => $n));
        }

        if ('unregisteredRegister' === $type) {
            $base = self::uploadBaseDir();
            if (null === $base || !is_dir($base)) {
                $this->json(400, '上传目录不可用');
            }
            $baseReal = realpath($base);
            $baseAbs = rtrim(str_replace('\\', '/', (string) $baseReal), '/');
            $user = \Widget\User::alloc();
            $options = \Widget\Options::alloc();
            $now = time();
            $n = 0;
            foreach ($items as $rel) {
                $rel = str_replace('\\', '/', trim((string) $rel));
                if ('' === $rel || false !== strpos($rel, '..')) {
                    continue;
                }
                $abs = $baseAbs . '/' . ltrim($rel, '/');
                if (!self::pathUnderBase($abs, (string) $baseReal) || !is_file($abs)) {
                    continue;
                }
                $segs = explode('/', strtolower(ltrim($rel, '/')));
                if (in_array('.trash', $segs, true) || in_array('_backup_img', $segs, true)) {
                    continue;
                }

                $path = (defined('__TYPECHO_UPLOAD_DIR__') ? (string) __TYPECHO_UPLOAD_DIR__ : 'usr/uploads') . '/' . ltrim($rel, '/');
                $cands = $db->fetchAll($db->select('text')->from('table.contents')
                    ->where('type = ?', 'attachment')
                    ->where('text LIKE ?', '%' . $path . '%'));
                $dup = false;
                foreach ($cands as $c) {
                    $p = self::parseAttachmentText((string) $c['text']);
                    $f = '' !== $p['path'] ? self::resolveAttachmentFile((string) $p['path']) : null;
                    if (null !== $f && strtolower(self::normalizePathForCompare($f)) === strtolower(self::normalizePathForCompare($abs))) {
                        $dup = true;
                        break;
                    }
                }
                if ($dup) {
                    continue;
                }

                $info = pathinfo($abs);
                $ext = isset($info['extension']) ? strtolower($info['extension']) : '';
                $record = array(
                    'name' => (string) $info['basename'],
                    'path' => $path,
                    'size' => (int) @filesize($abs),
                    'type' => $ext,
                    'mime' => method_exists(\Typecho\Common::class, 'mimeContentType')
                        ? (string) \Typecho\Common::mimeContentType($abs) : '',
                );

                $cid = (int) $db->query($db->insert('table.contents')->rows(array(
                    'title'        => $record['name'],
                    'slug'         => self::uniqueSlug((string) $record['name']),
                    'created'      => $now,
                    'modified'     => $now,
                    'text'         => self::attachmentText($record),
                    'authorId'     => (int) $user->uid,
                    'type'         => 'attachment',
                    'status'       => 'publish',
                    'password'     => '',
                    'commentsNum'  => 0,
                    'allowComment' => (string) $options->defaultAllowComment,
                    'allowPing'    => (string) $options->defaultAllowPing,
                    'allowFeed'    => (string) $options->defaultAllowFeed,
                    'parent'       => 0,
                )));
                if ($cid > 0) {
                    try {
                        \FileManager_Plugin::autoMapUserFolder($cid, (int) $user->uid);
                    } catch (\Throwable $e) {

                    }
                    $n++;
                }
            }
            $this->json(200, '已登记 ' . $n . ' 个文件，可在列表中查看和编辑', array('cleaned' => $n));
        }

        if ('lost' === $type) {
            $n = 0;
            foreach ($items as $cid) {
                $cid = (int) $cid;
                if ($cid <= 0) {
                    continue;
                }
                $row = $db->fetchRow($db->select('cid', 'text')->from('table.contents')
                    ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
                if (!$row) {
                    continue;
                }
                $info = self::parseAttachmentText((string) $row['text']);
                $file = self::resolveAttachmentFile((string) $info['path']);
                if (null !== $file && is_file($file)) {
                    continue;
                }
                $db->query($db->delete('table.contents')->where('cid = ?', $cid));
                $db->query($db->delete('table.filemanager_map')->where('cid = ?', $cid));
                $db->query($db->delete('table.filemanager_shares')->where('cid = ?', $cid));
                $n++;
            }
            $this->json(200, '已清理 ' . $n . ' 条失效附件记录', array('cleaned' => $n));
        }

        if ('unused' === $type) {
            $cleaned = 0;
            $recycled = 0;
            foreach ($items as $cid) {
                $cid = (int) $cid;
                if ($cid <= 0) {
                    continue;
                }
                $row = $db->fetchRow($db->select()->from('table.contents')
                    ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
                if (!$row) {
                    continue;
                }
                $info = self::parseAttachmentText((string) $row['text']);
                $mapRow = $db->fetchRow($db->select('fid')->from('table.filemanager_map')
                    ->where('cid = ?', $cid)->limit(1));

                if (self::trashAttachment($cid, $row, $info, $mapRow ? (int) $mapRow['fid'] : 0)) {
                    $recycled++;
                } else {
                    $file = self::resolveAttachmentFile($info['path']);
                    if (null !== $file && is_file($file)) {
                        @unlink($file);
                    }
                    self::dropOriginBackup((string) $info['path']);
                }

                $db->query($db->delete('table.contents')->where('cid = ?', $cid));
                $db->query($db->delete('table.filemanager_map')->where('cid = ?', $cid));
                $db->query($db->delete('table.filemanager_shares')->where('cid = ?', $cid));
                $cleaned++;
            }
            $msg = '已清理 ' . $cleaned . ' 个未使用附件';
            $msg .= $recycled > 0 ? '（' . $recycled . ' 个已移入回收站，可还原）' : '';
            $this->json(200, $msg, array('cleaned' => $cleaned));
        }

        if ('duplicates' === $type) {
            $base = self::uploadBaseDir();
            if (null === $base || !is_dir($base)) {
                $this->json(400, '上传目录不可用');
            }
            $baseReal = realpath($base);
            $baseAbs = rtrim(str_replace('\\', '/', (string) $baseReal), '/');

            $prefix = $db->getPrefix();
            $knownCid = array();
            foreach ($db->fetchAll("SELECT cid, text FROM `{$prefix}contents` WHERE type = 'attachment'") as $row) {
                $info2 = self::parseAttachmentText((string) $row['text']);
                $f2 = self::computeAttachmentFile((string) $info2['path']);
                if (null === $f2) {
                    continue;
                }
                $knownCid[strtolower(self::normalizePathForCompare($f2))] = (int) $row['cid'];
                $real3 = realpath($f2);
                if (false !== $real3) {
                    $knownCid[strtolower(self::normalizePathForCompare($real3))] = (int) $row['cid'];
                }
            }

            $sizeMap = array();
            $it3 = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it3 as $f3) {
                if ($f3->isDir()) {
                    continue;
                }
                $path3 = str_replace('\\', '/', (string) $f3->getPathname());
                $rel3 = ('' !== $baseAbs && 0 === strpos(strtolower($path3), strtolower($baseAbs . '/')))
                    ? substr($path3, strlen($baseAbs) + 1)
                    : $path3;
                $segs3 = explode('/', strtolower(ltrim($rel3, '/')));
                if (in_array('.trash', $segs3, true) || in_array('_backup_img', $segs3, true)) {
                    continue;
                }
                $sizeMap[(int) $f3->getSize()][] = strtolower(ltrim($rel3, '/'));
            }

            $sel = array();
            foreach ($items as $rel) {
                $rel = str_replace('\\', '/', trim((string) $rel));
                if ('' !== $rel && false === strpos($rel, '..')) {
                    $sel[strtolower(ltrim($rel, '/'))] = true;
                }
            }
            if (!$sel) {
                $this->json(400, '未选择任何要清理的项');
            }

            $n = 0;
            $recycled = 0;
            $skipped = 0;
            $size = 0;
            foreach (array_keys($sel) as $rel) {
                $abs = $baseAbs . '/' . ltrim($rel, '/');
                if (!self::pathUnderBase($abs, (string) $baseReal)) {
                    continue;
                }
                $segs3 = explode('/', strtolower(ltrim($rel, '/')));
                if (in_array('.trash', $segs3, true) || in_array('_backup_img', $segs3, true)) {
                    continue;
                }
                if (!is_file($abs)) {
                    continue;
                }

                $normKey = strtolower(self::normalizePathForCompare($abs));
                $real3 = realpath($abs);
                $cid = 0;
                if (isset($knownCid[$normKey])) {
                    $cid = (int) $knownCid[$normKey];
                } elseif (false !== $real3 && isset($knownCid[strtolower(self::normalizePathForCompare($real3))])) {
                    $cid = (int) $knownCid[strtolower(self::normalizePathForCompare($real3))];
                }

                if ($cid > 0) {

                    $row = $db->fetchRow($db->select()->from('table.contents')
                        ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
                    if (!$row) {
                        continue;
                    }
                    $info = self::parseAttachmentText((string) $row['text']);
                    $mapRow = $db->fetchRow($db->select('fid')->from('table.filemanager_map')
                        ->where('cid = ?', $cid)->limit(1));
                    if (self::trashAttachment($cid, $row, $info, $mapRow ? (int) $mapRow['fid'] : 0)) {
                        $recycled++;
                    } else {
                        $file2 = self::resolveAttachmentFile((string) $info['path']);
                        if (null !== $file2 && is_file($file2)) {
                            @unlink($file2);
                        }
                        self::dropOriginBackup((string) $info['path']);
                    }
                    $db->query($db->delete('table.contents')->where('cid = ?', $cid));
                    $db->query($db->delete('table.filemanager_map')->where('cid = ?', $cid));
                    $db->query($db->delete('table.filemanager_shares')->where('cid = ?', $cid));
                    $size += (int) $info['size'];
                    $n++;
                    continue;
                }

                $fsize = (int) @filesize($abs);
                $remaining = 0;
                foreach ((array) (isset($sizeMap[$fsize]) ? $sizeMap[$fsize] : array()) as $other) {
                    if (!isset($sel[$other])) {
                        $remaining++;
                        break;
                    }
                }
                if ($remaining < 1) {
                    $skipped++;
                    continue;
                }
                if (@unlink($abs)) {
                    $size += $fsize;
                    $n++;
                }
            }

            $msg = '已清理 ' . $n . ' 个重复文件';
            $msg .= $recycled > 0 ? '（' . $recycled . ' 个已登记附件移入回收站，可还原）' : '';
            $msg .= $skipped > 0 ? '；' . $skipped . ' 个跳过（每组至少需保留一份）' : '';
            $msg .= '，释放 ' . ($size >= 1048576 ? round($size / 1048576, 2) . ' MB' : round($size / 1024, 1) . ' KB');
            $this->json(200, $msg, array('cleaned' => $n));
        }

        $this->json(400, '未知的体检清理类型');
    }

    private function doFileInfo()
    {
        $db = $this->db();
        $cid = (int) $this->request->get('cid', 0);
        if ($cid <= 0) {
            $this->json(400, '参数错误');
        }

        $row = $db->fetchRow($db->select()->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
        if (!$row) {
            $this->json(404, '附件不存在');
        }

        $info = self::parseAttachmentText((string) $row['text']);
        $mapRow = $db->fetchRow($db->select('fid', 'size', 'ext')->from('table.filemanager_map')
            ->where('cid = ?', $cid)->limit(1));

        $ext = $mapRow ? (string) $mapRow['ext']
            : strtolower((string) pathinfo('' !== $info['path'] ? $info['path'] : (string) $row['title'], PATHINFO_EXTENSION));
        $size = $mapRow ? (int) $mapRow['size'] : (int) ($info['size'] ?? 0);

        $this->json(200, '', array(
            'cid'     => $cid,
            'title'   => (string) $row['title'],
            'name'    => '' !== $info['name'] ? $info['name'] : (string) $row['title'],
            'ext'     => $ext,
            'size'    => $size,
            'fid'     => $mapRow ? (int) $mapRow['fid'] : 0,
            'url'     => self::displayAttachmentUrl($info, \Widget\Options::alloc()->rootUrl),
            'isIh'    => self::isImageHostActive($info),
            'ihSt'    => self::ihStorageType($info),
            'isImage' => self::isImageExt($ext, $info['mime']),
        ));
    }

    private function doContentSearch()
    {
        $kw = trim((string) $this->request->get('kw', ''));
        if ('' === $kw) {
            $this->json(400, '请输入要搜索的内容关键字');
        }
        if (mb_strlen($kw, 'UTF-8') > 64) {
            $this->json(400, '关键字过长（最多 64 字）');
        }

        $db = $this->db();
        $select = $db->select('table.contents.cid', 'table.contents.title', 'table.contents.text',
                'table.filemanager_map.size')
            ->from('table.contents')
            ->join('table.filemanager_map', 'table.contents.cid = table.filemanager_map.cid', \Typecho\Db::INNER_JOIN)
            ->where('table.contents.type = ?', 'attachment')
            ->order('table.contents.cid', \Typecho\Db::SORT_DESC)
            ->limit(400);
        $this->extIn($select, $this->textEditableExts());

        $matches = array();
        $scanned = 0;
        foreach ($db->fetchAll($select) as $row) {
            if ($scanned >= 300 || count($matches) >= 50) {
                break;
            }
            $info = self::parseAttachmentText((string) $row['text']);
            if ('' === $info['path']) {
                continue;
            }
            $abs = self::resolveAttachmentFile($info['path']);
            if (null === $abs || !is_file($abs)) {
                continue;
            }
            $size = (int) $row['size'] > 0 ? (int) $row['size'] : (int) @filesize($abs);
            if ($size <= 0 || $size > 2 * 1024 * 1024) {
                continue;
            }
            $content = @file_get_contents($abs);
            if (false === $content || '' === $content) {
                continue;
            }
            if (!mb_check_encoding($content, 'UTF-8')) {
                $enc = @mb_detect_encoding($content, array('UTF-8', 'GB18030', 'BIG5'), true);
                $content = (string) @mb_convert_encoding($content, 'UTF-8', $enc ? $enc : 'UTF-8');
            }
            $pos = mb_stripos($content, $kw, 0, 'UTF-8');
            if (false === $pos) {
                continue;
            }
            $scanned++;
            $start = max(0, (int) $pos - 40);
            $snippet = trim((string) preg_replace('/\s+/u', ' ', (string) mb_substr($content, $start, 120, 'UTF-8')));
            $matches[] = array(
                'cid'     => (int) $row['cid'],
                'title'   => (string) $row['title'],
                'name'    => '' !== $info['name'] ? $info['name'] : (string) $row['title'],
                'ext'     => $row['ext'] ?? strtolower((string) pathinfo($info['path'], PATHINFO_EXTENSION)),
                'url'     => \Typecho\Common::url($info['path'], \Widget\Options::alloc()->rootUrl),
                'snippet' => $snippet,
            );
        }

        $this->json(200, '', array(
            'matches'   => $matches,
            'scanned'   => $scanned,
            'truncated' => count($matches) >= 50,
        ));
    }

    /* 当前 memory_limit 的字节数；-1 表示不限制，0 表示无法识别 */
    private static function memoryLimitBytes(): int
    {
        $v = function_exists('ini_get') ? trim((string) ini_get('memory_limit')) : '';
        if ('' === $v) { return 0; }
        if ('-1' === $v) { return -1; }
        $num = (float) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $num *= 1073741824; break;
            case 'm': $num *= 1048576; break;
            case 'k': $num *= 1024; break;
        }
        return (int) $num;
    }

    private static function bytesLabel(int $bytes): string
    {
        if ($bytes < 0) { return '不限'; }
        if ($bytes >= 1048576) { return (string) (int) round($bytes / 1048576) . 'M'; }
        if ($bytes >= 1024) { return (string) (int) round($bytes / 1024) . 'K'; }
        return $bytes . 'B';
    }

    /** 按像素预算（宽×高×4×$factor + 48MB）抬高 memory_limit；返回 false 表示已到上限仍不足 */
    private static function ensureImageMemory(string $file, float $factor = 3.4): bool
    {
        $limit = self::memoryLimitBytes();
        if ($limit < 0) { return true; }

        $size = @getimagesize($file);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            return true; /* 尺寸未知时不做限制，交给原流程判断 */
        }
        $px = (int) $size[0] * (int) $size[1];
        $need = (int) ($px * 4 * $factor) + 48 * 1048576;
        if ($need < 128 * 1048576) { $need = 128 * 1048576; }
        if ($limit >= $need) { return true; }

        $target = min($need, 1024 * 1048576);
        /* 部分主机把 ini_set 放进 disable_functions，此时只能按现状判断，不能直接调用 */
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', (string) $target);
        }
        $now = self::memoryLimitBytes();
        return $now < 0 || $now >= $need;
    }

    /* 大图处理前的统一拦截：尽量抬限，抬不动就给出可执行的提示，而不是让 PHP 直接 fatal */
    private function assertImageMemory(string $file, float $factor = 3.4)
    {
        if (self::ensureImageMemory($file, $factor)) { return; }
        $size = @getimagesize($file);
        $dim = (is_array($size) && !empty($size[0])) ? ((int) $size[0] . '×' . (int) $size[1]) : '未知尺寸';
        $this->json(400, '图片尺寸 ' . $dim . ' 超出当前 PHP 内存上限（memory_limit='
            . self::bytesLabel(self::memoryLimitBytes())
            . '），无法完成处理。请在 PHP 设置中提高 memory_limit（建议 512M 以上），或先把图片缩小再试。');
    }

    private function doImageRotateSave()
    {
        $cid = (int) $this->request->get('cid', 0);
        $angle = (int) $this->request->get('angle', 0);
        if ($cid <= 0 || !in_array($angle, array(90, 180, 270), true)) {
            $this->json(400, '参数错误');
        }

        /* 大图重编码可能几十秒：抬高超时并允许关页后跑完，中途被掐会留下半成品 */
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        @ignore_user_abort(true);
        /* 补致命错误收尾钩子：把空响应换成可读 JSON（header 已发则跳过） */
        register_shutdown_function(array(__CLASS__, 'rotFatalReport'));

        $rename = '0' !== (string) $this->request->get('rename', '1');
        $doRefs = '0' !== (string) $this->request->get('refs', '1');
        $saveas = '1' === (string) $this->request->get('saveas', '0');
        /* 已无「q100 救画质」分支：有损图再压一次只会变大，不会变清楚 */

        $row = $this->db()->fetchRow($this->db()->select('text')->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
        if (!$row) {
            $this->json(404, '文件不存在或已被删除');
        }

        $info = self::parseAttachmentText((string) $row['text']);
        $ext = strtolower((string) pathinfo($info['path'], PATHINFO_EXTENSION));
        if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true)) {
            $this->json(400, '该格式不支持旋转保存（仅限 jpg/png/webp）');
        }

        $abs = self::resolveAttachmentFile($info['path']);
        if (null === $abs || !is_file($abs)) {
            $this->json(400, '物理文件缺失，无法旋转');
        }
        if (!is_writable(dirname($abs))) {
            $this->json(400, '目录不可写（权限不足），无法保存');
        }

        /* 先记保存前的 mtime/size：原地覆盖若落在同一秒且体积相同，收尾要顶开版本号 */
        $preStat = @stat($abs);

        $raw = self::decodeAttachmentRaw((string) $row['text']);
        $rot = isset($raw['fm_rot']) && is_array($raw['fm_rot']) ? $raw['fm_rot'] : null;

        /* 先读 WebP 容器头：动图直接拒绝；VP8L 无损源记住，后面必须无损重编 */
        $wh = array('webp' => false, 'lossless' => false, 'anim' => false);
        if ('webp' === $ext) {
            $wh = self::webpHeader($abs);
            if (!empty($wh['anim'])) {
                $this->json(400, '这是动图 WebP，旋转保存会把它压成静态单帧，已取消（请在电脑上用专业工具旋转）');
            }
        }
        /* APNG 只解默认帧，保存即砍动画 → 拒绝；16 位记下来决定能否报「无损」 */
        $ph = array('png' => false, 'depth' => 8, 'anim' => false, 'palette' => false);
        if ('png' === $ext) {
            $ph = self::pngHeader($abs);
            if (!empty($ph['anim'])) {
                $this->json(400, '这是动图 PNG（APNG），旋转保存会把它压成静态单帧，已取消（请在电脑上用专业工具旋转）');
            }
        }

        /* 先算保存后的路径：画质源判定要拿原始文件名去备份目录找原图 */
        $dir = (string) pathinfo($info['path'], PATHINFO_DIRNAME);
        $origBase = (string) pathinfo($info['path'], PATHINFO_FILENAME);
        $base = is_array($rot) && !empty($rot['base']) ? (string) $rot['base'] : $origBase;
        $relDir = ('.' === $dir || '' === $dir) ? '' : $dir . '/';

        $backupDir = __DIR__ . '/.fmcache';
        $backupName = 'rot-' . $cid . '.' . $ext;
        $backupPath = $backupDir . '/' . $backupName;

        $isNew = !(is_file($backupPath) && filesize($backupPath) > 0);
        $baseLinked = false;
        if ($isNew) {
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
                $this->json(500, '无法创建基准副本目录');
            }
            /* 母版优先硬链接（零占用）；前提是落盘一律写临时文件再 rename，直接覆盖会连母版一起毁掉 */
            $baseLinked = @link($abs, $backupPath);
            if (!$baseLinked && (!@copy($abs, $backupPath) || filesize($backupPath) <= 0)) {
                $this->json(500, '建立基准副本失败，已取消保存');
            }
            /* 副本沿用原图时间戳：它是「旋转前的字节副本」，不是本次成品 */
            $srcMtime = (int) @filemtime($abs);
            if ($srcMtime > 0) { @touch($backupPath, $srcMtime); }
            @file_put_contents($backupDir . '/.htaccess', "Require all denied\n");
        }

        /* 画质源优先用原始 jpg（同尺寸实测 PSNR 45.88 vs 43.77）；三个条件见 hasOrigSource */
        $imgSrc = $backupPath;
        $imgSrcExt = $ext;
        $useOrig = false;
        $origSrc = self::origQualitySource($relDir . $base . '.' . $ext, $abs, $ext);
        if ('' !== $origSrc) {
            $imgSrc = $origSrc;
            $imgSrcExt = 'jpg';
            $useOrig = true;
        }

        $orient = 1;
        if ($useOrig) {
            /* 源换成原始 jpg 后，方向必须按它自己的 EXIF 重读，不能沿用 fm_rot 里已烘焙的值 */
            if (function_exists('exif_read_data')) {
                $exifO = @exif_read_data($imgSrc);
                $oO = is_array($exifO) && isset($exifO['Orientation']) ? (int) $exifO['Orientation'] : 1;
                if ($oO >= 1 && $oO <= 8) { $orient = $oO; }
            }
        } elseif ($isNew) {
            if (in_array($ext, array('jpg', 'jpeg'), true) && function_exists('exif_read_data')) {
                $exif = @exif_read_data($abs);
                $o = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
                if ($o >= 1 && $o <= 8) { $orient = $o; }
            }
        } elseif (!empty($rot['orig'])) {
            $orient = 1;
        } elseif (!empty($rot['orient'])) {
            $orient = (int) $rot['orient'];
        }

        /* 没有基准副本时旧累计角度作废，否则会把上个格式的角度叠到新基准上 */
        $total = $isNew
            ? $angle
            : (((is_array($rot) && isset($rot['angle']) ? (int) $rot['angle'] : 0) + $angle) % 360);

        /* 质量参数一律读基准副本，不读当前文件，避免把上一代的损失叠进来 */
        $srcInfo = self::imageSourceInfo($backupPath, $ext);
        $srcBytes = (int) @filesize($backupPath);

        /* 体积目标锚在首次原图体积（fm_rot.origBytes），锚当前副本会逐代雪崩（实测崩到 0.5 bpp） */
        $baseBytes = (is_array($rot) && isset($rot['origBytes'])) ? (int) $rot['origBytes'] : 0;
        if ($baseBytes <= 0 || $baseBytes > $srcBytes * 6 || $baseBytes < $srcBytes / 8) {
            $baseBytes = $srcBytes;
        }

        /* bpp 也用基线体积重算，否则档位会从 q96 一路滑到 q90 */
        if ('webp' === $ext && !empty($srcInfo['w']) && !empty($srcInfo['h'])) {
            $basePx = (int) $srcInfo['w'] * (int) $srcInfo['h'];
            if ($basePx > 0 && $baseBytes > 0) {
                $srcInfo['bpp'] = round($baseBytes * 8 / $basePx, 3);
            }
        }

        $inPlace = !$saveas && !$rename;
        if ($inPlace) {
            $newBase = $origBase;
            $newPath = (string) $info['path'];
            $newAbs = $abs;
        } else {
            $newBase = $rename ? ($base . '_rot' . $total . '_' . substr(bin2hex(random_bytes(3)), 0, 4))
                               : ($base . '_rot' . $total);
            $newPath = $relDir . $newBase . '.' . $ext;
            $newAbs = dirname($abs) . '/' . $newBase . '.' . $ext;
            if (!$rename && is_file($newAbs)) {
                $newBase .= '_' . substr(bin2hex(random_bytes(3)), 0, 4);
                $newPath = $relDir . $newBase . '.' . $ext;
                $newAbs = dirname($abs) . '/' . $newBase . '.' . $ext;
            }
        }

        /* 原子落盘：先写同目录临时文件再 rename；直接覆盖会毁掉硬链接母版，中途失败还会留下半张图 */
        $writeAbs = $newAbs . '.fmrot.tmp';
        if (is_file($writeAbs)) { @unlink($writeAbs); }

        /* 回到 0° 且无需烘焙方向时，直接用母版字节还原；是否启用看 RESTORE_ON_ZERO（当前关闭） */
        $restoreOrient = $useOrig ? 1 : $orient;
        $restored = self::canRestoreBase($total, $restoreOrient) && self::restoreBaseCopy($backupPath, $writeAbs);
        $ok = $restored;
        /* 16 位 PNG 走 Imagick 专用通道（GD 会截成 8 位）；索引色不走，实测 IM 会丢 tRNS */
        $pngIs16   = ('png' === $ext && isset($ph['depth']) && 16 === (int) $ph['depth']);
        $png16Kept = false;
        /* 已逐字节还原，副本与成品相同可删；另存为不能删——原附件还在用它 */
        if ($restored && !$saveas) {
            @unlink($backupPath);
        }
        /* 按实际执行结果上报通道：jpegtran 失败会静默退 GD，只看「装没装」会误报无损 */
        $chan = $restored ? 'restore' : '';
        $jt = in_array($ext, array('jpg', 'jpeg'), true) ? self::jpegtranPath() : '';
        if (!$ok && '' !== $jt) {
            $ok = self::jpegtranRotate($jt, $backupPath, $writeAbs, $orient, $total);
            if ($ok) { $chan = 'jpegtran'; }
        }
        if (!$ok) {
            /* 兜底链路：Imagick（jpg 原质量档＋原色度抽样 / webp 按 bpp 定档，无损源则 webp:lossless）→ GD 同档 → 报错 */
            /* 无损 WebP 更吃内存（libwebp 内部多份缓冲），系数放宽，抬不动就报可读错误 */
            /* 16 位 PNG 按 8 字节/像素算（Q16 每通道 2 字节 × 4 通道），系数翻倍 */
            $memFactor = ('png' === $ext && $pngIs16) ? 7.0
                : (('webp' === $ext && !empty($wh['lossless'])) ? 4.8 : 3.4);
            $this->assertImageMemory($imgSrc, $memFactor);
            /* 16 位 PNG 抢在通用分支前处理：后者只认 jpg/webp，png 会落到 GD 失精度 */
            if ($pngIs16) {
                $png16Kept = self::imagickPng16Save($imgSrc, $writeAbs, $orient, $total);
                if ($png16Kept) {
                    $chan = 'imagick';
                    $ok   = true;
                }
            }
            if (!$ok && ('webp' === $ext || in_array($ext, array('jpg', 'jpeg'), true))) {
                $ok = self::imagickRotateSave(
                    $imgSrc,
                    $writeAbs,
                    'webp' === $ext ? 'webp' : 'jpg',
                    $orient,
                    $total,
                    'webp' === $ext && !empty($wh['lossless']),
                    $baseBytes,
                    $srcInfo
                );
                if ($ok) { $chan = 'imagick'; }
            }
            if (!$ok) {
                $ok = self::gdRotateSave($imgSrc, $writeAbs, $ext, $orient, $total, $srcInfo, $baseBytes, $imgSrcExt);
                if ($ok) { $chan = 'gd'; }
            }
        }
        if (!$ok) {
            $hint = ('webp' === $ext && !self::gdWebpOk() && !self::imagickWebpOk())
                ? '保存失败：当前服务器既没有可写 WebP 的 Imagick，也没有带 WebP 支持的 GD 扩展'
                : '保存失败：旋转输出出错（请检查 GD 扩展与目录写权限）';
            $this->json(500, $hint);
        }
        /* 同目录 rename 原子替换：要么整份换上去，要么原文件一字节未动 */
        clearstatcache(true, $writeAbs);
        if (!is_file($writeAbs) || (int) @filesize($writeAbs) <= 0) {
            if (is_file($writeAbs)) { @unlink($writeAbs); }
            $this->json(500, '旋转输出为空，已取消保存，原图未改动');
        }
        if (!@rename($writeAbs, $newAbs)) {
            /* rename 失败（Windows 上文件被占用）退为复制，至少不让这次保存白做 */
            $cv = @copy($writeAbs, $newAbs);
            @unlink($writeAbs);
            if (!$cv) {
                $this->json(500, '写入旋转结果失败（目录不可写或文件被占用），原图未改动');
            }
        }
        /* 无损模式是否真生效：落盘后回读容器头，老版 IM 会静默退成有损 */
        $webpLosslessOut = false;
        if ('webp' === $ext && 'imagick' === $chan) {
            $outHead = self::webpHeader($newAbs);
            $webpLosslessOut = !empty($outHead['lossless']);
        }
        /* 无损上报口径：png 仅 16 位走通 Imagick 才算；jpg 只认 jpegtran；webp 只认 VP8L + 无损重编成功 */
        $pngDepthLoss = $pngIs16 && !$png16Kept;
        $lossless = $restored || 'jpegtran' === $chan
            || ('png' === $ext && in_array($chan, array('gd', 'imagick'), true) && !$pngDepthLoss)
            || $webpLosslessOut;
        $chanLabel = '无损旋转';
        if (!$lossless) {
            /* 16 位降 8 位是精度损失而非压缩损失，单独起标签，别让人以为被压糊了 */
            if ($pngDepthLoss) {
                $chanLabel = '精度有损';
            } else {
                $chanLabel = ('imagick' === $chan && 'webp' !== $ext) ? '近乎无损' : '有损旋转';
            }
        }
        @chmod($newAbs, 0644);

        /* 自校验：90/270 必须宽高互换，否则回滚——通道可能「报成功但没真的转」 */
        if (!$restored && 90 === ($total % 180)) {
            clearstatcache();
            $bSz = @getimagesize($backupPath);
            $oSz = @getimagesize($newAbs);
            if (is_array($bSz) && is_array($oSz)
                && (int) $bSz[0] > 0 && (int) $bSz[1] > 0 && (int) $bSz[0] !== (int) $bSz[1]  /* 正方形宽高互换后相等，不适用 */
                && (int) $bSz[0] === (int) $oSz[0] && (int) $bSz[1] === (int) $oSz[1]) {
                if ($newAbs !== $abs && is_file($newAbs)) {
                    @unlink($newAbs); /* 这份没转过的产物别留在目录里误导人 */
                } elseif (is_file($backupPath) && filesize($backupPath) > 0) {
                    /* 原地覆盖失败要用母版还原字节，同样走临时文件 + rename */
                    $rb = $abs . '.fmrot.rb';
                    if (self::restoreBaseCopy($backupPath, $rb)) {
                        if (!@rename($rb, $abs)) { @copy($rb, $abs); @unlink($rb); }
                    }
                }
                $this->json(500, '旋转没有生效：输出尺寸与基准完全相同（' . (int) $oSz[0] . '×' . (int) $oSz[1] . '），'
                    . '说明本次走的 ' . $chan . ' 通道没有真正执行旋转。已回滚，未改动原图');
            }
        }

        if (!$saveas && $newAbs !== $abs && is_file($abs)) {
            @unlink($abs);
        }

        clearstatcache(true, $newAbs);

        self::clearThumbFiles($cid);
        $newSize = (int) @filesize($newAbs);
        /* mtime+size 作缓存版本号：原地覆盖时 URL 不变，只能靠它让浏览器/CDN 重新取图 */
        $newMtime = (int) @filemtime($newAbs);
        /* 同秒同体积时版本号不会变（灯箱仍显示旧图），把 mtime 顶开 1 秒 */
        if ($inPlace && is_array($preStat)
            && (int) $preStat['size'] === $newSize && (int) $preStat['mtime'] === $newMtime) {
            @touch($newAbs, $newMtime + 1);
            clearstatcache(true, $newAbs);
            $newMtime = (int) @filemtime($newAbs);
        }

        if ($saveas) {
            $user = \Widget\User::alloc();
            $options = \Widget\Options::alloc();
            $now = time();
            $newInfo = array(
                'name' => $newBase . '.' . $ext,
                'path' => $newPath,
                'size' => $newSize,
                'type' => $ext,
                'mime' => isset($raw['mime']) ? (string) $raw['mime'] : '',
                /* 另存出来的新附件以自身体积为基线（它就是一份成品，没有更早的母本可循） */
                'fm_rot' => array('angle' => 0, 'base' => $newBase, 'orient' => 1, 'origBytes' => $newSize),
            );
            $newCid = (int) $this->db()->query($this->db()->insert('table.contents')->rows(array(
                'title'        => $newInfo['name'],
                'slug'         => self::uniqueSlug((string) $newInfo['name']),
                'created'      => $now,
                'modified'     => $now,
                'text'         => self::attachmentText($newInfo),
                'authorId'     => (int) $user->uid,
                'type'         => 'attachment',
                'status'       => 'publish',
                'password'     => '',
                'commentsNum'  => 0,
                'allowComment' => (string) $options->defaultAllowComment,
                'allowPing'    => (string) $options->defaultAllowPing,
                'allowFeed'    => (string) $options->defaultAllowFeed,
                'parent'       => 0,
            )));
            if ($newCid <= 0) {
                $this->json(500, '另存失败：附件记录创建出错');
            }
            $origMap = $this->db()->fetchRow($this->db()->select('fid')->from('table.filemanager_map')
                ->where('cid = ?', $cid)->limit(1));
            $this->db()->query($this->db()->insert('table.filemanager_map')->rows(array(
                'cid'  => $newCid,
                'fid'  => $origMap ? (int) $origMap['fid'] : 0,
                'size' => $newSize,
                'ext'  => $ext,
            )));
            if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
                $this->json(500, '无法创建基准副本目录');
            }
            /* 新附件自身即基准，不再建副本，另存后 .fmcache 不会多占一份原图 */
            @file_put_contents($backupDir . '/.htaccess', "Require all denied\n");

            $this->json(200, '', array(
                'cid'    => $newCid,
                'size'   => $newSize,
                'mtime'  => $newMtime,
                'angle'  => 0,      /* 新附件自身即基准，累计角度从 0 起算 */
                'lossless' => $lossless,
                'channel'  => $chan,
                'label'    => $chanLabel,
                'refs'   => 0,
                'srcSize' => $srcBytes,
                'enc'    => self::$lastEnc,   /* 实际编码参数，前端 console 直接打印 */
                'name'   => $newInfo['name'],
                'path'   => $newPath,
                /* 与 doList 的 displayAttachmentUrl 同路径拼接，否则开图床时同一张图会有两个 URL */
                'url'    => self::displayAttachmentUrl($newInfo, \Widget\Options::alloc()->rootUrl),
            ));
        }

        $raw['path'] = $newPath;
        $raw['name'] = $newBase . '.' . $ext;
        $raw['size'] = $newSize;
        /* 保留母版：每轮从同一份原始字节出发只编码一次，画质恒定；angle 记累计值、orient 记原值 */
        $raw['fm_rot'] = array(
            'angle' => (int) $total,
            'base' => $base,
            'orient' => (int) $orient,
            'origBytes' => $baseBytes,
            'ts' => time(),
        );
        $this->db()->query($this->db()->update('table.contents')
            ->rows(array('text' => self::attachmentText($raw)))
            ->where('cid = ?', $cid));
        $this->db()->query($this->db()->update('table.filemanager_map')
            ->rows(array('size' => $newSize))
            ->where('cid = ?', $cid));

        /* 清掉同 cid 其它扩展名的旧副本（格式转换后留下的，永远对不上） */
        foreach ((array) glob($backupDir . '/rot-' . $cid . '.*') as $stale) {
            if (is_file($stale) && $stale !== $backupPath) {
                @unlink($stale);
            }
        }
        /* 本次母版不删（见 fm_rot 说明）；归零还原时已在上面删过 */

        $refCount = 0;
        if ($rename && $doRefs && $newPath !== (string) $info['path']) {
            $refCount = $this->renameAttachmentRefs((string) $info['path'], $newPath);
        }

        $this->json(200, '', array(
            'cid'    => $cid,
            'size'   => $newSize,
            'mtime'  => $newMtime,
            'angle'  => (int) $total,   /* 累计角度（母版方案下不归零），接口兼容用 */
            'baseKept' => (!$saveas && !$restored && is_file($backupPath)) ? 1 : 0,
            'srcOrig'  => $useOrig ? 1 : 0,   /* 1=从原始 jpg 重算（画质更高），0=从当前成品 */
            'lossless' => $lossless,
            'channel'  => $chan,
            'label'    => $chanLabel,
            'refs'   => $refCount,
            'srcSize' => $srcBytes,
            'baseSize' => $baseBytes,
            'enc'    => self::$lastEnc,   /* 质量基线体积（体积优先模式的锚点） */
            'name'   => $raw['name'],
            'path'   => $newPath,
            'url'    => self::displayAttachmentUrl($raw, \Widget\Options::alloc()->rootUrl),
        ));
    }

    /** 保存前的无损能力探测（只读）：通道顺序与 doImageRotateSave 一致 */
    private function doRotProbe()
    {
        $cid = (int) $this->request->get('cid');
        $db = $this->db();
        $row = $db->fetchRow($db->select('cid', 'text')->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
        if (!$row) {
            $this->json(404, '文件不存在或已被删除');
        }
        $info = self::parseAttachmentText((string) $row['text']);
        $ext = strtolower((string) pathinfo($info['path'], PATHINFO_EXTENSION));

        $execOn = function_exists('exec');
        $jt = $execOn ? self::jpegtranPath() : '';
        $ver = '' !== $jt ? self::jpegtranVersion($jt) : '';
        $hasImagick = class_exists('Imagick');
        /* 分开记「扩展在不在」与「能不能写 WebP」：前者决定文案，后者决定通道 */
        $imgAny = $hasImagick;
        $hasGd = function_exists('imagecreatetruecolor');

        /* 能力必须按格式探测，否则会出现「弹窗说能存、点下去 500」 */
        $abs = self::resolveAttachmentFile($info['path']);
        $hasFile = (null !== $abs && is_file($abs));
        $meta = array();
        if ('webp' === $ext) {
            $hasImagick = $hasImagick && self::imagickWebpOk();
            $meta['imgNoWebp'] = ($imgAny && !$hasImagick);
            $hasGd = self::gdWebpOk();
            if ($hasFile) {
                $h = self::webpHeader($abs);
                $meta = array_merge($meta, $h);
            }
        }
        /* png 判定依赖位深/动图/索引色三个字段，缺一个只能给出错误的「无损」 */
        if ('png' === $ext && $hasFile) {
            $meta = array_merge($meta, self::pngHeader($abs));
        }
        /* ICC 单独判一次：只有落 GD 会丢，不能混进「无损」那句话里 */
        if ($hasFile && in_array($ext, array('jpg', 'jpeg', 'png'), true)) {
            $meta['icc'] = self::hasIccProfile($abs, $ext) ? 1 : 0;
        }

        $r = self::rotChannel($ext, $jt, $execOn, $hasImagick, $hasGd, $meta);
        /* 读源图编码参数，让用户先看到「现在是什么档」 */
        $srcInfo = $hasFile ? self::imageSourceInfo($abs, $ext) : array();

        /* 画质读数：WebP 按 bpp 估算等效质量档，并给出本次确定性档位（非估算） */
        $srcKind = isset($srcInfo['kind']) ? (string) $srcInfo['kind'] : '';
        $lossyWebp = ('webp' === $ext && 'webp-lossy' === $srcKind);
        $estQ = $lossyWebp ? self::webpBppToQuality((float) (isset($srcInfo['bpp']) ? $srcInfo['bpp'] : 0)) : 0;
        /* nextQ 必须与保存同口径（用基线体积而非当前体积），否则弹窗与实际档位不一致 */
        /* 母版已在＝从最初原图重算；不在＝本次新建母版，两种说法不同 */
        $rotRec = null;
        $basePath = __DIR__ . '/.fmcache/rot-' . $cid . '.' . $ext;
        $hasBase = ('' !== $ext && is_file($basePath) && (int) @filesize($basePath) > 0);

        /* 是否改用原始 jpg 当源：与保存路径共用同一判定，避免两处口径分叉 */
        $srcOrig = 0;
        if ($hasFile) {
            $infoP = self::parseAttachmentText((string) $row['text']);
            $pPath = (string) $infoP['path'];
            $pDir = (string) pathinfo($pPath, PATHINFO_DIRNAME);
            $pBase = (string) pathinfo($pPath, PATHINFO_FILENAME);
            $rawP = self::decodeAttachmentRaw((string) $row['text']);
            if (is_array($rawP) && !empty($rawP['fm_rot']['base'])) {
                $pBase = (string) $rawP['fm_rot']['base'];
            }
            $pRel = (('.' === $pDir || '' === $pDir) ? '' : $pDir . '/') . $pBase . '.' . $ext;
            $srcOrig = '' !== self::origQualitySource($pRel, $abs, $ext) ? 1 : 0;
        }

        $nextInfo = $srcInfo;
        if ($lossyWebp && !empty($srcInfo['w']) && !empty($srcInfo['h'])) {
            $nxPx = (int) $srcInfo['w'] * (int) $srcInfo['h'];
            $curBytes = isset($srcInfo['bytes']) ? (int) $srcInfo['bytes'] : 0;
            $rawAll = self::decodeAttachmentRaw((string) $row['text']);
            $rotRec = isset($rawAll['fm_rot']) && is_array($rawAll['fm_rot']) ? $rawAll['fm_rot'] : null;
            $baseBytesN = (is_array($rotRec) && isset($rotRec['origBytes'])) ? (int) $rotRec['origBytes'] : 0;
            if ($baseBytesN <= 0 || ($curBytes > 0 && ($baseBytesN > $curBytes * 6 || $baseBytesN < $curBytes / 8))) {
                $baseBytesN = $curBytes;
            }
            if ($nxPx > 0 && $baseBytesN > 0) {
                $nextInfo['bpp'] = round($baseBytesN * 8 / $nxPx, 3);
            }
        }
        $nextQ = ($lossyWebp && in_array($r['channel'], array('imagick', 'gd'), true))
            ? self::lossySourceQuality($nextInfo, 'webp') : 0;

        $this->json(200, '', array(
            'ext'      => $ext,
            'channel'  => $r['channel'],
            'lossless' => $r['lossless'],
            'level'    => $r['level'],
            'label'    => $r['label'],
            'tip'      => $r['tip'],
            'fix'      => $r['fix'],
            'chain'    => $r['chain'],
            /* 非空＝本次会丢 ICC（仅 GD 通道），前端单独一行渲染 */
            'icc'      => isset($r['icc']) ? $r['icc'] : '',
            'srcInfo'  => $srcInfo,
            'hasBase'  => $hasBase ? 1 : 0,   /* 1=已有旋转原图基准，本次从原图重算，画质不再累积 */
            'srcOrig'  => $srcOrig,           /* 1=这次从原始 jpg 重算（损失只发生一次），0=从当前 webp 成品 */
            'estQ'     => $estQ,     /* 按 bpp 估算的等效质量档（仅 WebP 有损，0=不适用） */
            'nextQ'    => $nextQ,    /* 本次保存实际会用的档位（确定性，非估算） */
            'exec'     => $execOn,
            'jpegtran' => $jt,
            'imagickAny' => $imgAny,   /* 扩展在不在（与「能不能写 WebP」分开，排查用） */
            'imgNoWebp'  => !empty($meta['imgNoWebp']),
            'version'  => $ver,
            'imagick'  => $hasImagick,
            'gd'       => $hasGd,
        ));
    }

    /** 通道判定（纯函数）：jpg → jpegtran/Imagick/GD，webp → Imagick/GD，png → GD */
    private static function rotChannel(string $ext, string $jt, bool $execOn, bool $hasImagick, bool $hasGd, array $meta = array()): array
    {
        /* $meta 只对 webp 有意义：lossless=VP8L 源，anim=动图；默认按有损静态处理 */
        /* 兜底顺序与 doImageRotateSave 严格一致，避免「弹窗说的」与「实际做的」分叉 */
        $chainJpg = self::rotChain(array(
            array('jpegtran', 'jpegtran', '' !== $jt),
            array('imagick', 'Imagick', $hasImagick),
            array('gd', 'GD', $hasGd),
        ));
        $chainWebp = self::rotChain(array(
            array('imagick', 'Imagick', $hasImagick),
            array('gd', 'GD', $hasGd),
        ));
        /* 只有 GD 会丢 ICC（libgd 不读写色彩配置），所以这句只挂 GD 分支 */
        $iccNote = !empty($meta['icc'])
            ? '源图带 ICC 色彩配置文件，本次走 GD 通道会把它丢掉——像素与亮度不变，'
                . '但在 Photoshop 或支持色彩管理的浏览器里可能偏色。需要保留的话请先把原图另存一份备份。'
            : '';

        if (in_array($ext, array('jpg', 'jpeg'), true)) {
            if ('' !== $jt) {
                /* 已经是真·无损，没有「降级到哪」的话题，兜底链一律不下发（前端见空数组就不渲染） */
                return self::rotCase('jpegtran', true, 'ok', '无损旋转',
                    '直接重排原始压缩数据，不重新编码，画质零损失', '', array());
            }
            /* 无 jpegtran 时 Imagick 沿用原质量档与抽样是最好结果；曾写 q100+4:4:4，只涨体积 */
            $adviceNear = $execOn
                ? '如想要彻底无损旋转，请在服务器安装jpegtran'
                : '如想要彻底无损旋转，请先在宝塔「禁用函数」中移除exec';
            if ($hasImagick) {
                return self::rotCase('imagick', false, 'near', '近乎无损',
                    '未检测到jpegtran无损变换工具，改用Imagick图像引擎处理，按原图质量与色度抽样重新编码，'
                    . '体积基本不变，只剩一次DCT往返取整误差，' . $adviceNear,
                    '', $chainJpg);
            }
            /* 有损是最后一道兜底：两条出路都要给——装 jpegtran 是最优解，装 Imagick 也能大幅改善 */
            $adviceGd = $execOn
                ? '如想要彻底无损旋转，请在服务器安装jpegtran或PHP安装Imagick扩展，无损旋转JPG图片推荐jpegtran'
                : '如想要彻底无损旋转，请先在宝塔「禁用函数」中移除exec或PHP安装Imagick扩展';
            if ($hasGd) {
                return self::rotCase('gd', false, 'warn', '有损旋转',
                    '未检测到jpegtran也没有Imagick扩展，本次会用GD按原图质量重新编码，体积基本不变，'
                    . '但色度被4:2:0抽样，画质仍有可见损失，' . $adviceGd,
                    '', $chainJpg, $iccNote);
            }
            return self::rotCase('none', false, 'bad', '无法保存',
                '既没有 jpegtran 也没有 Imagick / GD 扩展，无法保存旋转', '', $chainJpg);
        }

        if ('webp' === $ext) {
            /* 动图一旦进解码器就只剩第一帧，保存出去等于砍掉动画，宁可不给存 */
            if (!empty($meta['anim'])) {
                return self::rotCase('none', false, 'bad', '无法保存',
                    '这是动图 WebP，旋转保存会把它压成静态单帧，因此不支持。需要旋转请在电脑上用专业工具处理',
                    '', array());
            }
            $srcLossless = !empty($meta['lossless']);
            /* 只讲「有损 WebP 无法无损旋转」，不提 jpegtran；首选通道正常时不列兜底顺序 */
            if ($srcLossless && $hasImagick) {
                return self::rotCase('imagick', true, 'ok', '无损旋转',
                    '源文件本身是 WebP 无损编码（VP8L），旋转后用无损模式重新编码，像素完全不变，只有体积会小幅波动',
                    '', array());
            }
            if ($hasImagick) {
                return self::rotCase('imagick', false, 'warn', '有损旋转',
                    '这是有损压缩的 WebP，码流不能直接无损旋转，只能先解码再重新编码，'
                    . '本次按当前画质档重编码一次，体积在正常范围内小幅浮动',
                    '', $chainWebp);
            }
            if ($hasGd) {
                /* 区分「没装 Imagick」与「装了但底层没编 WebP」，两者修法不同 */
                $imgNoWebp = !empty($meta['imgNoWebp']);
                $why = $imgNoWebp
                    ? 'Imagick 扩展已安装，但底层的 ImageMagick 没有编译 WebP 支持，只能退到 GD 通道'
                    : '未检测到 Imagick 扩展，已退到兜底通道 GD';
                $fix = $imgNoWebp
                    ? '安装 libwebp 依赖后重装 ImageMagick，用 convert -list format 确认出现 WEBP'
                    : '在 PHP 中安装 Imagick 扩展后可改走 Imagick 通道';
                /* 无损源只能落 GD 有损重编，必须当场说清，否则用户以为「无损进无损出」 */
                $keep = $srcLossless
                    ? '；但源文件本身是 WebP 无损编码，本次无法保持无损'
                    : '';
                return self::rotCase('gd', false, 'warn', '有损旋转',
                    $why . '，本次按当前画质档重编码一次，体积在正常范围内小幅浮动' . $keep,
                    $fix, $chainWebp);
            }
            return self::rotCase('none', false, 'bad', '无法保存',
                '当前服务器既没有可写 WebP 的 Imagick，也没有带 WebP 支持的 GD 扩展，无法保存旋转', '', $chainWebp);
        }

        /* 探针会被 gif/svg/bmp 调用，非 jpg/png/webp 显式拒绝，别让 png 隐式兜底 */
        if ('png' !== $ext) {
            return self::rotCase('none', false, 'bad', '无法保存',
                '该格式不支持旋转保存（仅限 jpg/png/webp）', '');
        }

        /* png 无损有三个前提：8 位（或 16 位且有 Imagick）、非动图、索引色会展开成真彩 */
        if (!empty($meta['anim'])) {
            return self::rotCase('none', false, 'bad', '无法保存',
                '这是动图 PNG（APNG），扩展名仍是 .png，浏览器里会自己播放。'
                . '旋转保存只能输出静态单帧（只剩第一帧），动画会永久丢失，因此不支持。'
                . '需要旋转请用支持 APNG 的专业工具处理',
                '');
        }
        if (16 === (int) (isset($meta['depth']) ? $meta['depth'] : 8)) {
            /* 16 位能否无损取决于服务器有无 Imagick，必须与保存路径同口径 */
            $imPng = false;
            if (class_exists('Imagick')) {
                try { $imPng = (bool) \Imagick::queryFormats('PNG'); } catch (\Exception $e) { $imPng = false; }
            }
            if ($imPng) {
                return self::rotCase('png', true, 'ok', '无损旋转',
                    '这是 16 位色深 PNG，本服务器有 Imagick（Q16），旋转后仍按 16 位输出，'
                    . '像素与精度都不变；若写入时 Imagick 拒绝 16 位，会自动退回 GD 并按「精度有损」上报',
                    '');
            }
            return $hasGd
                ? self::rotCase('png', false, 'warn', '精度有损',
                    '这是 16 位色深 PNG，而本服务器的 GD 每通道只有 8 位，解码时会被截断成 8 位——'
                    . '像素位置和色值不会被重采样，但高精度色阶会丢。装并启用 imagick 扩展后即可保住 16 位',
                    '', array(), $iccNote)
                : self::rotCase('none', false, 'bad', '无法保存',
                    '缺少 GD 扩展，无法保存旋转（PNG 旋转由 GD 处理）', '');
        }
        if ($hasGd) {
            /* 索引色/灰度像素不变但会展开成真彩（实测 +80.8%）；这类图不能交 Imagick（丢 tRNS） */
            $expand = !empty($meta['palette'])
                ? '；这是索引色 PNG，旋转后会展开成真彩色，像素不变但文件通常会变大（实测 256×256 约 +80%）'
                : '';
            /* 曾经跟着「（与 jpegtran 无关）」：旋转 PNG 却看到 JPG 工具名是噪音，已删 */
            return self::rotCase('png', true, 'ok', '无损旋转',
                'PNG 本身是无损压缩，旋转后像素不变' . $expand, '',
                array(), $iccNote);
        }
        return self::rotCase('none', false, 'bad', '无法保存',
            '缺少 GD 扩展，无法保存旋转（PNG 旋转由 GD 处理）', '');
    }

    /** rotChannel() 的返回结构，统一出口避免各分支字段不齐 */
    /** 组装兜底链：每环标注是否可用，供前端高亮/置灰 */
    private static function rotChain(array $items): array
    {
        $out = array();
        foreach ($items as $it) {
            $out[] = array(
                'key'   => (string) $it[0],
                'label' => (string) $it[1],
                'ok'    => (bool) $it[2],
            );
        }
        return $out;
    }

    private static function rotCase(string $channel, bool $lossless, string $level, string $label, string $tip, string $fix, array $chain = array(), string $icc = ''): array
    {
        return array(
            'channel'    => $channel,
            'lossless'   => $lossless,
            'level'      => $level,   /* ok | near | warn | bad，前端据此上色 */
            'label'      => $label,   /* 无损旋转 | 近乎无损 | 有损旋转 | 无法保存 */
            'tip'        => $tip,     /* 一句话说明这次会怎么编码、掉不掉画质 */
            'fix'        => $fix,
            'chain'      => $chain,   /* 兜底顺序，空数组表示不展示（png 无需降级链） */
            /* ICC 是否会丢：只在确定会丢的通道填，前端空串不渲染 */
            'icc'        => $icc,
        );
    }

    /** 源图是否带 ICC（png 看 iCCP，jpg 看 APP2/ICC_PROFILE）；GD 重编必丢色彩配置 */
    private static function hasIccProfile(string $abs, string $ext): bool
    {
        if (!is_file($abs)) { return false; }
        $ext = strtolower($ext);
        if ('png' === $ext) {
            $ph = self::pngHeader($abs);
            return in_array('iCCP', (array) $ph['chunks'], true);
        }
        if ('jpg' === $ext || 'jpeg' === $ext) {
            return self::jpegHasIcc($abs);
        }
        return false;
    }

    /** 扫 JPEG 段结构找 APP2/ICC_PROFILE。只读到 SOS 为止，不碰像素数据 */
    private static function jpegHasIcc(string $file): bool
    {
        $fp = @fopen($file, 'rb');
        if (!$fp) { return false; }
        $has = false;
        @fread($fp, 2);   /* SOI */
        /* 段落不会太多，给个上限防畸形文件把循环拖死 */
        for ($i = 0; $i < 256; $i++) {
            $m = @fread($fp, 2);
            if (2 !== strlen($m) || "\xFF" !== $m[0]) { break; }
            $mk = ord($m[1]);
            /* 无长度的标记：TEM(01)、RST0-7(D0-D7)、SOI(D8) —— 直接读下一个 */
            if (0x01 === $mk || 0xD8 === $mk || (0xD0 <= $mk && $mk <= 0xD7)) { continue; }
            if (0xDA === $mk) { break; }   /* SOS 之后是压缩数据，再扫没有意义 */
            $lb = @fread($fp, 2);
            if (2 !== strlen($lb)) { break; }
            $u = unpack('n', $lb);
            $len = (int) $u[1];
            if ($len < 2) { break; }
            $body = $len - 2;
            if (0xE2 === $mk) {
                $head = (string) @fread($fp, min(12, $body));
                if (0 === strpos($head, "ICC_PROFILE\x00")) { $has = true; break; }
                $rest = $body - strlen($head);
                if ($rest > 0) { @fseek($fp, $rest, SEEK_CUR); }
            } else {
                @fseek($fp, $body, SEEK_CUR);
            }
        }
        @fclose($fp);
        return $has;
    }

    /** 取 jpegtran 版本号；-v 之后必须接住 stdin，否则它会一直挂着 */
    private static function jpegtranVersion(string $jt): string
    {
        if (!function_exists('exec')) {
            return '';
        }
        $nullIn = (0 === stripos(PHP_OS, 'WIN')) ? 'NUL' : '/dev/null';
        $o = array();
        $rc = 1;
        @exec(escapeshellarg($jt) . ' -v < ' . $nullIn . ' 2>&1', $o, $rc);
        $s = trim(implode(' ', $o));
        if ('' === $s) {
            return '';
        }
        return preg_match('/version\s+([0-9][0-9A-Za-z.+-]*)/i', $s, $m) ? $m[1] : '';
    }

    /** 找可用作画质源的原始 jpg（输出 webp、备份存在、尺寸一致），纯查询不改状态 */
    private static function origQualitySource(string $relPath, string $abs, string $ext): string
    {
        if ('webp' !== $ext || !class_exists('FileManager_ImageTool') || !is_file($abs)) {
            return '';
        }
        $sizeCur = @getimagesize($abs);
        if (!is_array($sizeCur)) { return ''; }
        foreach ((array) FileManager_ImageTool::backupOriginalCandidates($relPath) as $cand) {
            $ce = strtolower((string) pathinfo($cand, PATHINFO_EXTENSION));
            if (!in_array($ce, array('jpg', 'jpeg', 'jpe'), true) || !is_file($cand)) { continue; }
            $sizeOrig = @getimagesize($cand);
            if (!is_array($sizeOrig)
                || (int) $sizeOrig[0] !== (int) $sizeCur[0]
                || (int) $sizeOrig[1] !== (int) $sizeCur[1]) {
                continue;
            }
            return $cand;
        }
        return '';
    }

    /** 0° 且无需烘焙时成品等于母版，可直接复制字节；启用与否看 RESTORE_ON_ZERO */
    private static function canRestoreBase(int $total, int $orient): bool
    {
        return self::RESTORE_ON_ZERO && 0 === $total % 360 && 1 === $orient;
    }

    /** 读 WebP RIFF 头判码流：VP8 有损 / VP8L 无损 / VP8X 外壳（含动图标志），只读 64 字节 */
    private static function webpHeader(string $file): array
    {
        $out = array('webp' => false, 'lossless' => false, 'anim' => false, 'w' => 0, 'h' => 0, 'alpha' => false);
        $fp = @fopen($file, 'rb');
        if (!$fp) {
            return $out;
        }
        $head = (string) @fread($fp, 64);
        @fclose($fp);
        if (strlen($head) < 16 || 'RIFF' !== substr($head, 0, 4) || 'WEBP' !== substr($head, 8, 4)) {
            return $out;
        }
        $out['webp'] = true;
        $cc = substr($head, 12, 4);
        /* 顺手读画布尺寸：WebP 无质量字段，像素+体积算出的 bpp 是唯一可比量 */
        if ('VP8L' === $cc) {
            $out['lossless'] = true;
            if (strlen($head) >= 25 && "\x2f" === $head[20]) {   /* 0x2f = VP8L 签名 */
                $b = ord($head[21]) | (ord($head[22]) << 8) | (ord($head[23]) << 16) | (ord($head[24]) << 24);
                $out['w'] = ($b & 0x3FFF) + 1;
                $out['h'] = (($b >> 14) & 0x3FFF) + 1;
                $out['alpha'] = 1 === (($b >> 28) & 1);
            }
            return $out;
        }
        if ('VP8X' === $cc) {
            if (strlen($head) >= 21) {
                $out['anim'] = 0 !== (ord($head[20]) & 0x02);
                $out['alpha'] = 0 !== (ord($head[20]) & 0x10);
            }
            /* 画布宽高紧跟 flags：flags(1) + 保留(3) + 宽-1(3) + 高-1(3) */
            if (strlen($head) >= 30) {
                $out['w'] = (ord($head[24]) | (ord($head[25]) << 8) | (ord($head[26]) << 16)) + 1;
                $out['h'] = (ord($head[27]) | (ord($head[28]) << 8) | (ord($head[29]) << 16)) + 1;
            }
            /* VP8X 载荷 10 字节，子块从 30 开始；VP8L=无损，'VP8 '=有损 */
            if (false !== strpos(substr($head, 30), 'VP8L')) {
                $out['lossless'] = true;
            }
            return $out;
        }
        /* 'VP8 '：关键帧头里 3 字节 frame tag + 3 字节起始码，之后是 14bit 宽 + 14bit 高 */
        if ('VP8 ' === $cc && strlen($head) >= 30) {
            $tag = ord($head[20]) | (ord($head[21]) << 8) | (ord($head[22]) << 16);
            if (0 === ($tag & 1) && "\x9d\x01\x2a" === substr($head, 23, 3)) {
                $out['w'] = (ord($head[26]) | (ord($head[27]) << 8)) & 0x3FFF;
                $out['h'] = (ord($head[28]) | (ord($head[29]) << 8)) & 0x3FFF;
            }
        }
        return $out;
    }

    /** 读 PNG IHDR 与 IDAT 前辅助块：位深、色彩类型、acTL 动图、tRNS；GD 对 16 位解码有损 */
    private static function pngHeader(string $file): array
    {
        $out = array('png' => false, 'depth' => 8, 'colorType' => 6, 'interlace' => 0,
            'palette' => false, 'alpha' => false, 'trns' => false, 'anim' => false,
            'w' => 0, 'h' => 0, 'chunks' => array());
        $fp = @fopen($file, 'rb');
        if (!$fp) {
            return $out;
        }
        $sig = (string) @fread($fp, 8);
        if ("\x89PNG\r\n\x1a\n" !== $sig) {
            @fclose($fp);
            return $out;
        }
        $ihdr = (string) @fread($fp, 25);
        if (strlen($ihdr) < 25 || 'IHDR' !== substr($ihdr, 4, 4)) {
            @fclose($fp);
            return $out;
        }
        $out['png'] = true;
        $out['w'] = (ord($ihdr[8]) << 24) | (ord($ihdr[9]) << 16) | (ord($ihdr[10]) << 8) | ord($ihdr[11]);
        $out['h'] = (ord($ihdr[12]) << 24) | (ord($ihdr[13]) << 16) | (ord($ihdr[14]) << 8) | ord($ihdr[15]);
        $out['depth'] = ord($ihdr[16]);
        $out['colorType'] = ord($ihdr[17]);
        $out['interlace'] = ord($ihdr[20]);
        $out['palette'] = (3 === $out['colorType']);
        $out['alpha'] = in_array($out['colorType'], array(4, 6), true);

        /* 只扫 IDAT 之前的块，32 步上限防损坏文件拖死循环 */
        $pos = 8;
        for ($i = 0; $i < 32; $i++) {
            if (0 !== @fseek($fp, $pos)) { break; }
            $ch = (string) @fread($fp, 8);
            if (strlen($ch) < 8) { break; }
            $lenArr = unpack('N', substr($ch, 0, 4));
            $len = (int) $lenArr[1];
            $type = substr($ch, 4, 4);
            if ('IDAT' === $type || 'IEND' === $type) { break; }
            /* 攒下辅助块类型：16 位通道要按「源里有什么」决定排除哪些 IM 自补块 */
            $out['chunks'][] = $type;
            if ('acTL' === $type) { $out['anim'] = true; }
            if ('tRNS' === $type) { $out['trns'] = true; $out['alpha'] = true; }
            if ($len < 0 || $len > 16777216) { break; }
            $pos += 12 + $len;   /* 长度(4) + 类型(4) + 数据(len) + CRC(4) */
        }
        @fclose($fp);
        return $out;
    }

    /** 解析源图编码参数（只读文件头到 SOS）：JPEG 反推质量与抽样，WebP/PNG 只报编码类型 */
    private static function imageSourceInfo(string $file, string $ext): array
    {
        $out = array(
            'kind' => 'unknown', 'quality' => 0, 'exact' => false,
            'sub' => '', 'progressive' => false, 'bytes' => 0,
            'w' => 0, 'h' => 0, 'bpp' => 0.0, 'alpha' => false,
        );
        $sz = @filesize($file);
        $out['bytes'] = $sz ? (int) $sz : 0;

        if ('webp' === $ext) {
            $h = self::webpHeader($file);
            $out['kind'] = !empty($h['anim']) ? 'webp-anim'
                : (!empty($h['lossless']) ? 'webp-lossless' : 'webp-lossy');
            $out['w'] = (int) $h['w'];
            $out['h'] = (int) $h['h'];
            $out['alpha'] = !empty($h['alpha']);
            return self::withBpp($out);
        }
        if ('png' === $ext) {
            /* 统一走 pngHeader()，避免两处各读一遍 IHDR 导致口径分叉 */
            $out['kind'] = 'png';
            $h = self::pngHeader($file);
            $out['w'] = (int) $h['w'];
            $out['h'] = (int) $h['h'];
            $out['alpha'] = !empty($h['alpha']);
            return self::withBpp($out);
        }
        if (!in_array($ext, array('jpg', 'jpeg'), true)) {
            return $out;
        }

        $fp = @fopen($file, 'rb');
        if (!$fp) {
            return $out;
        }
        if ("\xFF\xD8" !== (string) @fread($fp, 2)) {
            @fclose($fp);
            return $out;
        }
        $out['kind'] = 'jpeg';

        $luma = array();  /* 亮度量化表 64 项（zigzag 序），用于反推质量并核对是否标准表 */
        $yHv = 0;         /* Y 分量采样因子：高 4 位 = 水平，低 4 位 = 垂直 */
        $cHv = 0;         /* Cb 分量采样因子 */
        $ncomp = 0;
        $pos = 2;
        $guard = 0;
        while ($guard++ < 256) {
            if (0 !== @fseek($fp, $pos)) { break; }
            $mk = (string) @fread($fp, 2);
            if (strlen($mk) < 2 || "\xFF" !== $mk[0]) { break; } /* 段边界错乱就停，别硬猜 */
            $m = ord($mk[1]);
            if (0xD8 === $m || 0xD9 === $m || 0x01 === $m || (0xD0 <= $m && $m <= 0xD7)) {
                $pos += 2;                                        /* 无载荷的标记 */
                continue;
            }
            if (0xDA === $m) { break; }                           /* SOS 之后是压缩数据 */
            $lb = (string) @fread($fp, 2);
            if (strlen($lb) < 2) { break; }
            $len = (ord($lb[0]) << 8) | ord($lb[1]);
            if ($len < 2) { break; }
            $seg = (string) @fread($fp, $len - 2);

            if (0xDB === $m) {                   /* DQT：可能一次带色度表，逐张跳过 */
                $p = 0;
                $L = strlen($seg);
                while ($p < $L) {
                    $pq = ord($seg[$p]) >> 4;
                    $tq = ord($seg[$p]) & 0x0F;
                    $p++;
                    $need = 64 * (1 === $pq ? 2 : 1);
                    if ($p + $need > $L) { break; }
                    if (0 === $tq && !$luma) {   /* 只要亮度表；zigzag 第一项恒为 DC */
                        for ($k = 0; $k < 64; $k++) {
                            $luma[] = 1 === $pq
                                ? ((ord($seg[$p + $k * 2]) << 8) | ord($seg[$p + $k * 2 + 1]))
                                : ord($seg[$p + $k]);
                        }
                    }
                    $p += $need;
                }
            } elseif (0xC0 <= $m && $m <= 0xC3) { /* SOF0/1/2/3：精度、尺寸、分量、采样因子 */
                $out['progressive'] = (0xC2 === $m);
                if (strlen($seg) >= 5) {
                    $out['h'] = (ord($seg[1]) << 8) | ord($seg[2]);
                    $out['w'] = (ord($seg[3]) << 8) | ord($seg[4]);
                }
                if (strlen($seg) >= 6) {
                    $ncomp = ord($seg[5]);
                    for ($i = 0; $i < $ncomp; $i++) {
                        $base = 6 + $i * 3;
                        if ($base + 2 >= strlen($seg)) { break; }
                        $hv = ord($seg[$base + 1]);
                        if (0 === $i) { $yHv = $hv; } elseif (1 === $i) { $cHv = $hv; }
                    }
                }
            }
            $pos += 2 + $len;
        }
        @fclose($fp);

        if (1 === $ncomp) {
            $out['sub'] = '灰度';
        } elseif ($ncomp >= 3 && $yHv && $cHv) {
            $yh = $yHv >> 4; $yv = $yHv & 0x0F;
            $ch = $cHv >> 4; $cv = $cHv & 0x0F;
            if ($yh === $ch && $yv === $cv) {
                $out['sub'] = '4:4:4';
            } elseif (2 === $yh && 2 === $yv && 1 === $ch && 1 === $cv) {
                $out['sub'] = '4:2:0';
            } elseif (2 === $yh && 1 === $yv && 1 === $ch && 1 === $cv) {
                $out['sub'] = '4:2:2';
            } elseif (1 === $yh && 2 === $yv && 1 === $ch && 1 === $cv) {
                $out['sub'] = '4:4:0';
            } elseif (4 === $yh && 1 === $yv) {
                $out['sub'] = '4:1:1';
            } elseif (4 === $yh && 2 === $yv) {
                $out['sub'] = '4:1:0';
            } else {
                $out['sub'] = $yh . 'x' . $yv . '/' . $ch . 'x' . $cv;
            }
        }

        /* 质量因子：1..100 里找最接近标准量化表的一档，比 DC 单点反推稳定 */
        if (!empty($luma)) {
            $bestQ = 0;
            $bestDiff = PHP_INT_MAX;
            for ($q = 1; $q <= 100; $q++) {
                $d = self::jpegStdDiff($luma, $q);
                if ($d < $bestDiff) {
                    $bestDiff = $d;
                    $bestQ = $q;
                }
                if (0 === $d) {
                    break;
                }
            }
            $out['quality'] = max(1, min(100, $bestQ));
            /* 64 项平均差 <= 1 级才算「这张表就是 IJG 标准表缩放的」 */
            $out['exact'] = ($bestDiff / 64) <= 1.0;
        } else {
            /* 段都读完了还没有量化表：文件截断或结构异常，别硬报一个质量值 */
            $out['kind'] = 'unknown';
        }

        return self::withBpp($out);
    }

    /** 补 bpp（体积×8÷像素数）：唯一能跨格式比较压缩强度的量，不等于画质 */
    private static function withBpp(array $out): array
    {
        if ($out['w'] > 0 && $out['h'] > 0 && $out['bytes'] > 0) {
            $out['bpp'] = round($out['bytes'] * 8 / ($out['w'] * $out['h']), 2);
        }
        return $out;
    }

    /** 按质量档重建 IJG 亮度量化表，返回与文件表的 64 项绝对差之和 */
    private static function jpegStdDiff(array $tbl, int $q): int
    {
        static $std = array(                 /* IJG 标准亮度量化表（自然序） */
            16, 11, 10, 16, 24, 40, 51, 61,
            12, 12, 14, 19, 26, 58, 60, 55,
            14, 13, 16, 24, 40, 57, 69, 56,
            14, 17, 22, 29, 51, 87, 80, 62,
            18, 22, 37, 56, 68, 109, 103, 77,
            24, 35, 55, 64, 81, 104, 113, 92,
            49, 64, 78, 87, 103, 121, 120, 101,
            72, 92, 95, 98, 112, 100, 103, 99,
        );
        static $zig = array(                 /* zigzag 序 → 自然序下标 */
            0, 1, 8, 16, 9, 2, 3, 10, 17, 24, 32, 25, 18, 11, 4, 5,
            12, 19, 26, 33, 40, 48, 41, 34, 27, 20, 13, 6, 7, 14, 21, 28,
            35, 42, 49, 56, 57, 50, 43, 36, 29, 22, 15, 23, 30, 37, 44, 51,
            58, 59, 52, 45, 38, 31, 39, 46, 53, 60, 61, 54, 47, 55, 62, 63,
        );
        $scale = $q < 50 ? 5000 / max(1, $q) : 200 - 2 * $q;
        $diff = 0;
        for ($i = 0; $i < 64; $i++) {
            if (!isset($tbl[$i])) { continue; }
            $v = (int) floor(($std[$zig[$i]] * $scale + 50) / 100);
            $v = max(1, min(255, $v));
            $diff += abs($v - (int) $tbl[$i]);
        }
        return $diff;
    }

    /** GD 是否真的能读写 WebP：光有 imagecreatetruecolor 不代表编进了 libwebp */
    private static function gdWebpOk(): bool
    {
        return function_exists('imagecreatetruecolor')
            && function_exists('imagewebp')
            && function_exists('imagecreatefromwebp');
    }

    /** Imagick 是否带 WEBP 代理：装了扩展但没编 libwebp 的话，弹窗说能存、点了必 500 */
    private static function imagickWebpOk(): bool
    {
        if (!class_exists('Imagick')) {
            return false;
        }
        try {
            $f = \Imagick::queryFormats('WEBP');
            return is_array($f) && count($f) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 致命错误收尾：内存可能已耗尽，用最小拼接不走 json_encode */
    public static function rotFatalReport(): void
    {
        $e = error_get_last();
        $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        if (!is_array($e) || !in_array((int) $e['type'], $fatal, true) || headers_sent()) {
            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $msg = '保存旋转中断：' . (string) $e['message'];
        if (false !== stripos($msg, 'memory')) {
            $msg .= '。图片较大，请在 PHP 设置里提高 memory_limit 后重试';
        } elseif (false !== stripos($msg, 'execution time')) {
            $msg .= '。处理耗时超过 PHP 上限，请提高 max_execution_time，或先把图片缩小再旋转';
        }
        @header('Content-Type: application/json; charset=UTF-8');
        echo '{"code":500,"message":"'
            . str_replace(array('\\', '"', "\r", "\n"), array('\\\\', '\\"', ' ', ' '), $msg)
            . '"}';
    }

    /** 把基准副本原样复制到目标路径；成功返回 true（失败交由正常编码通道兜底）。 */
    private static function restoreBaseCopy(string $base, string $dst): bool
    {
        if (!is_file($base) || (int) @filesize($base) <= 0) {
            return false;
        }
        if (!@copy($base, $dst)) {
            return false;
        }
        clearstatcache(true, $dst);
        return (int) @filesize($dst) > 0;
    }

    private static function jpegtranPath(): string
    {
        static $cached = null;
        if (null !== $cached) { return $cached; }
        $cached = '';

        /* 1) 优先用 PATH 查找（主机禁用 exec 时这里的 function_exists 为 false，直接跳到第 2 步） */
        if (function_exists('exec')) {
            $out = array();
            $rc = 1;
            if (0 === stripos(PHP_OS, 'WIN')) {
                @exec('where jpegtran 2>NUL', $out, $rc);
            } else {
                @exec('command -v jpegtran 2>/dev/null', $out, $rc);
            }
            if (0 === $rc && !empty($out[0])) {
                $p = trim((string) $out[0]);
                /* command -v 返回路径即为可执行；不调 is_file，open_basedir 会把 /usr/bin 排除导致误判 */
                $isAbs = '' !== $p && ('/' === $p[0] || preg_match('/^[A-Za-z]:[\\\\\/]/', $p));
                if ($isAbs || @is_file($p)) { $cached = $p; return $cached; }
            }
        }

        /* 兜底找 jpegtran：常见安装路径，@ 静默继续试下一个 */
        $candidates = 0 === stripos(PHP_OS, 'WIN')
            ? array('C:/libjpeg-turbo/jpegtran.exe', 'C:/Program Files/libjpeg-turbo/jpegtran.exe')
            : array(
                '/usr/bin/jpegtran',
                '/usr/local/bin/jpegtran',
                '/usr/local/sbin/jpegtran',
                '/bin/jpegtran',
                '/opt/local/bin/jpegtran',
                '/opt/homebrew/bin/jpegtran',
                '/usr/lib/libjpeg-turbo-progs/jpegtran',
            );
        foreach ($candidates as $p) {
            if (@is_file($p)) { $cached = $p; break; }
        }
        return $cached;
    }

    private static function jpegtranRotate(string $jt, string $src, string $dst, int $orient, int $total): bool
    {
        $bake = array(
            2 => '-flip horizontal',
            3 => '-rotate 180',
            4 => '-flip vertical',
            5 => '-transpose',
            6 => '-rotate 90',
            7 => '-transverse',
            8 => '-rotate 270',
        );
        $steps = array();
        if (isset($bake[$orient])) { $steps[] = $bake[$orient]; }
        /* 传给 jpegtran 前角度必须归一化，否则 -rotate 450 直接失败并静默退到 GD */
        $deg = (($total % 360) + 360) % 360;
        if (90 === $deg || 180 === $deg || 270 === $deg) {
            $steps[] = '-rotate ' . $deg;
        } elseif (0 !== $deg) {
            return false; /* 非 90 的倍数：jpegtran 无法只重排 DCT，交给上层退到 Imagick/GD */
        }
        if (empty($steps)) {
            return @copy($src, $dst);
        }

        $tag = 'jt' . getmypid() . '_' . substr(bin2hex(random_bytes(3)), 0, 4);
        $tmp = $dst . '.' . $tag . '.jpg';
        $cur = $src;
        $last = count($steps) - 1;
        /* orient=1 才 -copy all；否则带 Orientation=6 会被浏览器二次旋转，此时用 -copy icc 保住色域 */
        $copy = (1 === $orient) ? '-copy all' : '-copy icc';
        /* Windows 下 2>/dev/null 会在盘根生成名为 null 的垃圾文件，必须用 2>NUL */
        $nullDev = (0 === stripos(PHP_OS, 'WIN')) ? '2>NUL' : '2>/dev/null';
        foreach ($steps as $i => $opt) {
            $out = ($last === $i) ? $tmp : $dst . '.' . $tag . '.mid' . $i . '.jpg';
            /* -perfect 要求边长是 MCU 整数倍；失败退 -trim，只损这一次，且避免 8px 错位细条 */
            $attempt = static function (string $copyMode, string $extra) use ($jt, $opt, $cur, $out, $nullDev): bool {
                if (is_file($out)) { @unlink($out); }
                $o = array();
                $rc = 1;
                @exec(escapeshellarg($jt) . ' ' . $copyMode . $extra
                    . ' ' . $opt . ' -outfile ' . escapeshellarg($out) . ' ' . escapeshellarg($cur)
                    . ' ' . $nullDev, $o, $rc);
                return 0 === $rc && is_file($out) && @filesize($out) > 0;
            };
            $okStep = $attempt($copy, ' -perfect')
                || $attempt($copy, ' -trim')
                || $attempt($copy, '')
                || $attempt('-copy none', '');
            if (!$okStep) {
                foreach ((array) @glob(dirname($dst) . '/[!^]*.' . $tag . '.*') as $f) { @unlink($f); }
                return false;
            }
            if ($cur !== $src) { @unlink($cur); }
            $cur = $out;
        }
        if (!@rename($tmp, $dst)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** 「4:2:0」这类写法 → Imagick 采样因子数组（顺序 Y, Cb, Cr）；识别不了返回空数组交给默认值 */
    private static function samplingFactorsOf(string $sub): array
    {
        switch ($sub) {
            case '4:4:4':
                return array('1x1', '1x1', '1x1');
            case '4:2:2':
                return array('2x1', '1x1', '1x1');
            case '4:2:0':
                return array('2x2', '1x1', '1x1');
            case '4:4:0':
                return array('1x2', '1x1', '1x1');
            case '4:1:1':
                return array('4x1', '1x1', '1x1');
            case '4:1:0':
                return array('4x2', '1x1', '1x1');
        }
        return array();
    }

    /** 有损重编的档位：用基线 bpp 确定性落档（按体积解方程曾两次把 1.86 MB 压成 600 KB） */
    private static function lossySourceQuality(array $srcInfo, string $ext): int
    {
        if ('webp' === $ext) {
            /* 与弹窗的「估算 qNN」同一条曲线，避免显示 q99、实存 q96 */
            $bpp = isset($srcInfo['bpp']) ? (float) $srcInfo['bpp'] : 0.0;
            $q = self::webpBppToQuality($bpp);
            /* bpp 读不出来（容器头异常 / 尺寸缺失）时才回落到保守档 */
            return $q > 0 ? $q : 96;
        }
        /* JPEG 的质量档是可读的（imageSourceInfo 反推），直接沿用原图档位 */
        $q = isset($srcInfo['quality']) ? (int) $srcInfo['quality'] : 0;
        return $q > 0 ? min(100, $q) : 90;
    }

    /** bpp → 等效质量档（仅 WebP 有损）：典型照片的经验曲线，一律按「约」呈现，高端封 97 */
    private static function webpBppToQuality(float $bpp): int
    {
        if ($bpp <= 0) { return 0; }
        $pts = array(
            array(0.12, 40), array(0.20, 55), array(0.30, 68), array(0.45, 80),
            array(0.60, 86), array(0.85, 90), array(1.00, 92), array(1.26, 96),
            /* 96/97 封顶：q99 实测 1.48 MB → 6.96 MB，买不到细节只买到字节 */
            array(1.60, 96), array(2.20, 97),
        );
        if ($bpp <= $pts[0][0]) { return (int) $pts[0][1]; }
        $n = count($pts);
        for ($i = 1; $i < $n; $i++) {
            if ($bpp <= $pts[$i][0]) {
                $x0 = $pts[$i - 1][0]; $y0 = $pts[$i - 1][1];
                $x1 = $pts[$i][0];     $y1 = $pts[$i][1];
                $q = $y0 + ($y1 - $y0) * ($bpp - $x0) / ($x1 - $x0);
                return (int) max(1, min(100, (int) round($q)));
            }
        }
        /* 超出锚点沿用末项档位，绝不返回 100 */
        return (int) $pts[$n - 1][1];
    }

    /** 体积护栏（纯函数，0=不动）：偏小 +3 档、偏大 -6 档，防逐代掉体积与高端失控 */
    private static function webpGuardQ(int $q, int $outSz, int $targetBytes): int
    {
        if ($q <= 0 || $outSz <= 0 || $targetBytes <= 0) { return 0; }
        /* 升档条件：不足目标六成，一次 +3 档封顶 97；跌两成属正常波动 */
        if ($outSz < (int) ($targetBytes * 0.6)) {
            $up = min(97, $q + 3);
            return $up > $q ? $up : 0;
        }
        /* 偏大降档：1.25 倍留余量，纹理密的图同档重编本来就会大两成 */
        if ($outSz > (int) ($targetBytes * 1.25)) {
            $down = max(86, $q - 6);
            return $down < $q ? $down : 0;
        }
        return 0;
    }

    /** Imagick 通道的体积护栏：落盘后用同一个 $im 重写，最多两轮，须在 destroy 前调用 */
    private static function webpVolumeGuard($im, string $dst, int $targetBytes, int $chanQ): int
    {
        $q = (int) $chanQ;
        if ($q <= 0 || $targetBytes <= 0) { return 0; }
        $final = 0;
        for ($i = 0; $i < 2; $i++) {
            clearstatcache(true, $dst);
            $outSz = (int) @filesize($dst);
            $q2 = self::webpGuardQ($q, $outSz, $targetBytes);
            if (0 === $q2) { break; }
            $im->setImageCompressionQuality($q2);
            $ok = @$im->writeImage($dst);
            clearstatcache(true, $dst);
            $newSz = (int) @filesize($dst);
            if (!$ok || $newSz <= 0) { break; }
            /* 升档只做一次：升到 98 之后再判还是同一个结论，再写一遍纯属浪费 */
            $up = $q2 > $q;
            $q = $q2;
            $final = $q2;
            if ($up) { break; }
        }
        return $final;
    }

    /** 体积优先：按 sz ∝ q^2.5 试写逼近目标体积，最多 3 轮、±8% 即停 */
    private static function probeQualityBySize(\Imagick $im, string $probe, int $target, int $floor, string $ext = 'webp'): int
    {
        $q = 88;                 /* libwebp / libjpeg 在照片上的常见高画质档，先拿它探一次 */
        $best = $q;
        $bestDiff = PHP_INT_MAX;
        for ($i = 0; $i < 3; $i++) {
            $im->setImageCompressionQuality($q);
            if (!@$im->writeImage($probe)) {
                break;
            }
            clearstatcache(true, $probe);
            $sz = (int) @filesize($probe);
            if ($sz <= 0) {
                break;
            }
            /* 量到的体积与目标不同量级说明路径不对（扩展名/delegate 问题），宁可保守也不继续解方程 */
            if ($sz > $target * 6 || $sz * 6 < $target) {
                @unlink($probe);
                return 90;
            }
            $diff = abs($sz - $target);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $q;
            }
            /* 已经落在 ±8% 里就没必要再试——继续调只会在区间内来回摆 */
            if ($sz >= (int) round($target * 0.92) && $sz <= (int) round($target * 1.08)) {
                break;
            }
            $nq = (int) round($q * pow($target / $sz, 1 / 2.5));
            $nq = max($floor, min(100, $nq));
            if ($nq === $q) {
                break;
            }
            $q = $nq;
        }
        @unlink($probe);
        /* 逼近到下限仍差一大截时锁 q90：体积大几成可以接受，画质崩了补不回来 */
        if ($best <= $floor && $bestDiff > (int) ($target * 0.5)) {
            return 90;
        }
        return $best;
    }

    /** Imagick 编码：jpg 沿用原质量档与抽样，webp 按确定性档位（VP8L 源走无损） */
    private static function imagickRotateSave(string $src, string $dst, string $ext, int $orient, int $total,
        bool $losslessWebp = false, int $targetBytes = 0, array $srcInfo = array()): bool
    {
        if (!class_exists('Imagick')) { return false; }
        try {
            $im = new \Imagick($src);
        } catch (\Exception $e) {
            self::$lastEnc['err'] = 'open: ' . $e->getMessage();
            return false;
        }
        try {
            switch ($orient) {
                case 2:
                    $im->flopImage();
                    break;
                case 3:
                    $im->rotateImage(new \ImagickPixel('none'), 180);
                    break;
                case 4:
                    $im->flipImage();
                    break;
                case 5:
                    $im->rotateImage(new \ImagickPixel('none'), 90);
                    $im->flopImage();
                    break;
                case 6:
                    $im->rotateImage(new \ImagickPixel('none'), 90);
                    break;
                case 7:
                    $im->rotateImage(new \ImagickPixel('none'), 270);
                    $im->flopImage();
                    break;
                case 8:
                    $im->rotateImage(new \ImagickPixel('none'), 270);
                    break;
            }
            /* 累计角度先归一化，别把 450 原样交给 rotateImage */
            $deg = (($total % 360) + 360) % 360;
            if (0 !== $deg) {
                $im->rotateImage(new \ImagickPixel('none'), $deg);
            }
            if ('webp' === $ext) {
                $im->setImageFormat('webp');
                if ($losslessWebp) {
                    /* VP8L 源必须无损重编；method=4 而非 6，最慢档会撞网关超时 */
                    $im->setOption('webp:lossless', 'true');
                    $im->setOption('webp:method', '4');
                    $im->setImageCompressionQuality(100);
                } else {
                    /* 有损源按确定性档位重编，不再写临时文件试体积（曾两次把图压崩） */
                    $wq = self::lossySourceQuality($srcInfo, 'webp');
                    $im->setImageCompressionQuality($wq);
                    self::$lastEnc = array('chan' => 'imagick', 'q' => $wq, 'target' => $targetBytes);
                }
            } else {
                if (1 !== $orient) {
                    $icc = null;
                    try { $icc = $im->getImageProfile('icc'); } catch (\Exception $e3) { $icc = null; }
                    $im->stripImage();
                    if (is_string($icc) && '' !== $icc) { $im->setImageProfile('icc', $icc); }
                }
                $im->setImageFormat('jpeg');
                $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
                /* JPEG 质量可从 DQT 反推，直接用原质量与抽样，体积自然落在原图附近 */
                $sq = isset($srcInfo['quality']) ? (int) $srcInfo['quality'] : 0;
                if ($sq < 1 && $targetBytes > 0) {
                    /* 读不出质量档时退化成按体积试，临时文件仍要 .jpg 扩展名 */
                    $sq = self::probeQualityBySize($im, $dst . '.probe.' . $ext, $targetBytes, 40, $ext);
                }
                $im->setImageCompressionQuality($sq > 0 ? $sq : 88);
                self::$lastEnc = array('chan' => 'imagick', 'q' => ($sq > 0 ? $sq : 88),
                    'target' => $targetBytes, 'sub' => (isset($srcInfo['sub']) ? (string) $srcInfo['sub'] : ''));
                $sf = self::samplingFactorsOf(isset($srcInfo['sub']) ? (string) $srcInfo['sub'] : '');
                if ($sf && method_exists($im, 'setSamplingFactors')) {
                    $im->setSamplingFactors($sf);
                }
                $im->setImagePage(0, 0, 0, 0);
            }
            $ok = $im->writeImage($dst);
            if (!$ok) {
                self::$lastEnc['err'] = 'write: writeImage() 返回 false';
            }
            /* 体积护栏必须在 destroy 之前跑：它要拿同一个 Imagick 对象重编一次 */
            if ($ok && 'webp' === $ext && empty($losslessWebp) && $targetBytes > 0) {
                $q0 = isset(self::$lastEnc['q']) ? (int) self::$lastEnc['q'] : 0;
                $qFinal = self::webpVolumeGuard($im, $dst, $targetBytes, $q0);
                /* 护栏重写过就把真实档位回填，前端 console 的「q=NN」才是落盘那一档 */
                if ($qFinal > 0) { self::$lastEnc['q'] = $qFinal; }
            }
            $im->destroy();
            clearstatcache(true, $dst);
            return (bool) $ok && is_file($dst) && filesize($dst) > 0;
        } catch (\Exception $e) {
            self::$lastEnc['err'] = 'encode: ' . $e->getMessage();
            try { $im->destroy(); } catch (\Exception $e2) {  }
            if (is_file($dst)) { @unlink($dst); }
            return false;
        }
    }

    /** 16 位 PNG 专用通道：GD 解码即截断，写完回读 IHDR 确认仍是 16 位 */
    private static function imagickPng16Save(string $src, string $dst, int $orient, int $total): bool
    {
        if (!class_exists('Imagick')) {
            self::$lastEnc['err'] = 'png16: Imagick 未安装';
            return false;
        }
        try {
            $im = new \Imagick($src);
        } catch (\Exception $e) {
            self::$lastEnc['err'] = 'open: ' . $e->getMessage();
            return false;
        }
        $ok = false;
        $excl = array();
        try {
            switch ($orient) {
                case 2:
                    $im->flopImage();
                    break;
                case 3:
                    $im->rotateImage(new \ImagickPixel('none'), 180);
                    break;
                case 4:
                    $im->flipImage();
                    break;
                case 5:
                    $im->rotateImage(new \ImagickPixel('none'), 90);
                    $im->flopImage();
                    break;
                case 6:
                    $im->rotateImage(new \ImagickPixel('none'), 90);
                    break;
                case 7:
                    $im->rotateImage(new \ImagickPixel('none'), 270);
                    $im->flopImage();
                    break;
                case 8:
                    $im->rotateImage(new \ImagickPixel('none'), 270);
                    break;
            }
            /* 与其它通道一致：累计角度先归一化，别把 450° 原样丢给 rotateImage */
            $deg = (($total % 360) + 360) % 360;
            if (0 !== $deg) {
                $im->rotateImage(new \ImagickPixel('none'), $deg);
            }
            $im->setImageFormat('png');
            /* Q16 是内部表示，写盘还得明确要求 16 位，否则 IM 会「够用就行」降到 8 位 */
            $im->setImageDepth(16);
            $im->setOption('png:bit-depth', '16');
            /* 色彩类型跟源（0/2/4/6）：灰度 16 位写成 RGB 会白涨三倍体积 */
            $ph = self::pngHeader($src);
            $ct = isset($ph['colorType']) ? (int) $ph['colorType'] : 2;
            if (!in_array($ct, array(0, 2, 4, 6), true)) {
                $ct = 2;
            }
            $im->setOption('png:color-type', (string) $ct);
            /* IM 会自补 cHRM/gAMA/bKGD/tIME，按源图块清单设 exclude-chunk 白名单（tIME 会让同图两次保存字节不同） */
            $cand = array('cHRM', 'gAMA', 'bKGD', 'tIME', 'sRGB', 'pHYs', 'sBIT', 'tEXt',
                'zTXt', 'iTXt', 'oFFs', 'sCAL', 'sPLT', 'hIST', 'eXIf', 'iCCP', 'tRNS');
            $excl = array();
            foreach ($cand as $ck) {
                if (!in_array($ck, $ph['chunks'], true)) { $excl[] = $ck; }
            }
            if ($excl) {
                $im->setOption('png:exclude-chunk', implode(',', $excl));
            }
            $im->setOption('png:compression-level', '6');
            $ok = (bool) $im->writeImage($dst);
            if (!$ok) {
                self::$lastEnc['err'] = 'write: writeImage() 返回 false';
            }
            $im->destroy();
        } catch (\Exception $e) {
            self::$lastEnc['err'] = 'encode: ' . $e->getMessage();
            try { $im->destroy(); } catch (\Exception $e2) {  }
            if (is_file($dst)) { @unlink($dst); }
            return false;
        }
        clearstatcache(true, $dst);
        if (!$ok || !is_file($dst) || (int) @filesize($dst) <= 0) {
            if (is_file($dst)) { @unlink($dst); }
            return false;
        }
        /* 回读核对「16 位真的写出来了」——设了选项不等于生效，这一步不能省 */
        $oh = self::pngHeader($dst);
        if (empty($oh['png']) || 16 !== (int) $oh['depth']) {
            self::$lastEnc['err'] = 'png16: 输出位深 ' . (int) $oh['depth'] . '，未能保住 16 位';
            @unlink($dst);
            return false;
        }
        self::$lastEnc = array('chan' => 'imagick', 'bits' => 16);
        return true;
    }

    private static function gdRotateSave(string $src, string $dst, string $ext, int $orient, int $total,
        array $srcInfo = array(), int $targetBytes = 0, string $srcExt = ''): bool
    {
        if (!function_exists('imagecreatetruecolor')) { return false; }
        /* 解码器按源格式选（jpg 备份 → webp 输出），按输出扩展名会读错文件 */
        $dec = '' !== $srcExt ? $srcExt : $ext;
        switch ($dec) {
            case 'png':
                $im = @imagecreatefrompng($src);
                break;
            case 'webp':
                $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false;
                break;
            default:
                $im = @imagecreatefromjpeg($src);
        }
        if (!$im) { return false; }
        $im = self::bakeOrientation($im, $orient);
        /* GD 正角逆时针：顺时针 total 要写成 360-归一化值，imagerotate 不吃负角度 */
        $degNorm = (($total % 360) + 360) % 360;
        if (0 !== $degNorm) {
            $rot = @imagerotate($im, (360 - $degNorm) % 360, 0);
            if (!$rot) { imagedestroy($im); return false; }
            imagedestroy($im);
            $im = $rot;
        }
        /* 仅源图带透明才写 alpha：无条件置 true 会把 ct=2 写成 ct=6，语义变了 */
        $keepAlpha = true;
        if ('png' === $dec) {
            $ph = self::pngHeader($src);
            if (!empty($ph['png'])) {
                $keepAlpha = !empty($ph['alpha']);
            }
        }
        if ($keepAlpha) {
            imagealphablending($im, true);
            imagesavealpha($im, true);
        } else {
            imagesavealpha($im, false);
        }
        switch ($ext) {
            case 'png':
                $ok = @imagepng($im, $dst, 6);
                break;
            case 'webp':
                /* GD 与 Imagick 共用同一套档位；imagewebp 无法切换无损模式，这里以前写死 85 */
                $qw = self::lossySourceQuality($srcInfo, 'webp');
                /* 保留 Imagick 失败原因，落到 GD 时前端 console 才能解释 chan=gd */
                self::$lastEnc = array('chan' => 'gd', 'q' => $qw, 'target' => $targetBytes,
                    'bpp' => (isset($srcInfo['bpp']) ? $srcInfo['bpp'] : 0),
                    'err' => (isset(self::$lastEnc['err']) ? self::$lastEnc['err'] : ''));
                $ok = function_exists('imagewebp') ? @imagewebp($im, $dst, $qw) : false;
                /* 体积护栏与 Imagick 共用（webpGuardQ），最多两轮 */
                if ($ok && $targetBytes > 0 && function_exists('imagewebp')) {
                    for ($gi = 0; $gi < 2; $gi++) {
                        clearstatcache(true, $dst);
                        $outSz = (int) @filesize($dst);
                        $q2 = self::webpGuardQ((int) $qw, $outSz, $targetBytes);
                        if (0 === $q2) { break; }
                        $ok2 = @imagewebp($im, $dst, $q2);
                        clearstatcache(true, $dst);
                        if (!$ok2) { break; }
                        $up = $q2 > $qw;
                        $qw = $q2;
                        self::$lastEnc['q'] = $qw;   /* 前端 enc.q 要报真实落盘档位，不是初档 */
                        if ($up) { break; }
                    }
                }
                break;
            default:
                /* GD 本来就在丢色度，按原图质量档重编即可，追高只涨体积 */
                $qj = isset($srcInfo['quality']) ? (int) $srcInfo['quality'] : 0;
                self::$lastEnc = array('chan' => 'gd', 'q' => ($qj > 0 ? $qj : 88),
                    'target' => $targetBytes, 'sub' => (isset($srcInfo['sub']) ? (string) $srcInfo['sub'] : ''));
                $ok = @imagejpeg($im, $dst, $qj > 0 ? $qj : 88);
        }
        imagedestroy($im);
        if (!$ok && is_file($dst)) { @unlink($dst); }
        return (bool) $ok;
    }

    private static function bakeOrientation($im, int $orient)
    {
        switch ($orient) {
            case 2:
                imageflip($im, IMG_FLIP_HORIZONTAL);
                return $im;
            case 4:
                imageflip($im, IMG_FLIP_VERTICAL);
                return $im;
            case 3:
                $r = @imagerotate($im, 180, 0);
                break;
            case 6:
                $r = @imagerotate($im, 270, 0);
                break;
            case 8:
                $r = @imagerotate($im, 90, 0);
                break;
            case 5:
                $r = @imagerotate($im, 270, 0);
                if ($r) {
                    imagedestroy($im);
                    $im = $r;
                }
                imageflip($im, IMG_FLIP_HORIZONTAL);
                return $im;
            case 7:
                $r = @imagerotate($im, 90, 0);
                if ($r) {
                    imagedestroy($im);
                    $im = $r;
                }
                imageflip($im, IMG_FLIP_HORIZONTAL);
                return $im;
            default:
                return $im;
        }
        if ($r) {
            imagedestroy($im);
            return $r;
        }
        return $im;
    }

    private function renameAttachmentRefs(string $oldRelPath, string $newRelPath): int
    {
        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__')
            ? __TYPECHO_UPLOAD_DIR__ : \Widget\Upload::UPLOAD_DIR;
        $prefix = rtrim($uploadDir, '/') . '/';
        $oldInner = 0 === strpos($oldRelPath, $prefix) ? substr($oldRelPath, strlen($prefix)) : ltrim($oldRelPath, '/');
        $newInner = 0 === strpos($newRelPath, $prefix) ? substr($newRelPath, strlen($prefix)) : ltrim($newRelPath, '/');
        if ('' === $oldInner || '' === $newInner || $oldInner === $newInner) {
            return 0;
        }

        $needle = false !== strpos($oldInner, '/') ? $oldInner : $prefix . $oldInner;
        $replace = false !== strpos($newInner, '/') ? $newInner : $prefix . $newInner;
        if ($needle === $replace) {
            return 0;
        }

        $db = $this->db();
        $count = 0;

        $targets = array(
            array('table.contents', 'cid', true),
            array('table.comments', 'coid', false),
        );

        foreach ($targets as $t) {
            $select = $db->select($t[1], 'text')->from($t[0]);
            if ($t[2]) {
                $select->where('type <> ?', 'attachment');
            }
            foreach ($db->fetchAll($select->where('text LIKE ?', '%' . $needle . '%')) as $row) {
                $newText = str_replace($needle, $replace, (string) $row['text']);
                if ($newText !== (string) $row['text']) {
                    $db->query($db->update($t[0])
                        ->rows(array('text' => $newText))
                        ->where($t[1] . ' = ?', $row[$t[1]]));
                    $count++;
                }
            }
        }

        return $count;
    }

    private function doList()
    {
        $db = $this->db();
        $this->syncMap();

        try {
            \FileManager_Plugin::autoOrganizeUnmapped();
        } catch (\Throwable $e) {

        }

        try {
            self::purgeExpiredTrash();
        } catch (\Throwable $e) {

        }

        try {
            self::sweepRotBackups();
        } catch (\Throwable $e) {

        }

        $fid = (int) $this->request->get('fid', 0);
        $type = (string) $this->request->get('type', '');
        $keywords = trim((string) $this->request->get('keywords', ''));
        $page = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pageSize', self::PAGE_SIZE);
        if ($pageSize < 1 || $pageSize > 50) {
            $pageSize = self::PAGE_SIZE;
        }
        $sort = in_array((string) $this->request->get('sort', 'time'), array('name', 'time', 'size'))
            ? (string) $this->request->get('sort', 'time') : 'time';
        $dir = ('asc' === strtolower((string) $this->request->get('dir', 'desc'))) ? 'ASC' : 'DESC';

        $base = $db->select()
            ->from('table.contents')
            ->join('table.filemanager_map', 'table.contents.cid = table.filemanager_map.cid', \Typecho\Db::INNER_JOIN)
            ->where('table.contents.type = ?', 'attachment');

        if ($fid > 0) {
            $base->where('table.filemanager_map.fid = ?', $fid);
        } elseif (-1 === $fid) {
            $base->where('table.filemanager_map.fid = ?', 0);
        }

        $cats = $this->categories();

        if (isset($cats[$type])) {
            $this->extIn($base, $cats[$type]['exts']);
        } elseif ('other' === $type) {

            foreach ($this->allKnownExts() as $knownExt) {
                $base->where('table.filemanager_map.ext <> ?', $knownExt);
            }
        }

        if ('' !== $keywords) {
            $base->where('table.contents.title LIKE ?', '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $keywords) . '%');
        }

        $countRow = $db->fetchRow($base->select('COUNT(table.contents.cid) AS cnt'));
        $total = isset($countRow['cnt']) ? (int) $countRow['cnt'] : 0;

        $orderMap = array(
            'name' => 'table.contents.title',
            'time' => 'table.contents.created',
            'size' => 'table.filemanager_map.size',
        );
        $base->order($orderMap[$sort], 'ASC' === $dir ? \Typecho\Db::SORT_ASC : \Typecho\Db::SORT_DESC)
            ->order('table.contents.cid', \Typecho\Db::SORT_DESC);

        $rows = $db->fetchAll($base
            ->select('table.contents.cid', 'table.contents.title', 'table.contents.created', 'table.contents.text',
                'table.filemanager_map.fid', 'table.filemanager_map.size', 'table.filemanager_map.ext')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize));

        $options = \Widget\Options::alloc();
        $rootUrl = $options->rootUrl;
        $files = array();
        foreach ($rows as $row) {
            $info = self::parseAttachmentText($row['text']);
            $isImage = self::isImageExt($row['ext'], $info['mime']);
            $cid = (int) $row['cid'];
            $mtime = 0;
            if ($isImage && '' !== $info['path']) {
                $abs = self::resolveAttachmentFile($info['path']);
                if (null !== $abs && is_file($abs)) {
                    $mtime = (int) @filemtime($abs);
                }
            }
            $hasThumb = false;
            if ($isImage && $mtime > 0) {
                $st = @stat(__DIR__ . '/.fmcache/thumb-' . $cid . '-w640.webp');
                $hasThumb = false !== $st && $st['size'] > 0 && (int) $st['mtime'] >= $mtime;
            }
            $files[] = array(
                'cid'      => $cid,
                'title'    => $row['title'],
                'name'     => '' !== $info['name'] ? $info['name'] : $row['title'],
                'fid'      => (int) $row['fid'],
                'ext'      => $row['ext'],
                'size'     => (int) $row['size'],
                'created'  => (int) $row['created'],
                'mtime'    => $mtime,
                'hasThumb' => $hasThumb,
                'url'      => self::displayAttachmentUrl($info, $rootUrl),
                'isIh'     => self::isImageHostActive($info),
                'ihSt'     => self::ihStorageType($info),
                'isImage'  => $isImage,
            );
        }

        $this->json(200, '', array(
            'files'    => $files,
            'total'    => $total,
            'page'     => $page,
            'pages'    => max(1, (int) ceil($total / $pageSize)),
            'pageSize' => $pageSize,
        ));
    }

    private function doMove()
    {
        $cids = $this->parseCids((string) $this->request->get('cids'));
        $fid = max(0, (int) $this->request->get('fid', 0));

        if ($fid > 0 && !$this->folderExists($fid)) {
            $this->json(400, '目标文件夹不存在');
        }

        $prefix = $this->db()->getPrefix();
        $this->db()->query("UPDATE `{$prefix}filemanager_map` SET fid = " . $fid
            . " WHERE cid IN (" . implode(',', $cids) . ")");

        $this->json(200, '已移动 ' . count($cids) . ' 个文件');
    }

    private function doFileDelete()
    {
        $cids = $this->parseCids((string) $this->request->get('cids'));
        $db = $this->db();

        $deleted = 0;
        $recycled = 0;
        $ihDeleted = 0;
        foreach ($cids as $cid) {
            $row = $db->fetchRow($db->select()->from('table.contents')
                ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
            if (!$row) {
                continue;
            }

            $info = self::parseAttachmentText($row['text']);
            $mapRow = $db->fetchRow($db->select('fid')->from('table.filemanager_map')
                ->where('cid = ?', $cid)->limit(1));

            $raw = self::decodeAttachmentRaw((string) $row['text']);
            $ihKey = (is_array($raw) && isset($raw['fm_ih']['key'])) ? (string) $raw['fm_ih']['key'] : '';
            if ('' !== $ihKey && $this->imageHostDeleteKey($ihKey)) {
                $ihDeleted++;
            }

            if (self::trashAttachment($cid, $row, $info, $mapRow ? (int) $mapRow['fid'] : 0)) {
                $recycled++;
            } elseif (null !== ($file = self::resolveAttachmentFile($info['path'])) && is_file($file)) {
                @unlink($file);
            }

            $db->query($db->delete('table.contents')->where('cid = ?', $cid));
            $db->query($db->delete('table.filemanager_map')->where('cid = ?', $cid));
            $db->query($db->delete('table.filemanager_shares')->where('cid = ?', $cid));
            $deleted++;
        }

        $message = '已删除 ' . $deleted . ' 个附件';
        $message .= $recycled > 0
            ? '（' . $recycled . ' 个已移入回收站，可还原）'
            : '（含物理文件）';
        if ($ihDeleted > 0) {
            $message .= '，图床图片已同步删除 ' . $ihDeleted . ' 张';
        }
        $this->json(200, $message);
    }

    private function doTrashList()
    {
        try {
            self::purgeExpiredTrash();
        } catch (\Throwable $e) {

        }

        $db = $this->db();
        try {
            $rows = $db->fetchAll($db->select('id', 'cid', 'name', 'size', 'ext', 'folder_fid', 'created', 'expire')
                ->from('table.filemanager_trash')
                ->order('created', \Typecho\Db::SORT_DESC));
        } catch (\Throwable $e) {
            $rows = array();
        }

        $items = array();
        foreach ($rows as $row) {
            $folder = '';
            if ((int) $row['folder_fid'] > 0) {
                try {
                    $f = $db->fetchRow($db->select('name')->from('table.filemanager_folders')
                        ->where('fid = ?', (int) $row['folder_fid'])->limit(1));
                    $folder = $f ? (string) $f['name'] : '';
                } catch (\Throwable $e) {

                }
            }
            $items[] = array(
                'id'         => (int) $row['id'],
                'name'       => (string) $row['name'],
                'size'       => (int) $row['size'],
                'ext'        => (string) $row['ext'],
                'folder'     => $folder,
                'created'    => (int) $row['created'],
                'remainDays' => max(0, (int) ceil(((int) $row['expire'] - time()) / 86400)),
            );
        }

        $this->json(200, '', array('rows' => $items, 'count' => count($items)));
    }

    private function doTrashRestore()
    {
        $id = (int) $this->request->get('id');
        $db = $this->db();

        $row = $db->fetchRow($db->select()->from('table.filemanager_trash')
            ->where('id = ?', $id)->limit(1));
        if (!$row) {
            $this->json(404, '回收站中不存在该记录，请刷新列表');
        }

        $cid = (int) $row['cid'];
        if ($cid > 0 && $db->fetchRow($db->select('cid')->from('table.contents')
            ->where('cid = ?', $cid)->limit(1))) {
            $this->json(400, '原附件 ID 已被其它内容占用，无法还原');
        }

        $data = @unserialize((string) $row['data']);
        if (!is_array($data) || empty($data)) {
            $this->json(500, '回收站记录已损坏，无法还原');
        }

        $dir = self::trashDir();
        $src = (null !== $dir && '' !== (string) $row['trash_name']) ? $dir . '/' . $row['trash_name'] : null;
        if (null !== $src && is_file($src)) {

            $destAbs = self::computeAttachmentFile((string) $row['path']);
            if (null === $destAbs) {
                $this->json(500, '原始路径无法解析，无法还原');
            }
            if (is_file($destAbs)) {
                $this->json(400, '原位置已存在同名物理文件，无法还原');
            }
            $destDir = dirname($destAbs);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                $this->json(500, '恢复原目录失败，请检查写权限');
            }
            if (!@rename($src, $destAbs)) {
                if (!@copy($src, $destAbs) || !@unlink($src)) {
                    @unlink($destAbs);
                    $this->json(500, '物理文件还原失败，请检查目录权限');
                }
            }
        }

        if ($cid > 0 && !isset($data['cid'])) {
            $data['cid'] = $cid;
        }
        /* 还原的是整行快照：slug 可能已被占用，只替换 slug 防撞唯一键 */
        $data['slug'] = self::uniqueSlug(
            isset($data['slug']) ? (string) $data['slug'] : (string) $row['name']
        );
        try {
            $db->query($db->insert('table.contents')->rows($data));
        } catch (\Throwable $e) {
            $this->json(500, '附件记录还原失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        if ($cid > 0) {
            try {
                $db->query($db->insert('table.filemanager_map')->rows(array(
                    'cid'  => $cid,
                    'fid'  => (int) $row['folder_fid'],
                    'size' => (int) $row['size'],
                    'ext'  => (string) $row['ext'],
                )));
            } catch (\Throwable $e) {

            }
        }

        try {
            $db->query($db->delete('table.filemanager_trash')->where('id = ?', $id));
        } catch (\Throwable $e) {

        }

        $this->json(200, '已还原「' . $row['name'] . '」');
    }

    private function doTrashDelete()
    {
        $id = (int) $this->request->get('id');
        $db = $this->db();

        $row = $db->fetchRow($db->select('id', 'name', 'path', 'trash_name')->from('table.filemanager_trash')
            ->where('id = ?', $id)->limit(1));
        if (!$row) {
            $this->json(404, '回收站中不存在该记录，请刷新列表');
        }

        $dir = self::trashDir();
        if (null !== $dir && '' !== (string) $row['trash_name']) {
            $f = $dir . '/' . $row['trash_name'];
            if (is_file($f)) {
                @unlink($f);
            }
        }
        self::dropOriginBackup((string) $row['path']);
        $db->query($db->delete('table.filemanager_trash')->where('id = ?', $id));

        $this->json(200, '已彻底删除「' . $row['name'] . '」');
    }

    private function doTrashClear()
    {
        $db = $this->db();
        try {
            $rows = $db->fetchAll($db->select('id', 'path', 'trash_name')->from('table.filemanager_trash'));
        } catch (\Throwable $e) {
            $rows = array();
        }
        if (!$rows) {
            $this->json(200, '回收站已是空的');
        }

        $dir = self::trashDir();
        $n = 0;
        foreach ($rows as $row) {
            if (null !== $dir && '' !== (string) $row['trash_name']) {
                $f = $dir . '/' . $row['trash_name'];
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            self::dropOriginBackup((string) $row['path']);
            try {
                $db->query($db->delete('table.filemanager_trash')->where('id = ?', (int) $row['id']));
                $n++;
            } catch (\Throwable $e) {

            }
        }

        $this->json(200, '已清空回收站（' . $n . ' 项）');
    }

    private function doFileGet()
    {
        $cid = (int) $this->request->get('cid');
        $db = $this->db();
        $row = $db->fetchRow($db->select('cid', 'title', 'slug', 'text')->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
        if (!$row) {
            $this->json(404, '文件不存在或已被删除');
        }
        $info = self::parseAttachmentText((string) $row['text']);

        $actual = '' !== $info['path']
            ? basename(str_replace('\\', '/', $info['path'])) : $info['name'];
        $this->json(200, '', array(
            'title' => (string) $row['title'],
            'slug'  => (string) $row['slug'],
            'name'  => $actual,
            'url'   => self::displayAttachmentUrl($info, \Widget\Options::alloc()->rootUrl),
            'isIh'  => self::isImageHostActive($info),
            'ihSt'  => self::ihStorageType($info),
        ));
    }

    private function doFileUpdate()
    {
        $cid = (int) $this->request->get('cid');
        $title = trim((string) $this->request->get('title'));
        $newName = trim((string) $this->request->get('name'));
        $db = $this->db();

        $row = $db->fetchRow($db->select('cid', 'slug', 'text')->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
        if (!$row) {
            $this->json(404, '文件不存在或已被删除');
        }
        $u8len = function_exists('mb_strlen')
            ? function ($s) { return mb_strlen($s, 'UTF-8'); }
            : function ($s) { return (int) preg_match_all('/./us', $s); };

        if ('' === $title) {
            $this->json(400, '文件标题不能为空');
        }
        if ($u8len($title) > 150) {
            $this->json(400, '文件标题过长（最多 150 字）');
        }

        $slugParam = $this->request->get('slug');
        $slug = null === $slugParam ? null : trim((string) $slugParam);
        if (null !== $slug && '' !== $slug) {
            $slug = str_replace(' ', '-', $slug);
            if (preg_match('/^[\p{L}\p{N}_-]+$/u', $slug) !== 1 || $u8len($slug) > 200) {
                $this->json(400, '缩略名格式不正确：仅允许字母、数字、下划线、短横线或中文');
            }
            $exists = $db->fetchRow($db->select('cid')->from('table.contents')
                ->where('slug = ? AND cid <> ?', $slug, $cid)->limit(1));
            if ($exists) {
                $this->json(400, '缩略名已被其它内容使用，请换一个');
            }
        }

        $newText = null;
        $newPath = '';
        $oldName = '';
        $syncCount = 0;
        if ('' !== $newName) {
            $info = self::parseAttachmentText((string) $row['text']);
            $oldPath = $info['path'];
            $oldName = '' !== $oldPath
                ? basename(str_replace('\\', '/', $oldPath)) : $info['name'];
            if ('' === $oldName || '' === $oldPath) {
                $this->json(400, '附件记录缺少路径信息，无法修改文件名');
            }
            $oldExt = strtolower((string) pathinfo($oldName, PATHINFO_EXTENSION));
            $oldBase = '' !== $oldExt
                ? substr($oldName, 0, strlen($oldName) - strlen($oldExt) - 1) : $oldName;

            /* 提交值是不含扩展名的基础名，但也可能把原扩展名一起带上；两种写法都还原成基础名 */
            $inputExt = strtolower((string) pathinfo($newName, PATHINFO_EXTENSION));
            $newBase = ('' !== $inputExt && $inputExt === $oldExt)
                ? substr($newName, 0, strlen($newName) - strlen($inputExt) - 1)
                : $newName;

            /* 基础名没变即没有改物理文件名（例如只改标题）：直接跳过重命名，否则会撞上自身文件 */
            if ($newBase !== $oldBase) {
                if ('' === $oldExt) {
                    $this->json(400, '原文件没有扩展名，无法修改文件名');
                }
                if ('' !== $inputExt && $inputExt !== $oldExt && self::isCommonFileExt($inputExt)) {
                    $this->json(400, '不能修改扩展名（当前为 .' . $oldExt . '）');
                }
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $newBase) !== 1
                    || strlen($newBase) > 190) {
                    $this->json(400, '文件名格式不正确：仅允许字母、数字、点、下划线、短横线（不含扩展名）');
                }
                $newName = $newBase . '.' . $oldExt;
                $abs = self::resolveAttachmentFile($oldPath);
                if (null === $abs || !is_file($abs)) {
                    $this->json(400, '物理文件缺失，无法重命名');
                }
                $newAbs = dirname($abs) . DIRECTORY_SEPARATOR . $newName;
                if (is_file($newAbs)) {
                    $this->json(400, '目标文件名已存在，请换一个');
                }
                if (!@rename($abs, $newAbs)) {
                    $this->json(500, '文件重命名失败（权限不足或文件被占用）');
                }
                @clearstatcache(true, $newAbs);

                $newPath = substr($oldPath, 0, strlen($oldPath) - strlen($oldName)) . $newName;
                $raw = self::decodeAttachmentRaw((string) $row['text']);
                $raw['name'] = $newName;
                $raw['path'] = $newPath;
                $newText = self::attachmentText($raw);

                $refs = $db->fetchAll($db->select('cid', 'text')->from('table.contents')
                    ->where('type = ? OR type = ?', 'post', 'page')
                    ->where('text LIKE ?', '%' . $oldPath . '%'));
                foreach ($refs as $ref) {
                    $content = (string) $ref['text'];
                    if (false === strpos($content, $oldPath)) { continue; }
                    $db->query($db->update('table.contents')
                        ->rows(array('text' => str_replace($oldPath, $newPath, $content)))
                        ->where('cid = ?', (int) $ref['cid']));
                    $syncCount++;
                }
            }
        }

        $rows = array('title' => $title, 'slug' => null === $slug ? (string) $row['slug'] : $slug);
        if (null !== $newText) {
            $rows['text'] = $newText;
        }
        $db->query($db->update('table.contents')->rows($rows)->where('cid = ?', $cid));

        $data = array();
        if (null !== $newText) {
            $data['url'] = \Typecho\Common::url($newPath, \Widget\Options::alloc()->rootUrl);
            $data['oldUrl'] = \Typecho\Common::url($oldPath, \Widget\Options::alloc()->rootUrl);
            $data['oldPath'] = $oldPath;
            $data['newPath'] = $newPath;
            $data['sync'] = $syncCount;
        }
        $this->json(200, null !== $newText
            ? '文件已重命名，并同步替换 ' . $syncCount . ' 处引用'
            : '文件信息已保存', $data);
    }

    /* 常见扩展名清单：只用来拦下「把扩展名改掉」这类误操作；不在清单内的点号片段按文件名的一部分处理 */
    private static function isCommonFileExt(string $ext): bool
    {
        return in_array($ext, array(
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif', 'ico', 'tif', 'tiff',
            'mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'flv', 'wmv',
            'mp3', 'wav', 'flac', 'ogg', 'm4a', 'aac',
            'zip', 'rar', '7z', 'gz', 'tar', 'bz2', 'xz',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md',
            'html', 'htm', 'css', 'js', 'json', 'xml', 'php', 'csv', 'sql', 'exe', 'apk', 'iso',
        ), true);
    }

    private function textEditableExts(): array
    {
        return array('txt', 'md', 'html', 'htm', 'css', 'js', 'json', 'xml', 'svg',
            'log', 'ini', 'conf', 'yml', 'yaml', 'csv', 'sql', 'php');
    }

    private function textEditableEncodings(): array
    {
        return array('UTF-8', 'GB18030', 'GBK', 'BIG5');
    }

    private function normalizeTextEncoding(string $enc): string
    {
        $enc = strtoupper(trim($enc));
        $alias = array('UTF8' => 'UTF-8', 'GB2312' => 'GB18030', 'GBK2312' => 'GB18030', 'CP936' => 'GB18030', 'MS936' => 'GB18030', 'BIG-5' => 'BIG5');
        if (isset($alias[$enc])) {
            $enc = $alias[$enc];
        }
        return in_array($enc, $this->textEditableEncodings(), true) ? $enc : '';
    }

    private function textEditableFile(int $cid): array
    {
        $db = $this->db();
        $row = $db->fetchRow($db->select('text')->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
        if (!$row) {
            $this->json(404, '文件不存在或已被删除');
        }

        $info = self::parseAttachmentText((string) $row['text']);
        if (!in_array($info['ext'], $this->textEditableExts(), true)) {
            $this->json(400, '该文件类型不支持在线编辑（仅限文本/代码文件）');
        }

        $abs = '' !== $info['path'] ? self::resolveAttachmentFile($info['path']) : null;
        if (null === $abs || !is_file($abs)) {
            $this->json(400, '物理文件缺失，无法编辑');
        }
        if (!is_writable($abs)) {
            $this->json(400, '文件不可写（权限不足），无法在线编辑');
        }

        return array($info, $abs, self::decodeAttachmentRaw((string) $row['text']));
    }

    private function doFileTextGet()
    {
        list($info, $abs) = $this->textEditableFile((int) $this->request->get('cid'));

        clearstatcache(true, $abs);
        $size = (int) @filesize($abs);
        if ($size > 2 * 1024 * 1024) {
            $this->json(400, '文件超过 2 MB，请下载后用本地编辑器处理');
        }

        $content = @file_get_contents($abs);
        if (false === $content) {
            $this->json(500, '读取文件失败：请检查文件权限');
        }

        $encParam = strtoupper(trim((string) $this->request->get('enc', 'auto')));
        if ('' === $encParam || 'AUTO' === $encParam) {
            if (mb_check_encoding($content, 'UTF-8')) {
                $enc = 'UTF-8';
            } else {

                $detected = mb_detect_encoding($content, array('GB18030', 'BIG5'), true);
                $enc = $this->normalizeTextEncoding((string) $detected);
                if ('' === $enc) {
                    if (mb_check_encoding($content, 'GB18030')) {
                        $enc = 'GB18030';
                    } elseif (mb_check_encoding($content, 'BIG5')) {
                        $enc = 'BIG5';
                    } else {
                        $this->json(400, '无法识别文件编码，请手动选择编码后重试');
                    }
                }
            }
        } else {
            $enc = $this->normalizeTextEncoding($encParam);
            if ('' === $enc) {
                $this->json(400, '不支持的编码类型');
            }
            if (!mb_check_encoding($content, $enc)) {
                $this->json(400, '文件不是有效的 ' . $enc . ' 编码，请换一个编码再试');
            }
        }
        if ('UTF-8' !== $enc) {
            $content = mb_convert_encoding($content, 'UTF-8', $enc);
        }

        $this->json(200, '', array(
            'name'    => (string) $info['name'],
            'ext'     => (string) $info['ext'],
            'size'    => $size,
            'mtime'   => (int) @filemtime($abs),
            'enc'     => $enc,
            'content' => $content,
        ));
    }

    private function doFileTextSave()
    {
        $cid = (int) $this->request->get('cid');
        list($info, $abs, $raw) = $this->textEditableFile($cid);

        $content = (string) $this->request->get('content');
        if (!mb_check_encoding($content, 'UTF-8')) {
            $this->json(400, '内容编码异常，保存已取消');
        }

        $enc = $this->normalizeTextEncoding((string) $this->request->get('enc'));
        if ('' === $enc) {
            $enc = 'UTF-8';
        }
        $out = 'UTF-8' !== $enc ? @mb_convert_encoding($content, $enc, 'UTF-8') : $content;
        if (!is_string($out)) {
            $this->json(400, '内容无法转换为 ' . $enc . ' 编码，保存已取消');
        }
        $newSize = strlen($out);
        if ($newSize > 2 * 1024 * 1024) {
            $this->json(400, '内容超过 2 MB，保存已取消');
        }

        if (false === @file_put_contents($abs, $out, LOCK_EX)) {
            $this->json(500, '写入失败：请检查文件/目录权限');
        }
        clearstatcache(true, $abs);

        $raw['size'] = $newSize;
        $db = $this->db();
        $db->query($db->update('table.contents')
            ->rows(array('text' => self::attachmentText($raw)))
            ->where('cid = ?', $cid));
        $db->query($db->update('table.filemanager_map')
            ->rows(array('size' => $newSize))
            ->where('cid = ?', $cid));

        $this->json(200, '已保存', array('newSize' => $newSize));
    }

    private function doFileTextConvert()
    {
        $cid = (int) $this->request->get('cid');
        list($info, $abs, $raw) = $this->textEditableFile($cid);

        $to = $this->normalizeTextEncoding((string) $this->request->get('to'));
        if ('' === $to) {
            $this->json(400, '请指定要转换成的编码');
        }

        $bytes = @file_get_contents($abs);
        if (false === $bytes) {
            $this->json(500, '读取文件失败：请检查文件权限');
        }

        $fromParam = strtoupper(trim((string) $this->request->get('from')));
        if ('' === $fromParam || 'AUTO' === $fromParam) {
            if (mb_check_encoding($bytes, 'UTF-8')) {
                $from = 'UTF-8';
            } else {

                $detected = mb_detect_encoding($bytes, array('GB18030', 'BIG5'), true);
                $from = $this->normalizeTextEncoding((string) $detected);
                if ('' === $from) {
                    if (mb_check_encoding($bytes, 'GB18030')) {
                        $from = 'GB18030';
                    } elseif (mb_check_encoding($bytes, 'BIG5')) {
                        $from = 'BIG5';
                    } else {
                        $this->json(400, '无法识别文件当前编码，转换已取消');
                    }
                }
            }
        } else {
            $from = $this->normalizeTextEncoding($fromParam);
            if ('' === $from) {
                $this->json(400, '不支持的来源编码');
            }
            if (!mb_check_encoding($bytes, $from)) {
                $this->json(400, '文件不是有效的 ' . $from . ' 编码，转换已取消');
            }
        }
        if ($from === $to) {
            $this->json(400, '文件已经是 ' . $to . ' 编码，无需转换');
        }

        $out = mb_convert_encoding($bytes, $to, $from);
        $newSize = strlen($out);
        if ($newSize > 2 * 1024 * 1024) {
            $this->json(400, '转换后超过 2 MB，转换已取消');
        }
        if (false === @file_put_contents($abs, $out, LOCK_EX)) {
            $this->json(500, '写入失败：请检查文件/目录权限');
        }
        clearstatcache(true, $abs);

        $raw['size'] = $newSize;
        $db = $this->db();
        $db->query($db->update('table.contents')
            ->rows(array('text' => self::attachmentText($raw)))
            ->where('cid = ?', $cid));
        $db->query($db->update('table.filemanager_map')
            ->rows(array('size' => $newSize))
            ->where('cid = ?', $cid));

        $this->json(200, '已转换为 ' . $to, array('newSize' => $newSize, 'enc' => $to));
    }

    private function doThumb()
    {
        $cid = (int) $this->request->get('cid', 0);
        $w = (int) $this->request->get('w', 640);
        $w = max(80, min(1280, $w));
        $warm = '1' === (string) $this->request->get('warm', '0');
        if ($cid <= 0) {
            $this->thumbRaw(404);
        }

        $db = $this->db();
        $row = $db->fetchRow($db->select('text')->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
        if (!$row) {
            $this->thumbRaw(404);
        }

        $info = self::parseAttachmentText((string) $row['text']);
        $abs = '' !== $info['path'] ? self::resolveAttachmentFile($info['path']) : null;
        if (null === $abs || !is_file($abs) || !is_readable($abs)) {

            if (isset($info['ih']['url']) && is_string($info['ih']['url']) && '' !== $info['ih']['url']) {
                if ($warm) {
                    $this->thumbWarmed('ih');
                }
                header('Location: ' . $info['ih']['url'], true, 302);
                exit;
            }
            $this->thumbRaw(404);
        }

        $ext = strtolower($info['ext']);
        $canThumb = in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true);

        $cacheDir = __DIR__ . '/.fmcache';
        $cacheFile = $cacheDir . '/thumb-' . $cid . '-w' . $w . '.webp';
        if ($canThumb && is_file($cacheFile) && @filesize($cacheFile) > 0
            && (int) @filemtime($cacheFile) >= (int) @filemtime($abs)) {
            if ($warm) {
                $this->thumbWarmed('cache');
            }
            $this->thumbRaw(200, $cacheFile, 'image/webp', 'cache');
        }

        $dims = @getimagesize($abs);
        $mime = $dims ? (string) $dims['mime'] : (string) (isset($info['mime']) ? $info['mime'] : '');
        if (!$dims || !$canThumb || $dims[0] <= $w) {
            if ($warm) {
                $this->thumbWarmed('origin');
            }
            $this->thumbRaw(200, $abs, '' !== $mime ? $mime : 'application/octet-stream', 'origin');
        }

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
            if ($warm) {
                $this->thumbWarmed('nowrite');
            }
            $this->thumbRaw(200, $abs, '' !== $mime ? $mime : 'application/octet-stream', 'origin-nowrite');
        }

        $ok = false;
        if (class_exists('Imagick')) {
            try {
                $im = new \Imagick($abs);
                if (!$im->valid()) {
                    throw new \Exception('imagick invalid image');
                }
                $im->setIteratorIndex(0);
                $im->stripImage();
                $im->thumbnailImage($w, 0);
                $im->setImageFormat('webp');
                $im->setImageCompressionQuality(80);
                $ok = (bool) $im->writeImage($cacheFile);
                $im->destroy();
            } catch (\Throwable $e) {
                $ok = false;
            }
        }
        if (!$ok && function_exists('imagecreatetruecolor')) {
            /* 超大图解码本身就是峰值，先按尺寸尽量抬限；失败也不阻塞，交给下面的 origin 兜底 */
            self::ensureImageMemory($abs, 1.6);
            $src = null;
            if ('jpg' === $ext || 'jpeg' === $ext) {
                $src = @imagecreatefromjpeg($abs);
            } elseif ('png' === $ext) {
                $src = @imagecreatefrompng($abs);
            } elseif ('webp' === $ext && function_exists('imagecreatefromwebp')) {
                $src = @imagecreatefromwebp($abs);
            }
            if ($src) {
                $ow = imagesx($src);
                $oh = imagesy($src);
                $nw = $w;
                $nh = max(1, (int) round($oh * $w / max(1, $ow)));
                $dst = imagecreatetruecolor($nw, $nh);
                if ($dst) {
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    $ok = imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh)
                        && imagewebp($dst, $cacheFile, 80);
                    imagedestroy($dst);
                }
                imagedestroy($src);
            }
        }
        if (!$ok || !is_file($cacheFile) || @filesize($cacheFile) <= 0) {

            if ($warm) {
                $this->thumbWarmed('origin');
            }
            $this->thumbRaw(200, $abs, '' !== $mime ? $mime : 'application/octet-stream', 'origin');
        }
        @chmod($cacheFile, 0644);
        if ($warm) {
            $this->thumbWarmed('new');
        }
        $this->thumbRaw(200, $cacheFile, 'image/webp', 'new');
    }

    private function thumbWarmed(string $tag = 'warm')
    {
        @http_response_code(204);
        @header('Cache-Control: no-store');
        @header('X-FM-Thumb: ' . $tag);
        exit;
    }

    private function thumbRaw(int $status, string $file = '', string $mime = '', string $tag = '')
    {
        /* 二进制输出前必须清干净：混进 warning 会让图片字节损坏（前端表现为图裂） */
        self::discardStrayOutput();

        if (200 !== $status || '' === $file) {
            @http_response_code(200 === $status ? 404 : $status);
            @header('Cache-Control: no-store');
            exit;
        }

        $size = (int) @filesize($file);
        $mtime = (int) @filemtime($file);
        $hash = md5($mime . '|' . $size . '|' . $mtime);

        @header('Content-Type: ' . $mime);
        @header('Cache-Control: public, max-age=604800, immutable');
        @header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 604800) . ' GMT');
        @header('ETag: W/"' . $hash . '"');
        @header('X-Content-Type-Options: nosniff');
        if ('' !== $tag) {
            @header('X-FM-Thumb: ' . $tag);
        }
        @header_remove('Pragma');
        if ($mtime > 0) {
            @header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        }

        if (self::thumbHitCache($hash, $mtime)) {
            @http_response_code(304);
            @header_remove('Content-Type');
            @header_remove('Content-Length');
            exit;
        }

        @header('Content-Length: ' . (string) $size);
        readfile($file);
        exit;
    }

    private static function thumbHitCache(string $hash, int $mtime): bool
    {
        $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) : '';
        if ('' !== $inm) {
            if ('*' === $inm) {
                return true;
            }
            foreach (explode(',', $inm) as $tag) {
                $tag = trim($tag);
                if ('W/' === substr($tag, 0, 2)) {
                    $tag = substr($tag, 2);
                }
                if (trim($tag, '"') === $hash) {
                    return true;
                }
            }
            return false;
        }

        if ($mtime <= 0) {
            return false;
        }
        $ims = isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
            ? (int) strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;

        return $ims > 0 && $ims >= $mtime;
    }

    /** 体积转成人看的写法：MB 保留两位、KB 保留一位，0 直接给 0 KB */
    private static function sizeText(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }

    private function doThumbClear()
    {
        $n = self::clearThumbFiles(0);
        /* 顺手把「旋转基准副本」的冗余项一起收掉：用户点清理，就是想把 .fmcache 腾干净 */
        $rot = self::sweepRotBackups(true);
        $msg = '已清除 ' . $n . ' 个缩略图缓存';
        if ($rot['deleted'] > 0) {
            $msg .= '，并清理 ' . $rot['deleted'] . ' 份旋转基准副本（释放 '
                . self::sizeText($rot['freed']) . '）';
        } elseif ($rot['kept'] > 0) {
            /* 母版默认保留，「还在用」是常态：要把占用与删除后果说清 */
            $msg .= '；另有 ' . $rot['kept'] . ' 份旋转原图基准（' . self::sizeText($rot['keptBytes'])
                . '）仍在使用中，未删除：它们是「旋转前原图的字节副本」，'
                . '多次旋转都从这里出发，画质才不会逐次累积损失，'
                . '超过 90 天不再旋转的会自动回收';
        }
        $this->json(200, $msg, array('count' => $n, 'rotDeleted' => $rot['deleted'], 'rotFreed' => $rot['freed']));
    }

    private static function clearThumbFiles(int $cid): int
    {
        $dir = __DIR__ . '/.fmcache';
        if (!is_dir($dir)) {
            return 0;
        }
        $pattern = $cid > 0 ? '/thumb-' . $cid . '-w*.webp' : '/thumb-*-w*.webp';
        $n = 0;
        foreach ((array) glob($dir . $pattern) as $f) {
            if (is_file($f) && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    /** 清理旋转母版：只删附件已删、扩展名变了、90 天未再旋转的；整组删光要清 fm_rot 防角度错位 */
    private static function sweepRotBackups(bool $force = false): array
    {
        $res = array('deleted' => 0, 'freed' => 0, 'kept' => 0, 'keptBytes' => 0);
        $dir = __DIR__ . '/.fmcache';
        if (!is_dir($dir) || !is_writable($dir)) {
            return $res;
        }

        /* 节流：列表页每次打开都会走到这里，没必要每次都扫盘；手动清理走 $force */
        $mark = $dir . '/.sweep';
        if (!$force && is_file($mark) && time() - (int) @filemtime($mark) < 21600) {
            return $res;
        }
        @touch($mark);

        $files = (array) glob($dir . '/rot-*');
        if (!$files) {
            return $res;
        }

        $byCid = array();
        foreach ($files as $f) {
            if (!is_file($f) || !preg_match('/^rot-(\d+)\.([A-Za-z0-9]+)$/', basename($f), $m)) {
                continue;
            }
            $cid = (int) $m[1];
            if ($cid > 0) {
                $byCid[$cid][] = array('file' => $f, 'ext' => strtolower($m[2]));
            }
        }
        if (!$byCid) {
            return $res;
        }

        try {
            $db = \Typecho\Db::get();
            $prefix = $db->getPrefix();
            $rows = array();
            foreach (array_chunk(array_keys($byCid), 300) as $chunk) {
                $in = implode(',', array_map('intval', $chunk));
                $got = $db->fetchAll("SELECT cid, text FROM `{$prefix}contents` WHERE type = 'attachment' AND cid IN ({$in})");
                foreach ((array) $got as $r) {
                    $rows[(int) $r['cid']] = (string) $r['text'];
                }
            }
        } catch (\Throwable $e) {
            return $res;
        }

        foreach ($byCid as $cid => $items) {
            $curExt = '';
            $needBase = false;
            $gone = false;
            $stale = false;

            if (!isset($rows[$cid])) {
                $gone = true;
            } else {
                $info = self::parseAttachmentText($rows[$cid]);
                $curExt = strtolower((string) pathinfo((string) $info['path'], PATHINFO_EXTENSION));
                if ('' === $curExt) {
                    $curExt = strtolower((string) $info['ext']);
                }
                $abs = '' !== (string) $info['path'] ? self::resolveAttachmentFile((string) $info['path']) : null;
                if (null === $abs || !is_file($abs)) {
                    $gone = true;
                } else {
                    $raw = self::decodeAttachmentRaw($rows[$cid]);
                    $rot = isset($raw['fm_rot']) && is_array($raw['fm_rot']) ? $raw['fm_rot'] : null;
                    $needBase = self::rotNeedBase($rot);
                    /* 90 天没再旋转视为旧图，连副本带角度一起清；老记录无 ts 用附件 mtime 估 */
                    $last = (is_array($rot) && isset($rot['ts'])) ? (int) $rot['ts'] : (int) @filemtime($abs);
                    if ($last > 0 && time() - $last > 90 * 86400) {
                        $stale = true;
                    }
                }
            }

            $kept = 0;
            foreach ($items as $it) {
                $kill = $stale || self::rotBaseDead($gone, $needBase, $curExt, $it['ext']);
                if (!$kill) {
                    $kept++;
                    $res['kept']++;
                    /* 统计「还在用」的体积：用户看到 .fmcache 占一份原图会想删，先解释清楚 */
                    $res['keptBytes'] += (int) @filesize($it['file']);
                    continue;
                }
                if (!is_file($it['file'])) {
                    continue;
                }
                $sz = (int) @filesize($it['file']);
                if (@unlink($it['file'])) {
                    $res['deleted']++;
                    $res['freed'] += $sz;
                }
            }

            /* 一份都没留下，而记录里还写着累计角度 → 基准已经不存在，角度必须归零 */
            if (0 === $kept && isset($rows[$cid])) {
                self::clearRotMeta($rows[$cid], (int) $cid);
            }
        }

        return $res;
    }

    /** 去掉附件记录里的 fm_rot（基准副本已不存在时调用，避免下次保存按旧角度叠加） */
    private static function clearRotMeta(string $text, int $cid): bool
    {
        $raw = self::decodeAttachmentRaw($text);
        if (!isset($raw['fm_rot'])) {
            return false;
        }
        /* 解析不出基本字段就别写：宁可留着 fm_rot，也不能把附件记录覆盖成一个空对象 */
        if (!isset($raw['path']) && !isset($raw['name'])) {
            return false;
        }
        unset($raw['fm_rot']);
        try {
            $db = \Typecho\Db::get();
            $db->query($db->update('table.contents')
                ->rows(array('text' => self::attachmentText($raw)))
                ->where('cid = ?', $cid));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 母版是否保留：有 fm_rot 记录就留（观感问题可以解释，画质丢了回不来） */
    private static function rotNeedBase($rot): bool
    {
        if (!is_array($rot)) {
            return false;
        }
        $angle = isset($rot['angle']) ? (int) $rot['angle'] : 0;
        $orient = isset($rot['orient']) ? (int) $rot['orient'] : 1;
        /* RESTORE_ON_ZERO=false 时 0° 也是重编产物，母版必须留，只靠 90 天回收 */
        if (!self::RESTORE_ON_ZERO) {
            return true;
        }
        return 0 !== ($angle % 360) || 1 !== $orient;
    }

    /** 单份母版是否可删（纯函数）：附件消失 / 扩展名变了 / 超期 */
    private static function rotBaseDead(bool $gone, bool $needBase, string $curExt, string $fileExt): bool
    {
        if ($gone || !$needBase) {
            return true;
        }
        return '' !== $curExt && $fileExt !== $curExt;
    }

    private function doImageHostTest()
    {
        if ('s3' === (string) $this->request->get('type', 'lsky')) {
            $this->doImageHostTestS3();
        }
        $api = rtrim(trim((string) $this->request->get('api')), '/');
        $token = trim((string) $this->request->get('token'));
        if ('' === $api || '' === $token) {
            $this->json(400, '请先填写接口地址和访问令牌');
        }
        if (!preg_match('#^https?://#i', $api)) {
            $this->json(400, '接口地址需以 http(s):// 开头');
        }

        $profile = $this->imageHostRequest($api . '/profile', $token);
        if (empty($profile['status'])) {
            $msg = isset($profile['message']) && '' !== (string) $profile['message']
                ? (string) $profile['message'] : '令牌无效或接口不可达';
            $this->json(400, '连接失败：' . $msg);
        }

        $strategies = array();
        $st = $this->imageHostRequest($api . '/strategies', $token);
        if (!empty($st['status']) && isset($st['data']['strategies']) && is_array($st['data']['strategies'])) {
            foreach ($st['data']['strategies'] as $s) {
                if (isset($s['id'])) {
                    $strategies[] = array('id' => (int) $s['id'], 'name' => (string) (isset($s['name']) ? $s['name'] : ''));
                }
            }
        }

        $d = isset($profile['data']) && is_array($profile['data']) ? $profile['data'] : array();
        $this->json(200, '连接成功', array(
            'username'   => (string) (isset($d['username']) ? $d['username'] : ''),
            'name'       => (string) (isset($d['name']) ? $d['name'] : ''),
            'capacity'   => (float) (isset($d['capacity']) ? $d['capacity'] : 0),
            'size'       => (float) (isset($d['size']) ? $d['size'] : 0),
            'imageNum'   => (int) (isset($d['image_num']) ? $d['image_num'] : 0),
            'strategies' => $strategies,
        ));
    }

    private function imageHostRequest(string $url, string $token)
    {
        $headers = array('Accept: application/json', 'Authorization: Bearer ' . $token);
        $body = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ));
            $body = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if (false === $body) {
                return array('status' => false, 'message' => '请求失败：' . $err);
            }
        } else {
            $ctx = stream_context_create(array('http' => array(
                'method'  => 'GET',
                'timeout' => 15,
                'header'  => implode("\r\n", $headers),
            )));
            $body = @file_get_contents($url, false, $ctx);
            if (false === $body) {
                return array('status' => false, 'message' => '请求失败：请检查接口地址或服务器网络');
            }
        }
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : array('status' => false, 'message' => '接口响应不是有效 JSON');
    }

    private function s3Conf(array $ih)
    {
        $endpoint = rtrim(trim((string) (isset($ih['s3Endpoint']) ? $ih['s3Endpoint'] : '')), '/');
        $bucket = trim((string) (isset($ih['s3Bucket']) ? $ih['s3Bucket'] : ''));
        $ak = trim((string) (isset($ih['s3AccessKey']) ? $ih['s3AccessKey'] : ''));
        $sk = trim((string) (isset($ih['s3SecretKey']) ? $ih['s3SecretKey'] : ''));
        if ('' === $endpoint || '' === $bucket || '' === $ak || '' === $sk) {
            return null;
        }
        if (!preg_match('#^https?://#i', $endpoint)) {
            return null;
        }
        $u = parse_url($endpoint);
        if (!isset($u['host'])) {
            return null;
        }
        $host = $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $region = trim((string) (isset($ih['s3Region']) ? $ih['s3Region'] : ''));
        if ('' === $region) {
            $region = 'us-east-1';
        }

        $prefix = trim((string) (isset($ih['s3Prefix']) ? $ih['s3Prefix'] : ''), "/ \t\r\n");
        $prefix = ('' === $prefix) ? '' : $prefix . '/';
        $baseUrl = rtrim(trim((string) (isset($ih['s3BaseUrl']) ? $ih['s3BaseUrl'] : '')), '/');
        return array($endpoint, $host, $region, $bucket, $ak, $sk, $prefix, $baseUrl);
    }

    private static function s3UriEncode(string $str, bool $keepSlash = true): string
    {
        $out = rawurlencode($str);
        return $keepSlash ? str_replace('%2F', '/', $out) : $out;
    }

    private static function s3AuthHeader(string $method, string $path, string $canonicalQuery, array $headers,
        string $payloadHash, string $region, string $ak, string $sk, string $amzDate, string $dateStamp): string
    {
        ksort($headers);
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalHeaders = '';
        foreach ($headers as $hk => $hv) {
            $canonicalHeaders .= $hk . ':' . trim((string) $hv) . "\n";
        }
        $canonicalRequest = implode("\n", array(
            $method, $path, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash,
        ));
        $scope = $dateStamp . '/' . $region . '/s3/aws4_request';
        $stringToSign = 'AWS4-HMAC-SHA256' . "\n" . $amzDate . "\n" . $scope . "\n"
            . hash('sha256', $canonicalRequest);
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $sk, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        return 'AWS4-HMAC-SHA256 Credential=' . $ak . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
    }

    private function s3Request(array $conf, string $method, string $key, array $query, string $payload, string $contentType = '')
    {
        if (!function_exists('curl_init')) {
            return array('code' => 0, 'body' => '', 'err' => '服务器缺少 curl 扩展');
        }
        list($endpoint, $host, $region, $bucket, $ak, $sk) = $conf;

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $payload);

        $encKey = ('' !== $key) ? self::s3UriEncode($key, true) : '';
        $path = '/' . $bucket . ('' !== $encKey ? '/' . $encKey : '');

        ksort($query);
        $qPairs = array();
        foreach ($query as $qk => $qv) {
            $qPairs[] = self::s3UriEncode((string) $qk) . '=' . self::s3UriEncode((string) $qv);
        }
        $canonicalQuery = implode('&', $qPairs);

        $headers = array(
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        );
        if ('' !== $contentType) {
            $headers['content-type'] = $contentType;
        }

        $auth = self::s3AuthHeader($method, $path, $canonicalQuery, $headers, $payloadHash, $region, $ak, $sk, $amzDate, $dateStamp);

        $url = $endpoint . $path . ('' !== $canonicalQuery ? '?' . $canonicalQuery : '');
        $hdrs = array(
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
            'Authorization: ' . $auth,
        );
        if ('' !== $contentType) {
            $hdrs[] = 'Content-Type: ' . $contentType;
        }
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => ('PUT' === $method) ? 120 : 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $hdrs,
        );
        if ('PUT' === $method && '' !== $payload) {
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (false === $body) {
            return array('code' => 0, 'body' => '', 'err' => '' !== $err ? $err : '网络请求失败');
        }
        return array('code' => $code, 'body' => (string) $body, 'err' => '');
    }

    private static function s3ErrMessage(string $body): string
    {
        if (preg_match('#<Message>(.*?)</Message>#s', $body, $m)) {
            $msg = trim(html_entity_decode(strip_tags((string) $m[1]), ENT_QUOTES, 'UTF-8'));
            if ('' !== $msg) {
                return $msg;
            }
        }
        return '';
    }

    private function doImageHostTestS3()
    {
        $ih = array(
            's3Endpoint'  => (string) $this->request->get('endpoint'),
            's3Region'    => (string) $this->request->get('region'),
            's3Bucket'    => (string) $this->request->get('bucket'),
            's3AccessKey' => (string) $this->request->get('ak'),
            's3SecretKey' => (string) $this->request->get('sk'),
        );
        $conf = $this->s3Conf($ih);
        if (null === $conf) {
            $this->json(400, '请先填写完整的 Endpoint、Bucket、AccessKey ID 和 AccessKey Secret');
        }
        $res = $this->s3Request($conf, 'GET', '', array('list-type' => '2', 'max-keys' => '1'), '');
        if (0 === $res['code']) {
            $this->json(400, '连接失败：' . (string) $res['err']);
        }
        if ($res['code'] >= 200 && $res['code'] < 300) {
            $this->json(200, '连接成功', array(
                'bucket' => $conf[3],
                'region' => $conf[2],
            ));
        }
        if (403 === $res['code']) {
            $this->json(400, 'AccessKey/SecretKey 校验失败，或无该 Bucket 的访问权限');
        }
        if (404 === $res['code']) {
            $this->json(400, 'Bucket 不存在，或 Endpoint/区域不正确');
        }
        $msg = 'Bucket 访问失败（HTTP ' . $res['code'] . '）';
        $extra = self::s3ErrMessage((string) $res['body']);
        $this->json(400, '' !== $extra ? $msg . '：' . $extra : $msg);
    }

    private function imageHostDeleteKey(string $key): bool
    {
        if ('' === $key || !function_exists('curl_init')) {
            return false;
        }
        try {
            $up = \FileManager_Plugin::getUserPrefs((int) \Widget\User::alloc()->uid);
        } catch (\Throwable $e) {
            return false;
        }
        $ih = (isset($up['imageHost']) && is_array($up['imageHost'])) ? $up['imageHost'] : null;
        if (!is_array($ih) || '1' !== (string) (isset($ih['enabled']) ? $ih['enabled'] : '0')) {
            return false;
        }

        if ('s3' === (string) (isset($ih['type']) ? $ih['type'] : '')) {
            $conf = $this->s3Conf($ih);
            if (null === $conf) {
                return false;
            }
            $res = $this->s3Request($conf, 'DELETE', $key, array(), '');
            return $res['code'] >= 200 && $res['code'] < 300;
        }
        $api = rtrim(trim((string) (isset($ih['api']) ? $ih['api'] : '')), '/');
        $token = trim((string) (isset($ih['token']) ? $ih['token'] : ''));
        if ('' === $api || '' === $token) {
            return false;
        }
        $ch = curl_init($api . '/images/' . rawurlencode($key));
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => array('Accept: application/json', 'Authorization: Bearer ' . $token),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return false !== $body && $code >= 200 && $code < 300;
    }

    private function doImageHostUpload()
    {

        $uid = (int) \Widget\User::alloc()->uid;
        $up = \FileManager_Plugin::getUserPrefs($uid);
        $ih = (isset($up['imageHost']) && is_array($up['imageHost'])) ? $up['imageHost'] : null;
        if (!is_array($ih) || '1' !== (string) (isset($ih['enabled']) ? $ih['enabled'] : '0')) {
            $this->json(400, '图床上传未启用：请先在「图床/对象存储」中配置并启用');
        }
        $ihType = (string) (isset($ih['type']) ? $ih['type'] : 'lsky');
        if ('s3' === $ihType) {
            if (null === $this->s3Conf($ih)) {
                $this->json(400, 'S3 配置不完整：请填写 Endpoint、Bucket、AccessKey ID 和 AccessKey Secret');
            }
        } else {
            $api = rtrim(trim((string) (isset($ih['api']) ? $ih['api'] : '')), '/');
            $token = trim((string) (isset($ih['token']) ? $ih['token'] : ''));
            if ('' === $api || '' === $token) {
                $this->json(400, '图床配置不完整：请填写接口地址和访问令牌');
            }
        }

        $cid = (int) $this->request->get('cid', 0);
        if ($cid <= 0) {
            $this->json(400, '参数缺少附件 cid');
        }

        $delLocal = '1' === (string) $this->request->get('delLocal', '0');

        $useUrl = ('site' === (string) $this->request->get('useUrl', '')) ? 'site' : 'ih';
        $db = $this->db();
        $row = $db->fetchRow($db->select('text')->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
        if (!$row) {
            $this->json(404, '附件不存在或已被删除');
        }
        $info = self::parseAttachmentText((string) $row['text']);
        $abs = '' !== $info['path'] ? self::resolveAttachmentFile($info['path']) : null;
        if (null === $abs || !is_file($abs) || !is_readable($abs)) {
            $this->json(400, '本站物理文件缺失，无法转发到图床');
        }
        $ext = strtolower('' !== $info['ext'] ? $info['ext'] : (string) pathinfo($abs, PATHINFO_EXTENSION));

        if ('s3' !== $ihType && !in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'), true)) {
            $this->json(400, '兰空图床仅支持上传图片文件，非图片文件上传到本站');
        }

        $dims = @getimagesize($abs);
        $mime = $dims ? (string) $dims['mime'] : '';
        if ('' === $mime || false === strpos($mime, '/')) {
            $mimeMap = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'webp' => 'image/webp', 'gif' => 'image/gif', 'bmp' => 'image/bmp');
            $mime = isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'application/octet-stream';
            if ('application/octet-stream' === $mime && function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) {
                    $fm = finfo_file($fi, $abs);
                    finfo_close($fi);
                    if (is_string($fm) && false !== strpos($fm, '/')) {
                        $mime = $fm;
                    }
                }
            }
        }

        $url = '';
        $keyOut = '';
        $nameOut = '';
        $sizeOut = 0;
        if ('s3' === $ihType) {
            $conf = $this->s3Conf($ih);
            if (null === $conf) {
                $this->json(400, 'S3 配置不完整：请填写 Endpoint、Bucket、AccessKey ID 和 AccessKey Secret');
            }

            $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) pathinfo($abs, PATHINFO_FILENAME)), '-.');
            if ('' === $name) {
                $name = 'img';
            }
            if (strlen($name) > 60) {
                $name = substr($name, 0, 60);
            }
            $key = $conf[6] . date('Y/m/d') . '/' . $name . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;

            $isImgFile = in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'), true);
            $payload = (string) @file_get_contents($abs);
            if ('' === $payload) {
                $this->json(500, '读取本站文件内容失败，无法上传到对象存储');
            }
            $res = $this->s3Request($conf, 'PUT', $key, array(), $payload, $mime);
            if (0 === $res['code']) {
                $this->json(500, 'S3 上传请求失败：' . (string) $res['err']);
            }
            if ($res['code'] < 200 || $res['code'] >= 300) {
                $msg = 'S3 上传失败（HTTP ' . $res['code'] . '）';
                $extra = self::s3ErrMessage((string) $res['body']);
                $this->json(400, '' !== $extra ? $msg . '：' . $extra : $msg);
            }

            $url = ('' !== $conf[7] ? $conf[7] : $conf[0] . '/' . $conf[3]) . '/' . self::s3UriEncode($key, true);
            $keyOut = $key;
            $nameOut = basename($key);
            $sizeOut = strlen($payload);
        } else {
            if (!function_exists('curl_init')) {
                $this->json(500, '服务器缺少 curl 扩展，无法转发到图床');
            }
            $isImgFile = true;
            $post = array(
                'file' => new \CURLFile($abs, $mime, basename($abs)),
            );
            $strategyId = (int) (isset($ih['strategyId']) ? $ih['strategyId'] : 0);
            $albumId = (int) (isset($ih['albumId']) ? $ih['albumId'] : 0);
            if ($strategyId > 0) { $post['strategy_id'] = $strategyId; }
            if ($albumId > 0) { $post['album_id'] = $albumId; }
            $post['permission'] = (isset($ih['permission']) && '0' === (string) $ih['permission']) ? '0' : '1';

            $ch = curl_init($api . '/upload');
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => array('Accept: application/json', 'Authorization: Bearer ' . $token),
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $post,
            ));
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (false === $body) {
                $this->json(500, '图床请求失败：' . $err);
            }
            $data = json_decode((string) $body, true);
            if (!is_array($data)) {
                $this->json(500, '图床响应异常（HTTP ' . $httpCode . '），请稍后重试');
            }
            if (empty($data['status'])) {
                $msg = isset($data['message']) && '' !== (string) $data['message'] ? (string) $data['message'] : '图床拒绝上传';
                $this->json(400, '图床上传失败：' . $msg);
            }

            $d = isset($data['data']) && is_array($data['data']) ? $data['data'] : array();
            $links = isset($d['links']) && is_array($d['links']) ? $d['links'] : array();
            $url = (string) (isset($links['url']) ? $links['url'] : '');
            if ('' === $url) {
                $this->json(500, '图床未返回图片链接');
            }
            $keyOut = (string) (isset($d['key']) ? $d['key'] : '');
            $nameOut = (string) (isset($d['origin_name']) ? $d['origin_name'] : (isset($d['name']) ? $d['name'] : ''));
            $sizeOut = (float) (isset($d['size']) ? $d['size'] : 0) * 1024;
        }

        $raw = self::decodeAttachmentRaw((string) $row['text']);
        if (is_array($raw)) {
            $raw['fm_ih'] = array('key' => $keyOut, 'url' => $url, 'use' => $useUrl,
                'st' => ('s3' === $ihType ? 's3' : 'lsky'));
            try {
                $db->query($db->update('table.contents')
                    ->rows(array('text' => self::attachmentText($raw)))
                    ->where('cid = ?', $cid));
            } catch (\Throwable $e) {

            }
        }

        if ($delLocal) {
            self::clearThumbFiles($cid);
            if (null !== $abs) {
                @unlink($abs);
            }
        }

        $this->json(200, 's3' === $ihType
            ? ($delLocal ? '已上传到对象存储，本站原图已删除' : '已上传到对象存储')
            : ($delLocal ? '已上传到图床，本站原图已删除' : '已上传到图床'), array(
            'url'      => $url,
            'name'     => $nameOut,
            'size'     => $sizeOut,
            'markdown' => $isImgFile ? '![](' . $url . ')' : '[' . $nameOut . '](' . $url . ')',
            'html'     => $isImgFile ? '<img src="' . $url . '" alt="">' : '<a href="' . $url . '">' . $nameOut . '</a>',
            'key'      => $keyOut,
            'cid'      => $cid,
            'isIh'     => true,
            'ihSt'     => ('s3' === $ihType ? 's3' : 'lsky'),
        ));
    }

    private function doMigrateList()
    {
        $uid = (int) \Widget\User::alloc()->uid;
        $up = \FileManager_Plugin::getUserPrefs($uid);
        $ih = (isset($up['imageHost']) && is_array($up['imageHost'])) ? $up['imageHost'] : null;
        $ihType = (is_array($ih) && '1' === (string) (isset($ih['enabled']) ? $ih['enabled'] : '0'))
            ? (string) (isset($ih['type']) ? $ih['type'] : 'lsky') : '';
        if ('' === $ihType) {
            $this->json(400, '请先在「图床/对象存储」中配置并启用');
        }
        $s3 = ('s3' === $ihType);
        $db = $this->db();
        $rows = $db->fetchAll($db->select('cid', 'title', 'text')->from('table.contents')
            ->where('type = ?', 'attachment'));
        $map = array();
        foreach ($db->fetchAll($db->select('cid', 'size', 'ext')->from('table.filemanager_map')) as $m) {
            $map[(int) $m['cid']] = $m;
        }
        $files = array();
        foreach ($rows as $row) {
            $info = self::parseAttachmentText((string) $row['text']);
            if (is_array($info['ih'])) {
                continue;
            }
            $ext = strtolower((string) $info['ext']);
            if ('' === $ext && isset($map[(int) $row['cid']])) {
                $ext = strtolower((string) $map[(int) $row['cid']]['ext']);
            }
            if (!$s3 && !in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'), true)) {
                continue;
            }
            $abs = '' !== $info['path'] ? self::resolveAttachmentFile($info['path']) : null;
            $exists = (null !== $abs && is_file($abs));
            $size = isset($map[(int) $row['cid']]) ? (int) $map[(int) $row['cid']]['size'] : 0;
            if (!$size && $exists) {
                $size = (int) @filesize($abs);
            }
            $files[] = array(
                'cid'     => (int) $row['cid'],
                'title'   => (string) $row['title'],
                'name'    => '' !== $info['name'] ? $info['name'] : (string) $row['title'],
                'path'    => (string) $info['path'],
                'ext'     => $ext,
                'size'    => $size,
                'missing' => $exists ? false : true,
            );
        }
        $this->json(200, '', array(
            'type'  => $ihType,
            'files' => $files,
            'total' => count($files),
        ));
    }

    private function doExif()
    {
        $cid = (int) $this->request->get('cid');
        $db = $this->db();
        $row = $db->fetchRow($db->select('text')->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
        if (!$row) {
            $this->json(404, '文件不存在或已被删除');
        }

        $info = self::parseAttachmentText((string) $row['text']);
        $abs = '' !== $info['path'] ? self::resolveAttachmentFile($info['path']) : null;
        if (null === $abs || !is_file($abs)) {
            $this->json(404, '物理文件缺失，无法读取 EXIF');
        }

        $direct = array('jpg', 'jpeg', 'jpe', 'tiff', 'tif');
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $source = '当前文件';
        $canRead = function_exists('exif_read_data');
        $ex = null;

        if ($canRead && in_array($ext, $direct, true)) {
            $ex = @exif_read_data($abs);

            if (!is_array($ex)) {
                $ex = null;
            }
        }

        if (null === $ex && $canRead) {
            foreach (FileManager_ImageTool::backupOriginalCandidates($info['path']) as $cand) {
                $cext = strtolower(pathinfo($cand, PATHINFO_EXTENSION));

                if (!in_array($cext, $direct, true)) {
                    continue;
                }

                $ex = @exif_read_data($cand);

                if (is_array($ex)) {
                    $source = '备份原图';
                    break;
                }

                $ex = null;
            }
        }

        if (null === $ex && !empty($info['exif']) && is_array($info['exif'])) {
            $ex = $info['exif'];
            $source = '上传时抓取';
        }

        if (null === $ex) {
            if (!$canRead) {
                $this->json(200, '', array('items' => array(), 'source' => '',
                    'note' => '服务器未启用 PHP exif 扩展，无法读取'));
            }

            $this->json(200, '', array('items' => array(), 'source' => '',
                'note' => '该格式不携带 EXIF，也未找到备份原图或上传时抓取的参数（转换后拍摄参数已不可恢复）'));
        }

        $items = $this->fmBuildExifItems($ex);

        $dim = @getimagesize($abs);
        if (is_array($dim) && $dim[0] > 0 && $dim[1] > 0) {
            $real = $dim[0] . ' × ' . $dim[1];
            $found = false;
            foreach ($items as $i => $it) {
                if ('尺寸' === $it[0]) {
                    $items[$i][1] = $real;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $items[] = array('尺寸', $real);
            }
        }

        $this->json(200, '', array('items' => $items, 'source' => $source, 'note' => ''));
    }

    private function fmBuildExifItems(array $ex): array
    {
        $items = array();

        $make = trim((string) (isset($ex['Make']) ? $ex['Make'] : ''));
        $model = trim((string) (isset($ex['Model']) ? $ex['Model'] : ''));
        if ('' !== $make || '' !== $model) {
            $items[] = array('机身', trim($make . ' ' . $model));
        }

        $lens = trim((string) (isset($ex['LensModel']) ? $ex['LensModel'] : ''));
        if ('' === $lens && isset($ex['UndefinedTag:0x0095'])) {
            $lens = trim((string) $ex['UndefinedTag:0x0095']);
        }
        if ('' !== $lens) {
            $items[] = array('镜头', $lens);
        }

        if (isset($ex['ExposureTime'])) {
            $v = $this->fmExifNum($ex['ExposureTime']);
            if (null !== $v && $v > 0) {
                $items[] = array('快门', $v < 1 ? '1/' . (string) round(1 / $v) . ' s' : round($v, 1) . ' s');
            }
        }
        if (isset($ex['FNumber'])) {
            $v = $this->fmExifNum($ex['FNumber']);
            if (null !== $v && $v > 0) {
                $items[] = array('光圈', 'f/' . round($v, 1));
            }
        }
        if (isset($ex['ISOSpeedRatings']) && (int) $ex['ISOSpeedRatings'] > 0) {
            $items[] = array('ISO', (string) (int) $ex['ISOSpeedRatings']);
        }

        if (isset($ex['FocalLength'])) {
            $v = $this->fmExifNum($ex['FocalLength']);
            if (null !== $v && $v > 0) {
                $items[] = array('焦距', round($v) . ' mm');
            }
        }
        if (isset($ex['FocalLengthIn35mmFilm']) && (int) $ex['FocalLengthIn35mmFilm'] > 0) {
            $items[] = array('等效焦距', (int) $ex['FocalLengthIn35mmFilm'] . ' mm');
        }

        if (isset($ex['ExposureBiasValue'])) {
            $v = $this->fmExifNum($ex['ExposureBiasValue']);
            if (null !== $v && 0.0 !== $v) {
                $items[] = array('曝光补偿', ($v > 0 ? '+' : '') . round($v, 2) . ' EV');
            }
        }

        $programs = array(1 => '手动', 2 => '程序自动', 3 => '光圈优先', 4 => '快门优先',
            5 => '创意（景深优先）', 6 => '动作（高速快门优先）', 7 => '人像', 8 => '风景', 9 => 'B 门');
        if (isset($ex['ExposureProgram']) && isset($programs[(int) $ex['ExposureProgram']])) {
            $items[] = array('曝光模式', $programs[(int) $ex['ExposureProgram']]);
        }

        $meters = array(1 => '平均测光', 2 => '中央重点平均', 3 => '点测光', 4 => '多点测光',
            5 => '评价测光', 6 => '局部测光');
        if (isset($ex['MeteringMode']) && isset($meters[(int) $ex['MeteringMode']])) {
            $items[] = array('测光', $meters[(int) $ex['MeteringMode']]);
        }

        if (isset($ex['Flash'])) {
            $items[] = array('闪光灯', ((int) $ex['Flash'] & 1) ? '已闪光' : '未闪光');
        }

        $wb = array(0 => '自动白平衡', 1 => '手动白平衡');
        if (isset($ex['WhiteBalance']) && isset($wb[(int) $ex['WhiteBalance']])) {
            $items[] = array('白平衡', $wb[(int) $ex['WhiteBalance']]);
        }

        if (!empty($ex['DateTimeOriginal'])) {
            $dt = trim((string) $ex['DateTimeOriginal']);

            if (preg_match('#^(\d{4}):(\d{2}):(\d{2})(.*)$#', $dt, $m)) {
                $dt = $m[1] . '-' . $m[2] . '-' . $m[3] . $m[4];
            }
            $items[] = array('拍摄时间', $dt);
        }

        if (isset($ex['COMPUTED']['Width'], $ex['COMPUTED']['Height'])) {
            $items[] = array('尺寸', $ex['COMPUTED']['Width'] . ' × ' . $ex['COMPUTED']['Height']);
        }

        if (!empty($ex['Software'])) {
            $items[] = array('软件', trim((string) $ex['Software']));
        }

        $gps = $this->fmExifGps($ex);
        if (null !== $gps) {
            $items[] = array('GPS', $gps);
        }

        return $items;
    }

    private function fmExifNum($v)
    {
        if (is_array($v)) {
            if (isset($v['num'], $v['den'])) {
                $v = $v['num'] . '/' . $v['den'];
            } else {
                $v = implode('/', $v);
            }
        }
        $v = trim((string) $v);
        if ('' === $v) {
            return null;
        }
        if (preg_match('#^(-?\d+(?:\.\d+)?)/(-?\d+(?:\.\d+)?)$#', $v, $m)) {
            $den = (float) $m[2];
            return 0.0 === $den ? null : (float) $m[1] / $den;
        }

        return is_numeric($v) ? (float) $v : null;
    }

    private function fmExifGps(array $ex)
    {
        if (empty($ex['GPSLatitude']) || empty($ex['GPSLongitude'])) {
            return null;
        }
        $lat = $this->fmExifCoord($ex['GPSLatitude']);
        $lon = $this->fmExifCoord($ex['GPSLongitude']);
        if (null === $lat || null === $lon) {
            return null;
        }
        if ((isset($ex['GPSLatitudeRef']) ? (string) $ex['GPSLatitudeRef'] : 'N') === 'S') {
            $lat = -$lat;
        }
        if ((isset($ex['GPSLongitudeRef']) ? (string) $ex['GPSLongitudeRef'] : 'E') === 'W') {
            $lon = -$lon;
        }

        return sprintf('%.6f, %.6f', $lat, $lon);
    }

    private function fmExifCoord($v)
    {
        if (!is_array($v) || count($v) < 3) {
            return null;
        }
        $d = $this->fmExifNum($v[0]);
        $m = $this->fmExifNum($v[1]);
        $s = $this->fmExifNum($v[2]);
        if (null === $d || $d < 0) {
            return null;
        }

        return $d + ($m ?? 0.0) / 60 + ($s ?? 0.0) / 3600;
    }

    private function doBatchWebpScan()
    {
        $db = $this->db();
        $scope = (string) $this->request->get('scope', 'all');
        $to = self::convTarget((string) $this->request->get('to', 'webp'));
        $srcExts = self::convSources($to);

        if ('1' === (string) $this->request->get('wm', '0')) {
            $srcExts = array_values(array_unique(array_merge($srcExts, self::sameFormatExts($to))));
        }

        $select = $db->select('table.contents.cid', 'table.contents.title', 'table.filemanager_map.size')
            ->from('table.contents')
            ->join('table.filemanager_map', 'table.contents.cid = table.filemanager_map.cid', \Typecho\Db::INNER_JOIN)
            ->where('table.contents.type = ?', 'attachment');

        if ('selected' === $scope) {
            $cids = array();
            foreach (explode(',', (string) $this->request->get('cids', '')) as $v) {
                $v = (int) trim($v);
                if ($v > 0) { $cids[] = $v; }
            }
            if (!empty($cids)) {

                $ph = implode(',', array_fill(0, count($cids), '?'));
                $select->where('table.filemanager_map.cid IN (' . $ph . ')', ...$cids);
            } else {
                $this->json(200, '', array('items' => array(), 'total' => 0));
            }
        } elseif ('folder' === $scope) {
            $fid = (int) $this->request->get('fid', 0);

            if ($fid > 0) {
                $select->where('table.filemanager_map.fid = ?', $fid);
            } elseif (-1 === $fid) {
                $select->where('table.filemanager_map.fid = ?', 0);
            }
        }

        $this->extIn($select, $srcExts);

        $rows = $db->fetchAll($select);
        $items = array();
        $total = 0;
        foreach ($rows as $row) {
            $items[] = array(
                'cid'  => (int) $row['cid'],
                'name' => (string) $row['title'],
                'size' => (int) $row['size'],
            );
            $total += (int) $row['size'];
        }

        $cfg = FileManager_ImageTool::settings();

        $this->json(200, '', array('items' => $items, 'total' => $total, 'to' => $to,
            'cfg' => array(
                'quality'       => (string) (isset($cfg['quality']) ? $cfg['quality'] : ''),
                'max_width'     => (string) (isset($cfg['max_width']) ? $cfg['max_width'] : ''),
                'max_height'    => (string) (isset($cfg['max_height']) ? $cfg['max_height'] : ''),
                'size_policy'   => (string) (isset($cfg['size_policy']) ? $cfg['size_policy'] : 'keep'),
                'origin_policy' => (string) (isset($cfg['origin_policy']) ? $cfg['origin_policy'] : 'delete'),
            )));
    }

    private static function convTarget(string $to): string
    {
        return in_array($to, array('webp', 'jpg', 'png'), true) ? $to : 'webp';
    }

    private static function convSources(string $to): array
    {
        switch ($to) {
            case 'jpg':
                return array('webp', 'png', 'bmp');
            case 'png':
                return array('jpg', 'jpeg', 'webp', 'bmp');
            default:
                return array('jpg', 'jpeg', 'png', 'bmp');
        }
    }

    private static function sameFormatExts(string $to): array
    {
        return 'jpg' === $to ? array('jpg', 'jpeg') : array($to);
    }

    private function doBatchWebpOne()
    {
        $cid = (int) $this->request->get('cid');
        $wm = (string) $this->request->get('wm', '0') === '1';
        $refs = (string) $this->request->get('refs', '1') === '1';
        $to = self::convTarget((string) $this->request->get('to', 'webp'));

        $quality = (int) $this->request->get('quality', 0);
        $maxw = (int) $this->request->get('maxw', 0);
        $maxh = (int) $this->request->get('maxh', 0);

        $policy = $wm ? 'continue'
            : ((string) $this->request->get('policy', 'keep') === 'continue' ? 'continue' : 'keep');
        $origin = (string) $this->request->get('origin', '');

        $overrides = array(
            'imgEnabled'  => '1',
            'watermark'   => $wm ? '1' : '0',
            'size_policy' => $policy,
            'to_format'   => $to,
        );
        if ($quality >= 1 && $quality <= 100) {
            $overrides['quality'] = (string) $quality;
        }
        if ($maxw > 0) {
            $overrides['max_width'] = (string) $maxw;
        }
        if ($maxh > 0) {
            $overrides['max_height'] = (string) $maxh;
        }
        if ('backup' === $origin || 'delete' === $origin) {
            $overrides['origin_policy'] = $origin;
        }

        if ($wm && !\FileManager_Converter::watermarkReady(
            array_merge(\FileManager_ImageTool::settings(), $overrides)
        )) {
            $this->json(200, '', array('status' => 'skip',
                'reason' => '水印未生效：请先在插件设置中配置水印（文字并上传字体，或上传水印图片）'));
        }

        $db = $this->db();
        $row = $db->fetchRow($db->select('text', 'created')->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));

        if (!$row) {
            $this->json(200, '', array('status' => 'skip', 'reason' => '记录不存在'));
        }

        $info = self::parseAttachmentText((string) $row['text']);
        $oldExt = strtolower($info['ext']);

        $srcExts = self::convSources($to);
        if ($wm) {
            $srcExts = array_values(array_unique(array_merge($srcExts, self::sameFormatExts($to))));
        }
        if (!in_array($oldExt, $srcExts, true)) {
            $this->json(200, '', array('status' => 'skip', 'reason' => '格式不支持转换'));
        }

        $abs = '' !== $info['path'] ? self::resolveAttachmentFile($info['path']) : null;

        if (null === $abs || !is_file($abs)) {
            $this->json(200, '', array('status' => 'skip', 'reason' => '物理文件缺失'));
        }

        clearstatcache(true, $abs);
        $oldSize = (int) @filesize($abs);

        /* 单张转换可能几十秒：放宽到 180s，仍受 FPM 层限制兜底 */
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        /* 大图转码按像素吃内存，系数比 webp 高（PNG 无损多一份缓冲） */
        self::ensureImageMemory($abs, 'png' === $to ? 4.8 : 3.4);

        $result = \FileManager_ImageTool::processStored(array(
            'name' => (string) $info['name'],
            'path' => (string) $info['path'],
            'size' => $oldSize,
            'type' => $oldExt,
            'mime' => (string) $info['mime'],
        ), $abs, $overrides);

        clearstatcache(true, $abs);
        $newExt = strtolower((string) (isset($result['type']) ? $result['type'] : $oldExt));
        $newSize = (int) (isset($result['size']) ? $result['size'] : 0);

        if ($to !== $newExt) {
            if ($newSize > 0 && $newSize !== $oldSize) {

                $raw = self::decodeAttachmentRaw((string) $row['text']);
                $raw['size'] = $newSize;
                $db->query($db->update('table.contents')
                    ->rows(array('text' => self::attachmentText($raw)))
                    ->where('cid = ?', $cid));
                $db->query($db->update('table.filemanager_map')
                    ->rows(array('size' => $newSize))
                    ->where('cid = ?', $cid));

                $this->json(200, '', array('status' => 'ok', 'kind' => 'reencode',
                    'oldSize' => $oldSize, 'newSize' => $newSize,
                    'saved' => max(0, $oldSize - $newSize), 'refs' => 0,
                    'note' => '目标格式未更小，已按原格式加水印'));
            }

            $this->json(200, '', array('status' => 'skip', 'reason' => '转换后未变小或格式不支持'));
        }

        $raw = self::decodeAttachmentRaw((string) $row['text']);
        foreach (array('name', 'path', 'size', 'type', 'mime') as $k) {
            if (isset($result[$k])) {
                $raw[$k] = $result[$k];
            }
        }
        if (isset($result['fm_exif'])) {
            $raw['fm_exif'] = $result['fm_exif'];
        }

        if ('1' === (string) $this->request->get('renameDt', '0')) {
            $convAbs = self::resolveAttachmentFile((string) $raw['path']);
            if (null !== $convAbs && is_file($convAbs)) {
                $extN = strtolower((string) pathinfo((string) $raw['path'], PATHINFO_EXTENSION));
                $dirN = (string) pathinfo((string) $raw['path'], PATHINFO_DIRNAME);
                $relDirN = ('.' === $dirN || '' === $dirN) ? '' : $dirN . '/';
                $stampN = date('YmdHis', (int) (isset($row['created']) ? $row['created'] : time()));

                $baseN = $stampN;
                $seqN = 0;
                while (is_file(dirname($convAbs) . '/' . $baseN . '.' . $extN)) {
                    $seqN++;
                    $baseN = $stampN . '-' . $seqN;
                }

                $newAbsN = dirname($convAbs) . '/' . $baseN . '.' . $extN;
                if (@rename($convAbs, $newAbsN)) {
                    @chmod($newAbsN, 0644);
                    clearstatcache(true, $newAbsN);
                    $raw['name'] = $baseN . '.' . $extN;
                    $raw['path'] = $relDirN . $baseN . '.' . $extN;
                }
            }
        }

        $db->query($db->update('table.contents')
            ->rows(array('text' => self::attachmentText($raw)))
            ->where('cid = ?', $cid));

        $mapRow = $db->fetchRow($db->select('cid')->from('table.filemanager_map')
            ->where('cid = ?', $cid)->limit(1));

        if ($mapRow) {
            $db->query($db->update('table.filemanager_map')
                ->rows(array('size' => $newSize, 'ext' => $to))
                ->where('cid = ?', $cid));
        } else {
            $db->query($db->insert('table.filemanager_map')->rows(array(
                'cid'  => $cid,
                'fid'  => 0,
                'size' => $newSize,
                'ext'  => $to,
            )));
        }

        $refCount = 0;
        if ($refs) {
            $refCount = $this->batchWebpReplaceRefs((string) $info['path'], (string) $raw['path']);
        }

        self::clearThumbFiles($cid);

        $this->json(200, '', array('status' => 'ok', 'kind' => $to,
            'oldSize' => $oldSize, 'newSize' => $newSize,
            'saved' => max(0, $oldSize - $newSize), 'refs' => $refCount));
    }

    private function batchWebpReplaceRefs(string $oldRel, string $newRel): int
    {
        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__')
            ? __TYPECHO_UPLOAD_DIR__ : \Widget\Upload::UPLOAD_DIR;
        $prefix = rtrim($uploadDir, '/') . '/';
        $oldInner = 0 === strpos($oldRel, $prefix) ? substr($oldRel, strlen($prefix)) : ltrim($oldRel, '/');
        $newInner = 0 === strpos($newRel, $prefix) ? substr($newRel, strlen($prefix)) : ltrim($newRel, '/');

        if ('' === $oldInner || $oldInner === $newInner) {
            return 0;
        }

        $needle = false !== strpos($oldInner, '/') ? $oldInner : $prefix . $oldInner;
        $replace = false !== strpos($newInner, '/') ? $newInner : $prefix . $newInner;

        if ($needle === $replace) {
            return 0;
        }

        $db = $this->db();
        $count = 0;

        $targets = array(
            array('table.contents', 'cid', true),
            array('table.comments', 'coid', false),
        );

        foreach ($targets as $t) {
            $select = $db->select($t[1], 'text')->from($t[0]);

            if ($t[2]) {
                $select->where('type <> ?', 'attachment');
            }

            $rows = $db->fetchAll($select->where('text LIKE ?', '%' . $needle . '%'));

            foreach ($rows as $row) {
                $newText = str_replace($needle, $replace, (string) $row['text']);

                if ($newText !== (string) $row['text']) {
                    $db->query($db->update($t[0])
                        ->rows(array('text' => $newText))
                        ->where($t[1] . ' = ?', $row[$t[1]]));
                    $count++;
                }
            }
        }

        return $count;
    }

    private function doUnattachedCount()
    {
        $db = $this->db();
        $prefix = $db->getPrefix();
        $row = $db->fetchRow("SELECT COUNT(*) AS cnt FROM `{$prefix}contents`"
            . " WHERE type = 'attachment' AND parent = 0");
        $this->json(200, '', array('count' => (int) $row['cnt']));
    }

    private function doCleanUnattached()
    {
        $db = $this->db();
        $prefix = $db->getPrefix();
        $rows = $db->fetchAll("SELECT * FROM `{$prefix}contents`"
            . " WHERE type = 'attachment' AND parent = 0");

        $deleted = 0;
        $recycled = 0;
        foreach ($rows as $row) {
            $info = self::parseAttachmentText($row['text']);
            $mapRow = $db->fetchRow($db->select('fid')->from('table.filemanager_map')
                ->where('cid = ?', (int) $row['cid'])->limit(1));

            if (self::trashAttachment((int) $row['cid'], $row, $info, $mapRow ? (int) $mapRow['fid'] : 0)) {
                $recycled++;
            } else {
                $file = self::resolveAttachmentFile($info['path']);
                if (null !== $file && is_file($file)) {
                    @unlink($file);
                }

                self::dropOriginBackup((string) $info['path']);
            }

            $db->query($db->delete('table.contents')->where('cid = ?', $row['cid']));
            $db->query($db->delete('table.filemanager_map')->where('cid = ?', $row['cid']));
            $db->query($db->delete('table.filemanager_shares')->where('cid = ?', $row['cid']));
            $deleted++;
        }

        $message = '已清理 ' . $deleted . ' 个未归档文件';
        $message .= $recycled > 0 ? '（' . $recycled . ' 个已移入回收站，可还原）' : '（含物理文件）';
        $this->json(200, $message);
    }

    private function doClassifyPreview()
    {
        $db = $this->db();
        $mode = $this->classifyMode();
        $force = ('1' === (string) $this->request->get('force', '0'));

        if ('category' === $mode) {
            $items = $this->classifyStatsByCategory($force);
        } elseif ('ym' === $mode) {
            $items = $this->classifyStatsByYm($force);
        } else {
            $items = $this->classifyStats($force);
        }

        foreach ($items as &$item) {
            if ('ym' === $mode) {

                list($year, $month) = explode('-', $item['key']);
                $yearFid = $this->findRootFolder($year);
                if (!$yearFid) {
                    $item['fid'] = 0;
                    $item['note'] = '将新建「' . $year . '」及「' . $month . '」文件夹';
                } elseif ($monthFid = $this->findChildFolder($yearFid, $month)) {
                    $item['fid'] = $monthFid;
                    $item['note'] = '复用已有文件夹';
                } else {
                    $item['fid'] = 0;
                    $item['note'] = '复用「' . $year . '」，将新建月文件夹「' . $month . '」';
                }
            } else {
                $folder = $db->fetchRow($db->select('fid')->from('table.filemanager_folders')
                    ->where('name = ? AND parent = ?', $item['name'], 0)->limit(1));
                $item['fid'] = $folder ? (int) $folder['fid'] : 0;
            }
        }
        unset($item);

        $this->json(200, '', array(
            'mode'  => $mode,
            'scope' => (string) $this->request->get('force', '0'),
            'items' => $items,
        ));
    }

    private function classifyMode()
    {
        $mode = (string) $this->request->get('mode', 'type');
        return in_array($mode, array('category', 'ym'), true) ? $mode : 'type';
    }

    private function doClassifyRun()
    {
        $mode = $this->classifyMode();
        $force = ('1' === (string) $this->request->get('force', '0'));

        $db = $this->db();
        $this->syncMap();

        if ('category' === $mode) {
            $items = array();
        } elseif ('ym' === $mode) {
            $items = array();
        } else {
            if (empty($this->categories())) {
                $this->json(400, '按类型归类需要先在插件设置中填写「自定义文件类型分类」，或改用「按文章分类归类」');
            }
            $items = $this->classifyStats($force);
        }

        $results = array();
        $cats = $this->categories();
        $allExts = $this->allKnownExts();

        if ('ym' === $mode) {

            $groups = array();
            foreach ($this->ymAssignments($force) as $cid => $key) {
                $groups[$key][] = (int) $cid;
            }

            ksort($groups);

            $prefix = $db->getPrefix();
            foreach ($groups as $key => $cids) {
                list($year, $month) = explode('-', $key);
                $yearFid = $this->ensureRootFolder($year);
                $fid = $this->ensureChildFolder($yearFid, $month);

                foreach (array_chunk($cids, 500) as $chunk) {
                    $db->query("UPDATE `{$prefix}filemanager_map` SET fid = " . $fid
                        . " WHERE cid IN (" . implode(',', $chunk) . ")");
                }

                $results[] = array('name' => $year . '/' . $month, 'fid' => $fid, 'moved' => count($cids));
            }
        } elseif ('category' === $mode) {

            $groups = array();
            foreach ($this->categoryAssignments($force) as $cid => $cat) {
                $groups['' === $cat ? '未归属' : $cat][] = (int) $cid;
            }

            uasort($groups, function ($a, $b) {
                return count($b) - count($a);
            });

            $prefix = $db->getPrefix();
            foreach ($groups as $name => $cids) {
                $fid = $this->ensureRootFolder($name);

                foreach (array_chunk($cids, 500) as $chunk) {
                    $db->query("UPDATE `{$prefix}filemanager_map` SET fid = " . $fid
                        . " WHERE cid IN (" . implode(',', $chunk) . ")");
                }

                $results[] = array('name' => $name, 'fid' => $fid, 'moved' => count($cids));
            }
        } else {
            foreach ($items as $item) {
                $key = $item['key'];
                $name = $item['name'];

                if ($item['count'] < 1) {
                    continue;
                }

                if ('other' === $key) {
                    if (empty($allExts)) {
                        continue;
                    }
                } elseif (!isset($cats[$key])) {
                    continue;
                }

                $fid = $this->ensureRootFolder($name);

                $select = $db->select('table.contents.cid')
                    ->from('table.contents')
                    ->join('table.filemanager_map', 'table.contents.cid = table.filemanager_map.cid', \Typecho\Db::INNER_JOIN)
                    ->where('table.contents.type = ?', 'attachment');

                if ('other' === $key) {

                    foreach ($allExts as $knownExt) {
                        $select->where('table.filemanager_map.ext <> ?', $knownExt);
                    }
                } else {
                    $this->extIn($select, $cats[$key]['exts']);
                }

                if (!$force) {
                    $select->where('table.filemanager_map.fid = ?', 0);
                }

                $cids = array();
                foreach ($db->fetchAll($select) as $row) {
                    $cids[] = (int) $row['cid'];
                }

                if (empty($cids)) {
                    continue;
                }

                $prefix = $db->getPrefix();
                foreach (array_chunk($cids, 500) as $chunk) {
                    $db->query("UPDATE `{$prefix}filemanager_map` SET fid = " . $fid
                        . " WHERE cid IN (" . implode(',', $chunk) . ")");
                }

                $results[] = array('name' => $name, 'fid' => $fid, 'moved' => count($cids));
            }
        }

        $this->json(200, '', array('results' => $results));
    }

    private function ensureRootFolder($name)
    {
        $fid = $this->findRootFolder($name);
        if ($fid) {
            return $fid;
        }

        $db = $this->db();
        $user = \Widget\User::alloc();
        $db->query($db->insert('table.filemanager_folders')->rows(array(
            'name'    => $name,
            'parent'  => 0,
            'sort'    => 0,
            'owner'   => (int) $user->uid,
            'created' => time(),
        )));

        return $this->findRootFolder($name);
    }

    private function findRootFolder($name)
    {
        $folder = $this->db()->fetchRow($this->db()->select('fid')->from('table.filemanager_folders')
            ->where('name = ? AND parent = ?', $name, 0)->limit(1));

        return $folder ? (int) $folder['fid'] : 0;
    }

    private function findChildFolder($parentFid, $name)
    {
        $folder = $this->db()->fetchRow($this->db()->select('fid')->from('table.filemanager_folders')
            ->where('name = ? AND parent = ?', $name, (int) $parentFid)->limit(1));

        return $folder ? (int) $folder['fid'] : 0;
    }

    private function ensureChildFolder($parentFid, $name)
    {
        $fid = $this->findChildFolder($parentFid, $name);
        if ($fid) {
            return $fid;
        }

        $db = $this->db();
        $user = \Widget\User::alloc();
        $db->query($db->insert('table.filemanager_folders')->rows(array(
            'name'    => $name,
            'parent'  => (int) $parentFid,
            'sort'    => 0,
            'owner'   => (int) $user->uid,
            'created' => time(),
        )));

        return $this->findChildFolder($parentFid, $name);
    }

    private function ymAssignments($force)
    {
        $db = $this->db();
        $prefix = $db->getPrefix();

        $sql = "SELECT c.cid, c.created FROM `{$prefix}contents` c"
            . " INNER JOIN `{$prefix}filemanager_map` m ON c.cid = m.cid"
            . " WHERE c.type = 'attachment'";
        if (!$force) {
            $sql .= ' AND m.fid = 0';
        }

        $assign = array();
        foreach ($db->fetchAll($sql) as $row) {
            $assign[(int) $row['cid']] = date('Y-m', (int) $row['created']);
        }

        return $assign;
    }

    private function classifyStatsByYm($force)
    {
        $items = array();
        foreach ($this->ymAssignments($force) as $key) {
            if (!isset($items[$key])) {
                list($year, $month) = explode('-', $key);
                $items[$key] = array(
                    'key'   => $key,
                    'name'  => $year . '/' . $month,
                    'count' => 0,
                );
            }
            $items[$key]['count']++;
        }

        ksort($items);

        return array_values($items);
    }

    private function doShareCreate()
    {
        $cid = (int) $this->request->get('cid', 0);
        $db = $this->db();

        $content = $db->fetchRow($db->select('cid')->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
        if (!$content) {
            $this->json(400, '附件不存在');
        }

        $settings = FileManager_Plugin::settings();
        FileManager_Plugin::ensureShareSchema();

        $password = trim((string) $this->request->get('password', ''));
        $expire = (int) $this->request->get('expire', $settings['defaultExpire']);
        $max = (int) $this->request->get('maxDownloads', $settings['defaultMaxDownloads']);
        $loginRequired = ('1' === (string) $this->request->get('loginRequired', '0')) ? 1 : 0;
        $preview = ('1' === (string) $this->request->get('preview', '0')) ? 1 : 0;
        $displayName = $this->sanitizeDisplayName((string) $this->request->get('displayName', ''));
        $note = $this->sanitizeShareNote((string) $this->request->get('note', ''));

        $expire = in_array($expire, array(0, 1, 7, 30)) ? $expire : 0;
        $max = max(0, min(999999, $max));

        $token = $this->sanitizeShareToken((string) $this->request->get('token', ''));
        if ('' !== $token) {
            if (null === $token) {
                $this->json(400, '自定义链接仅支持字母、数字、下划线、短横线，长度 3-64 位');
            }
            if ($this->shareTokenExists($token)) {
                $this->json(400, '自定义链接「' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '」已被占用，请换一个');
            }
        } else {
            $token = bin2hex(random_bytes(8));
        }

        $user = \Widget\User::alloc();
        $db->query($db->insert('table.filemanager_shares')->rows(array(
            'cid'            => $cid,
            'token'          => $token,
            'password'       => '' !== $password ? password_hash($password, PASSWORD_DEFAULT) : '',
            'password_plain' => $password,
            'expire'         => $expire > 0 ? time() + $expire * 86400 : 0,
            'max_downloads'  => $max,
            'login_required' => $loginRequired,
            'preview'        => $preview,
            'display_name'   => $displayName,
            'note'           => $note,
            'downloads'      => 0,
            'created'        => time(),
            'owner'          => (int) $user->uid,
        )));

        $this->json(200, '', array(
            'token' => $token,
            'url'   => $this->shareUrl($token),
        ));
    }

    private function doShareList()
    {
        $db = $this->db();
        FileManager_Plugin::ensureShareSchema();
        $rows = $db->fetchAll($db->select(
            'table.filemanager_shares.id', 'table.filemanager_shares.cid', 'table.filemanager_shares.token',
            'table.filemanager_shares.password', 'table.filemanager_shares.password_plain',
            'table.filemanager_shares.expire',
            'table.filemanager_shares.max_downloads',             'table.filemanager_shares.login_required',
            'table.filemanager_shares.display_name',
            'table.filemanager_shares.note',
            'table.filemanager_shares.downloads',
            'table.filemanager_shares.views',
            'table.filemanager_shares.preview',
            'table.filemanager_shares.created', 'table.contents.title'
        )->from('table.filemanager_shares')
            ->join('table.contents', 'table.contents.cid = table.filemanager_shares.cid', \Typecho\Db::LEFT_JOIN)
            ->where('table.contents.type = ?', 'attachment')
            ->order('table.filemanager_shares.id', \Typecho\Db::SORT_DESC));

        $now = time();
        $shares = array();
        foreach ($rows as $row) {
            if ($row['expire'] > 0 && $row['expire'] < $now) {
                $status = 'expired';
            } elseif ($row['max_downloads'] > 0 && $row['downloads'] >= $row['max_downloads']) {
                $status = 'exhausted';
            } else {
                $status = 'active';
            }

            $shares[] = array(
                'id'        => (int) $row['id'],
                'cid'       => (int) $row['cid'],
                'title'     => $row['title'],
                'url'       => $this->shareUrl($row['token']),
                'token'     => (string) $row['token'],
                'hasPwd'    => '' !== $row['password'],
                'password'  => isset($row['password_plain']) ? (string) $row['password_plain'] : '',
                'expire'    => (int) $row['expire'],
                'max'       => (int) $row['max_downloads'],
                'downloads' => (int) $row['downloads'],
                'views'     => (int) (isset($row['views']) ? $row['views'] : 0),
                'preview'   => !empty($row['preview']),
                'created'   => (int) $row['created'],
                'status'    => $status,
                'loginRequired' => !empty($row['login_required']),
                'displayName'   => isset($row['display_name']) ? (string) $row['display_name'] : '',
                'note'          => isset($row['note']) ? (string) $row['note'] : '',
            );
        }

        $this->json(200, '', array('shares' => $shares));
    }

    private function doShareUpdate()
    {
        $id = (int) $this->request->get('id', 0);
        $db = $this->db();
        FileManager_Plugin::ensureShareSchema();

        $share = $db->fetchRow($db->select('id', 'token')->from('table.filemanager_shares')
            ->where('id = ?', $id)->limit(1));
        if (!$share) {
            $this->json(404, '分享不存在或已被删除');
        }

        $password = trim((string) $this->request->get('password', ''));
        $expire = (string) $this->request->get('expire', 'keep');
        $max = (int) $this->request->get('maxDownloads', 0);
        $loginRequired = ('1' === (string) $this->request->get('loginRequired', '0')) ? 1 : 0;
        $preview = ('1' === (string) $this->request->get('preview', '0')) ? 1 : 0;
        $displayName = $this->sanitizeDisplayName((string) $this->request->get('displayName', ''));
        $note = $this->sanitizeShareNote((string) $this->request->get('note', ''));

        $max = max(0, min(999999, $max));

        $rows = array(
            'max_downloads'  => $max,
            'login_required' => $loginRequired,
            'preview'        => $preview,
            'display_name'   => $displayName,
            'note'           => $note,
        );

        $newToken = (string) $this->request->get('token', '');
        if ('' !== trim($newToken)) {
            $token = $this->sanitizeShareToken($newToken);
            if (null === $token) {
                $this->json(400, '自定义链接仅支持字母、数字、下划线、短横线，长度 3-64 位');
            }
            if ($token !== (string) $share['token'] && $this->shareTokenExists($token, $id)) {
                $this->json(400, '自定义链接「' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '」已被占用，请换一个');
            }
            $rows['token'] = $token;
        }

        if ('keep' !== $expire) {
            $expire = (int) $expire;
            $expire = in_array($expire, array(0, 1, 7, 30)) ? $expire : 0;
            $rows['expire'] = $expire > 0 ? time() + $expire * 86400 : 0;
        }

        $rows['password'] = '' !== $password ? password_hash($password, PASSWORD_DEFAULT) : '';
        $rows['password_plain'] = $password;

        $db->query($db->update('table.filemanager_shares')->rows($rows)->where('id = ?', $id));

        $this->json(200, '分享已更新');
    }

    private function doShareDelete()
    {
        $id = (int) $this->request->get('id', 0);
        $db = $this->db();
        FileManager_Plugin::ensureShareSchema();
        $db->query($db->delete('table.filemanager_shares')->where('id = ?', $id));

        try {
            $db->query($db->delete('table.filemanager_share_logs')->where('share_id = ?', $id));
        } catch (\Throwable $e) {

        }
        $this->json(200, '分享已删除');
    }

    private function doShareLogs()
    {
        $id = (int) $this->request->get('id', 0);
        $page = max(1, (int) $this->request->get('page', 1));
        $limit = 20;
        $db = $this->db();
        FileManager_Plugin::ensureShareSchema();

        $share = $db->fetchRow($db->select('table.filemanager_shares.id', 'table.contents.title')
            ->from('table.filemanager_shares')
            ->join('table.contents', 'table.contents.cid = table.filemanager_shares.cid', \Typecho\Db::LEFT_JOIN)
            ->where('table.filemanager_shares.id = ?', $id)->limit(1));
        if (!$share) {
            $this->json(404, '分享不存在或已被删除');
        }

        $row = $db->fetchRow($db->select('COUNT(*) AS n')
            ->from('table.filemanager_share_logs')->where('share_id = ?', $id));
        $total = (int) $row['n'];
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);

        $logs = array();
        if ($total > 0) {
            $rows = $db->fetchAll($db->select('action', 'ip', 'ua', 'created')
                ->from('table.filemanager_share_logs')
                ->where('share_id = ?', $id)
                ->order('id', \Typecho\Db::SORT_DESC)
                ->offset(($page - 1) * $limit)
                ->limit($limit));
            foreach ($rows as $row) {
                $logs[] = array(
                    'action'  => (string) $row['action'],
                    'ip'      => (string) $row['ip'],
                    'ua'      => (string) $row['ua'],
                    'created' => (int) $row['created'],
                );
            }
        }

        $this->json(200, '', array(
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'logs'  => $logs,
            'title' => (string) $share['title'],
        ));
    }

    /** 上传时的图片处理覆盖参数：弹窗取消勾选也要显式写 0，否则回落到插件设置 */
    private static function uploadImageOverrides(string $uploadTo, bool $uploadWm, bool $explicit): ?array
    {
        if ('' === $uploadTo && !$uploadWm && !$explicit) {
            return null;
        }

        return array(
            'imgEnabled'   => '1',
            'to_format'    => ('' !== $uploadTo) ? $uploadTo : 'keep',
            'watermark'    => $uploadWm ? '1' : '0',
            'size_policy'  => $uploadWm ? 'continue' : 'keep',
        );
    }

    private function doUpload()
    {
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            $this->json(400, '未选择文件');
        }

        $file = $_FILES['file'];

        if (!isset($file['error']) || UPLOAD_ERR_OK !== (int) $file['error']) {
            $messages = array(
                UPLOAD_ERR_INI_SIZE   => '文件超过服务器限制（upload_max_filesize）',
                UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制（post_max_size）',
                UPLOAD_ERR_PARTIAL    => '文件上传不完整，请重试',
                UPLOAD_ERR_NO_FILE    => '未选择文件',
                UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录，无法上传',
                UPLOAD_ERR_CANT_WRITE => '服务器写入失败，请检查目录权限',
                UPLOAD_ERR_EXTENSION  => '上传被服务器扩展中断',
            );
            $code = (int) $file['error'];
            $this->json(400, isset($messages[$code]) ? $messages[$code] : '上传失败，请重试');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $this->json(400, '非法上传请求');
        }

        \FileManager_Plugin::$lastUploadResult = null;

        $uploadTo = (string) $this->request->get('uploadTo', '');
        $uploadWm = '1' === (string) $this->request->get('uploadWm', '0');
        /* 上传弹窗会带 imgCtl=1：此时水印/格式完全按弹窗勾选执行，取消勾选也必须覆盖插件设置 */
        $imgCtl = '1' === (string) $this->request->get('imgCtl', '0');
        $prevUploadOverrides = \FileManager_Plugin::$uploadOverrides;
        $ov = self::uploadImageOverrides($uploadTo, $uploadWm, $imgCtl);
        if (null !== $ov) {
            if ('' !== $uploadTo && !in_array($uploadTo, array('webp', 'jpg', 'png'), true)) {
                $this->json(400, '转换目标格式不支持');
            }

            if ($uploadWm && !\FileManager_Converter::watermarkReady(\FileManager_ImageTool::settings())) {
                $this->json(400, '水印未生效：请先在插件设置中配置水印（文字并上传字体，或上传水印图片）');
            }

            \FileManager_Plugin::$uploadOverrides = $ov;
        }

        $result = \Widget\Upload::uploadHandle($file);
        \FileManager_Plugin::$uploadOverrides = $prevUploadOverrides;

        if ((!is_array($result) || empty($result['path']))
            && is_array(\FileManager_Plugin::$lastUploadResult)
            && !empty(\FileManager_Plugin::$lastUploadResult['path'])) {
            $result = \FileManager_Plugin::$lastUploadResult;
        }

        if (!$result || empty($result['path'])) {

            $diag = array();

            $ext = '';
            $name = str_replace(array('\\', '/'), '/', (string) $file['name']);
            $info = pathinfo('a' . (false === strpos($name, '/') ? $name : substr($name, (int) strrpos($name, '/') + 1)));
            $ext = isset($info['extension']) ? strtolower($info['extension']) : '';

            try {
                $typePass = $ext && \Widget\Upload::checkFileType($ext);
                $diag[] = '类型检查：' . $ext . ' ' . ($typePass ? '通过' : '不通过');
            } catch (\Throwable $e) {
                $diag[] = '类型检查异常：' . $e->getMessage();
            }

            try {
                $types = \Widget\Options::alloc()->allowedAttachmentTypes;
                $diag[] = '站点允许：' . (is_array($types) ? implode('/', $types) : var_export($types, true));
            } catch (\Throwable $e) {
                $diag[] = '允许类型读取失败：' . $e->getMessage();
            }

            try {
                $export = \Typecho\Plugin::export();
                $hooks = isset($export['handles']['Widget_Upload:uploadHandle'])
                    ? $export['handles']['Widget_Upload:uploadHandle'] : array();
                $names = array();
                foreach ((array) $hooks as $cb) {
                    $names[] = is_array($cb) ? implode('::', (array) $cb) : (string) $cb;
                }
                $diag[] = '上传钩子链：' . ($names ? implode(' → ', $names) : '无');
            } catch (\Throwable $e) {
                $diag[] = '钩子链读取失败';
            }

            $diag[] = '钩子返回：' . var_export($result, true);

            $this->json(400, '上传被拒绝。诊断：' . implode('；', $diag)
                . '（可在后台「设置 → 基本 → 允许上传的文件类型」中调整）');
        }

        $user = \Widget\User::alloc();
        $options = \Widget\Options::alloc();
        $db = $this->db();
        $now = time();

        if ('1' === (string) $this->request->get('renameDt', '0')) {
            $result = $this->renameUploadedByDate($result);
        }

        /* title 是 varchar(200)：长 ASCII 文件名会 Data too long，入库前截断 */
        $title = self::clampChars((string) $result['name'], 200);
        try {
            $cid = (int) $db->query($db->insert('table.contents')->rows(array(
                'title'        => $title,
                'slug'         => self::uniqueSlug((string) $result['name']),
                'created'      => $now,
                'modified'     => $now,
                'text'         => self::attachmentText($result),
                'authorId'     => (int) $user->uid,
                'type'         => 'attachment',
                'status'       => 'publish',
                'password'     => '',
                'commentsNum'  => 0,
                'allowComment' => (string) $options->defaultAllowComment,
                'allowPing'    => (string) $options->defaultAllowPing,
                'allowFeed'    => (string) $options->defaultAllowFeed,
                'parent'       => 0,
            )));
        } catch (\Throwable $e) {
            /* 入库失败：删掉刚落盘的文件避免孤儿，且不把裸 SQL 抛给用户 */
            $orphan = self::resolveAttachmentFile((string) $result['path']);
            if (null !== $orphan && is_file($orphan)) {
                @unlink($orphan);
            }
            $this->json(500, '附件记录写入失败，本次上传已回滚。'
                . '数据库返回：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        $fid = (int) $this->request->get('fid', 0);
        $mapped = false;
        if ($cid > 0 && $fid > 0) {
            $folder = $db->fetchRow($db->select('fid')->from('table.filemanager_folders')
                ->where('fid = ?', $fid)->limit(1));
            if ($folder) {
                $db->query($db->insert('table.filemanager_map')->rows(array(
                    'cid'  => $cid,
                    'fid'  => $fid,
                    'size' => (int) $result['size'],
                    'ext'  => (string) $result['type'],
                )));
                $mapped = true;
            }
        }

        if ($cid > 0 && !$mapped) {
            try {
                \FileManager_Plugin::autoMapUserFolder($cid, (int) $user->uid);
            } catch (\Throwable $e) {

            }
        }

        $this->json(200, '上传成功', array(
            'cid'     => $cid,
            'title'   => (string) $result['name'],
            'url'     => \Typecho\Common::url((string) $result['path'], $options->rootUrl),
            'isImage' => self::isImageExt((string) $result['type'], isset($result['mime']) ? (string) $result['mime'] : ''),
            'size'    => (int) $result['size'],
            'ext'     => (string) $result['type'],
        ));
    }

    private function renameUploadedByDate(array $result): array
    {
        $abs = self::resolveAttachmentFile((string) $result['path']);
        if (null === $abs || !is_file($abs)) {
            return $result;
        }

        $ext = strtolower((string) pathinfo((string) $result['path'], PATHINFO_EXTENSION));
        $dir = (string) pathinfo((string) $result['path'], PATHINFO_DIRNAME);
        $relDir = ('.' === $dir || '' === $dir) ? '' : $dir . '/';
        $stamp = date('YmdHis');

        $newBase = $stamp;
        $n = 0;
        while (is_file(dirname($abs) . '/' . $newBase . '.' . $ext)) {
            $n++;
            $newBase = $stamp . '-' . $n;
        }

        $newAbs = dirname($abs) . '/' . $newBase . '.' . $ext;
        if (!@rename($abs, $newAbs)) {
            return $result;
        }
        @chmod($newAbs, 0644);

        clearstatcache(true, $newAbs);
        $result['name'] = $newBase . '.' . $ext;
        $result['path'] = $newPath = $relDir . $newBase . '.' . $ext;

        return $result;
    }

    private function doZipCreate()
    {
        if (!class_exists('ZipArchive')) {
            $this->json(400, '服务器未启用 php_zip 扩展（ZipArchive），无法在线压缩');
        }

        $cids = $this->parseCids((string) $this->request->get('cids'));
        $name = $this->sanitizeZipName((string) $this->request->get('name', ''));
        $level = ('6' === (string) $this->request->get('level', '0')) ? 6 : 0;
        $structure = ('flat' === (string) $this->request->get('structure', 'folder')) ? 'flat' : 'folder';
        $targetFid = max(0, (int) $this->request->get('fid', 0));
        $deleteOriginal = ('1' === (string) $this->request->get('deleteOriginal', '0'));
        $password = trim((string) $this->request->get('password', ''));

        if ($targetFid > 0 && !$this->folderExists($targetFid)) {
            $this->json(400, '目标文件夹不存在');
        }

        $db = $this->db();

        $files = array();
        $missing = array();
        $totalSize = 0;

        foreach ($cids as $cid) {
            $row = $db->fetchRow($db->select('cid', 'title', 'text')->from('table.contents')
                ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
            if (!$row) {
                $missing[] = array('cid' => $cid, 'title' => 'CID ' . $cid, 'reason' => '附件不存在');
                continue;
            }

            $info = self::parseAttachmentText($row['text']);
            $real = self::resolveAttachmentFile($info['path']);
            if (null === $real || !is_file($real)) {
                $missing[] = array('cid' => $cid, 'title' => '' !== $info['name'] ? $info['name'] : $row['title'], 'reason' => '物理文件缺失（云存储或已丢失）');
                continue;
            }

            $files[] = array(
                'cid'  => $cid,
                'name' => '' !== $info['name'] ? $info['name'] : $row['title'],
                'real' => $real,
                'fid'  => 0,
                'size' => (int) $info['size'] > 0 ? (int) $info['size'] : (int) @filesize($real),
            );
        }

        if (!empty($files)) {
            $cidList = implode(',', array_map('intval', array_column($files, 'cid')));
            $prefix = $db->getPrefix();
            foreach ($db->fetchAll("SELECT cid, fid FROM `{$prefix}filemanager_map` WHERE cid IN ({$cidList})") as $row) {
                foreach ($files as &$file) {
                    if ((int) $file['cid'] === (int) $row['cid']) {
                        $file['fid'] = (int) $row['fid'];
                        break;
                    }
                }
                unset($file);
            }
        }

        foreach ($files as $file) {
            $totalSize += $file['size'];
        }

        if (empty($files)) {
            $this->json(400, '没有可打包的文件（所选附件的物理文件均缺失）');
        }

        if ((int) $this->request->get('confirm', 0) !== 1) {
            $items = array();
            foreach ($files as $file) {
                $items[] = array('name' => $file['name'], 'size' => $file['size']);
            }
            $this->json(200, '', array(
                'files'      => $items,
                'missing'    => $missing,
                'totalSize'  => $totalSize,
                'warnBig'    => $totalSize > self::ZIP_WARN_BYTES,
                'name'       => $name,
            ));
        }

        $zip = new ZipArchive();
        $tmpFile = tempnam(sys_get_temp_dir(), 'fmz');
        if (false === $tmpFile || true !== $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            $this->json(500, '创建压缩包临时文件失败，请检查服务器临时目录权限');
        }

        $settings = FileManager_Plugin::settings();
        $encMethod = null;
        if ('' !== $password) {
            if ('1' === $settings['zipEncryption'] && defined('ZipArchive::EM_AES256')) {
                $encMethod = ZipArchive::EM_AES256;
            } elseif (defined('ZipArchive::EM_ZIPCRYPTO')) {
                $encMethod = ZipArchive::EM_ZIPCRYPTO;
            } else {
                $zip->close();
                @unlink($tmpFile);
                $support = FileManager_Plugin::encryptionSupport();
                $this->json(400, '服务器 zip 扩展不支持加密写入（' . $support['env'] . '），请去掉解压密码后重试');
            }

            if (true !== $zip->setPassword($password)) {
                $zip->close();
                @unlink($tmpFile);
                $support = FileManager_Plugin::encryptionSupport();
                $this->json(400, '服务器 zip 扩展不支持设置密码（' . $support['env'] . '），请去掉解压密码后重试');
            }
        }

        $canCompress = method_exists('ZipArchive', 'setCompressionName');
        $canEncrypt = '' !== $password && null !== $encMethod && method_exists('ZipArchive', 'setEncryptionName');

        $folderPaths = array();
        if ('folder' === $structure) {
            $tree = array();
            foreach ($db->fetchAll($db->select('fid', 'name', 'parent')->from('table.filemanager_folders')) as $row) {
                $tree[(int) $row['fid']] = array('name' => $row['name'], 'parent' => (int) $row['parent']);
            }
            foreach ($files as $file) {
                $fid = $file['fid'];
                if ($fid > 0 && isset($tree[$fid])) {
                    $chain = array();
                    $cur = $fid;
                    while ($cur > 0 && isset($tree[$cur]) && count($chain) < 10) {
                        array_unshift($chain, $this->sanitizeEntrySegment($tree[$cur]['name']));
                        $cur = $tree[$cur]['parent'];
                    }
                    $folderPaths[$file['cid']] = implode('/', $chain);
                }
            }
        }

        $used = array();
        $added = 0;
        foreach ($files as $file) {
            $base = $this->sanitizeEntrySegment($file['name']);
            if ('' === $base) {
                $base = 'file-' . $file['cid'];
            }

            if ('flat' === $structure) {
                $entry = $this->uniqueEntryName($base, $used);
            } else {
                $dir = isset($folderPaths[$file['cid']]) ? $folderPaths[$file['cid']] . '/' : '';
                $entry = $this->uniqueEntryName($dir . $base, $used);
            }

            if (!$zip->addFile($file['real'], $entry)) {
                $zip->close();
                @unlink($tmpFile);
                $this->json(500, '添加文件「' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '」到压缩包失败');
            }

            if ($canCompress) {
                if (0 === $level) {
                    $zip->setCompressionName($entry, ZipArchive::CM_STORE);
                } else {
                    $zip->setCompressionName($entry, ZipArchive::CM_DEFLATE, 6);
                }
            }
            if ($canEncrypt) {
                if (true !== $zip->setEncryptionName($entry, $encMethod)) {
                    $zip->close();
                    @unlink($tmpFile);
                    $support = FileManager_Plugin::encryptionSupport();
                    $this->json(400, '服务器 zip 扩展不支持该加密方式写入（' . $support['env']
                        . '）。新版 libzip 已移除 ZipCrypto 支持，可在插件设置中改用 AES-256，或去掉解压密码');
                }
            }
            $added++;
        }

        if (!$zip->close() || !is_file($tmpFile)) {
            @unlink($tmpFile);
            $this->json(500, '' !== $password
                ? '压缩包写入失败：服务器 libzip 可能不支持该加密方式，请去掉密码或改用 AES-256 后重试'
                : '压缩包写入失败，请重试');
        }

        $target = $this->makeUploadTarget('zip');
        if (null === $target || !@rename($tmpFile, $target['abs']) && !@copy($tmpFile, $target['abs'])) {
            @unlink($tmpFile);
            $this->json(500, '压缩包保存到 uploads 目录失败，请检查目录写权限');
        }
        @unlink($tmpFile);
        if (!is_file($target['abs'])) {
            $this->json(500, '压缩包保存失败，请重试');
        }
        $zipSize = (int) @filesize($target['abs']);

        $cid = $this->registerAttachment($target['abs'], $target['rel'], $name, $zipSize, 'zip', 'application/zip', $targetFid);
        if ($cid <= 0) {
            @unlink($target['abs']);
            $this->json(500, '压缩包已生成但写入附件记录失败，请重试');
        }

        $removed = 0;
        if ($deleteOriginal) {
            foreach ($files as $file) {
                $row = $db->fetchRow($db->select()->from('table.contents')
                    ->where('cid = ? AND type = ?', $file['cid'], 'attachment')->limit(1));
                if ($row) {
                    $info = self::parseAttachmentText($row['text']);
                    $mapRow = $db->fetchRow($db->select('fid')->from('table.filemanager_map')
                        ->where('cid = ?', $file['cid'])->limit(1));
                    $real = self::resolveAttachmentFile($info['path']);
                    if (!self::trashAttachment($file['cid'], $row, $info, $mapRow ? (int) $mapRow['fid'] : 0)
                        && null !== $real && is_file($real) && $real !== $target['abs']) {
                        @unlink($real);
                    }
                }
                $db->query($db->delete('table.contents')->where('cid = ?', $file['cid']));
                $db->query($db->delete('table.filemanager_map')->where('cid = ?', $file['cid']));
                $db->query($db->delete('table.filemanager_shares')->where('cid = ?', $file['cid']));
                $removed++;
            }
        }

        $this->json(200, '', array(
            'cid'     => $cid,
            'name'    => $name,
            'url'     => \Typecho\Common::url($target['rel'], \Widget\Options::alloc()->rootUrl),
            'size'    => $zipSize,
            'count'   => $added,
            'removed' => $removed,
            'fid'     => $targetFid,
        ));
    }

    private function doFolderZip()
    {
        if (!class_exists('ZipArchive')) {
            $this->json(400, '服务器未启用 php_zip 扩展（ZipArchive），无法打包下载');
        }

        $fid = (int) $this->request->get('fid', 0);
        if ($fid <= 0) {
            $this->json(400, '请选择一个具体的文件夹（「所有文件」与「未分类」不支持整包下载）');
        }

        $confirm = ((int) $this->request->get('confirm', 0) === 1);
        $built = $this->buildFolderZip($fid, $confirm);
        if (!$confirm) {
            $this->json(200, '', $built);
        }

        $tmpFile = $built['tmp'];
        $dlName = $built['name'];
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="folder-' . $fid . '.zip"; filename*=UTF-8\'\'' . rawurlencode($dlName));
        header('Content-Length: ' . (string) filesize($tmpFile));
        header('Cache-Control: no-store');

        $fh = fopen($tmpFile, 'rb');
        if (false !== $fh) {
            while (!feof($fh)) {
                print fread($fh, 1048576);
                flush();
            }
            fclose($fh);
        }
        @unlink($tmpFile);
        exit;
    }

    private function buildFolderZip($fid, $build)
    {
        $db = $this->db();

        $tree = array();
        $children = array();
        $rootName = '';
        foreach ($db->fetchAll($db->select('fid', 'name', 'parent')->from('table.filemanager_folders')) as $row) {
            $tree[(int) $row['fid']] = array('name' => (string) $row['name'], 'parent' => (int) $row['parent']);
            $children[(int) $row['parent']][] = (int) $row['fid'];
        }
        if (!isset($tree[$fid])) {
            $this->json(400, '文件夹不存在或已被删除');
        }
        $rootName = $this->sanitizeEntrySegment($tree[$fid]['name']);
        if ('' === $rootName) {
            $rootName = 'folder-' . $fid;
        }

        $fids = array($fid => true);
        $queue = array(array($fid, 0));
        while ($queue) {
            list($cur, $depth) = array_shift($queue);
            if ($depth >= 32) {
                continue;
            }
            foreach ((array) (isset($children[$cur]) ? $children[$cur] : array()) as $child) {
                if (!isset($fids[$child])) {
                    $fids[$child] = true;
                    $queue[] = array($child, $depth + 1);
                }
            }
        }
        $fidList = array_map('intval', array_keys($fids));
        $idIn = implode(',', $fidList);

        $prefix = $db->getPrefix();
        $files = array();
        $missing = array();
        $totalSize = 0;
        $rows = $db->fetchAll("SELECT m.cid AS cid, m.fid AS fid, c.text AS text, c.title AS title"
            . " FROM `{$prefix}filemanager_map` m"
            . " INNER JOIN `{$prefix}contents` c ON c.cid = m.cid AND c.type = 'attachment'"
            . " WHERE m.fid IN ({$idIn})");
        foreach ($rows as $row) {
            $info = self::parseAttachmentText((string) $row['text']);
            $real = self::resolveAttachmentFile((string) $info['path']);
            if (null === $real || !is_file($real)) {
                $missing[] = array(
                    'name'   => '' !== (string) $info['name'] ? (string) $info['name'] : (string) $row['title'],
                    'reason' => '物理文件缺失（云存储或已丢失）',
                );
                continue;
            }
            $files[] = array(
                'name' => '' !== (string) $info['name'] ? (string) $info['name'] : (string) $row['title'],
                'real' => $real,
                'fid'  => (int) $row['fid'],
                'size' => (int) $info['size'] > 0 ? (int) $info['size'] : (int) @filesize($real),
            );
            $totalSize += (int) $info['size'] > 0 ? (int) $info['size'] : (int) @filesize($real);
        }

        if (!$build) {
            return array(
                'name'      => $this->sanitizeZipName($rootName . '.zip'),
                'count'     => count($files),
                'totalSize' => $totalSize,
                'missing'   => $missing,
                'warnBig'   => $totalSize > self::ZIP_WARN_BYTES,
                'folders'   => count($fidList),
            );
        }

        if (empty($files)) {
            $this->json(400, '文件夹内没有可下载的文件（附件物理文件均缺失或为空）');
        }

        $zip = new ZipArchive();
        $tmpFile = tempnam(sys_get_temp_dir(), 'fmf');
        if (false === $tmpFile || true !== $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            $this->json(500, '创建压缩包临时文件失败，请检查服务器临时目录权限');
        }

        $canCompress = method_exists('ZipArchive', 'setCompressionName');
        $used = array();
        $zip->addEmptyDir($rootName);

        foreach (array_keys($fids) as $treeFid) {
            if ($treeFid === $fid) {
                continue;
            }
            $rel = $this->folderRelChain($treeFid, $fid, $tree);
            if ('' !== $rel) {
                $zip->addEmptyDir($rootName . '/' . $rel);
            }
        }

        foreach ($files as $file) {
            $rel = $file['fid'] === $fid ? '' : $this->folderRelChain($file['fid'], $fid, $tree);
            $base = $this->sanitizeEntrySegment($file['name']);
            if ('' === $base) {
                $base = 'file-' . basename($file['real']);
            }
            $entry = $this->uniqueEntryName($rootName . ('' !== $rel ? '/' . $rel : '') . '/' . $base, $used);

            if (!$zip->addFile($file['real'], $entry)) {
                $zip->close();
                @unlink($tmpFile);
                $this->json(500, '添加文件「' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '」到压缩包失败');
            }
            if ($canCompress) {

                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            }
        }

        if (!$zip->close() || !is_file($tmpFile)) {
            @unlink($tmpFile);
            $this->json(500, '压缩包写入失败，请重试');
        }

        return array(
            'tmp'       => $tmpFile,
            'name'      => $this->sanitizeZipName($rootName . '.zip'),
            'count'     => count($files),
            'totalSize' => $totalSize,
            'missing'   => $missing,
        );
    }

    private function folderRelChain($treeFid, $rootFid, array $tree)
    {
        $chain = array();
        $cur = $treeFid;
        while ($cur > 0 && $cur !== $rootFid && isset($tree[$cur]) && count($chain) < 32) {
            array_unshift($chain, $this->sanitizeEntrySegment($tree[$cur]['name']));
            $cur = $tree[$cur]['parent'];
        }
        if ($cur !== $rootFid) {
            return '';
        }
        return implode('/', $chain);
    }

    private function doExtract()
    {
        $cid = (int) $this->request->get('cid', 0);
        $mode = ('sub' === (string) $this->request->get('mode', 'current')) ? 'sub' : 'current';
        $deleteOriginal = ('1' === (string) $this->request->get('deleteOriginal', '0'));
        $password = trim((string) $this->request->get('password', ''));
        $fid = max(0, (int) $this->request->get('fid', 0));

        $db = $this->db();
        $row = $db->fetchRow($db->select('cid', 'title', 'text')->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
        if (!$row) {
            $this->json(400, '压缩包附件不存在或已被删除');
        }

        $info = self::parseAttachmentText($row['text']);
        $real = self::resolveAttachmentFile($info['path']);
        if (null === $real || !is_file($real)) {
            $this->json(400, '压缩包物理文件缺失（云存储或已被移动）');
        }

        $kind = $this->archiveKind($info['name'] ?: $row['title'], $info['ext']);
        if (null === $kind || 'gz' === $kind) {
            $this->json(400, '该格式不支持在线解压（仅支持 zip / tar / tar.gz）');
        }

        if ('zip' === $kind && !class_exists('ZipArchive')) {
            $this->json(400, '服务器未启用 php_zip 扩展，无法解压 zip');
        }
        if ('tar' === $kind && !class_exists('PharData')) {
            $this->json(400, '服务器未启用 phar 扩展，无法解压 tar/tar.gz');
        }

        if ($fid > 0 && !$this->folderExists($fid)) {
            $fid = 0;
        }

        if ((int) $this->request->get('confirm', 0) !== 1) {
            if ('zip' === $kind) {
                $pre = $this->zipPrecheck($real, $password);
            } else {
                $pre = $this->tarPrecheck($real);
            }
            $pre['kind'] = $kind;
            $pre['title'] = '' !== $info['name'] ? $info['name'] : $row['title'];
            $pre['subName'] = $this->sanitizeFolderTitle(preg_replace('/\.(tar\.gz|tgz|zip|tar|gz)$/i', '', $info['name'] ?: $row['title']));
            $this->json(200, '', $pre);
        }

        if ('zip' === $kind) {
            $result = $this->zipExtract($real, $password);
        } else {
            $result = $this->tarExtract($real);
        }

        if (!empty($result['error'])) {
            $this->json($result['error'][0], $result['error'][1]);
        }

        $folderName = '';
        if ('sub' === $mode) {
            $folderName = $this->sanitizeFolderTitle(preg_replace('/\.(tar\.gz|tgz|zip|tar|gz)$/i', '', $info['name'] ?: $row['title']));
            if ('' === $folderName) {
                $folderName = '解压文件';
            }
            $fid = $this->ensureFolder($folderName, $fid);
        }

        $registered = 0;
        $failed = array();
        foreach ($result['files'] as $item) {
            $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
            $target = $this->makeUploadTarget($ext);
            if (null === $target) {
                $failed[] = $item['name'];
                continue;
            }
            if (!@rename($item['tmp'], $target['abs']) && !@copy($item['tmp'], $target['abs'])) {
                @unlink($item['tmp']);
                $failed[] = $item['name'];
                continue;
            }
            @unlink($item['tmp']);

            $mime = '';
            try {
                $mime = \Typecho\Common::mimeContentType($target['abs']);
            } catch (\Throwable $e) {
                $mime = '';
            }

            $newCid = $this->registerAttachment($target['abs'], $target['rel'], $item['name'], $item['size'], $ext, $mime, $fid);
            if ($newCid > 0) {
                $registered++;
            } else {
                @unlink($target['abs']);
                $failed[] = $item['name'];
            }
        }

        if ($deleteOriginal && $registered > 0) {
            $zipRow = $db->fetchRow($db->select()->from('table.contents')
                ->where('cid = ? AND type = ?', $cid, 'attachment')->limit(1));
            if ($zipRow) {
                $zipInfo = self::parseAttachmentText($zipRow['text']);
                $zipMap = $db->fetchRow($db->select('fid')->from('table.filemanager_map')
                    ->where('cid = ?', $cid)->limit(1));
                if (!self::trashAttachment($cid, $zipRow, $zipInfo, $zipMap ? (int) $zipMap['fid'] : 0)) {
                    @unlink($real);
                }
            } else {
                @unlink($real);
            }
            $db->query($db->delete('table.contents')->where('cid = ?', $cid));
            $db->query($db->delete('table.filemanager_map')->where('cid = ?', $cid));
            $db->query($db->delete('table.filemanager_shares')->where('cid = ?', $cid));
        }

        $message = '已解压 ' . $registered . ' 个文件'
            . ('sub' === $mode ? ' 到「' . $folderName . '」' : '');
        if (!empty($result['skipped'])) {
            $message .= '；已跳过 ' . count($result['skipped']) . ' 个脚本类文件（安全黑名单）';
        }
        if (!empty($failed)) {
            $message .= '；' . count($failed) . ' 个文件注册失败';
        }

        $this->json(200, $message, array(
            'count'      => $registered,
            'skipped'    => array_slice($result['skipped'], 0, 10),
            'failed'     => array_slice($failed, 0, 10),
            'fid'        => $fid,
            'folderName' => 'sub' === $mode ? $folderName : '',
        ));
    }

    private function zipPrecheck($real, $password)
    {
        $zip = new ZipArchive();
        if (true !== $zip->open($real, ZipArchive::RDONLY)) {
            $this->json(400, '无法打开压缩包（文件可能已损坏）');
        }

        if ($zip->numFiles > self::EXTRACT_MAX_ENTRIES) {
            $zip->close();
            $this->json(400, '压缩包条目数超过 ' . self::EXTRACT_MAX_ENTRIES . '（疑似 Zip 炸弹），拒绝解压');
        }

        $entries = 0;
        $totalSize = 0;
        $skipped = array();
        $firstEntry = '';
        $encrypted = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (false === $name || '' === $name) {
                continue;
            }
            if ($this->isJunkEntry($name)) {
                continue;
            }
            if ('/' === substr($name, -1)) {
                continue;
            }

            $base = basename(str_replace('\\', '/', $name));
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (in_array($ext, self::EXTRACT_SCRIPT_EXTS, true)) {
                $skipped[] = $base;
                continue;
            }

            $stat = $zip->statIndex($i);
            $totalSize += isset($stat['size']) ? (int) $stat['size'] : 0;
            $entries++;
            if ('' === $firstEntry) {
                $firstEntry = $name;
            }
        }

        if ($totalSize > self::EXTRACT_MAX_BYTES) {
            $zip->close();
            $this->json(400, '压缩包声明解压大小超过 1GB（疑似 Zip 炸弹），拒绝解压');
        }

        if ('' !== $firstEntry) {
            if ('' !== $password) {
                $zip->setPassword($password);
            }
            $fp = $zip->getStream($firstEntry);
            if (false === $fp) {
                $encrypted = true;
            } else {
                $head = @fread($fp, 8);
                @fclose($fp);
                if (false === $head) {
                    $encrypted = true;
                }
            }
            if ($encrypted && '' !== $password) {
                $zip->close();
                $this->json(400, '解压密码错误，请重新输入');
            }
        }
        $zip->close();

        return array(
            'entries'    => $entries,
            'totalSize'  => $totalSize,
            'skipped'    => $skipped,
            'encrypted'  => $encrypted,
            'wrongPassword' => false,
        );
    }

    private function tarPrecheck($real)
    {
        try {
            $opened = $this->tarOpen($real);
            $phar = $opened['phar'];
        } catch (\Throwable $e) {
            $this->json(400, '无法打开压缩包（文件可能已损坏）：' . $e->getMessage());
        }

        $entries = 0;
        $totalSize = 0;
        $skipped = array();

        $pharBase = 'phar://' . str_replace('\\', '/', realpath($phar->getPath()));

        try {
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                if ($file->isDir()) {
                    continue;
                }
                $relPath = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($pharBase) + 1), '/');
                $name = $file->getFilename();
                if ('' === $name || $this->isJunkEntry($relPath)) {
                    continue;
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, self::EXTRACT_SCRIPT_EXTS, true)) {
                    $skipped[] = $name;
                    continue;
                }
                try {
                    $totalSize += (int) $file->getSize();
                } catch (\Throwable $e) {

                }
                $entries++;
            }
        } catch (\Throwable $e) {
            $this->cleanupWorkDir($opened['workDir']);
            $this->json(400, '读取压缩包内容失败：' . $e->getMessage());
        }
        $this->cleanupWorkDir($opened['workDir']);

        if ($entries > self::EXTRACT_MAX_ENTRIES) {
            $this->json(400, '压缩包条目数超过 ' . self::EXTRACT_MAX_ENTRIES . '（疑似炸弹包），拒绝解压');
        }
        if ($totalSize > self::EXTRACT_MAX_BYTES) {
            $this->json(400, '压缩包声明解压大小超过 1GB（疑似炸弹包），拒绝解压');
        }

        return array(
            'entries'    => $entries,
            'totalSize'  => $totalSize,
            'skipped'    => $skipped,
            'encrypted'  => false,
            'wrongPassword' => false,
        );
    }

    private function zipExtract($real, $password)
    {
        $out = array('files' => array(), 'skipped' => array(), 'error' => null);

        $zip = new ZipArchive();
        if (true !== $zip->open($real, ZipArchive::RDONLY)) {
            $out['error'] = array(400, '无法打开压缩包（文件可能已损坏）');
            return $out;
        }
        if ('' !== $password) {
            $zip->setPassword($password);
        }

        $used = array();
        $extracted = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (false === $name || '' === $name) {
                continue;
            }
            if ($this->isJunkEntry($name)) {
                continue;
            }
            if ('/' === substr($name, -1)) {
                continue;
            }

            $base = basename(str_replace('\\', '/', $name));
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (in_array($ext, self::EXTRACT_SCRIPT_EXTS, true)) {
                $out['skipped'][] = $base;
                continue;
            }
            if ('' === $base || '..' === $base || '.' === $base) {
                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'fmx');
            if (false === $tmp) {
                $out['error'] = array(500, '创建临时文件失败，请检查服务器临时目录权限');
                break;
            }

            $fp = $zip->getStream($name);
            if (false === $fp) {
                @unlink($tmp);

                if (0 === $extracted && '' !== $password) {
                    $out['error'] = array(400, '解压密码错误，未产生任何文件');
                } else {
                    $out['error'] = array(400, '读取压缩包内条目失败（可能已加密但未提供密码）');
                }
                break;
            }
            $dst = @fopen($tmp, 'wb');
            if (!$dst) {
                @fclose($fp);
                @unlink($tmp);
                $out['error'] = array(500, '临时文件写入失败');
                break;
            }
            $bytes = stream_copy_to_stream($fp, $dst);
            @fclose($fp);
            @fclose($dst);

            if (false === $bytes && 0 === $extracted && '' !== $password) {
                @unlink($tmp);
                $out['error'] = array(400, '解压密码错误，未产生任何文件');
                break;
            }

            $stat = $zip->statIndex($i);
            $out['files'][] = array(
                'tmp'  => $tmp,
                'name' => $this->uniqueEntryName($base, $used),
                'size' => isset($stat['size']) ? (int) $stat['size'] : (int) @filesize($tmp),
            );
            $extracted++;
        }
        $zip->close();

        if (null !== $out['error']) {
            foreach ($out['files'] as $item) {
                @unlink($item['tmp']);
            }
            $out['files'] = array();
        }

        return $out;
    }

    private function tarExtract($real)
    {
        $out = array('files' => array(), 'skipped' => array(), 'error' => null);
        $used = array();
        $extracted = 0;

        try {
            $opened = $this->tarOpen($real);
            $phar = $opened['phar'];
        } catch (\Throwable $e) {
            $out['error'] = array(400, '无法打开压缩包（文件可能已损坏）：' . $e->getMessage());
            return $out;
        }

        $pharBase = 'phar://' . str_replace('\\', '/', realpath($phar->getPath()));

        try {
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                if ($file->isDir()) {
                    continue;
                }

                $relPath = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($pharBase) + 1), '/');
                $name = $file->getFilename();
                if ('' === $name || $this->isJunkEntry($relPath)) {
                    continue;
                }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, self::EXTRACT_SCRIPT_EXTS, true)) {
                    $out['skipped'][] = $name;
                    continue;
                }

                $tmp = tempnam(sys_get_temp_dir(), 'fmx');
                if (false === $tmp) {
                    $out['error'] = array(500, '创建临时文件失败，请检查服务器临时目录权限');
                    break;
                }

                try {
                    $bytes = @file_put_contents($tmp, file_get_contents($file->getPathname()));
                } catch (\Throwable $e) {
                    $bytes = false;
                }
                if (false === $bytes) {
                    @unlink($tmp);
                    $out['error'] = array(400, '读取压缩包内条目失败：' . $name);
                    break;
                }

                $out['files'][] = array(
                    'tmp'  => $tmp,
                    'name' => $this->uniqueEntryName($name, $used),
                    'size' => (int) @filesize($tmp),
                );
                $extracted++;
            }
        } catch (\Throwable $e) {
            $out['error'] = array(400, '无法打开压缩包（文件可能已损坏）：' . $e->getMessage());
        }

        $this->cleanupWorkDir($opened['workDir']);

        if (null !== $out['error']) {
            foreach ($out['files'] as $item) {
                @unlink($item['tmp']);
            }
            $out['files'] = array();
        }

        return $out;
    }

    private function tarOpen($real)
    {
        if ('.gz' !== strtolower(substr($real, -3))) {
            return array('phar' => new PharData($real), 'workDir' => '');
        }

        $workDir = sys_get_temp_dir() . '/fmtar' . uniqid();
        if (!@mkdir($workDir, 0700, true) && !is_dir($workDir)) {
            $this->json(500, '创建临时目录失败，请检查服务器临时目录权限');
        }
        $tmpGz = $workDir . '/pack.tar.gz';
        if (!@copy($real, $tmpGz)) {
            $this->cleanupWorkDir($workDir);
            $this->json(500, '读取压缩包失败（无法复制到临时目录）');
        }

        try {
            $phar = new PharData($tmpGz);
            $plain = $phar->decompress();
        } catch (\Throwable $e) {
            $this->cleanupWorkDir($workDir);
            $this->json(400, '无法解压 gzip 层（文件可能已损坏）：' . $e->getMessage());
        }

        return array('phar' => $plain, 'workDir' => $workDir);
    }

    private function cleanupWorkDir($dir)
    {
        if ('' === (string) $dir || !is_dir($dir)) {
            return;
        }
        foreach ((array) @scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            @unlink($dir . '/' . $item);
        }
        @rmdir($dir);
    }

    private function archiveKind($name, $ext)
    {
        $lower = strtolower((string) $name);
        $ext = strtolower((string) $ext);

        if ('zip' === $ext || '.zip' === substr($lower, -4)) {
            return 'zip';
        }
        if ('tar' === $ext || '.tar' === substr($lower, -4)) {
            return 'tar';
        }
        if ('tgz' === $ext || '.tgz' === substr($lower, -4)) {
            return 'tar';
        }
        if ('gz' === $ext || '.gz' === substr($lower, -3)) {
            if ('.tar.gz' === substr($lower, -7) || '.tgz' === substr($lower, -4)) {
                return 'tar';
            }
            return 'gz';
        }

        return null;
    }

    private function isJunkEntry($entry)
    {
        $path = str_replace('\\', '/', (string) $entry);
        if (false !== stripos($path, '__macosx')) {
            return true;
        }
        $base = strtolower(basename($path));
        return in_array($base, self::EXTRACT_JUNK_NAMES, true);
    }

    private function sanitizeEntrySegment($name)
    {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $name));
        $name = str_replace(array('/', '\\', ':', '*', '?', '"', '<', '>', '|'), '', $name);
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 180, 'UTF-8');
        } else {
            $name = substr($name, 0, 360);
        }
        return trim($name);
    }

    private function uniqueEntryName($entry, array &$used)
    {
        $entry = (string) $entry;
        $key = strtolower($entry);
        if (!isset($used[$key])) {
            $used[$key] = 1;
            return $entry;
        }

        $dir = '';
        $base = $entry;
        $pos = strrpos($entry, '/');
        if (false !== $pos) {
            $dir = substr($entry, 0, $pos + 1);
            $base = substr($entry, $pos + 1);
        }
        $dot = strrpos($base, '.');
        $stem = false !== $dot && $dot > 0 ? substr($base, 0, $dot) : $base;
        $extPart = false !== $dot && $dot > 0 ? substr($base, $dot) : '';

        do {
            $used[$key]++;
            $candidate = strtolower($dir . $stem . '-' . $used[$key] . $extPart);
        } while (isset($used[$candidate]) && $candidate !== $key);

        $used[$candidate] = 1;
        return $dir . $stem . '-' . $used[$key] . $extPart;
    }

    private function sanitizeZipName($name)
    {
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/', '', (string) $name));
        $name = str_replace(array('/', '\\', ':', '*', '?', '"', '<', '>', '|'), '', $name);
        $name = preg_replace('/^\.+\s*/', '', $name);
        if ('' === $name) {
            $name = date('YmdHis') . '.zip';
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 100, 'UTF-8');
        } else {
            $name = substr($name, 0, 200);
        }
        if (!preg_match('/\.zip$/i', $name)) {
            $name .= '.zip';
        }
        return $name;
    }

    private function sanitizeFolderTitle($name)
    {
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/', '', (string) $name));
        $name = str_replace(array('/', '\\', ':', '*', '?', '"', '<', '>', '|'), '', $name);
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 50, 'UTF-8');
        } else {
            $name = substr($name, 0, 100);
        }
        return trim($name);
    }

    private function ensureFolder($name, $parent)
    {
        $parent = max(0, (int) $parent);
        $db = $this->db();
        $folder = $db->fetchRow($db->select('fid')->from('table.filemanager_folders')
            ->where('name = ? AND parent = ?', $name, $parent)->limit(1));

        if ($folder) {
            return (int) $folder['fid'];
        }

        $user = \Widget\User::alloc();
        $db->query($db->insert('table.filemanager_folders')->rows(array(
            'name'    => $name,
            'parent'  => $parent,
            'sort'    => 0,
            'owner'   => (int) $user->uid,
            'created' => time(),
        )));

        $newRow = $db->fetchRow($db->select('fid')->from('table.filemanager_folders')
            ->where('name = ? AND parent = ?', $name, $parent)
            ->order('fid', \Typecho\Db::SORT_DESC)->limit(1));

        return $newRow ? (int) $newRow['fid'] : 0;
    }

    private function makeUploadTarget($ext)
    {
        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : 'usr/uploads';
        $uploadDir = trim(str_replace('\\', '/', (string) $uploadDir), '/');
        if ('' === $uploadDir) {
            $uploadDir = 'usr/uploads';
        }
        $baseDir = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
        $baseDir = rtrim(str_replace('\\', '/', (string) $baseDir), '/');

        $relDir = $uploadDir . '/' . date('Y') . '/' . date('m');
        $absDir = $baseDir . '/' . $relDir;

        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            return null;
        }

        $ext = preg_replace('/[^a-z0-9.]/', '', strtolower((string) $ext));
        $fileName = sprintf('%u', crc32(uniqid())) . ('' !== $ext ? '.' . $ext : '');

        return array(
            'abs' => $absDir . '/' . $fileName,
            'rel' => $relDir . '/' . $fileName,
        );
    }

    private function registerAttachment($absFile, $relPath, $displayName, $size, $ext, $mime, $fid)
    {
        if (!is_file($absFile)) {
            return 0;
        }

        $db = $this->db();
        $user = \Widget\User::alloc();
        $options = \Widget\Options::alloc();
        $now = time();

        /* 返回 0 而非抛异常：批次内单个失败不该中断整批解压 */
        try {
            $cid = (int) $db->query($db->insert('table.contents')->rows(array(
                'title'        => self::clampChars((string) $displayName, 200),
                'slug'         => self::uniqueSlug((string) $displayName),
                'created'      => $now,
                'modified'     => $now,
                'text'         => serialize(array(
                    'name' => (string) $displayName,
                    'path' => (string) $relPath,
                    'size' => (int) $size,
                    'type' => (string) $ext,
                    'mime' => (string) $mime,
                )),
                'authorId'     => (int) $user->uid,
                'type'         => 'attachment',
                'status'       => 'publish',
                'password'     => '',
                'commentsNum'  => 0,
                'allowComment' => (string) $options->defaultAllowComment,
                'allowPing'    => (string) $options->defaultAllowPing,
                'allowFeed'    => (string) $options->defaultAllowFeed,
                'parent'       => 0,
            )));
        } catch (\Throwable $e) {
            return 0;
        }

        if ($cid > 0 && $fid > 0 && $this->folderExists($fid)) {
            $db->query($db->insert('table.filemanager_map')->rows(array(
                'cid'  => $cid,
                'fid'  => (int) $fid,
                'size' => (int) $size,
                'ext'  => (string) $ext,
            )));
        }

        return $cid;
    }

    private function sanitizeShareToken($token)
    {
        $token = trim((string) $token);

        if ('' === $token) {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $token)) {
            return null;
        }

        return $token;
    }

    private function shareTokenExists($token, $excludeId = 0)
    {
        $db = $this->db();
        $query = $db->select('id')->from('table.filemanager_shares')->where('token = ?', $token);

        if ($excludeId > 0) {
            $query->where('id <> ?', $excludeId);
        }

        return (bool) $db->fetchRow($query);
    }

    private function sanitizeDisplayName($name)
    {        $name = trim(preg_replace('/[\x00-\x1f\x7f]/', '', (string) $name));

        if ('' === $name) {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, 120, 'UTF-8');
        }

        return substr($name, 0, 240);
    }

    private function sanitizeShareNote($note)
    {
        $note = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', (string) $note));

        if ('' === $note) {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($note, 0, 2000, 'UTF-8');
        }

        return substr($note, 0, 4000);
    }

    private function db()
    {
        return \Typecho\Db::get();
    }

    /** 按字符截断（varchar 按字符计宽）；无 mbstring 时逐字节回退保证合法 UTF-8 */
    private static function clampChars(string $s, int $limit): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($s, 'UTF-8') > $limit ? mb_substr($s, 0, $limit, 'UTF-8') : $s;
        }

        $len = strlen($s);
        if ($len <= $limit) {
            return $s;
        }

        $s = substr($s, 0, $limit);
        while ('' !== $s && 1 !== @preg_match('//u', $s)) {
            $s = substr($s, 0, -1);
        }

        return $s;
    }

    /** 生成未被占用的 slug（同名加 -1/-2）：插件直接写 contents，绕过了核心的 applySlug 去重 */
    private static function uniqueSlug(string $slug, string $fallback = 'attachment'): string
    {
        $db = \Typecho\Db::get();

        $slug = trim($slug);
        if ('' === $slug) {
            $slug = $fallback;
        }

        /* slug 列 varchar(200)，先留出 "-123" 的位置 */
        $slug = self::clampChars($slug, 180);

        $cand = $slug;
        for ($n = 1; $n <= 300; $n++) {
            $hit = $db->fetchRow($db->select('cid')->from('table.contents')
                ->where('slug = ?', $cand)->limit(1));
            if (!$hit) {
                return $cand;
            }
            $cand = $slug . '-' . $n;
        }

        return $slug . '-' . substr(md5(uniqid('', true)), 0, 8);
    }

    /** 把致命错误转成可读 JSON，仅在尚未输出时接管 */
    private static function registerFatalGuard()
    {
        static $done = false;
        if ($done) { return; }
        $done = true;

        register_shutdown_function(function () {
            $err = error_get_last();
            if (!is_array($err) || empty($err['type'])) { return; }
            $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
            if (0 === ($err['type'] & $fatal)) { return; }
            if (headers_sent()) { return; }

            $msg = (string) $err['message'];
            if (false !== strpos($msg, 'Allowed memory size')) {
                $limit = function_exists('ini_get') ? (string) ini_get('memory_limit') : '未知';
                $msg = '服务器内存不足：本次操作所需内存超过 memory_limit（当前 ' . $limit . '），请提高 PHP 内存上限后重试。';
            } elseif (false !== strpos($msg, 'Maximum execution time')) {
                $msg = '服务器执行超时：文件较大，可在 PHP 设置中提高 max_execution_time 后重试。';
            } else {
                $msg = '服务器处理异常：' . $msg;
            }

            self::discardStrayOutput();
            @header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(array(
                'code'    => 500,
                'message' => $msg,
                'ver'     => FileManager_Plugin::VERSION,
            ), JSON_UNESCAPED_UNICODE);
        });
    }

    private function json($code, $message = '', $data = array())
    {
        self::discardStrayOutput();
        $this->response->throwJson(array_merge(array(
            'code'    => $code,
            'message' => $message,
            'ver'     => FileManager_Plugin::VERSION,
        ), $data));
    }

    /** 丢弃入口后的意外输出（warning/notice），只保留外层缓冲 */
    private static function discardStrayOutput()
    {
        if (null === self::$obBase) {
            $len = ob_get_length();
            if (false !== $len && $len > 0) { @ob_clean(); }
            return;
        }
        while (ob_get_level() > self::$obBase) { @ob_end_clean(); }
    }

    private function assertFolderName($name)
    {
        $length = function_exists('mb_strlen')
            ? (int) mb_strlen($name, 'UTF-8')
            : (int) preg_match_all('/./us', $name);
        if ('' === $name || $length > 50) {
            $this->json(400, '文件夹名称需为 1-50 个字符');
        }
        if (false !== strpos($name, '/') || false !== strpos($name, '\\')) {
            $this->json(400, '文件夹名称不能包含路径分隔符');
        }
    }

    private function folderExists($fid)
    {
        return (bool) $this->db()->fetchRow($this->db()->select('fid')
            ->from('table.filemanager_folders')->where('fid = ?', $fid)->limit(1));
    }

    private function parseCids($raw)
    {
        $cids = array_filter(array_map('intval', explode(',', (string) $raw)), function ($v) {
            return $v > 0;
        });

        if (empty($cids)) {
            $this->json(400, '未选择任何文件');
        }

        return array_values(array_unique($cids));
    }

    private function classifyStats($force)
    {
        if (empty($this->categories())) {
            return array();
        }

        $db = $this->db();
        $prefix = $db->getPrefix();

        $sql = "SELECT m.ext, COUNT(*) AS cnt FROM `{$prefix}contents` c"
            . " INNER JOIN `{$prefix}filemanager_map` m ON c.cid = m.cid"
            . " WHERE c.type = 'attachment'";

        if (!$force) {
            $sql .= ' AND m.fid = 0';
        }
        $sql .= ' GROUP BY m.ext';

        $items = array();
        foreach ($db->fetchAll($sql) as $row) {
            $name = $this->categoryOfExt((string) $row['ext']);
            if (null === $name) {
                $name = 'other';
            }
            if (!isset($items[$name])) {
                $items[$name] = array(
                    'key'   => $name,
                    'name'  => 'other' === $name ? '其它' : $name,
                    'count' => 0,
                );
            }
            $items[$name]['count'] += (int) $row['cnt'];
        }

        foreach ($this->categories() as $name => $category) {
            if (!isset($items[$name])) {
                $items[$name] = array(
                    'key'   => $name,
                    'name'  => $name,
                    'count' => 0,
                );
            }
        }

        usort($items, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        return array_values($items);
    }

    private function categoryAssignments($force)
    {
        $db = $this->db();
        $prefix = $db->getPrefix();

        $sql = "SELECT c.cid, c.parent FROM `{$prefix}contents` c"
            . " INNER JOIN `{$prefix}filemanager_map` m ON c.cid = m.cid"
            . " WHERE c.type = 'attachment'";
        if (!$force) {
            $sql .= ' AND m.fid = 0';
        }

        $attachments = array();
        foreach ($db->fetchAll($sql) as $row) {
            $attachments[(int) $row['cid']] = (int) $row['parent'];
        }

        if (empty($attachments)) {
            return array();
        }

        $postCats = array();
        $rows = $db->fetchAll($db->select('table.relationships.cid AS rcid', 'table.metas.name AS mname')
            ->from('table.relationships')
            ->join('table.metas', 'table.relationships.mid = table.metas.mid', \Typecho\Db::INNER_JOIN)
            ->where('table.metas.type = ?', 'category')
            ->order('table.metas.name', \Typecho\Db::SORT_ASC));

        foreach ($rows as $row) {
            $postCats[(int) $row['rcid']][] = (string) $row['mname'];
        }

        $assign = array();
        foreach ($attachments as $cid => $parent) {
            $assign[$cid] = isset($postCats[$parent]) ? $postCats[$parent][0] : '';
        }

        return $assign;
    }

    private function classifyStatsByCategory($force)
    {
        $items = array();
        foreach ($this->categoryAssignments($force) as $cat) {
            if (!isset($items[$cat])) {
                $items[$cat] = array(
                    'key'   => $cat,
                    'name'  => '' === $cat ? '未归属' : $cat,
                    'count' => 0,
                );
            }
            $items[$cat]['count']++;
        }

        usort($items, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        return array_values($items);
    }

    private function syncMap()
    {
        $db = $this->db();
        $prefix = $db->getPrefix();

        $db->query("DELETE FROM `{$prefix}filemanager_map` WHERE cid NOT IN"
            . " (SELECT cid FROM `{$prefix}contents` WHERE type = 'attachment')");
        $db->query("DELETE FROM `{$prefix}filemanager_shares` WHERE cid NOT IN"
            . " (SELECT cid FROM `{$prefix}contents` WHERE type = 'attachment')");

        $missing = $db->fetchAll("SELECT cid, authorId, text FROM `{$prefix}contents` WHERE type = 'attachment'"
            . " AND cid NOT IN (SELECT cid FROM `{$prefix}filemanager_map`)");

        $prefCache = array();

        foreach ($missing as $row) {
            $uid = (int) $row['authorId'];
            if (!isset($prefCache[$uid])) {
                try {
                    $prefCache[$uid] = \FileManager_Plugin::prefUploadFid($uid);
                } catch (\Throwable $e) {
                    $prefCache[$uid] = 0;
                }
            }
            $info = self::parseAttachmentText($row['text']);
            $db->query($db->insert('table.filemanager_map')->rows(array(
                'cid'  => (int) $row['cid'],
                'fid'  => $prefCache[$uid],
                'size' => (int) $info['size'],
                'ext'  => $info['ext'],
            )));
        }
    }

    private function doPrefsGet()
    {
        $user = \Widget\User::alloc();
        $this->json(200, '', array(
            'prefs'  => \FileManager_Plugin::getUserPrefs((int) $user->uid),
            'global' => self::globalSettingsForPrefs(),
        ));
    }

    /** 偏好设置里同时展示的插件级设置（与设置页共用同一份配置） */
    private static function globalSettingsForPrefs()
    {
        try {
            $s = \FileManager_Plugin::settings();
            $days = (int) $s['recycleDays'];
            if ($days < 1) {
                $days = 30;
            }
            return array(
                'recycleEnabled' => (int) $s['recycleEnabled'],
                'recycleDays'    => $days,
                'videoHtml'      => (int) $s['editorVideoHtml'],
            );
        } catch (\Throwable $e) {
            return array('recycleEnabled' => 1, 'recycleDays' => 30, 'videoHtml' => 0);
        }
    }

    private function doPrefsSave()
    {
        $raw = (string) $this->request->get('prefs', '');
        $prefs = json_decode($raw, true);
        if (!is_array($prefs)) {
            $this->json(400, '参数格式错误');
        }

        $user = \Widget\User::alloc();
        $uid = (int) $user->uid;

        $clean = array(
            'gridDefault' => '5x6',
            'uploadMode'  => 'uncat',
            'uploadFid'   => 0,
            'pasteMode'   => 'uncat',
            'pasteFid'    => 0,
            'startMode'   => 'all',
            'startFid'    => 0,
            'shortcut'    => '1',
            'hoverPreview'=> '1',
            'defaultUploadDest' => 'site',
            'uploadConv'  => '',
            'uploadRenameDt' => '0',
            'sortDefault' => 'time-desc',
        );
        $gridDefault = (string) (isset($prefs['gridDefault']) ? $prefs['gridDefault'] : '');
        $clean['gridDefault'] = in_array($gridDefault, array('5x6', '4x7', 'masonry', 'list'), true)
            ? $gridDefault : '5x6';

        $shortcut = (string) (isset($prefs['shortcut']) ? $prefs['shortcut'] : '1');
        $clean['shortcut'] = ('0' === $shortcut) ? '0' : '1';

        $clean['hoverPreview'] = (isset($prefs['hoverPreview']) && '0' === (string) $prefs['hoverPreview'])
            ? '0' : '1';

        $clean['thumbCache'] = (isset($prefs['thumbCache']) && '0' === (string) $prefs['thumbCache'])
            ? '0' : '1';

        $clean['defaultUploadDest'] = (isset($prefs['defaultUploadDest']) && 'imagehost' === (string) $prefs['defaultUploadDest'])
            ? 'imagehost' : 'site';

        $uploadConv = (string) (isset($prefs['uploadConv']) ? $prefs['uploadConv'] : '');
        $clean['uploadConv'] = in_array($uploadConv, array('', 'webp', 'jpg', 'png'), true) ? $uploadConv : '';

        $clean['uploadRenameDt'] = (isset($prefs['uploadRenameDt']) && '1' === (string) $prefs['uploadRenameDt'])
            ? '1' : '0';

        $sortDefault = (string) (isset($prefs['sortDefault']) ? $prefs['sortDefault'] : 'time-desc');
        $clean['sortDefault'] = in_array($sortDefault, array(
            'time-desc', 'time-asc', 'name-desc', 'name-asc', 'size-desc', 'size-asc',
        ), true) ? $sortDefault : 'time-desc';

        if (isset($prefs['imageHost']) && is_array($prefs['imageHost'])) {
            $ih = $prefs['imageHost'];
            $ihPrev = \FileManager_Plugin::getUserPrefs($uid);
            $ihPrev = (isset($ihPrev['imageHost']) && is_array($ihPrev['imageHost'])) ? $ihPrev['imageHost'] : array();
            $ihVal = function (string $k) use ($ih, $ihPrev) {
                if (array_key_exists($k, $ih)) {
                    return $ih[$k];
                }
                return isset($ihPrev[$k]) ? $ihPrev[$k] : '';
            };
            $ihType = (string) $ihVal('type');
            $clean['imageHost'] = array(
                'type'       => in_array($ihType, array('lsky', 's3'), true) ? $ihType : 'lsky',
                'enabled'    => ('1' === (string) $ihVal('enabled')) ? '1' : '0',
                'api'        => mb_substr(trim((string) $ihVal('api')), 0, 200),
                'token'      => mb_substr(trim((string) $ihVal('token')), 0, 200),
                'strategyId' => (int) $ihVal('strategyId'),
                'albumId'    => (int) $ihVal('albumId'),
                'permission' => ('0' === (string) $ihVal('permission')) ? '0' : '1',
                's3Endpoint'  => mb_substr(trim((string) $ihVal('s3Endpoint')), 0, 200),
                's3Region'    => mb_substr(trim((string) $ihVal('s3Region')), 0, 60),
                's3Bucket'    => mb_substr(trim((string) $ihVal('s3Bucket')), 0, 120),
                's3AccessKey' => mb_substr(trim((string) $ihVal('s3AccessKey')), 0, 200),
                's3SecretKey' => mb_substr(trim((string) $ihVal('s3SecretKey')), 0, 200),
                's3Prefix'    => mb_substr(trim((string) $ihVal('s3Prefix')), 0, 120),
                's3BaseUrl'   => mb_substr(trim((string) $ihVal('s3BaseUrl')), 0, 300),
            );
        } else {

            $existing = \FileManager_Plugin::getUserPrefs($uid);
            $clean['imageHost'] = (isset($existing['imageHost']) && is_array($existing['imageHost']))
                ? $existing['imageHost'] : null;
        }

        $hlTheme = (string) (isset($prefs['hlTheme']) ? $prefs['hlTheme'] : 'github');
        $clean['hlTheme'] = in_array($hlTheme, array(
            'none', 'github', 'atom-one-light', 'atom-one-dark', 'github-dark', 'monokai', 'nord', 'vs2015',
        ), true) ? $hlTheme : 'github';

        $uploadFid = isset($prefs['uploadFid']) ? (int) $prefs['uploadFid'] : 0;
        $uploadMode = (string) (isset($prefs['uploadMode']) ? $prefs['uploadMode'] : '');
        if (!in_array($uploadMode, array('uncat', 'latest', 'custom'), true)) {
            $uploadMode = (-1 === $uploadFid) ? 'latest' : ($uploadFid > 0 ? 'custom' : 'uncat');
        }
        $clean['uploadMode'] = $uploadMode;
        if ('latest' === $uploadMode) {

            $clean['uploadFid'] = -1;
        } elseif ('custom' === $uploadMode) {
            if ($uploadFid <= 0 || !$this->folderExists($uploadFid)) {
                $this->json(400, '请选择上传文件的默认文件夹');
            }
            $clean['uploadFid'] = $uploadFid;
        }

        $pasteMode = (string) (isset($prefs['pasteMode']) ? $prefs['pasteMode'] : 'uncat');
        $clean['pasteMode'] = in_array($pasteMode, array('uncat', 'current', 'latest', 'custom'), true)
            ? $pasteMode : 'uncat';

        $pasteFid = isset($prefs['pasteFid']) ? (int) $prefs['pasteFid'] : 0;
        if ('custom' === $clean['pasteMode']) {
            if ($pasteFid <= 0 || !$this->folderExists($pasteFid)) {
                $this->json(400, '请选择粘贴截图上传的默认文件夹');
            }
            $clean['pasteFid'] = $pasteFid;
        }

        $startMode = (string) (isset($prefs['startMode']) ? $prefs['startMode'] : 'all');
        $clean['startMode'] = in_array($startMode, array('all', 'uncat', 'latest', 'last', 'custom'), true)
            ? $startMode : 'all';

        $startFid = isset($prefs['startFid']) ? (int) $prefs['startFid'] : 0;
        if ('custom' === $clean['startMode']) {
            if ($startFid <= 0 || !$this->folderExists($startFid)) {
                $this->json(400, '请选择进入文件管理页时打开的文件夹');
            }
            $clean['startFid'] = $startFid;
        }

        try {
            \FileManager_Plugin::ensurePrefsTable();
            $db = $this->db();
            $exists = $db->fetchRow($db->select('uid')->from('table.filemanager_prefs')
                ->where('uid = ?', $uid)->limit(1));
            $json = json_encode($clean);
            if ($exists) {
                $db->query($db->update('table.filemanager_prefs')
                    ->rows(array('prefs' => $json))->where('uid = ?', $uid));
            } else {
                $db->query($db->insert('table.filemanager_prefs')
                    ->rows(array('uid' => $uid, 'prefs' => $json)));
            }
        } catch (\Throwable $e) {
            $this->json(500, '偏好设置保存失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        $globalRaw = (string) $this->request->get('global', '');
        if ('' !== $globalRaw) {
            $g = self::parseGlobalPrefs($globalRaw);
            if (null !== $g) {
                try {
                    /* 与插件设置页共用同一份插件配置，避免两处各存一份导致不一致 */
                    \Widget\Plugins\Edit::configPlugin(basename(__DIR__), array(
                        'editorVideoHtml' => $g['videoHtml'] ? array(1 => 1) : array(),
                        'recycleEnabled'  => $g['recycleEnabled'] ? array(1 => 1) : array(),
                        'recycleDays'     => (string) $g['recycleDays'],
                    ));
                } catch (\Throwable $e) {

                }
            }
        }

        $this->json(200, '偏好设置已保存', array('prefs' => $clean));
    }

    /** 解析偏好设置里提交的插件级配置；天数收敛到 1–3650，非法返回 null */
    private static function parseGlobalPrefs($raw)
    {
        $g = json_decode((string) $raw, true);
        if (!is_array($g)) {
            return null;
        }
        $days = isset($g['recycleDays']) ? (int) $g['recycleDays'] : 30;
        if ($days < 1) {
            $days = 1;
        } elseif ($days > 3650) {
            $days = 3650;
        }
        return array(
            'recycleEnabled' => empty($g['recycleEnabled']) ? 0 : 1,
            'recycleDays'    => $days,
            'videoHtml'      => empty($g['videoHtml']) ? 0 : 1,
        );
    }

    private function categories()
    {
        return FileManager_Plugin::categories();
    }

    private function extIn($query, array $exts)
    {
        if (empty($exts)) {
            $query->where('1 = 0');
            return;
        }

        $placeholders = implode(',', array_fill(0, count($exts), '?'));
        $query->where('table.filemanager_map.ext IN (' . $placeholders . ')', ...array_values($exts));
    }

    private function allKnownExts()
    {
        $exts = array();
        foreach ($this->categories() as $category) {
            $exts = array_merge($exts, $category['exts']);
        }

        return array_values(array_unique($exts));
    }

    private function categoryOfExt($ext)
    {
        $ext = strtolower($ext);
        foreach ($this->categories() as $name => $category) {
            if (in_array($ext, $category['exts'])) {
                return $name;
            }
        }

        return null;
    }

    public static function parseAttachmentText($text)
    {
        $data = self::decodeAttachmentRaw($text);

        $name = isset($data['name']) ? (string) $data['name'] : '';
        $ext = isset($data['type']) ? strtolower((string) $data['type']) : '';
        if ('' === $ext && '' !== $name && false !== ($pos = strrpos($name, '.'))) {
            $ext = strtolower(substr($name, $pos + 1));
        }

        return array(
            'name' => $name,
            'path' => isset($data['path']) ? (string) $data['path'] : '',
            'size' => isset($data['size']) ? (int) $data['size'] : 0,
            'ext'  => $ext,
            'mime' => isset($data['mime']) ? (string) $data['mime'] : '',
            'exif' => isset($data['fm_exif']) && is_array($data['fm_exif']) ? $data['fm_exif'] : null,
            'ih'   => isset($data['fm_ih']) && is_array($data['fm_ih']) ? $data['fm_ih'] : null,
        );
    }

    public static function isImageHostActive(array $info)
    {
        if (!isset($info['ih']) || !is_array($info['ih']) || empty($info['ih']['url'])) {
            return false;
        }
        $use = isset($info['ih']['use']) ? (string) $info['ih']['use'] : '';
        if ('site' === $use) {
            return false;
        }
        if ('ih' === $use) {
            return true;
        }
        $file = '' !== $info['path'] ? self::resolveAttachmentFile($info['path']) : null;
        return null === $file || !is_file($file);
    }

    public static function ihStorageType(array $info)
    {
        if (!isset($info['ih']) || !is_array($info['ih'])) {
            return '';
        }
        return ('s3' === (string) (isset($info['ih']['st']) ? $info['ih']['st'] : '')) ? 's3' : 'lsky';
    }

    public static function displayAttachmentUrl(array $info, $rootUrl = null)
    {
        $siteUrl = ('' !== $info['path'] && null !== $rootUrl)
            ? \Typecho\Common::url($info['path'], $rootUrl) : '';
        if (self::isImageHostActive($info)) {
            return (string) $info['ih']['url'];
        }
        return $siteUrl;
    }

    private static function decodeAttachmentRaw($text)
    {
        $text = (string) $text;
        $data = null;

        $trimmed = ltrim($text);
        if ('' !== $trimmed && ('{' === $trimmed[0] || '[' === $trimmed[0])) {
            $data = json_decode($text, true);
        }
        if (!is_array($data)) {
            $data = @unserialize($text);
        }
        if (!is_array($data)) {
            $data = json_decode($text, true);
        }

        return is_array($data) ? $data : array();
    }

    public static function isTypecho13Up()
    {
        if (class_exists('\Typecho\Common') && defined('\Typecho\Common::VERSION')) {
            return version_compare((string) \Typecho\Common::VERSION, '1.3', '>=');
        }
        return false;
    }

    public static function attachmentText(array $result)
    {
        return self::isTypecho13Up() ? json_encode($result) : serialize($result);
    }

    public static function isImageExt($ext, $mime = '')
    {
        if ('' !== $mime && 0 === strpos(strtolower($mime), 'image/')) {
            return true;
        }

        $ext = strtolower($ext);

        if (in_array($ext, FileManager_Plugin::$categories['image']['exts'])) {
            return true;
        }

        foreach (FileManager_Plugin::categories() as $name => $category) {
            if ('图片' === $name && in_array($ext, $category['exts'])) {
                return true;
            }
        }

        return false;
    }

    public static function resolveAttachmentFile($path)
    {
        if ('' === $path) {
            return null;
        }

        $path = str_replace('\\', '/', trim((string) $path));

        if (preg_match('#^https?://[^/]+(/.*)$#i', $path, $m)) {
            $path = ltrim($m[1], '/');
        }

        if (!defined('__TYPECHO_ROOT_DIR__')) {
            return null;
        }
        $root = rtrim(str_replace('\\', '/', __TYPECHO_ROOT_DIR__), '/');

        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : 'usr/uploads';
        $uploadDir = str_replace('\\', '/', trim((string) $uploadDir, '/'));

        $bases = array();
        if (preg_match('#^([A-Za-z]:|)/#', $uploadDir)) {
            $bases[] = rtrim($uploadDir, '/');
        } else {
            $bases[] = $root . '/' . $uploadDir;
        }
        if (defined('__TYPECHO_UPLOAD_ROOT_DIR__')) {
            $uploadRoot = rtrim(str_replace('\\', '/', __TYPECHO_UPLOAD_ROOT_DIR__), '/');
            $bases[] = $uploadRoot . '/' . $uploadDir;
            $bases[] = $uploadRoot;
        }
        $bases[] = $root;

        $stripped = ltrim($path, '/');
        $relative = $stripped;
        foreach (array($uploadDir, 'usr/uploads') as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ('' !== $prefix && 0 === stripos($relative, $prefix . '/')) {
                $relative = substr($relative, strlen($prefix) + 1);
                break;
            }
        }

        foreach (array_unique(array($stripped, $relative)) as $rel) {
            if ('' === $rel) {
                continue;
            }
            foreach ($bases as $base) {
                $real = realpath($base . '/' . ltrim($rel, '/'));
                if (false !== $real && self::isUnderBase($real, $base)) {
                    return $real;
                }
            }
        }

        if (preg_match('#^([A-Za-z]:|)/#', $path)) {
            $real = realpath($path);
            if (false !== $real) {
                foreach ($bases as $base) {
                    if (self::isUnderBase($real, $base)) {
                        return $real;
                    }
                }
            }
        }

        return null;
    }

    public static function computeAttachmentFile($path)
    {
        $path = str_replace('\\', '/', trim((string) $path));
        if (preg_match('#^https?://[^/]+(/.*)$#i', $path, $m)) {
            $path = ltrim($m[1], '/');
        }
        if (!defined('__TYPECHO_ROOT_DIR__')) {
            return null;
        }
        $root = rtrim(str_replace('\\', '/', __TYPECHO_ROOT_DIR__), '/');

        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : 'usr/uploads';
        $uploadDir = str_replace('\\', '/', trim((string) $uploadDir, '/'));

        $bases = array();
        if (preg_match('#^([A-Za-z]:|)/#', $uploadDir)) {
            $bases[] = rtrim($uploadDir, '/');
        } else {
            $bases[] = $root . '/' . $uploadDir;
        }
        if (defined('__TYPECHO_UPLOAD_ROOT_DIR__')) {
            $bases[] = rtrim(str_replace('\\', '/', __TYPECHO_UPLOAD_ROOT_DIR__), '/') . '/' . $uploadDir;
        }

        $stripped = ltrim($path, '/');
        $relative = $stripped;
        foreach (array($uploadDir, 'usr/uploads') as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ('' !== $prefix && 0 === stripos($relative, $prefix . '/')) {
                $relative = substr($relative, strlen($prefix) + 1);
                break;
            }
        }

        foreach (array_unique(array($relative, $stripped)) as $rel) {
            if ('' === $rel) {
                continue;
            }
            foreach ($bases as $base) {
                $cand = rtrim($base, '/') . '/' . ltrim($rel, '/');
                if (self::pathUnderBase($cand, $base)) {
                    return $cand;
                }
            }
        }
        return null;
    }

    private static function pathUnderBase($path, $base)
    {
        $norm = self::normalizePathForCompare($path);
        $baseReal = realpath($base);
        $baseNorm = (false !== $baseReal)
            ? self::normalizePathForCompare($baseReal)
            : self::normalizePathForCompare($base);
        return '' !== $baseNorm && 0 === strpos($norm, $baseNorm . '/');
    }

    private static function normalizePathForCompare($p)
    {
        $p = strtolower(str_replace('\\', '/', (string) $p));
        $isWin = (bool) preg_match('#^[a-z]:#', $p);
        $stack = array();
        foreach (explode('/', $p) as $seg) {
            if ('' === $seg || '.' === $seg) {
                continue;
            }
            if ('..' === $seg) {
                array_pop($stack);
                continue;
            }
            $stack[] = $seg;
        }
        $joined = implode('/', $stack);
        return $isWin ? $joined : '/' . $joined;
    }

    public static function trashDir()
    {
        if (!defined('__TYPECHO_ROOT_DIR__')) {
            return null;
        }
        $base = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : '/usr/uploads';
        $root = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
        $dir = \Typecho\Common::url($base, $root) . '/.trash';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        return $dir;
    }

    public static function recycleEnabled()
    {
        $s = \FileManager_Plugin::settings();
        return !empty($s['recycleEnabled']);
    }

    private static function dropOriginBackup($path)
    {
        if ('' === (string) $path || !class_exists('FileManager_ImageTool')) {
            return;
        }
        try {
            \FileManager_ImageTool::dropArchive((string) $path);
        } catch (\Throwable $e) {

        }
    }

    public static function trashDays()
    {
        $s = \FileManager_Plugin::settings();
        $days = isset($s['recycleDays']) ? (int) $s['recycleDays'] : 30;
        return max(1, $days > 0 ? $days : 30);
    }

    public static function trashAttachment($cid, $contentsRow, $info, $folderFid = 0)
    {
        if (!self::recycleEnabled()) {
            return false;
        }

        $file = self::resolveAttachmentFile($info['path']);
        if (null === $file || !is_file($file)) {
            return false;
        }

        $trashDir = self::trashDir();
        if (null === $trashDir) {
            return false;
        }

        $safeName = str_replace(array('/', '\\', "\0"), '_', '' !== $info['name'] ? $info['name'] : basename($file));
        $trashName = time() . '-' . (int) $cid . '-' . $safeName;
        if (strlen($trashName) > 240) {
            $trashName = time() . '-' . (int) $cid . '-' . substr($safeName, -180);
        }
        $dest = $trashDir . '/' . $trashName;

        if (!@rename($file, $dest)) {

            if (!@copy($file, $dest) || !@unlink($file)) {
                @unlink($dest);
                return false;
            }
        }

        try {
            $db = \Typecho\Db::get();
            $db->query($db->insert('table.filemanager_trash')->rows(array(
                'cid'        => (int) $cid,
                'name'       => (string) $info['name'],
                'path'       => (string) $info['path'],
                'size'       => (int) $info['size'],
                'ext'        => (string) $info['ext'],
                'mime'       => (string) $info['mime'],
                'folder_fid' => (int) $folderFid,
                'trash_name' => $trashName,
                'data'       => serialize($contentsRow),
                'owner'      => isset($contentsRow['authorId']) ? (int) $contentsRow['authorId'] : 0,
                'created'    => time(),
                'expire'     => time() + self::trashDays() * 86400,
            )));
        } catch (\Throwable $e) {

            @rename($dest, $file);
            return false;
        }

        return true;
    }

    public static function purgeExpiredTrash()
    {
        try {
            $db = \Typecho\Db::get();
            $rows = $db->fetchAll($db->select('id', 'path', 'trash_name')->from('table.filemanager_trash')
                ->where('expire <= ?', time())->limit(200));
        } catch (\Throwable $e) {
            return 0;
        }
        if (!$rows) {
            return 0;
        }

        $dir = self::trashDir();
        $n = 0;
        foreach ($rows as $row) {
            if (null !== $dir && '' !== (string) $row['trash_name']) {
                $f = $dir . '/' . $row['trash_name'];
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            self::dropOriginBackup((string) $row['path']);
            try {
                $db->query($db->delete('table.filemanager_trash')->where('id = ?', (int) $row['id']));
                $n++;
            } catch (\Throwable $e) {

            }
        }
        return $n;
    }

    private static function isUnderBase($real, $base)
    {
        $baseReal = realpath($base);
        if (false === $baseReal) {
            return false;
        }

        $baseReal = rtrim(str_replace('\\', '/', $baseReal), '/');
        $realNorm = str_replace('\\', '/', $real);

        return 0 === strpos($realNorm, $baseReal . '/');
    }

    private function shareUrl($token)
    {
        try {
            $url = \Typecho\Router::url('filemanager_share', array('token' => $token), \Widget\Options::alloc()->index);
            if (false === strpos($url, '{token}')) {
                return $url;
            }
        } catch (\Throwable $e) {

        }

        return \Widget\Options::alloc()->index . '/file/s/' . $token;
    }
}
