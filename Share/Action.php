<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class FileManager_Share_Action extends \Typecho\Widget implements \Widget\ActionInterface
{

    public function execute()
    {
    }

    public function action()
    {
        $token = (string) $this->request->get('token', '');

        if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $token)) {
            $this->renderNotice('链接无效', '分享链接不存在或已被删除。', 404);
            return;
        }

        $db = \Typecho\Db::get();
        FileManager_Plugin::ensureShareSchema();

        $share = $db->fetchRow($db->select()
            ->from('table.filemanager_shares')
            ->where('token = ?', $token)->limit(1));

        if (!$share) {
            $this->renderNotice('链接无效', '分享链接不存在或已被创建者删除。', 404);
            return;
        }

        $content = $db->fetchRow($db->select()
            ->from('table.contents')
            ->where('cid = ? AND type = ?', (int) $share['cid'], 'attachment')->limit(1));

        if (!$content) {
            $this->renderNotice('文件不存在', '该文件已被删除，无法下载。', 404);
            return;
        }

        $now = time();

        if ((int) $share['expire'] > 0 && (int) $share['expire'] < $now) {
            $this->renderNotice('链接已过期', '该分享链接已超过有效期，请联系分享者重新分享。', 410);
            return;
        }

        if ((int) $share['max_downloads'] > 0 && (int) $share['downloads'] >= (int) $share['max_downloads']) {
            $this->renderNotice('下载次数已达上限', '该分享链接的下载次数已达上限，请联系分享者重新分享。', 410);
            return;
        }

        $info = FileManager_Action::parseAttachmentText($content['text']);
        if ('' === $info['path']) {
            $this->renderNotice('文件不可用', '该文件信息异常，无法下载。', 500);
            return;
        }

        if ('' !== $share['password']) {
            if (!$this->verifyPassword((int) $share['id'], (string) $share['password'])) {
                return;
            }
        }

        $loginRequired = isset($share['login_required']) ? (int) $share['login_required'] : 0;

        if ('1' === (string) $this->request->get('preview', '')) {
            $previewEnabled = isset($share['preview']) ? (int) $share['preview'] : 0;
            if (!$previewEnabled) {
                $this->renderNotice('预览未开启', '该分享未开启在线预览，请直接下载文件查看。', 403);
                return;
            }
            if ($loginRequired && !\Widget\User::alloc()->hasLogin()) {
                $this->renderNotice('需要登录', '该文件仅登录用户可预览，请登录后再试。', 403);
                return;
            }
            if (!$this->verifyDownloadKey((int) $share['id'])) {
                $this->renderNotice('预览链接已失效', '预览链接为随机生成且跟随浏览会话，请返回分享页面重新操作。', 403);
                return;
            }
            $this->streamPreview($info, $content);
            return;
        }

        if ('1' === (string) $this->request->get('download', '')) {
            if ($loginRequired && !\Widget\User::alloc()->hasLogin()) {
                $this->renderNotice('需要登录', '该文件仅登录用户可下载，请登录后再试。', 403);
                return;
            }
            if (!$this->verifyDownloadKey((int) $share['id'])) {
                $this->renderNotice('下载链接已失效', '下载链接为随机生成且跟随浏览会话，请返回分享页面重新点击「下载文件」。', 403);
                return;
            }

            $this->logAccess((int) $share['id'], 'clicked');
            $this->sendFile((int) $share['id'], (int) $share['max_downloads'], $info, $content);
            return;
        }

        $this->countView((int) $share['id']);
        $this->renderSharePage($share, $info, $content, $loginRequired);
    }

    private function issueDownloadKey($shareId)
    {
        $this->startSession();
        $key = bin2hex(random_bytes(16));
        $_SESSION['fm_share_dl_' . $shareId] = $key;
        return $key;
    }

    private function verifyDownloadKey($shareId)
    {
        $this->startSession();
        $known = isset($_SESSION['fm_share_dl_' . $shareId]) ? (string) $_SESSION['fm_share_dl_' . $shareId] : '';
        $given = (string) $this->request->get('dlkey', '');
        return '' !== $known && '' !== $given && hash_equals($known, $given);
    }

    private function verifyPassword($shareId, $hash)
    {
        $this->startSession();

        if (isset($_SESSION['filemanager_share_' . $shareId])) {
            return true;
        }

        $password = (string) $this->request->get('password', '');
        $error = '';

        if ('' !== $password) {
            if (password_verify($password, $hash)) {
                $_SESSION['filemanager_share_' . $shareId] = 1;
                return true;
            }
            $error = '提取密码错误，请重试';
        }

        $this->renderPasswordPage($error);
        return false;
    }

    private function sendFile($shareId, $maxDownloads, $info, $content)
    {
        $file = FileManager_Action::resolveAttachmentFile($info['path']);
        $filename = '' !== $info['name'] ? $info['name'] : $content['title'];

        if (null === $file || !is_file($file)) {

            $url = \Typecho\Common::url($info['path'], \Widget\Options::alloc()->rootUrl);
            /* 只对外站回源：本站直链是同一份磁盘文件，回源只会拿到 404 或 WAF 拦截页 */
            if (preg_match('#^https?://#i', $url) && !self::isSameHost($url)) {
                $this->proxyRemote($shareId, $maxDownloads, $url, $filename);
                exit;
            }

            $this->renderNotice('文件已丢失', '服务器上已找不到该文件，请联系分享者。', 404);
            return;
        }

        $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-store');
        header('X-Robots-Tag: noindex');

        header('X-Accel-Buffering: no');
        ignore_user_abort(false);

        readfile($file);

        if (!connection_aborted()) {
            $this->countDownload($shareId, $maxDownloads);
        }
        exit;
    }

    private function logAccess($shareId, $action)
    {
        try {
            $db = \Typecho\Db::get();
            $prefix = $db->getPrefix();
            $ip = (string) $this->request->getIp();
            $ua = isset($_SERVER['HTTP_USER_AGENT'])
                ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255)
                : '';
            $db->query($db->insert('table.filemanager_share_logs')->rows(array(
                'share_id' => (int) $shareId,
                'action'   => (string) $action,
                'ip'       => '' !== $ip ? $ip : 'unknown',
                'ua'       => $ua,
                'created'  => time(),
            )));
        } catch (\Throwable $e) {

        }
    }

    private function countDownload($shareId, $maxDownloads)
    {
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();

        $affected = $db->query("UPDATE `{$prefix}filemanager_shares` SET downloads = downloads + 1"
            . " WHERE id = " . (int) $shareId
            . " AND (max_downloads = 0 OR downloads < " . (int) $maxDownloads . ")");

        if ($affected) {
            $this->logAccess($shareId, 'download');
        }
    }

    private function countView($shareId)
    {
        try {
            $db = \Typecho\Db::get();
            $prefix = $db->getPrefix();
            $db->query("UPDATE `{$prefix}filemanager_shares` SET views = views + 1 WHERE id = " . (int) $shareId);
            $this->logAccess($shareId, 'view');
        } catch (\Throwable $e) {

        }
    }

    private static function previewKind($name)
    {
        $ext = strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION));

        $map = array(
            'image' => array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'),
            'video' => array('mp4', 'm4v', 'webm', 'ogv', 'mov'),
            'audio' => array('mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'flac', 'opus'),
            'pdf'   => array('pdf'),
            'text'  => array('txt', 'log', 'csv', 'md', 'json'),
        );
        foreach ($map as $kind => $exts) {
            if (in_array($ext, $exts, true)) {
                return $kind;
            }
        }
        return '';
    }

    private static function previewMime($ext)
    {
        $map = array(
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'avif' => 'image/avif',
            'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm',
            'ogv' => 'video/ogg', 'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
            'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
            'flac' => 'audio/flac', 'opus' => 'audio/opus',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain; charset=UTF-8', 'log' => 'text/plain; charset=UTF-8',
            'csv' => 'text/plain; charset=UTF-8', 'md' => 'text/plain; charset=UTF-8',
            'json' => 'application/json',
        );
        $ext = strtolower((string) $ext);
        return isset($map[$ext]) ? $map[$ext] : '';
    }

    private function streamPreview($info, $content)
    {
        $filename = '' !== $info['name'] ? $info['name'] : $content['title'];
        $ext = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));
        $mime = self::previewMime($ext);

        if ('' === $mime) {
            $this->renderNotice('无法预览', '该文件类型暂不支持在线预览，请下载后查看。', 415);
            return;
        }

        $file = FileManager_Action::resolveAttachmentFile($info['path']);
        if (null !== $file && is_file($file)) {
            $this->streamLocalPreview($file, $mime, $filename);
            exit;
        }

        $url = \Typecho\Common::url($info['path'], \Widget\Options::alloc()->rootUrl);
        if (preg_match('#^https?://#i', $url) && !self::isSameHost($url)) {
            $this->proxyRemotePreview($url, $mime, $filename);
            exit;
        }

        $this->renderNotice('文件已丢失', '服务器上已找不到该文件，无法预览。', 404);
    }

    private function streamLocalPreview($file, $mime, $filename)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $size = filesize($file);
        $start = 0;
        $end = $size - 1;
        $partial = false;

        if (isset($_SERVER['HTTP_RANGE'])
            && preg_match('/^bytes=(\d*)-(\d*)$/i', trim((string) $_SERVER['HTTP_RANGE']), $m)
            && ('' !== $m[1] || '' !== $m[2])) {
            if ('' !== $m[1]) {
                $start = (int) $m[1];
            }
            if ('' !== $m[2]) {
                $end = (int) $m[2];
            } elseif ('' === $m[1]) {
                $start = 0;
            }
            if ($start > $end || $start >= $size) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $end = min($end, $size - 1);
            $partial = true;
        }

        $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        header($partial ? 'HTTP/1.1 206 Partial Content' : 'HTTP/1.1 200 OK');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $asciiName . '"');
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-store');
        header('X-Robots-Tag: noindex');
        header('X-Accel-Buffering: no');
        ignore_user_abort(false);
        @set_time_limit(0);

        if ($partial) {
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            header('Content-Length: ' . ($end - $start + 1));

            $fp = fopen($file, 'rb');
            if (!$fp) {
                exit;
            }
            fseek($fp, $start);
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
                $chunk = fread($fp, (int) min(8192, $remaining));
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                echo $chunk;
                flush();
                $remaining -= strlen($chunk);
            }
            fclose($fp);
            exit;
        }

        header('Content-Length: ' . $size);
        readfile($file);
        exit;
    }

    private function proxyRemotePreview($url, $mime, $filename)
    {
        $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('HTTP/1.1 200 OK');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $asciiName . '"');
        header('Accept-Ranges: none');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-store');
        header('X-Robots-Tag: noindex');
        header('X-Accel-Buffering: no');
        ignore_user_abort(false);
        @set_time_limit(0);

        if (!function_exists('curl_init')) {

            header('Location: ' . $url);
            exit;
        }

        $out = fopen('php://output', 'wb');
        $ch = curl_init($url);
        $headers = array();
        curl_setopt_array($ch, array(
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $headers[] = (string) $line;
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use ($out) {
                if (connection_aborted()) {
                    return 0;
                }
                $n = fwrite($out, $chunk);
                return false === $n ? 0 : $n;
            },
        ));
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_resource($out)) {
            fclose($out);
        }

        if ($code < 200 || $code >= 300) {
            error_log('[FileManager] preview proxy remote http ' . $code);
        }
        exit;
    }

    private function proxyRemote($shareId, $maxDownloads, $url, $filename)
    {
        $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-store');
        header('X-Robots-Tag: noindex');
        header('X-Accel-Buffering: no');
        ignore_user_abort(false);
        @set_time_limit(0);

        if (function_exists('curl_init')) {
            $out = fopen('php://output', 'wb');
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use ($out) {

                    if (connection_aborted()) {
                        return 0;
                    }
                    $n = fwrite($out, $chunk);
                    return false === $n ? 0 : $n;
                },
            ));
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_resource($out)) {
                fclose($out);
            }

            if ($code >= 200 && $code < 300 && !connection_aborted()) {
                $this->countDownload($shareId, $maxDownloads);
            }
            exit;
        }

        header('Location: ' . $url);
        exit;
    }

    private function startSession()
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
    }

    /** 拼下载路径 /download/<文件名>：整段 rawurlencode，避免 / ? # & 破坏 URL 与路由匹配 */
    public static function downloadPath($filename)
    {
        $name = str_replace(array("\0", "\r", "\n", "\t"), '', trim((string) $filename));
        if ('' === $name) {
            $name = 'file';
        }
        return '/download/' . rawurlencode($name);
    }

    /** 判断 URL 是否指向本站（www 与小写归一）：同站直链读不到就不再回源 */
    private static function isSameHost($url, $selfHost = null)
    {
        $parts = parse_url((string) $url);
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if ('' === $host) {
            return true;    /* 连 host 都没有，一定是站内相对地址 */
        }

        if (null !== $selfHost) {
            $self = strtolower((string) $selfHost);
        } else {
            $self = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
        }
        /* HTTP_HOST 可能带端口（非 80/443 环境），parse_url 的 host 不带，去掉再比 */
        $self = (string) preg_replace('/:\d+$/', '', $self);
        if ('' === $self) {
            try {
                $root = parse_url((string) \Widget\Options::alloc()->rootUrl);
                $self = isset($root['host']) ? strtolower((string) $root['host']) : '';
            } catch (\Throwable $e) {
                $self = '';
            }
        }
        if ('' === $self) {
            return false;   /* 判断不了就保守起见：允许回源 */
        }

        $bare = function ($h) {
            return (string) preg_replace('/^www\./i', '', $h);
        };
        return $host === $self || $bare($host) === $bare($self);
    }

    private static function formatSize($bytes)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, $i > 1 ? 2 : 1) . ' ' . $units[$i];
    }

    private static function formatDate($timestamp)
    {
        return 0 >= (int) $timestamp ? '-' : date('Y-m-d H:i', (int) $timestamp);
    }

    private function renderSharePage($share, $info, $content, $loginRequired = 0)
    {
        $siteTitle = $this->escape(\Widget\Options::alloc()->title);

        $displayName = isset($share['display_name']) ? trim((string) $share['display_name']) : '';
        $filename = $this->escape('' !== $displayName ? $displayName : ('' !== $info['name'] ? $info['name'] : $content['title']));
        $sizeText = $this->escape(self::formatSize((int) $info['size']));
        $createdText = $this->escape(self::formatDate((int) $content['created']));
        $expireText = $this->escape((int) $share['expire'] > 0 ? self::formatDate($share['expire']) : '永久有效');
        $downloadText = $this->escape(
            ((int) $share['max_downloads'] > 0
                ? (int) $share['downloads'] . ' / ' . (int) $share['max_downloads'] . ' 次'
                : (int) $share['downloads'] . ' 次（不限）')
        );

        $dlKey = $this->issueDownloadKey((int) $share['id']);
        $requestUrl = $this->request->getRequestUrl();

        /* 优先走 /download/<文件名> 绕开 WAF；路由未注册（没重新启用插件）时退回旧 URL */
        $downloadUrl = '';
        $previewUrl = '';
        try {
            $route = \Typecho\Router::get('filemanager_download');
            $useDlRoute = is_array($route);
        } catch (\Throwable $e) {
            $useDlRoute = false;
        }

        if ($useDlRoute) {
            $dlBase = \Typecho\Common::url(
                self::downloadPath('' !== $info['name'] ? $info['name'] : $content['title']),
                \Widget\Options::alloc()->rootUrl
            );
            $dlQuery = '?token=' . rawurlencode((string) $share['token']) . '&dlkey=' . $dlKey;
            $downloadUrl = $this->escape($dlBase . $dlQuery . '&download=1');
            $previewUrl = $dlBase . $dlQuery . '&preview=1';
        } else {
            $sep = false === strpos($requestUrl, '?') ? '?' : '&';
            $downloadUrl = $this->escape($requestUrl . $sep . 'download=1&dlkey=' . $dlKey);
            $previewUrl = $requestUrl . $sep . 'preview=1&dlkey=' . $dlKey;
        }

        $previewEnabled = isset($share['preview']) ? (int) $share['preview'] : 0;
        $kind = '';
        if ($previewEnabled && !($loginRequired && !\Widget\User::alloc()->hasLogin())) {
            $kind = self::previewKind('' !== $info['name'] ? $info['name'] : $info['path']);
        }
        $previewBlock = '';
        if ('' !== $kind) {
            $src = $this->escape($previewUrl);
            if ('image' === $kind) {

                $thumb = '<img src="' . $src . '" alt="在线预览" loading="lazy">';
            } elseif ('video' === $kind) {

                $thumb = '<video src="' . $src . '" preload="metadata" muted></video>'
                    . '<span class="fm-pv-badge">▶ 播放</span>';
            } elseif ('audio' === $kind) {
                $thumb = '<span class="fm-pv-glyph">♪</span>';
            } elseif ('pdf' === $kind) {
                $thumb = '<span class="fm-pv-glyph">PDF</span>';
            } else {
                $thumb = '<span class="fm-pv-glyph">TXT</span>';
            }
            $previewBlock = '<div class="fm-preview"><p class="fm-preview-label">在线预览（点击放大）</p>'
                . '<div class="fm-pv-thumb" data-kind="' . $kind . '" data-src="' . $src . '">' . $thumb . '</div></div>';
        }

        $noteHtml = isset($share['note']) ? trim((string) $share['note']) : '';
        $noteBlock = '' !== $noteHtml ? '<div class="fm-note">' . $noteHtml . '</div>' : '';

        if ($loginRequired && !\Widget\User::alloc()->hasLogin()) {
            $loginUrl = \Widget\Options::alloc()->loginUrl;
            $loginUrl .= (false === strpos($loginUrl, '?') ? '?' : '&')
                . 'referer=' . rawurlencode($requestUrl);
            $buttonHtml = '<a class="fm-btn" href="' . $this->escape($loginUrl) . '" rel="nofollow">登录后下载</a>'
                . '<p class="fm-hint">该文件仅登录用户可下载，登录后将返回本页继续下载。</p>';
        } else {
            $buttonHtml = '<a class="fm-btn" href="' . $downloadUrl . '" rel="nofollow">下载文件</a>';
        }

        $iconSvg = '<svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="#467b96" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
            . '<polyline points="14 2 14 8 20 8"/></svg>';

        $body = '<div class="fm-card">
    <div class="fm-icon">' . $iconSvg . '</div>
    <h1 class="fm-title">' . $filename . '</h1>
    <table class="fm-meta">
        <tr><td>文件大小</td><td>' . $sizeText . '</td></tr>
        <tr><td>上传时间</td><td>' . $createdText . '</td></tr>
        <tr><td>有效期至</td><td>' . $expireText . '</td></tr>
        <tr><td>已下载</td><td>' . $downloadText . '</td></tr>
    </table>
    ' . $noteBlock . '
    ' . $previewBlock . '
    ' . $buttonHtml . '
    <p class="fm-foot">' . $siteTitle . ' · 文件分享</p>
</div>';

        $body = $this->pageChrome($body, FileManager_Plugin::settings());

        $this->outputHtml('文件分享 - ' . \Widget\Options::alloc()->title, $body);
    }

    private function pageChrome($cardHtml, $settings)
    {
        $header = trim((string) (isset($settings['shareHeaderHtml']) ? $settings['shareHeaderHtml'] : ''));
        $footer = trim((string) (isset($settings['shareFooterHtml']) ? $settings['shareFooterHtml'] : ''));

        $inner = ('' !== $header ? '<div class="fm-chrome fm-chrome-top">' . $header . '</div>' : '')
            . $cardHtml
            . ('' !== $footer ? '<div class="fm-chrome fm-chrome-bottom">' . $footer . '</div>' : '');

        return '<div class="fm-page">' . $inner . '</div>';
    }

    private function renderPasswordPage($error)
    {
        $siteTitle = $this->escape(\Widget\Options::alloc()->title);
        $action = $this->escape($this->request->getRequestUrl());
        $errorHtml = '' !== $error
            ? '<p class="fm-error">' . $this->escape($error) . '</p>'
            : '';

        $iconSvg = '<svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="#467b96" '
            . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="3" y="11" width="18" height="11" rx="2"/>'
            . '<path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

        $body = '<div class="fm-card">
    <div class="fm-icon">' . $iconSvg . '</div>
    <h1 class="fm-title">请输入提取密码</h1>
    ' . $errorHtml . '
    <form method="post" action="' . $action . '" class="fm-form">
        <input type="password" name="password" class="fm-input" placeholder="提取密码" autofocus required>
        <button type="submit" class="fm-btn">下一步</button>
    </form>
    <p class="fm-foot">' . $siteTitle . ' · 文件分享</p>
</div>';

        $this->outputHtml('文件分享 - ' . \Widget\Options::alloc()->title, $body);
    }

    private function renderNotice($title, $message, $status)
    {
        http_response_code($status);

        $siteTitle = $this->escape(\Widget\Options::alloc()->title);
        $titleText = $this->escape($title);
        $messageText = $this->escape($message);

        $body = '<div class="fm-card">
    <h1 class="fm-title">' . $titleText . '</h1>
    <p class="fm-notice">' . $messageText . '</p>
    <p class="fm-foot">' . $siteTitle . ' · 文件分享</p>
</div>';

        $this->outputHtml($title . ' - ' . \Widget\Options::alloc()->title, $body);
    }

    private function outputHtml($title, $body)
    {
        header('X-Robots-Tag: noindex');
        header('Content-Type: text/html; charset=UTF-8');

        $pageTitle = htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8');

        echo '<!DOCTYPE HTML>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title>' . $pageTitle . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #f3f5f8; color: #333;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px;
        }
        .fm-card {
            background: #fff; border-radius: 12px; padding: 36px 40px;
            max-width: 440px; width: 100%; text-align: center;
            box-shadow: 0 2px 16px rgba(0, 0, 0, 0.06);
        }
        .fm-icon { margin-bottom: 14px; }
        .fm-title { font-size: 18px; font-weight: 500; word-break: break-all; margin-bottom: 18px; }
        .fm-meta { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .fm-meta td { padding: 8px 4px; font-size: 13px; border-bottom: 1px solid #f0f2f5; text-align: left; }
        .fm-meta td:first-child { color: #999; width: 80px; }
        .fm-meta td:last-child { color: #555; }
        .fm-btn {
            display: inline-block; background: #467b96; color: #fff; text-decoration: none;
            padding: 11px 0; width: 100%; border-radius: 6px; font-size: 15px;
            border: none; cursor: pointer;
        }
        .fm-btn:hover { background: #39677e; }
        .fm-form { display: flex; flex-direction: column; gap: 12px; }
        .fm-input {
            padding: 11px 14px; border: 1px solid #dfe3e8; border-radius: 6px;
            font-size: 14px; outline: none;
        }
        .fm-input:focus { border-color: #467b96; }
        .fm-error { color: #d9534f; font-size: 13px; margin-bottom: 14px; }
        .fm-notice { color: #777; font-size: 14px; line-height: 1.7; margin-bottom: 18px; }
        .fm-hint { color: #98a2ad; font-size: 12px; line-height: 1.6; margin-top: 12px; }
        .fm-note {
            margin: 0 0 14px; padding: 10px 12px; border-radius: 8px;
            background: #f7f9fb; border: 1px dashed #dde4ec;
            font-size: 13px; color: #667; line-height: 1.7; text-align: left; overflow: hidden;
        }
        .fm-note img { max-width: 100%; height: auto; }
        .fm-note:empty { display: none; }
        .fm-preview { width: 100%; margin-bottom: 18px; text-align: center; }
        .fm-preview-label { font-size: 12px; color: #98a2ad; margin-bottom: 8px; }
        .fm-pv-thumb {
            position: relative; cursor: zoom-in; width: 100%; margin: 0 auto;
            border-radius: 10px; overflow: hidden; background: #14171d;
            box-shadow: 0 2px 10px rgba(15, 30, 50, 0.14);
        }
        .fm-pv-thumb:hover { box-shadow: 0 4px 16px rgba(15, 30, 50, 0.26); }
        .fm-pv-thumb img, .fm-pv-thumb video {
            display: block; width: 100%; height: 158px; object-fit: cover;
        }
        .fm-pv-thumb .fm-pv-glyph {
            display: flex; align-items: center; justify-content: center;
            height: 118px; color: #fff; font-size: 26px; font-weight: 600; letter-spacing: 1px;
        }
        .fm-pv-badge {
            position: absolute; right: 10px; bottom: 10px;
            background: rgba(0, 0, 0, 0.55); color: #fff; font-size: 12px;
            padding: 4px 12px; border-radius: 999px; pointer-events: none;
        }
        .fm-pv-mask {
            position: fixed; inset: 0; z-index: 99999;
            background: rgba(8, 10, 14, 0.88);
            display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .fm-pv-mask img.fm-pv-media {
            max-width: 94vw; max-height: 90vh; border-radius: 8px;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5);
        }
        .fm-pv-mask video.fm-pv-media {
            max-width: 94vw; max-height: 90vh; border-radius: 8px; background: #000;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5);
        }
        .fm-pv-pdf {
            width: min(1100px, 94vw); height: 90vh; border: 0;
            border-radius: 8px; background: #fff;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5);
        }
        .fm-pv-pre {
            width: min(1100px, 94vw); max-height: 88vh; overflow: auto; margin: 0;
            white-space: pre-wrap; word-break: break-all;
            background: #fff; color: #333; border-radius: 8px; padding: 18px 20px;
            font-size: 13px; line-height: 1.7; font-family: Consolas, Menlo, monospace;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5);
        }
        .fm-pv-audio {
            background: #fff; border-radius: 12px; padding: 22px 26px;
            width: min(520px, 92vw); text-align: center;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5);
        }
        .fm-pv-audio-t { color: #555; font-size: 14px; margin: 0 0 12px; word-break: break-all; }
        .fm-pv-audio audio { width: 100%; }
        .fm-pv-close {
            position: fixed; top: 14px; right: 18px; width: 40px; height: 40px;
            border-radius: 50%; border: 0; cursor: pointer;
            background: rgba(255, 255, 255, 0.14); color: #fff;
            font-size: 22px; line-height: 1;
        }
        .fm-pv-close:hover { background: rgba(255, 255, 255, 0.3); }
        .fm-page { width: 100%; display: flex; flex-direction: column; align-items: center; gap: 18px; }
        .fm-chrome { width: 100%; max-width: 720px; text-align: center; color: #667; font-size: 13px; overflow: hidden; }
        .fm-chrome img { max-width: 100%; height: auto; }
        .fm-foot { margin-top: 24px; color: #b6bcc4; font-size: 12px; }
    </style>
</head>
<body>
' . $body . '
<script>
(function () {
    function closePv() {
        var m = document.querySelector(".fm-pv-mask");
        if (m && m.parentNode) { m.parentNode.removeChild(m); }
    }
    function openPv(el) {
        closePv();
        var kind = el.getAttribute("data-kind");
        var src = el.getAttribute("data-src");
        var mask = document.createElement("div");
        mask.className = "fm-pv-mask";
        var html = "<button type=\"button\" class=\"fm-pv-close\" aria-label=\"关闭\">\u00d7</button>";
        if ("image" === kind) {
            html += "<img class=\"fm-pv-media\" src=\"" + src + "\" alt=\"预览\">";
        } else if ("video" === kind) {
            html += "<video class=\"fm-pv-media\" src=\"" + src + "\" controls autoplay"
                + " controlslist=\"nodownload\" disablepictureinpicture></video>";
        } else if ("audio" === kind) {
            html += "<div class=\"fm-pv-audio\"><p class=\"fm-pv-audio-t\">音频在线预览</p>"
                + "<audio src=\"" + src + "\" controls autoplay></audio></div>";
        } else if ("pdf" === kind) {
            html += "<iframe class=\"fm-pv-pdf\" src=\"" + src + "\" title=\"PDF 预览\"></iframe>";
        } else if ("text" === kind) {
            html += "<pre class=\"fm-pv-pre\">加载中…</pre>";
        }
        mask.innerHTML = html;
        document.body.appendChild(mask);
        if ("text" === kind) {
            var pre = mask.querySelector(".fm-pv-pre");
            fetch(src).then(function (r) { return r.text(); }).then(function (t) {
                if (t.length >= 65536) {
                    t = t.slice(0, 65536) + "\n\n……（内容过长，仅显示前 64KB，下载可查看全文）";
                }
                pre.textContent = t; /* textContent 赋值天然免 XSS */
            }).catch(function () { pre.textContent = "预览加载失败，请下载后查看。"; });
        }
        mask.addEventListener("click", function (e) {
            if (e.target === mask || "fm-pv-close" === e.target.className) { closePv(); }
        });
        document.addEventListener("keydown", function onEsc(e) {
            if ("Escape" === e.key) {
                closePv();
                document.removeEventListener("keydown", onEsc);
            }
        });
    }
    document.addEventListener("contextmenu", function (e) {
        var t = e.target;
        var tag = t && t.tagName;
        if (("VIDEO" === tag || "IMG" === tag)
            && (t.closest(".fm-pv-thumb") || t.closest(".fm-pv-mask"))) {
            e.preventDefault();
        }
    });
    var thumbs = document.querySelectorAll(".fm-pv-thumb");
    for (var i = 0; i < thumbs.length; i++) {
        thumbs[i].addEventListener("click", function () { openPv(this); });
    }
})();
</script>
</body>
</html>';
        exit;
    }

    private function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
