<?php
if (!defined('__TYPECHO_ROOT_DIR__') || !defined('__TYPECHO_ADMIN__')) {
    exit;
}

if (!isset($user) || !$user->pass('administrator', true)) {
    echo '<div class="main"><div class="body container">'
        . '<div class="typecho-page-title"><h2>文件管家</h2></div>'
        . '<p class="description">无权访问本页面，请以管理员身份登录。</p></div></div>';
    return;
}

$fmSettings = FileManager_Plugin::settings();
$fmCategories = FileManager_Plugin::categories();
$fmAjaxUrl = $security->getIndex('/action/filemanager');
$fmCanZip = class_exists('ZipArchive');
$fmCanExtract = class_exists('ZipArchive') || class_exists('PharData');

require __TYPECHO_ROOT_DIR__ . '/admin/header.php';
require __TYPECHO_ROOT_DIR__ . '/admin/menu.php';
?>
<div class="main">
    <div class="body container">
        <div class="fm-layout">
            <aside class="fm-side">
                <div class="fm-side-head">
                    <button type="button" class="btn btn-s btn-primary" id="fmNewFolder">+ 新建文件夹</button>
                    <button type="button" class="btn btn-s" id="fmCleanEmptyBtn" title="删除所有不含文件且没有子文件夹的空文件夹">删除空文件夹</button>
                </div>
                <div class="fm-drag-tip" id="fmDragTip">拖入文件夹 = 移入<br>往左拖松手 = 移为一级</div>
                <ul class="fm-tree" id="fmTree"></ul>
            </aside>

            <section class="fm-main">
                <div class="fm-toolbar">
                    <div class="fm-toolbar-left">
                        <div class="fm-view-wrap">
                            <div class="fm-view-toggle">
                                <button type="button" id="fmViewGrid" title="网格视图">▦</button>
                                <button type="button" id="fmGridSizeBtn" title="网格布局">▾</button>
                                <button type="button" id="fmViewMasonry" title="瀑布流视图"><svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor" aria-hidden="true"><rect x="0.5" y="1.5" width="3.6" height="6.5" rx="1"/><rect x="5.2" y="3.5" width="3.6" height="9" rx="1"/><rect x="9.9" y="1.5" width="3.6" height="4.5" rx="1"/></svg></button>
                                <button type="button" id="fmViewList" title="列表视图">☰</button>
                            </div>
                            <div class="fm-grid-menu" id="fmGridSizeMenu" hidden>
                                <button type="button" data-gs="5x6">5×6（每页 30）</button>
                                <button type="button" data-gs="4x7">4×7（每页 28）</button>
                            </div>
                        </div>
                        <select id="fmType"<?php echo empty($fmCategories) ? ' title="在插件设置中填写自定义分类后可用"' : ''; ?>>
                            <option value="">全部类型</option>
                            <?php if (!empty($fmCategories)): ?>
                            <?php foreach ($fmCategories as $fmCat): ?>
                            <option value="<?php echo htmlspecialchars($fmCat['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($fmCat['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                            <option value="other">其它</option>
                            <?php endif; ?>
                        </select>
                        <select id="fmSort">
                            <option value="time-desc">按时间降序</option>
                            <option value="time-asc">按时间升序</option>
                            <option value="name-desc">按名称降序</option>
                            <option value="name-asc">按名称升序</option>
                            <option value="size-desc">按大小降序</option>
                            <option value="size-asc">按大小升序</option>
                        </select>
                        <div class="fm-search">
                            <input type="text" id="fmKeywords" placeholder="输入文件名关键字">
                            <label class="fm-csearch" title="勾选后按文件内容全文搜索（仅文本类文件）"><input type="checkbox" id="fmContentSearch">内容</label>
                            <button type="button" class="btn btn-s" id="fmSearchBtn">筛选</button>
                        </div>
                        <button type="button" class="btn btn-s" id="fmHealthBtn">文件体检</button>
                        <button type="button" class="btn btn-s" id="fmStatsBtn">存储统计</button>
                        <button type="button" class="btn btn-s" id="fmTrashBtn">回收站</button>
                    </div>
                    <div class="fm-toolbar-right">
                        <button type="button" class="btn btn-s btn-warning" id="fmClassifyBtn">一键归类</button>
                        <button type="button" class="btn btn-s" id="fmWebpBtn" title="把存量图片批量转换为 WebP/JPG/PNG，并同步替换文章中的引用">图片转换</button>
                        <button type="button" class="btn btn-s btn-share" id="fmShareListBtn">分享管理</button>
                        <button type="button" class="btn btn-s" id="fmPrefsBtn" title="网格布局、上传默认文件夹、进入页面时打开的文件夹">偏好设置</button>
                        <button type="button" class="btn btn-s" id="fmImgHostBtn" title="对接图床/对象存储（兰空图床 API）：配置接口与令牌，图片可上传图床引用外链">图床/对象存储</button>
                        <button type="button" class="btn btn-s" id="fmMigrateBtn" title="把本站存量附件批量迁移到图床/对象存储">迁移到图床/对象存储</button>
                    </div>
                </div>

                <div class="fm-batch" id="fmBatch">
                    <button type="button" class="btn btn-s" id="fmUploadBtn">上传文件</button>
                    <button type="button" class="btn btn-s" id="fmBatchAllBtn" title="全选当前列表中的所有文件">全选</button>
                    <span id="fmBatchCount">已选 0 项</span>
                    <select id="fmMoveTarget"></select>
                    <button type="button" class="btn btn-s" id="fmMoveBtn">移动</button>
                    <?php if (class_exists('ZipArchive')): ?>
                    <button type="button" class="btn btn-s" id="fmCompressBtn" title="将勾选的文件打包为 zip">压缩</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-s btn-danger" id="fmBatchDeleteBtn">删除选中</button>
                    <button type="button" class="btn btn-s btn-danger" id="fmCleanBtn">清理未归档文件</button>
                </div>

                <div id="fmLoading" class="fm-loading" hidden>加载中…</div>

                <div id="fmGrid" class="fm-grid"></div>
                <div id="fmMasonry" class="fm-masonry" hidden></div>
                <div id="fmListWrap" hidden>
                    <table class="typecho-list-table fm-list">
                        <thead>
                            <tr>
                                <th width="30"></th>
                                <th>文件名</th>
                                <th width="90">大小</th>
                                <th width="60">类型</th>
                                <th width="140">时间</th>
                                <th width="300">操作</th>
                            </tr>
                        </thead>
                        <tbody id="fmListBody"></tbody>
                    </table>
                </div>

                <div class="fm-empty" id="fmEmpty" hidden>暂无文件</div>

                <div class="fm-pager" id="fmPager">
                    <button type="button" id="fmPrev">‹ 上一页</button>
                    <span id="fmPageInfo"></span>
                    <button type="button" id="fmNext">下一页 ›</button>
                </div>
            </section>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmShareModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card" id="fmShareCard">
        <h3 id="fmShareModalTitle">创建分享链接</h3>
        <p class="fm-modal-file" id="fmShareFileName"></p>
        <!-- 结果区放在表单之前：创建成功后表单收起，链接落在顶部，弹窗不必滚动 -->
        <div class="fm-share-result" id="fmShareResult" hidden>
            <label>分享链接（点击复制）</label>
            <input type="text" id="fmShareLink" readonly>
            <button type="button" class="btn btn-s" id="fmShareCopy">复制链接</button>
        </div>
        <div class="fm-field">
            <label>展示名称</label>
            <input type="text" id="fmShareDisplayName" maxlength="120" placeholder="留空则显示原文件名">
        </div>
        <div class="fm-field">
            <label id="fmShareTokenLabel">自定义链接（留空则系统随机生成）</label>
            <input type="text" id="fmShareToken" maxlength="64" placeholder="仅限字母、数字、下划线、短横线，3-64 位">
        </div>
        <div class="fm-field">
            <label id="fmSharePwdLabel">提取密码（留空则无需密码）</label>
            <input type="text" id="fmSharePassword" placeholder="可留空">
        </div>
        <div class="fm-field">
            <label>有效期</label>
            <select id="fmShareExpire">
                <option value="keep" hidden>不修改（保持剩余时间）</option>
                <option value="0">永久有效</option>
                <option value="1">1 天</option>
                <option value="7">7 天</option>
                <option value="30">30 天</option>
            </select>
        </div>
        <div class="fm-field">
            <label>下载次数上限（0 为不限）</label>
            <input type="text" id="fmShareMax" value="<?php echo htmlspecialchars($fmSettings['defaultMaxDownloads'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmShareLogin" value="1"> 仅登录用户可下载</label>
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmSharePreview" value="1"> 开启在线预览（访客可在分享页查看图片 / 视频 / 音频 / 文本 / PDF）</label>
        </div>
        <div class="fm-field">
            <label>文件备注（显示在下载按钮上方，支持 HTML）</label>
            <textarea id="fmShareNote" rows="3" maxlength="2000" placeholder="如：解压密码、文件使用方法、注意事项等，留空则不显示"></textarea>
        </div>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" id="fmShareCancel" data-fm-close>取消</button>
            <button type="button" class="btn btn-s btn-primary" id="fmShareCreateBtn">创建</button>
            <button type="button" class="btn btn-s btn-primary" id="fmShareUpdateBtn" hidden>保存修改</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmEditModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card">
        <h3>文件编辑</h3>
        <p class="fm-modal-file" id="fmEditFileName"></p>
        <div class="fm-field">
            <label class="fm-flabel">文件名（修改后自动替换全站引用）</label>
            <input type="text" id="fmEditName" maxlength="190" placeholder="仅限字母、数字、点、下划线、短横线，不含扩展名">
            <p class="fm-modal-desc">仅当文件名有改动时才物理重命名（自动保留原扩展名），并同步替换文章/页面中的旧路径；只改标题不会动文件。</p>
        </div>
        <div class="fm-field">
            <label class="fm-flabel">文件标题</label>
            <input type="text" id="fmEditTitle" maxlength="150">
        </div>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>取消</button>
            <button type="button" class="btn btn-s btn-primary" id="fmEditSave">保存修改</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmTextEditModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card fm-modal-wide" id="fmTextEditCard">
        <h3 id="fmTextEditTitle">编辑文件内容</h3>
        <button type="button" class="fm-textedit-fullbtn" id="fmTextEditFullBtn" title="全屏 / 还原（F11 键也可切换）">⛶</button>
        <div class="fm-textedit-wrap">
            <div class="fm-textedit-gutter" id="fmTextEditGutter" aria-hidden="true"></div>
            <textarea id="fmTextEditArea" class="fm-textedit-area" spellcheck="false" wrap="off" disabled></textarea>
            <pre id="fmTextEditHl" class="fm-textedit-hl" hidden><code class="hljs"></code></pre>
        </div>
        <p class="fm-textedit-stats" id="fmTextEditStats"></p>
        <p class="fm-modal-desc" id="fmTextEditInfo" style="margin:6px 0 0"></p>
        <div class="fm-modal-btns">
            <select id="fmTextEditTheme" class="btn btn-s" title="高亮主题：选具体主题进入高亮编辑，选「不启用高亮代码」退出（选择后保存为默认偏好）"></select>
            <button type="button" class="btn btn-s" id="fmTextEditWrapBtn" title="切换自动换行（长行折行显示，行号标在每个逻辑行首行）">自动换行</button>
            <button type="button" class="btn btn-s" id="fmTextEditTitleBtn" title="修改文件名（含扩展名）并同步替换全站引用">编辑文件名</button>
            <label style="margin-left:12px; font-size:13px; color:#5f6f7e; display:inline-flex; align-items:center; gap:6px;">编码
                <select id="fmTextEditEnc" style="font-size:13px; padding:3px 6px; border:1px solid #d3dae2; border-radius:4px; background:#fff;">
                    <option value="auto">自动检测</option>
                    <option value="UTF-8">UTF-8</option>
                    <option value="GB18030">GB18030 / GBK / GB2312</option>
                    <option value="BIG5">BIG5</option>
                </select>
            </label>
            <span style="flex:1"></span>
            <button type="button" class="btn btn-s" data-fm-close>取消</button>
            <button type="button" class="btn btn-s btn-primary" id="fmTextEditSave">保存</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmShareListModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card fm-modal-wide">
        <h3>分享管理</h3>
        <div id="fmShareListBody"></div>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>关闭</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmQrModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card" style="width:280px;text-align:center">
        <h3>分享二维码</h3>
        <div id="fmQrBox" style="display:inline-block;margin:4px 0 8px"></div>
        <p id="fmQrText" class="fm-modal-desc" style="word-break:break-all;margin:0 0 10px"></p>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>关闭</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmShareLogModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card fm-modal-wide">
        <h3>访问日志：<span id="fmShareLogTitle"></span></h3>
        <div id="fmShareLogBody"></div>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" id="fmShareLogPrev">上一页</button>
            <span id="fmShareLogPage" class="fm-modal-desc" style="align-self:center"></span>
            <button type="button" class="btn btn-s" id="fmShareLogNext">下一页</button>
            <button type="button" class="btn btn-s" data-fm-close>关闭</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmCSearchModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card fm-modal-wide">
        <h3>内容搜索：<span id="fmCSearchKw"></span></h3>
        <div id="fmCSearchBody"></div>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>关闭</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmStatsModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card fm-modal-wide">
        <h3>存储统计</h3>
        <div id="fmStatsBody"><p class="fm-loading">加载中…</p></div>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>关闭</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmTrashModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card fm-modal-wide">
        <h3>回收站</h3>
        <div id="fmTrashBody"><p class="fm-loading">加载中…</p></div>
        <p class="fm-modal-desc" id="fmTrashDesc" style="margin:8px 0 0" hidden>还原后文件回到原位置（含原附件 ID 与所在文件夹）；超过保留天数的条目会在打开列表或文件页时自动清除。</p>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s btn-share-red" id="fmTrashClearBtn" hidden>清空回收站</button>
            <button type="button" class="btn btn-s" data-fm-close>关闭</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmHealthModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card fm-modal-wide">
        <h3>文件体检</h3>
        <div id="fmHealthBody"><p class="fm-loading">加载中…</p></div>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>关闭</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmMigrateModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card fm-modal-wide">
        <h3>迁移到图床/对象存储</h3>
        <div id="fmMigrateBody"><p class="fm-loading">加载中…</p></div>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s btn-primary" id="fmMigrateStart">开始迁移</button>
            <span style="flex:1"></span>
            <button type="button" class="btn btn-s" data-fm-close>关闭</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmUploadModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card">
        <h3>上传文件</h3>
        <div class="fm-field" id="fmUploadDestField">
            <label>存放位置</label>
            <select id="fmUploadDest" style="width:100%">
                <option value="site">本站（服务器本地）</option>
                <option value="imagehost">图床·对象存储（外链）</option>
            </select>
        </div>
        <div class="fm-field" id="fmUploadFolderField">
            <label>存放文件夹</label>
            <select id="fmUploadFolder"></select>
        </div>
        <div class="fm-upload-drop" id="fmUploadDrop">
            <button type="button" class="btn btn-s" id="fmUploadPick">选择文件</button>
            <input type="file" id="fmUploadInput" multiple hidden>
            <p class="fm-modal-desc" style="margin-top:10px">或拖拽文件到此处</p>
            <p class="fm-modal-desc" style="margin-top:4px">在此页面可直接按 Ctrl+V 粘贴截图上传，不用打开本窗口</p>
            <p class="fm-modal-desc" id="fmUploadInfo" style="margin-top:4px"></p>
        </div>
        <div class="fm-field" id="fmUploadToField">
            <label>上传图片转换到</label>
            <select id="fmUploadTo" style="width:100%">
                <option value="">不转换</option>
                <option value="webp">WebP（体积最小，推荐）</option>
                <option value="jpg">JPG（体积小，不保留透明背景）</option>
                <option value="png">PNG（无损，支持透明，体积可能变大）</option>
            </select>
        </div>
        <div class="fm-field" id="fmUploadWmField">
            <label><input type="checkbox" id="fmUploadWm"<?php
                $fmWmOn = false;
                $fmWmReady = false;
                try {
                    $fmWmCfg = Helper::options()->plugin('FileManager');
                    $fmWmOn = isset($fmWmCfg->watermark) && '1' === (string) $fmWmCfg->watermark;
                    $fmWmReady = \FileManager_Converter::watermarkReady(\FileManager_ImageTool::settings());
                } catch (\Throwable $e) {
                    $fmWmOn = false;
                    $fmWmReady = false;
                }
                echo ($fmWmOn && $fmWmReady) ? ' checked' : '';
                echo $fmWmReady ? '' : ' disabled';
            ?>> 添加水印</label>
            <?php if (!$fmWmReady): ?>
            <p class="fm-modal-desc" style="margin-top:4px">插件设置中还没有可用的水印（水印文字 + 字体，或水印图片），勾选也不会生效</p>
            <?php endif; ?>
        </div>
        <div class="fm-field" id="fmUploadRenameDtField">
            <label><input type="checkbox" id="fmUploadRenameDt"> 按上传时间对文件重命名（格式：年月日时分秒）</label>
        </div>
        <div class="fm-field" id="fmUploadKeepField" hidden>
            <label>上传完成后原图处理（原图 = 转换格式/加水印后的成品图）</label>
            <select id="fmUploadKeep" style="width:100%">
                <option value="del">删除原图（使用图床域名）</option>
                <option value="keep-site">保留原图（使用本站域名）</option>
                <option value="keep-ih">保留原图（使用图床域名）</option>
            </select>
        </div>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>取消</button>
            <button type="button" class="btn btn-s btn-primary" id="fmUploadStart" disabled>开始上传</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmWebpModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card">
        <h3>图片转换（批量）</h3>
        <div class="fm-field">
            <label>转换范围</label>
            <select id="fmWebpScope">
                <option value="all">全部图片</option>
                <option value="folder">当前文件夹</option>
                <option value="selected">勾选的文件</option>
            </select>
        </div>
        <div class="fm-field">
            <label>转换到</label>
            <select id="fmWebpTo" style="width:100%">
                <option value="webp">WebP（体积最小，推荐）</option>
                <option value="jpg">JPG（体积小，不保留透明背景）</option>
                <option value="png">PNG（无损，支持透明，体积可能变大）</option>
            </select>
        </div>
        <div class="fm-field">
            <label>本次转换图片参数</label>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                <label style="flex:1;min-width:110px">质量
                    <input type="number" id="fmWebpQuality" min="1" max="100" style="width:100%">
                </label>
                <label style="flex:1;min-width:110px">最大宽度
                    <input type="number" id="fmWebpMaxw" min="0" placeholder="不限制" style="width:100%">
                </label>
                <label style="flex:1;min-width:110px">最大高度
                    <input type="number" id="fmWebpMaxh" min="0" placeholder="不限制" style="width:100%">
                </label>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">
                <label style="flex:1;min-width:170px">体积超过原图
                    <select id="fmWebpPolicy" style="width:100%">
                        <option value="keep">跳过并保留原图</option>
                        <option value="continue">继续转换</option>
                    </select>
                </label>
                <label style="flex:1;min-width:170px">原图处理
                    <select id="fmWebpOrigin" style="width:100%">
                        <option value="delete">删除原图</option>
                        <option value="backup">备份原图</option>
                    </select>
                </label>
            </div>
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmWebpRefs" checked> 同步替换文章/评论中的引用链接（防止图片断链）</label>
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmWebpWm"> 添加水印</label>
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmWebpRenameDt"> 按上传时间对文件重命名（格式：年月日时分秒）</label>
        </div>
        <p class="fm-modal-desc" id="fmWebpInfo" style="margin-top:4px"></p>
        <p class="fm-modal-desc" id="fmWebpProgress" style="margin-top:4px"></p>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close id="fmWebpCancelBtn">取消</button>
            <button type="button" class="btn btn-s btn-primary" id="fmWebpStart" disabled>开始转换</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmRotSaveModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card">
        <h3>保存旋转</h3>
        <div class="fm-field">
            <label id="fmRotSaveInfo"></label>
        </div>
        <div class="fm-rot-src" id="fmRotSrc" hidden></div>
        <div class="fm-rot-lossless" id="fmRotLossless" hidden></div>
        <div class="fm-rot-icc" id="fmRotIcc" hidden></div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmRotRename" checked> 随机修改文件名称（CDN 开启了缓存图片文件建议开启）</label>
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmRotRefs" checked> 文章中的旧链接将同步替换为新链接</label>
        </div>
        <p class="fm-modal-desc">不勾选随机改名时将在原文件名上覆盖保存（URL 不变，文章里的引用无需改动）。文件内容变化后插件会带上版本号请求，浏览器缓存不会拿到旧图；若站点前面还有 CDN，请在 CDN 后台对该目录做「忽略 URL 参数缓存」或手动刷新，否则仍可能命中 CDN 的旧副本。</p>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>取消</button>
            <button type="button" class="btn btn-s" id="fmRotSaveAs">另存图片</button>
            <button type="button" class="btn btn-s btn-primary" id="fmRotSaveOk">保存旋转</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmClassifyModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card">
        <h3>一键归类</h3>
        <div class="fm-field">
            <label><input type="radio" name="fmClassifyMode" value="type"<?php echo empty($fmCategories) ? '' : ' checked'; ?>>
                按文件类型归类<?php if (empty($fmCategories)): ?>（需先在插件设置中填写自定义分类）<?php endif; ?></label>
            <label><input type="radio" name="fmClassifyMode" value="category"<?php echo empty($fmCategories) ? ' checked' : ''; ?>>
                按文章分类归类（依据附件所属文章的分类）</label>
            <label><input type="radio" name="fmClassifyMode" value="ym"> 按年月归类（仿 Typecho 原生 /年/月 上传目录）</label>
        </div>
        <p class="fm-modal-desc" id="fmClassifyDescType" hidden>将按文件类型（<?php
            $fmCatNames = array();
            foreach ($fmCategories as $fmCat) { $fmCatNames[] = htmlspecialchars($fmCat['name'], ENT_QUOTES, 'UTF-8'); }
            echo implode(' / ', $fmCatNames);
        ?>）自动创建同名文件夹并归类文件。已存在同名文件夹时直接复用。</p>
        <p class="fm-modal-desc" id="fmClassifyDescCategory" hidden>将按附件所属文章的分类自动创建同名文件夹并归类文件，一篇文章有多个分类时只归入其中一个；不属于任何分类文章的文件将归入「未归属」。</p>
        <p class="fm-modal-desc" id="fmClassifyDescYm" hidden>将按附件上传时间自动创建「年/月」两级文件夹（如 2026/10，与 Typecho 原生上传目录结构一致）并归类文件。已存在的文件夹直接复用。</p>
        <div class="fm-field">
            <label><input type="radio" name="fmClassifyScope" value="0" checked> 仅归类「未分类」的文件（推荐，不影响已整理的文件）</label>
            <label><input type="radio" name="fmClassifyScope" value="1"> 重新归类全部文件（将覆盖现有文件夹归属）</label>
        </div>
        <div id="fmClassifyPreview" class="fm-classify-preview">加载中…</div>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>取消</button>
            <button type="button" class="btn btn-s btn-primary" id="fmClassifyRunBtn" disabled>确认归类</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmPrefsModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card">
        <h3>偏好设置</h3>
        <div class="fm-field">
            <label class="fm-flabel">列表默认布局</label>
            <select id="fmPrefGrid">
                <option value="5x6">网格视图（5×6）</option>
                <option value="4x7">网格视图（4×7）</option>
                <option value="masonry">瀑布流视图</option>
                <option value="list">列表视图</option>
            </select>
        </div>
        <div class="fm-field">
            <label class="fm-flabel">默认排序</label>
            <select id="fmPrefSort">
                <option value="time-desc">按时间降序</option>
                <option value="time-asc">按时间升序</option>
                <option value="name-desc">按名称降序</option>
                <option value="name-asc">按名称升序</option>
                <option value="size-desc">按大小降序</option>
                <option value="size-asc">按大小升序</option>
            </select>
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmPrefThumbCache"> 列表缩略图缓存（生成小尺寸缩略图，加速列表加载）</label>
            <p style="margin:8px 0 0">
                <button type="button" class="btn btn-s" id="fmThumbWarmup" title="遍历全部图片，按当前浏览顺序预先生成缩略图缓存">预生成全部缩略图</button>
                <button type="button" class="btn btn-s" id="fmThumbClear" title="删除已生成的全部缩略图缓存，并清理旋转基准副本（保存旋转后已不再保留副本，这里清的是历史残留）">清理缓存文件</button>
                <span id="fmThumbStatus" style="margin-left:8px; font-size:13px; color:#7d8b99;"></span>
            </p>
        </div>
        <div class="fm-field">
            <label class="fm-flabel">上传文件存放位置</label>
            <select id="fmPrefUploadDest">
                <option value="site">本站（服务器本地）</option>
                <option value="imagehost">图床·对象存储（外链）</option>
            </select>
        </div>
        <div class="fm-field">
            <label class="fm-flabel">上传文件的默认文件夹</label>
            <select id="fmPrefUploadMode">
                <option value="uncat">未分类</option>
                <option value="latest">最近上传文件所在的文件夹</option>
                <option value="custom">指定文件夹</option>
            </select>
            <select id="fmPrefUploadFolder" style="margin-top:6px" hidden></select>
        </div>
        <div class="fm-field">
            <label class="fm-flabel">上传图片默认转换到</label>
            <select id="fmPrefUploadConv">
                <option value="">不转换</option>
                <option value="webp">WebP（体积最小，推荐）</option>
                <option value="jpg">JPG（体积小，不保留透明背景）</option>
                <option value="png">PNG（无损，支持透明，体积可能变大）</option>
            </select>
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmPrefUploadDt"> 默认启用按上传时间对文件重命名（格式：年月日时分秒）</label>
        </div>
        <div class="fm-field">
            <label class="fm-flabel">进入文件管理页时打开</label>
            <select id="fmPrefStart">
                <option value="all">所有文件</option>
                <option value="uncat">未分类</option>
                <option value="latest">最近上传文件所在的文件夹</option>
                <option value="last">上次选择的文件夹</option>
                <option value="custom">指定文件夹</option>
            </select>
            <select id="fmPrefStartFolder" style="margin-top:6px" hidden></select>
        </div>
        <div class="fm-field">
            <label class="fm-flabel">在文件管理页粘贴截图上传图片的默认文件夹</label>
            <select id="fmPrefPasteMode">
                <option value="uncat">未分类</option>
                <option value="current">当前文件夹</option>
                <option value="latest">最近上传文件所在的文件夹</option>
                <option value="custom">指定文件夹</option>
            </select>
            <select id="fmPrefPasteFolder" style="margin-top:6px" hidden></select>
        </div>
        <div class="fm-field">
            <label class="fm-flabel">文档编辑器默认高亮主题</label>
            <select id="fmPrefHlTheme">
                <option value="none">不启用高亮代码</option>
                <option value="github">GitHub 浅色（默认）</option>
                <option value="atom-one-light">One Light 浅色</option>
                <option value="atom-one-dark">One Dark 深色</option>
                <option value="github-dark">GitHub Dark 深色</option>
                <option value="vs2015">VS2015 深色</option>
                <option value="monokai">Monokai 深色</option>
                <option value="nord">Nord 深色</option>
            </select>
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmPrefShortcut"> 文件管理页启用快捷键（Ctrl+A 全选 / 取消全选当前列表，Delete 删除选中文件）</label>
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmPrefRecycle"> 删除文件移入回收站</label>
            <div style="margin:8px 0 0">
                <label class="fm-flabel" for="fmPrefRecycleDays">回收站保留天数</label>
                <input type="text" id="fmPrefRecycleDays" style="width:110px" maxlength="4" inputmode="numeric">
            </div>
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmPrefHoverPreview"> 在文章编辑器正文的图片链接上显示悬浮预览图</label>
        </div>
        <div class="fm-field">
            <label><input type="checkbox" id="fmPrefVideoHtml"> 文章编辑器插入视频类文件采用 HTML 代码</label>
        </div>
        <p class="fm-modal-desc" id="fmPrefsInfo"></p>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>取消</button>
            <button type="button" class="btn btn-s btn-primary" id="fmPrefsSave">保存</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmImgHostModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card">
        <h3>图床 / 对象存储</h3>
        <p class="fm-modal-desc">对接兰空图床（Lsky Pro）或任意 S3 协议对象存储（阿里云 OSS、腾讯云 COS、七牛、Cloudflare R2、MinIO 等）：配置并启用后即可把图片上传过去并引用外链。</p>
        <div class="fm-field">
            <label><input type="checkbox" id="fmIhEnabled"> 上传到图床/对象存储</label>
        </div>
        <div class="fm-field">
            <label class="fm-flabel">接口类型</label>
            <select id="fmIhType">
                <option value="lsky">兰空图床（Lsky Pro）</option>
                <option value="s3">S3 协议对象存储</option>
            </select>
        </div>
        <div id="fmIhLskyFields">
            <div class="fm-field">
                <label class="fm-flabel">接口地址</label>
                <input type="text" id="fmIhApi" maxlength="200" placeholder="https://wmimg.com/api/v1">
            </div>
            <div class="fm-field">
                <label class="fm-flabel">访问令牌 Token</label>
                <input type="text" id="fmIhToken" maxlength="200" placeholder="图站个人中心生成的令牌串，如 1|1bJb...（已保存过则留空沿用）">
            </div>
            <div class="fm-field">
                <label class="fm-flabel">储存策略</label>
                <select id="fmIhStrategy"><option value="0">图床默认策略</option></select>
            </div>
            <div class="fm-field">
                <label class="fm-flabel">相册 ID（可选，留 0 表示不入相册）</label>
                <input type="text" id="fmIhAlbum" maxlength="20" placeholder="0">
            </div>
            <div class="fm-field">
                <label class="fm-flabel">图片权限</label>
                <select id="fmIhPermission">
                    <option value="1">公开（任何人可通过外链访问）</option>
                    <option value="0">私有（需登录图站才能访问）</option>
                </select>
            </div>
        </div>
        <div id="fmIhS3Fields" hidden>
            <div class="fm-field">
                <label class="fm-flabel">Endpoint（S3 兼容端点）</label>
                <input type="text" id="fmIhS3Endpoint" maxlength="200" placeholder="如 https://cos.ap-guangzhou.myqcloud.com 或 MinIO 地址">
            </div>
            <div class="fm-field">
                <label class="fm-flabel">Region（区域，可选）</label>
                <input type="text" id="fmIhS3Region" maxlength="60" placeholder="如 ap-guangzhou / oss-cn-hangzhou，留空默认 us-east-1">
            </div>
            <div class="fm-field">
                <label class="fm-flabel">Bucket（存储桶名）</label>
                <input type="text" id="fmIhS3Bucket" maxlength="120" placeholder="如 my-bucket">
            </div>
            <div class="fm-field">
                <label class="fm-flabel">AccessKey ID</label>
                <input type="text" id="fmIhS3Ak" maxlength="200" placeholder="对象存储控制台生成的 AccessKey ID">
            </div>
            <div class="fm-field">
                <label class="fm-flabel">AccessKey Secret</label>
                <input type="text" id="fmIhS3Sk" maxlength="200" placeholder="AccessKey Secret（已保存过则留空沿用）">
            </div>
            <div class="fm-field">
                <label class="fm-flabel">路径前缀（可选）</label>
                <input type="text" id="fmIhS3Prefix" maxlength="120" placeholder="如 blog/，留空直接存 Bucket 根目录">
            </div>
            <div class="fm-field">
                <label class="fm-flabel">外链域名（可选）</label>
                <input type="text" id="fmIhS3BaseUrl" maxlength="300" placeholder="自定义域名/CDN/虚拟主机式，如 https://cdn.example.com；留空用 端点/Bucket">
            </div>
            <p class="fm-modal-desc" style="margin:4px 0 0">上传后按「前缀/日期/文件名-随机串.扩展名」生成对象键；检测连接会列出 Bucket 校验端点与密钥。建议 Bucket 设为公开读（或绑定 CDN 域名），否则外链无法直接访问。</p>
        </div>
        <p class="fm-modal-desc" id="fmIhInfo"></p>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" id="fmIhTest" title="验证连接是否可用">检测连接</button>
            <span style="flex:1"></span>
            <button type="button" class="btn btn-s" data-fm-close>取消</button>
            <button type="button" class="btn btn-s btn-primary" id="fmIhSave">保存</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmZipModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card">
        <h3>压缩文件</h3>
        <div class="fm-field">
            <label class="fm-flabel">压缩包名</label>
            <input type="text" id="fmZipName" maxlength="100">
        </div>
        <div class="fm-field">
            <label class="fm-flabel">压缩率</label>
            <label style="display:inline-flex;align-items:center;gap:4px;margin-right:18px"><input type="radio" name="fmZipLevel" value="0" checked> 快速</label>
            <label style="display:inline-flex;align-items:center;gap:4px"><input type="radio" name="fmZipLevel" value="6"> 标准</label>
        </div>
        <div class="fm-field">
            <label class="fm-flabel">内部结构（跨文件夹打包时）</label>
            <label style="display:inline-flex;align-items:center;gap:4px;margin-right:18px"><input type="radio" name="fmZipStructure" value="folder" checked> 按文件夹分目录</label>
            <label style="display:inline-flex;align-items:center;gap:4px"><input type="radio" name="fmZipStructure" value="flat"> 全部平铺</label>
        </div>
        <div class="fm-field">
            <label class="fm-flabel">存放位置</label>
            <label style="display:inline-flex;align-items:center;gap:4px;margin-right:18px"><input type="radio" name="fmZipPlace" value="current" checked> 当前文件夹</label>
            <label style="display:inline-flex;align-items:center;gap:4px"><input type="radio" name="fmZipPlace" value="pick"> 指定文件夹</label>
            <select id="fmZipFolder" style="margin-top:6px" hidden></select>
        </div>
        <div class="fm-field">
            <label style="color:#b91c1c"><input type="checkbox" id="fmZipDelete" value="1"> 打包后删除原文件（勾选请谨慎）</label>
        </div>
        <p class="fm-modal-desc" id="fmZipInfo"></p>
        <p class="fm-modal-desc" id="fmZipWarnBig" hidden style="color:#e07b32">⚠ 文件较多或较大，打包可能耗时较长，请耐心等待。</p>
        <div id="fmZipMissing" hidden>
            <p class="fm-modal-desc" style="color:#d9534f">以下文件物理文件缺失（云存储或已丢失），无法打包：</p>
            <div class="fm-modal-desc" id="fmZipMissingList" style="max-height:100px;overflow:auto"></div>
        </div>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>取消</button>
            <button type="button" class="btn btn-s btn-primary" id="fmZipStart">开始压缩</button>
        </div>
    </div>
</div>

<div class="fm-modal" id="fmExtractModal" hidden>
    <div class="fm-modal-mask" data-fm-close></div>
    <div class="fm-modal-card">
        <h3>解压文件</h3>
        <p class="fm-modal-file" id="fmExtractFileName"></p>
        <div class="fm-field" id="fmExtractModeField">
            <label class="fm-flabel">解压方式</label>
            <label><input type="radio" name="fmExtractMode" value="current" checked> 解压到当前文件夹</label>
            <label><input type="radio" name="fmExtractMode" value="sub"> 解压到 /<span id="fmExtractSubName" style="font-weight:600">压缩包名称</span>（新建同名子文件夹收纳）</label>
        </div>
        <div class="fm-field" id="fmExtractPwdField" hidden>
            <label>解压密码（该压缩包已加密）</label>
            <input type="text" id="fmExtractPassword" placeholder="请输入解压密码">
        </div>
        <div class="fm-field">
            <label style="color:#b91c1c"><input type="checkbox" id="fmExtractDelete" value="1"> 解压后删除原压缩包（勾选请谨慎）</label>
        </div>
        <p class="fm-modal-desc" id="fmExtractInfo">正在读取压缩包…</p>
        <p class="fm-modal-desc" id="fmExtractSkipped" hidden style="color:#e07b32"></p>
        <div class="fm-modal-btns">
            <button type="button" class="btn btn-s" data-fm-close>取消</button>
            <button type="button" class="btn btn-s btn-primary" id="fmExtractStart" disabled>开始解压</button>
        </div>
    </div>
</div>

<div class="fm-toast" id="fmToast" hidden></div>

<style>
[hidden] { display: none !important; }
.fm-layout { display: flex; gap: 16px; align-items: flex-start; min-height: 480px; margin-top: 16px; }
.fm-side { width: 230px; flex-shrink: 0; background: #fff; border: 1px solid #e6e9ee; border-radius: 6px; position: relative; }
.fm-side.fm-root-hint { outline: 2px dashed #2a6496; outline-offset: -2px; background: #f2f9fe; }
.fm-side-head { padding: 10px; border-bottom: 1px solid #eef1f5; display: flex; flex-wrap: wrap; gap: 6px; }
.fm-tree { list-style: none; margin: 0; padding: 8px 6px; font-size: 13px; }
.fm-tree ul { list-style: none; margin: 0; padding-left: 12px; }
.fm-node { display: flex; align-items: center; gap: 4px; padding: 5px 6px; border-radius: 4px; cursor: pointer; position: relative; }
.fm-node:hover { background: #f2f6fa; }
.fm-node.current { background: #e8f1f8; color: #2a6496; font-weight: 500; }
.fm-node .fm-fname { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fm-node .fm-badge { color: #98a2ad; font-size: 12px; }
.fm-node .fm-ops { display: none; gap: 2px; }
.fm-node:hover .fm-ops { display: inline-flex; }
.fm-node .fm-ops button { border: none; background: none; cursor: pointer; color: #7d8b99; padding: 0 3px; font-size: 12px; }
.fm-node .fm-ops button:hover { color: #2a6496; }
.fm-node.fm-drop-hint { background: #d8ebf8; outline: 1px dashed #2a6496; outline-offset: -1px; }
.fm-node.fm-drop-hint .fm-fname { color: #2a6496; }
.fm-card[draggable="true"], .fm-list tbody tr[draggable="true"], .fm-node[data-fid][draggable="true"] { cursor: grab; }
.fm-card[draggable="true"]:active, .fm-list tbody tr[draggable="true"]:active, .fm-node[data-fid][draggable="true"]:active { cursor: grabbing; }
.fm-drag-tip { display: none; position: absolute; top: calc(100% + 10px); left: 0; right: 0; z-index: 20; padding: 8px 10px; font-size: 12px; color: #2a6496; background: #eaf4fb; border: 1px dashed #7fb3d5; border-radius: 4px; line-height: 1.5; box-shadow: 0 2px 6px rgba(42,100,150,.2); pointer-events: none; }
.fm-drag-tip.show { display: block; }
.fm-drag-tip.armed { color: #fff; background: #2a6496; border-style: solid; border-color: #2a6496; }
.fm-arrow { width: 16px; display: inline-block; color: inherit; font-size: 16px; line-height: 1; cursor: pointer; text-align: center; position: relative; top: -1px; }
.fm-ficon { display: inline-flex; align-items: center; flex: none; margin-left: -2px; }
.fm-ficon svg { display: block; }
.fm-main { flex: 1; min-width: 0; }
.fm-toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; background: #fff; border: 1px solid #e6e9ee; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; }
.fm-toolbar-left, .fm-toolbar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.fm-toolbar-right { width: 100%; } /* 第二行功能按钮独占一行，左对齐 */
.fm-toolbar select, .fm-search input { height: 30px; border: 1px solid #d5dde5; border-radius: 6px; padding: 0 8px; font-size: 13px; background: #fff; transition: border-color .15s ease, box-shadow .15s ease; }
.fm-toolbar select:hover, .fm-search input:hover { border-color: #b7c9d9; }
.fm-toolbar select:focus, .fm-search input:focus { border-color: #6ea3c8; outline: none; box-shadow: 0 0 0 2px rgba(110, 163, 200, .18); }
.fm-view-toggle { display: inline-flex; border: 1px solid #d5dde5; border-radius: 6px; overflow: hidden; background: #fff; }
.fm-view-toggle button { border: none; background: #fff; width: 32px; height: 28px; cursor: pointer; color: #98a2ad; font-size: 14px; transition: background .15s ease, color .15s ease; }
.fm-view-toggle button + button { border-left: 1px solid #e6ecf1; }
.fm-view-toggle button:hover { background: #f2f7fb; color: #2a6496; }
.fm-view-toggle button.current { background: #e8f1f8; color: #2a6496; }
.fm-view-wrap { position: relative; display: inline-flex; }
.fm-view-toggle #fmGridSizeBtn { width: 22px; font-size: 11px; }
.fm-grid-menu { position: absolute; z-index: 500; margin-top: 6px; background: #fff; border: 1px solid #d5dde5; border-radius: 6px; box-shadow: 0 4px 14px rgba(15, 30, 50, .12); padding: 4px; min-width: 150px; }
.fm-grid-menu button { display: block; width: 100%; border: none; background: none; text-align: left; padding: 6px 10px; border-radius: 4px; font-size: 13px; color: #3b4754; cursor: pointer; white-space: nowrap; }
.fm-grid-menu button:hover { background: #f2f7fb; color: #2a6496; }
.fm-grid-menu button.current { background: #e8f1f8; color: #2a6496; }
.fm-grid-menu button.current::after { content: ' ✓'; }
.fm-search input { width: 170px; }
.fm-search { display: inline-flex; align-items: center; gap: 6px; } /* flex 垂直居中：复选框不再偏上，按钮也不再需要基线补偿 */
.fm-csearch input { margin: 0; }
.fm-marquee { position: fixed; z-index: 9998; border: 1px solid #2a6496; background: rgba(42, 100, 150, .12); pointer-events: none; display: none; }
body.fm-marqueeing { user-select: none; -webkit-user-select: none; }
.fm-list .fm-drag-handle { cursor: grab; } /* 列表视图拖拽手柄（按住它拖动到文件夹树） */
.fm-csearch { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; color: #4a5763; cursor: pointer; white-space: nowrap; user-select: none; }
#fmCSearchBody { max-height: 60vh; overflow: auto; }
.fm-cs-meta { font-size: 12px; color: #8892a0; margin: 0 0 8px; }
.fm-cs-row { border: 1px solid #e6e9ee; border-radius: 6px; padding: 8px 10px; margin-bottom: 8px; }
.fm-cs-head { display: flex; align-items: center; gap: 8px; }
.fm-cs-head a { color: #2a6496; text-decoration: none; font-weight: 500; }
.fm-cs-snip { font-size: 12px; color: #5f6b7a; margin-top: 4px; word-break: break-all; line-height: 1.6; }
.fm-cs-snip mark, #fmCSearchBody mark { background: #fff3bf; color: inherit; padding: 0 1px; }
.fm-layout .btn, .fm-modal-card .btn {
    display: inline-flex; align-items: center; justify-content: center;
    height: 30px; padding: 0 14px; border-radius: 6px;
    font-size: 13px; font-weight: 500; line-height: 1; white-space: nowrap;
    border: 1px solid #d5dde5; background: linear-gradient(180deg, #ffffff, #f7fafc);
    color: #4a5a68; cursor: pointer; text-decoration: none; user-select: none;
    box-shadow: 0 1px 2px rgba(16, 24, 40, .05);
    transition: background .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease, transform .1s ease;
}
.fm-layout .btn:hover, .fm-modal-card .btn:hover {
    background: #f2f7fb; border-color: #b7c9d9; color: #2a6496;
    box-shadow: 0 2px 4px rgba(16, 24, 40, .08);
}
.fm-layout .btn:active, .fm-modal-card .btn:active {
    transform: translateY(1px); box-shadow: none;
}
.fm-layout .btn:focus-visible, .fm-modal-card .btn:focus-visible {
    outline: 2px solid #9cc3e0; outline-offset: 1px;
}
.fm-layout .btn[disabled], .fm-modal-card .btn[disabled] {
    opacity: .55; cursor: not-allowed; transform: none;
    box-shadow: none; background: #eef1f4; border-color: #d9e0e7; color: #98a2ad;
}
.fm-layout .btn-s, .fm-modal-card .btn-s { height: 28px; padding: 0 12px; font-size: 12px; }

.btn-primary {
    background: linear-gradient(180deg, #3a7fb5, #2f6f9f);
    border-color: #2a6496; color: #fff;
    box-shadow: 0 1px 2px rgba(42, 100, 150, .25);
}
.btn-primary:hover {
    background: linear-gradient(180deg, #2f6f9f, #27618d);
    border-color: #235a86; color: #fff;
    box-shadow: 0 2px 5px rgba(42, 100, 150, .3);
}
.btn-warning {
    background: linear-gradient(180deg, #ef8c3c, #e07b32);
    border-color: #d3702a; color: #fff;
    box-shadow: 0 1px 2px rgba(224, 123, 50, .28);
}
.btn-warning:hover {
    background: linear-gradient(180deg, #e07b32, #d06d26);
    border-color: #c06420; color: #fff;
    box-shadow: 0 2px 5px rgba(224, 123, 50, .32);
}
.btn-danger {
    background: linear-gradient(180deg, #e2605c, #d9534f);
    border-color: #cf4844; color: #fff;
    box-shadow: 0 1px 2px rgba(217, 83, 79, .28);
}
.btn-danger:hover {
    background: linear-gradient(180deg, #d9534f, #c94642);
    border-color: #b83c38; color: #fff;
    box-shadow: 0 2px 5px rgba(217, 83, 79, .32);
}
#fmCleanBtn, #fmBatchDeleteBtn {
    background: linear-gradient(180deg, #e2605c, #d9534f) !important;
    border: 1px solid #cf4844 !important; color: #fff !important;
    box-shadow: 0 1px 2px rgba(217, 83, 79, .28);
}
#fmCleanBtn:hover, #fmBatchDeleteBtn:hover {
    background: linear-gradient(180deg, #d9534f, #c94642) !important;
    border-color: #b83c38 !important; color: #fff !important;
}
#fmCleanBtn:active, #fmCleanBtn:focus, #fmBatchDeleteBtn:active, #fmBatchDeleteBtn:focus {
    background: linear-gradient(180deg, #c94642, #b83c38) !important;
    border-color: #a83531 !important; color: #fff !important;
    outline: none;
}
#fmShareListBtn {
    background: linear-gradient(180deg, #3a7fb5, #2f6f9f) !important;
    border: 1px solid #2a6496 !important; color: #fff !important;
    box-shadow: 0 1px 2px rgba(42, 100, 150, .25);
}
#fmShareListBtn:hover {
    background: linear-gradient(180deg, #2f6f9f, #27618d) !important;
    border-color: #235a86 !important; color: #fff !important;
}
#fmShareListBtn:active, #fmShareListBtn:focus {
    background: linear-gradient(180deg, #27618d, #235a86) !important;
    border-color: #1e4f75 !important; color: #fff !important;
    outline: none;
}
#fmUploadBtn {
    background: linear-gradient(180deg, #28854f, #1f7345) !important;
    border: 1px solid #1a6b40 !important; color: #fff !important;
    box-shadow: 0 1px 2px rgba(31, 115, 69, .25);
}
#fmUploadBtn:hover {
    background: linear-gradient(180deg, #1f7345, #1a6439) !important;
    border-color: #155c34 !important; color: #fff !important;
}
#fmUploadBtn:active, #fmUploadBtn:focus {
    background: linear-gradient(180deg, #1a6439, #165531) !important;
    border-color: #12492a !important; color: #fff !important;
    outline: none;
}
#fmShareCreateBtn, #fmShareUpdateBtn, #fmShareCopy, .fm-share-item .btn-share-blue {
    background: linear-gradient(180deg, #3a7fb5, #2f6f9f) !important;
    border: 1px solid #2a6496 !important; color: #fff !important;
    box-shadow: 0 1px 2px rgba(42, 100, 150, .25);
}
#fmShareCreateBtn:hover, #fmShareUpdateBtn:hover, #fmShareCopy:hover, .fm-share-item .btn-share-blue:hover {
    background: linear-gradient(180deg, #2f6f9f, #27618d) !important;
    border-color: #235a86 !important; color: #fff !important;
}
.fm-share-item .btn-share-red, #fmTrashClearBtn, .fm-trash-table .btn-share-red {
    background: linear-gradient(180deg, #e2605c, #d9534f) !important;
    border: 1px solid #cf4844 !important; color: #fff !important;
}
.fm-share-item .btn-share-red:hover, #fmTrashClearBtn:hover, .fm-trash-table .btn-share-red:hover {
    background: linear-gradient(180deg, #d9534f, #c94642) !important;
    border-color: #b83c38 !important; color: #fff !important;
}
.fm-modal-card .btn-primary {
    background: linear-gradient(180deg, #3a7fb5, #2f6f9f) !important;
    border: 1px solid #2a6496 !important; color: #fff !important;
    box-shadow: 0 1px 2px rgba(42, 100, 150, .25);
}
.fm-modal-card .btn-primary:hover {
    background: linear-gradient(180deg, #2f6f9f, #27618d) !important;
    border-color: #235a86 !important; color: #fff !important;
}
.fm-modal-card .btn-primary:active, .fm-modal-card .btn-primary:focus {
    background: linear-gradient(180deg, #27618d, #235a86) !important;
    border-color: #1e4f75 !important; color: #fff !important;
    outline: none;
}
.fm-modal-card .btn-primary[disabled] {
    background: #eef1f4 !important;
    border-color: #d9e0e7 !important; color: #98a2ad !important;
    box-shadow: none;
}
.fm-card .fm-actions button[data-act="del"],
.fm-list td .btn.btn-del,
#fmLightbox .fm-lb-act-del {
    background: linear-gradient(180deg, #e2605c, #d9534f) !important;
    border: 1px solid #cf4844 !important; color: #fff !important;
    box-shadow: 0 1px 2px rgba(217, 83, 79, .28);
}
.fm-card .fm-actions button[data-act="del"]:hover,
.fm-list td .btn.btn-del:hover,
#fmLightbox .fm-lb-act-del:hover {
    background: linear-gradient(180deg, #d9534f, #c94642) !important;
    border-color: #b83c38 !important; color: #fff !important;
}
.fm-card .fm-actions button[data-act="del"]:active,
.fm-list td .btn.btn-del:active,
#fmLightbox .fm-lb-act-del:active {
    background: linear-gradient(180deg, #c94642, #b83c38) !important;
    border-color: #a83531 !important; color: #fff !important;
}
.fm-stats-total { font-size: 14px; margin: 0 0 12px; color: #333; }
.fm-stats-total b { font-size: 16px; color: #1a1a1a; }
.fm-stats-row { display: flex; align-items: center; gap: 8px; margin: 0 0 7px; font-size: 13px; }
.fm-stats-label { width: 160px; text-align: left; color: #555; flex: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fm-stats-barwrap { flex: 1; height: 14px; background: #eef1f5; border-radius: 7px; overflow: hidden; }
.fm-stats-bar { display: block; height: 100%; background: linear-gradient(180deg, #6ea3c8, #5b8fb8); border-radius: 7px; }
.fm-stats-bar.fm-stats-bar-ext { background: linear-gradient(180deg, #82c3a3, #5fae8d); }
.fm-stats-val { width: 168px; flex: none; color: #555; }
.fm-stats-table { width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 13px; }
.fm-stats-table th, .fm-stats-table td { border-bottom: 1px solid #eef1f5; padding: 6px 6px; text-align: left; }
.fm-stats-table th { color: #666; font-weight: 500; background: #fafbfc; }
.fm-stats-table td a { color: #2a6496; }
#fmTrashBody { overflow-x: auto; }
.fm-trash-table { width: 100%; min-width: 640px; table-layout: fixed; border-collapse: collapse; margin-top: 4px; font-size: 13px; }
.fm-trash-table th, .fm-trash-table td {
    border-bottom: 1px solid #eef1f5; padding: 9px 10px; text-align: left;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.fm-trash-table th { color: #6b7a89; font-weight: 500; font-size: 12px; background: #fafbfc; }
.fm-trash-table tbody tr:hover { background: #f7fafd; }
.fm-trash-table .fm-trash-name { color: #2f3f4d; font-weight: 500; }
.fm-trash-table .fm-trash-dim { color: #7d8b99; }
.fm-trash-table .fm-trash-act { display: flex; align-items: center; gap: 6px; }
.fm-trash-table .fm-trash-act .btn { flex: none; }
.fm-health-tips { margin: 12px 0 0; padding-top: 10px; border-top: 1px dashed #e9edf2; font-size: 12px; color: #7d8b99; line-height: 1.9; }
.fm-health-tips p { margin: 0; }
.fm-health-tips b { color: #5f6f7e; font-weight: 500; }
.fm-batch { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; background: #fff8e8; border: 1px solid #f0e2c0; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; font-size: 13px; }
body.fm-select-mode .fm-card { cursor: pointer; }
body.fm-select-mode .fm-card .fm-thumb, body.fm-select-mode .fm-card .fm-mthumb { pointer-events: none; }
body.fm-select-mode .fm-list tbody tr { cursor: pointer; }
body.fm-select-mode .fm-list .fm-name-cell a { pointer-events: none; }
/* 上下各留「安全带」：padding 把 flex 居中区压进去，图片不会钻到底部那一摞底下 */
#fmLightbox { position: fixed; left: 0; top: 0; right: 0; bottom: 0; z-index: 20000; box-sizing: border-box; padding: 64px 0 112px; display: flex; align-items: center; justify-content: center; }
#fmLightbox[hidden] { display: none; }
#fmLightbox .fm-lb-backdrop { position: absolute; left: 0; top: 0; right: 0; bottom: 0; background: rgba(15, 22, 30, .88); }
#fmLightbox .fm-lb-close { position: absolute; top: 18px; right: 24px; z-index: 2; width: 42px; height: 42px; border: none; border-radius: 50%; background: rgba(255, 255, 255, .14); color: #fff; font-size: 26px; line-height: 1; cursor: pointer; transition: background .15s; }
#fmLightbox .fm-lb-close:hover { background: rgba(255, 255, 255, .32); }
#fmLightbox .fm-lb-nav { position: absolute; top: 50%; transform: translateY(-50%); z-index: 2; width: 46px; height: 46px; border: none; border-radius: 50%; background: rgba(255, 255, 255, .14); color: #fff; font-size: 30px; line-height: 1; cursor: pointer; transition: background .15s; user-select: none; }
#fmLightbox .fm-lb-nav:hover { background: rgba(255, 255, 255, .32); }
#fmLightbox .fm-lb-nav.fm-lb-prev { left: 20px; }
#fmLightbox .fm-lb-nav.fm-lb-next { right: 20px; }
#fmLightbox .fm-lb-body { position: relative; z-index: 1; max-width: 92vw; max-height: 100%; display: flex; flex-direction: column; align-items: center; gap: 10px; }
#fmLightbox img, #fmLightbox video { display: block; max-width: 92vw; max-height: calc(100vh - 240px); border-radius: 6px; box-shadow: 0 8px 40px rgba(0, 0, 0, .5); }
#fmLightbox img { background: #fff; }
#fmLightbox video { background: #000; }
#fmLightbox .fm-lb-caption { color: #dfe7ee; font-size: 13px; line-height: 1.5; text-shadow: 0 1px 3px rgba(0, 0, 0, .6); text-align: center; max-width: 100%; word-break: break-all; }
/* 底部一摞：文件信息 → EXIF → 按钮行，固定在页面底部；容器 pointer-events:none 保留「点空白关闭」 */
#fmLightbox .fm-lb-foot { position: absolute; left: 0; right: 0; bottom: 20px; z-index: 3; box-sizing: border-box; padding: 0 16px; display: flex; flex-direction: column; align-items: center; gap: 10px; pointer-events: none; }
#fmLightbox .fm-lb-foot > * { pointer-events: auto; }
#fmLightbox .fm-lb-toolbar { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; max-width: 94vw; }
#fmLightbox .fm-lb-toolbar button,
#fmLightbox .fm-lb-toolbar a { height: 34px; padding: 0 18px; border: none; border-radius: 17px; background: rgba(255, 255, 255, .14); color: #fff; font-size: 13px; line-height: 34px; cursor: pointer; text-decoration: none; transition: background .15s; }
#fmLightbox .fm-lb-toolbar button:hover,
#fmLightbox .fm-lb-toolbar a:hover { background: rgba(255, 255, 255, .32); }
#fmLightbox .fm-lb-toolbar .fm-lb-act-del:hover { background: rgba(217, 83, 79, .85); }
#fmLightbox .fm-lb-exifline { color: #9fb3c4; font-size: 12px; line-height: 1.6; text-align: center; max-width: 92vw; word-break: break-all; text-shadow: 0 1px 3px rgba(0, 0, 0, .6); }
#fmLightbox #fmLbContent img { cursor: zoom-in; transition: transform .15s ease-out; }
#fmLightbox #fmLbContent img.fm-zoomed { cursor: grab; }
#fmLightbox #fmLbContent img.fm-zoomed.fm-dragging { cursor: grabbing; transition: none; }
@keyframes fmLbFadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes fmLbFadeOut { from { opacity: 1; } to { opacity: 0; } }
@keyframes fmLbZoomIn { from { opacity: 0; transform: scale(.92); } to { opacity: 1; transform: scale(1); } }
@keyframes fmLbZoomOut { from { opacity: 1; transform: scale(1); } to { opacity: 0; transform: scale(.94); } }
@keyframes fmLbSlideNext { from { opacity: 0; transform: translateX(56px); } to { opacity: 1; transform: translateX(0); } }
@keyframes fmLbSlidePrev { from { opacity: 0; transform: translateX(-56px); } to { opacity: 1; transform: translateX(0); } }
#fmLightbox.fm-lb-opening .fm-lb-backdrop { animation: fmLbFadeIn .18s ease-out both; }
#fmLightbox.fm-lb-opening .fm-lb-body { animation: fmLbZoomIn .22s ease-out both; }
#fmLightbox.fm-lb-closing .fm-lb-backdrop { animation: fmLbFadeOut .16s ease-in both; }
#fmLightbox.fm-lb-closing .fm-lb-body { animation: fmLbZoomOut .16s ease-in both; }
#fmLightbox #fmLbContent.fm-lb-in-next { animation: fmLbSlideNext .2s ease-out; }
#fmLightbox #fmLbContent.fm-lb-in-prev { animation: fmLbSlidePrev .2s ease-out; }
#fmLightbox #fmLbContent { position: relative; }
#fmLightbox .fm-lb-saving { position: absolute; inset: 0; z-index: 6; display: flex; align-items: center; justify-content: center; gap: 10px; background: rgba(9, 14, 20, .18); transition: background .18s ease; }
#fmLightbox .fm-lb-saving.is-veil { background: rgba(9, 14, 20, .88); -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px); }
#fmLightbox .fm-lb-saving[hidden] { display: none; }
#fmLightbox .fm-lb-saving .fm-lb-spin { width: 22px; height: 22px; border-radius: 50%; border: 3px solid rgba(255, 255, 255, .35); border-top-color: #fff; animation: fmLbSpin .8s linear infinite; }
@keyframes fmLbSpin { to { transform: rotate(360deg); } }
#fmLightbox .fm-lb-saving span { color: #fff; font-size: 14px; text-shadow: 0 1px 4px rgba(0, 0, 0, .55); }
#fmLightbox .fm-lb-exif-btn { position: absolute; top: 18px; left: 24px; z-index: 2; height: 42px; padding: 0 18px; border: none; border-radius: 21px; background: rgba(255, 255, 255, .14); color: #fff; font-size: 14px; letter-spacing: 1px; cursor: pointer; transition: background .15s; }
#fmLightbox .fm-lb-exif-btn:hover { background: rgba(255, 255, 255, .32); }
#fmLightbox .fm-lb-exif-btn.on { background: #2a6496; }
#fmLightbox .fm-lb-exif { position: absolute; top: 74px; left: 24px; z-index: 2; width: 300px; max-width: 80vw; max-height: 72vh; overflow: auto; background: rgba(15, 22, 30, .94); border: 1px solid rgba(255, 255, 255, .16); border-radius: 8px; padding: 12px 14px; color: #dfe7ee; font-size: 13px; }
#fmLightbox .fm-exif-row { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; border-bottom: 1px solid rgba(255, 255, 255, .08); }
#fmLightbox .fm-exif-row:last-of-type { border-bottom: none; }
#fmLightbox .fm-exif-label { color: #9fb3c4; flex-shrink: 0; }
#fmLightbox .fm-exif-value { text-align: right; word-break: break-all; }
#fmLightbox .fm-exif-note { color: #9fb3c4; font-size: 12px; line-height: 1.6; padding: 4px 0; }
#fmLightbox .fm-exif-src { margin-top: 8px; color: #7d8b99; font-size: 11px; }
/* 窄屏：底栏可能换成两行按钮，留够高度并缩小按钮，避免第二行被裁 */
@media (max-width: 560px) {
    #fmLightbox { padding: 58px 0 128px; }
    #fmLightbox img, #fmLightbox video { max-height: calc(100vh - 268px); }
    #fmLightbox .fm-lb-foot { bottom: 14px; gap: 8px; padding: 0 10px; }
    #fmLightbox .fm-lb-caption { font-size: 12px; }
    #fmLightbox .fm-lb-toolbar { gap: 8px; }
    #fmLightbox .fm-lb-toolbar button,
    #fmLightbox .fm-lb-toolbar a { height: 30px; padding: 0 13px; border-radius: 15px; line-height: 30px; font-size: 12.5px; }
}
.fm-batch select { height: 28px; border: 1px solid #d9e0e7; border-radius: 4px; }
#fmBatchAllBtn { min-width: 76px; text-align: center; } /* 固定为「取消全选」四字宽度，切换文案时不跳宽 */
.fm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.fm-grid.cols-4 { grid-template-columns: repeat(4, 1fr); } /* 4×6 布局：卡片更大 */
.fm-masonry { column-width: 210px; column-gap: 12px; }
.fm-masonry .fm-card { display: inline-block; width: 100%; margin: 0 0 12px; break-inside: avoid; -webkit-column-break-inside: avoid; }
.fm-masonry .fm-card .fm-name { min-height: 0; }
.fm-masonry .fm-mthumb { display: flex; align-items: center; justify-content: center; width: 100%; background: #f4f6f9; overflow: hidden; }
.fm-masonry .fm-mthumb img { display: block; width: 100%; height: auto; }
.fm-masonry .fm-folder-card .fm-mthumb { padding: 18px 0; }
.fm-card { background: #fff; border: 1px solid #e6e9ee; border-radius: 6px; overflow: hidden; position: relative; display: flex; flex-direction: column; }
.fm-card:hover { border-color: #b9d2e5; }
.fm-card.selected { border-color: #2f6f9f; box-shadow: 0 0 0 1px #2f6f9f; }
.fm-layout input[type="checkbox"],
.fm-modal-card input[type="checkbox"] {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    width: 18px; height: 18px; margin: 0; padding: 0;
    border: 1px solid #cdd7e0; border-radius: 5px;
    background: linear-gradient(180deg, #ffffff, #f7fafc);
    cursor: pointer; position: relative; vertical-align: middle; flex-shrink: 0;
    box-shadow: 0 1px 2px rgba(16, 24, 40, .05);
    transition: background .15s ease, border-color .15s ease, box-shadow .15s ease;
}
.fm-layout input[type="checkbox"]:hover,
.fm-modal-card input[type="checkbox"]:hover {
    border-color: #6ea3c8;
    box-shadow: 0 0 0 2px rgba(110, 163, 200, .15);
}
.fm-layout input[type="checkbox"]:active,
.fm-modal-card input[type="checkbox"]:active { transform: translateY(1px); }
.fm-layout input[type="checkbox"]:focus-visible,
.fm-modal-card input[type="checkbox"]:focus-visible {
    outline: 2px solid #9cc3e0; outline-offset: 1px;
}
.fm-layout input[type="checkbox"]:checked,
.fm-modal-card input[type="checkbox"]:checked {
    background: linear-gradient(180deg, #3a7fb5, #2f6f9f);
    border-color: #2a6496;
}
.fm-layout input[type="checkbox"]:checked::after,
.fm-modal-card input[type="checkbox"]:checked::after {
    content: ''; position: absolute; left: 5px; top: 1px;
    width: 5px; height: 10px;
    border: solid #fff; border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}
.fm-card .fm-check {
    position: absolute; top: 7px; left: 7px; z-index: 2;
    background: rgba(255, 255, 255, .92);
    border-radius: 6px; padding: 2px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, .12);
    display: flex; align-items: center;
}
.fm-thumb { display: flex; align-items: center; justify-content: center; height: 110px; background: #f4f6f9; overflow: hidden; }
.fm-thumb img { max-width: 100%; max-height: 100%; object-fit: cover; }
.fm-thumb, .fm-mthumb, .fm-mini-wrap { position: relative; }
.fm-ih-badge { position: absolute; top: 4px; right: 4px; z-index: 2; pointer-events: none; background: rgba(17, 24, 32, .72); color: #fff; font-size: 11px; line-height: 1; padding: 3px 6px; border-radius: 3px; letter-spacing: 1px; }
.fm-mini-wrap { display: inline-block; line-height: 0; }
.fm-mini-wrap .fm-ih-badge { top: 2px; right: 2px; font-size: 10px; padding: 2px 4px; }
.fm-exticon { font-size: 20px; font-weight: 600; color: #fff; background: #8aa5bd; border-radius: 6px; padding: 10px 12px; text-transform: uppercase; letter-spacing: 1px; }
.fm-folder-card { cursor: pointer; }
.fm-folder-card .fm-thumb { background: #fafbfc; }
.fm-folder-row { cursor: pointer; }
.fm-folder-row:hover { background: #f2f6fa; }
.fm-card .fm-name { padding: 8px 10px 2px; font-size: 13px; word-break: break-all; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 36px; }
.fm-card .fm-meta { padding: 0 10px 8px; color: #98a2ad; font-size: 12px; }
.fm-card .fm-actions { display: flex; border-top: 1px solid #eef1f5; margin-top: auto; }
.fm-card .fm-actions a, .fm-card .fm-actions button { flex: 1; border: none; background: none; padding: 7px 0; font-size: 12px; color: #5f6f7e; cursor: pointer; text-align: center; text-decoration: none; }
.fm-card .fm-actions a:hover, .fm-card .fm-actions button:hover { background: #f2f6fa; color: #2a6496; }
.fm-card .fm-actions > * + * { border-left: 1px solid #eef1f5; }
.fm-layout .fm-list { width: 100%; background: #fff; border: 1px solid #e6e9ee; border-radius: 8px; border-collapse: separate; border-spacing: 0; overflow: hidden; box-shadow: 0 1px 2px rgba(16, 24, 40, .04); table-layout: fixed; }
.fm-layout .fm-list th { background: #f7fafc; color: #5f6f7e; font-size: 12px; font-weight: 600; padding: 10px 12px; border-bottom: 1px solid #eef1f5; border-top: none; }
.fm-layout .fm-list td { padding: 9px 12px; border-bottom: 1px solid #f1f4f8; font-size: 13px; border-top: none; background: transparent; }
.fm-layout .fm-list tbody tr:last-child td { border-bottom: none; }
.fm-layout .fm-list tbody tr { transition: background .12s ease; }
.fm-layout .fm-list tbody tr:hover { background: #f8fbfd; }
.fm-layout .fm-list tbody tr.selected { background: #eef6fc; }
.fm-layout .fm-list tbody tr.selected td { background: transparent; }
.fm-list th, .fm-list td { text-align: left; vertical-align: middle; }
.fm-list td .btn {
    display: inline-flex; align-items: center; vertical-align: middle;
    height: 26px; padding: 0 10px; border-radius: 5px; font-size: 12px;
    background: #fff; border: 1px solid #dfe6ec; color: #5f6f7e;
    box-shadow: none; transition: all .15s ease;
}
.fm-list td .btn:hover { background: #f2f7fb; border-color: #b7c9d9; color: #2a6496; }
.fm-list td .btn.btn-del { border-color: #ecd2d0; color: #c25650; }
.fm-list td .btn.btn-del:hover { background: #fdf1f0; border-color: #d9534f; color: #b84440; }
.fm-list td .btn + .btn { margin-left: 5px; }
.fm-list td.fm-act { white-space: nowrap; }
.fm-list .fm-mini-thumb { width: 36px; height: 36px; object-fit: cover; border-radius: 6px; background: #f4f6f9; border: 1px solid #eef1f5; }
.fm-list .fm-mini-icon { display: inline-block; min-width: 38px; text-align: center; font-size: 11px; font-weight: 600; color: #fff; background: #8aa5bd; border-radius: 6px; padding: 6px 4px; text-transform: uppercase; letter-spacing: 1px; box-sizing: border-box; }
.fm-list .fm-name-cell { overflow: hidden; }
.fm-list .fm-name-line { display: flex; align-items: center; gap: 8px; min-width: 0; }
.fm-list .fm-name-line a { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #2a6496; text-decoration: none; }
.fm-list .fm-name-line a:hover { text-decoration: underline; }
.fm-pager { display: flex; justify-content: center; align-items: center; gap: 14px; margin: 16px 0; }
.fm-pager button { border: 1px solid #d5dde5; background: linear-gradient(180deg, #ffffff, #f7fafc); border-radius: 6px; padding: 5px 14px; cursor: pointer; font-size: 13px; color: #4a5a68; transition: all .15s ease; }
.fm-pager button:hover:not(:disabled) { background: #f2f7fb; border-color: #b7c9d9; color: #2a6496; }
.fm-pager button:disabled { color: #c3cbd3; cursor: default; }
.fm-pager span { font-size: 13px; color: #7d8b99; }
.fm-loading, .fm-empty { text-align: center; color: #98a2ad; padding: 60px 0; font-size: 14px; }
.fm-modal { position: fixed; inset: 0; z-index: 20100; display: flex; align-items: center; justify-content: center; }
.fm-modal.fm-modal-sub { z-index: 20200; }
.fm-modal[hidden] { display: none; }
.fm-modal-mask { position: absolute; inset: 0; background: rgba(20, 28, 36, 0.45); }
.fm-modal-card { position: relative; background: #fff; border-radius: 8px; padding: 12px 22px 20px; width: 420px; max-width: calc(100vw - 40px); max-height: 82vh; overflow: auto; }
.fm-modal-wide { width: 880px; padding: 24px 28px; }
#fmShareModal { z-index: 20101; }
.fm-modal-card h3 { margin: 0 0 12px; font-size: 15px; text-align: center; }
.fm-modal-file { font-size: 13px; color: #5f6f7e; background: #f4f6f9; border-radius: 4px; padding: 6px 10px; word-break: break-all; margin-bottom: 12px; }
.fm-textedit-wrap { position: relative; display: flex; align-items: stretch; height: 46vh; width: 100%; box-sizing: border-box; }
.fm-textedit-gutter { flex: none; width: 3.4em; box-sizing: border-box; overflow: hidden; font-family: Consolas, Menlo, 'Courier New', monospace; font-size: 13px; line-height: 1.55; padding: 10px 7px 10px 0; text-align: right; color: #9aa7b4; background: #f4f6f9; border: 1px solid #d5dde5; border-right: none; border-radius: 6px 0 0 6px; white-space: pre; user-select: none; }
.fm-textedit-area { display: block; flex: 1; width: auto; min-width: 0; height: 100%; box-sizing: border-box; resize: none; font-family: Consolas, Menlo, 'Courier New', monospace; font-size: 13px; line-height: 1.55; padding: 10px 12px; border: 1px solid #d5dde5; border-radius: 0 6px 6px 0; color: #2b3a46; background: #fff; tab-size: 4; white-space: pre; overflow: auto; }
.fm-textedit-hl { display: block; flex: 1; width: auto; min-width: 0; height: 100%; box-sizing: border-box; margin: 0; font-family: Consolas, Menlo, 'Courier New', monospace; font-size: 13px; line-height: 1.55; border: 1px solid #d5dde5; border-radius: 0 6px 6px 0; background: #fff; tab-size: 4; overflow: auto; }
.fm-textedit-stats { font-size: 12px; color: #7d8b99; line-height: 1.7; margin: 5px 0 0; text-align: right; }
#fmTextEditModal .btn.fm-btn-on { background: #e3eef7; border-color: #6ea3c8; color: #2f6a99; }
.fm-textedit-wrap.fm-textedit-soft .fm-textedit-area,
.fm-textedit-wrap.fm-textedit-soft .fm-textedit-hl { white-space: pre-wrap; word-break: break-all; overflow-x: hidden; }
#fmTextEditModal .fm-textedit-fullbtn { position: absolute; top: 6px; right: 8px; border: none; background: none; cursor: pointer; font-size: 15px; line-height: 1; padding: 4px 7px; border-radius: 4px; color: #7a8a99; }
#fmTextEditModal .fm-textedit-fullbtn:hover { background: #eef2f6; color: #2b3a46; }
.fm-modal-card.fm-textedit-full { width: auto; max-width: none; max-height: none; position: fixed; inset: 10px; z-index: 5; display: flex; flex-direction: column; }
.fm-modal-card.fm-textedit-full .fm-textedit-wrap { flex: 1; min-height: 0; height: auto; }
.fm-modal-card.fm-textedit-full .fm-modal-btns { flex: none; }
/* 主题 CSS 的 pre code.hljs 带 overflow-x:auto，会让 <code> 自成一层滚动容器，与下面的 textarea 各出一条横条；滚动只交给 textarea（覆盖层靠 JS 同步 scrollLeft） */
/* font-family 必须 inherit：UA 对 <code> 单独声明 monospace，压过从 pre 继承的 Consolas，字宽差 2%，横向滚动后高亮与光标越滚越偏 */
.fm-textedit-hl code.hljs { display: block; overflow: visible; font-family: inherit; padding: 10px 12px; background: transparent; font-size: 13px; line-height: 1.55; }
.fm-textedit-hl.fm-textedit-overlaying { position: absolute; top: 0; bottom: 0; left: 3.4em; right: 0; width: auto; height: auto; overflow: hidden; z-index: 0; }
.fm-textedit-area.fm-textedit-glass { position: relative; z-index: 1; background: transparent; color: transparent; caret-color: #2b3a46; }
.fm-textedit-area.fm-textedit-glass::selection { background: rgba(110, 163, 200, .35); color: transparent; }
.fm-textedit-wrap.fm-textedit-dark .fm-textedit-area,
.fm-textedit-wrap.fm-textedit-dark .fm-textedit-hl { background: #282c34; border-color: #181a1f; }
.fm-textedit-wrap.fm-textedit-dark .fm-textedit-area { color: #abb2bf; }
.fm-textedit-wrap.fm-textedit-dark .fm-textedit-gutter { background: #21252b; color: #636d83; border-color: #181a1f; }
.fm-textedit-wrap.fm-textedit-dark .fm-textedit-area.fm-textedit-glass { background: transparent; color: transparent; caret-color: #fff; }
.fm-textedit-wrap.fm-textedit-dark .fm-textedit-area.fm-textedit-glass::selection { background: rgba(255, 255, 255, .2); color: transparent; }
.fm-textedit-area:focus { outline: none; border-color: #6ea3c8; }
.fm-textedit-area[disabled] { background: #f4f6f9; color: #98a6b3; }
.fm-modal-desc { font-size: 13px; color: #7d8b99; line-height: 1.7; margin-bottom: 12px; }
/* 保存旋转弹窗里的「本次是否无损」状态条：绿=无损、蓝=近乎无损、黄=有损但能存、红=无法保存 */
.fm-rot-lossless { margin: 0 0 12px; padding: 8px 10px; border-radius: 5px; border-left: 3px solid #b9c5d1; background: #f4f6f9; color: #5f6f7e; font-size: 12.5px; line-height: 1.7; word-break: break-word; }
.fm-rot-lossless b { font-weight: 700; }
.fm-rot-lossless.is-ok { border-left-color: #3fa45b; background: #eef8f0; color: #2f6b41; }
.fm-rot-lossless.is-near { border-left-color: #3f8ec9; background: #eef5fc; color: #1f5c8b; }
.fm-rot-lossless.is-warn { border-left-color: #e0a92b; background: #fdf7e7; color: #7d5d10; }
.fm-rot-lossless.is-bad { border-left-color: #d9534f; background: #fcf0f0; color: #8c2f2c; }
.fm-rot-src { margin: 0 0 10px; font-size: 12.5px; line-height: 1.7; color: #7b8794; word-break: break-word; }
.fm-rot-src b { font-weight: 600; color: #4a5a6a; }
/* 色彩配置提示单独上色：讲的不是画质，而是颜色可能变 */
.fm-rot-icc { margin: 0 0 12px; padding: 8px 10px; border-radius: 5px; border-left: 3px solid #d2a03a; background: #fdf7e7; color: #7d5d10; font-size: 12.5px; line-height: 1.7; word-break: break-word; }
.fm-rot-icc b { font-weight: 700; }
.fm-field { margin-bottom: 12px; font-size: 13px; }
.fm-field label { display: block; margin-bottom: 5px; color: #5f6f7e; }
.fm-field label.fm-flabel { font-size: 13px; font-weight: 600; }
#fmUploadDestField > label, #fmUploadFolderField > label, #fmUploadToField > label { font-weight: 700; color: #3f5265; }
#fmZipStart, #fmExtractStart { background: #f0ad4e !important; border-color: #e0a038 !important; color: #fff !important; }
#fmZipStart:hover, #fmExtractStart:hover { background: #ec971f !important; }
#fmZipStart:disabled, #fmExtractStart:disabled { opacity: .55; }
.fm-field input[type=text], .fm-field input[type=password], .fm-field select, .fm-field textarea { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d9e0e7; border-radius: 4px; font-size: 13px; }
.fm-field textarea { font-family: inherit; line-height: 1.6; resize: vertical; }
.fm-field select { height: auto; line-height: 1.5; -webkit-appearance: menulist; appearance: menulist; }
.fm-upload-drop {
    border: 2px dashed #cdd8e2; border-radius: 8px; padding: 26px 16px;
    text-align: center; color: #7d8b99; margin-bottom: 4px;
    transition: border-color .15s, background .15s;
}
.fm-upload-drop.dragover { border-color: #2f6f9f; background: #f2f7fb; }
.fm-field label input[type=radio] { margin-right: 6px; }
.fm-modal-btns { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
.fm-share-result { margin: 0 0 14px; }
/* 创建成功：收起整张表单，只留链接与关闭 —— 结果区回到标题下方，弹窗高度骤降，不用再滚动 */
.fm-modal-card.fm-share-done .fm-field { display: none; }
.fm-modal-card.fm-share-done .fm-modal-btns { margin-top: 4px; }
.fm-share-result label { display: block; font-size: 12px; color: #5f6f7e; margin-bottom: 5px; }
.fm-share-result input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #b9d2e5; background: #f4f9fd; border-radius: 4px; font-size: 13px; margin-bottom: 8px; }
.fm-classify-preview { margin: 12px 0; border: 1px solid #eef1f5; border-radius: 4px; font-size: 13px; }
.fm-classify-preview table { width: 100%; border-collapse: collapse; }
.fm-classify-preview td, .fm-classify-preview th { padding: 7px 12px; border-bottom: 1px solid #eef1f5; text-align: left; }
.fm-classify-preview th { color: #98a2ad; font-weight: normal; }
.fm-classify-preview .fm-new { color: #e07b32; }
.fm-classify-preview .fm-reuse { color: #4c9a6d; }
.fm-classify-preview tr.fm-zero td { color: #b3bcc5; }
.fm-tag { display: inline-block; margin-left: 6px; padding: 1px 7px; font-size: 11px; border-radius: 4px; background: #e8f1f8; color: #2a6496; vertical-align: 1px; }
.fm-share-item { padding: 12px 14px; border: 1px solid #e3e9ef; border-radius: 8px; margin-bottom: 10px; background: #fff; }
.fm-share-item:hover { border-color: #c8d6e2; background: #f9fbfd; }
.fm-share-line1 { display: flex; align-items: center; gap: 10px; }
.fm-share-name { font-size: 13px; font-weight: 500; color: #333; word-break: break-all; }
.fm-share-sub { margin-top: 5px; font-size: 12px; color: #8a949e; word-break: break-all; line-height: 1.6; }
.fm-share-line2 { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 16px; margin-top: 9px; }
.fm-share-meta { font-size: 12px; color: #5f6f7e; white-space: nowrap; }
.fm-share-acts { margin-left: auto; display: flex; gap: 6px; flex-wrap: wrap; }
.fm-log-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.fm-log-table th, .fm-log-table td { padding: 7px 8px; border-bottom: 1px solid #eef1f5; text-align: left; word-break: break-all; }
.fm-log-table th { color: #8a94a3; font-weight: 500; background: #f7f9fb; white-space: nowrap; }
.fm-log-ua { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #889; }
.fm-log-badge { display: inline-block; padding: 1px 9px; border-radius: 999px; font-size: 12px; white-space: nowrap; }
.fm-log-view { background: #eef4f8; color: #467b96; }
.fm-log-clicked { background: #fdf3e7; color: #c77c11; }
.fm-log-download { background: #eaf6ee; color: #2e8b57; }
.fm-status { display: inline-block; padding: 2px 10px; font-size: 12px; border-radius: 10px; }
.fm-status-active { background: #e8f6ec; color: #2e7d43; }
.fm-status-expired { background: #f0f1f3; color: #8a949e; }
.fm-status-exhausted { background: #fdf0e7; color: #c96a26; }
.fm-toast { position: fixed; bottom: 34px; left: 50%; transform: translateX(-50%); background: #37424d; color: #fff; padding: 9px 20px; border-radius: 6px; font-size: 13px; z-index: 20200; max-width: 80vw; }
.fm-toast .fm-toast-act { margin-left: 10px; border: 1px solid rgba(255, 255, 255, .45); background: rgba(255, 255, 255, .12); color: #fff; border-radius: 5px; padding: 3px 12px; font-size: 12px; cursor: pointer; transition: background .15s; }
.fm-toast .fm-toast-act:hover { background: rgba(255, 255, 255, .28); }
@media (max-width: 920px) { .fm-layout { flex-direction: column; } .fm-side { width: 100%; } }
</style>

<link rel="stylesheet" id="fmHlThemeCss" href="<?php echo htmlspecialchars(Helper::options()->pluginUrl, ENT_QUOTES, 'UTF-8'); ?>/FileManager/assets/highlight-theme.min.css?v=<?php echo FileManager_Plugin::VERSION; ?>">
<script src="<?php echo htmlspecialchars(Helper::options()->pluginUrl, ENT_QUOTES, 'UTF-8'); ?>/FileManager/assets/highlight.min.js?v=<?php echo FileManager_Plugin::VERSION; ?>"></script>
<script src="<?php echo htmlspecialchars(Helper::options()->pluginUrl, ENT_QUOTES, 'UTF-8'); ?>/FileManager/assets/qr.min.js?v=<?php echo FileManager_Plugin::VERSION; ?>"></script>

<script>
(function () {
    var cfg = <?php echo json_encode(array(
        'url'         => $fmAjaxUrl,
        'adminUrl'    => $options->adminUrl,
        'pluginUrl'   => $options->pluginUrl,
        'version'     => FileManager_Plugin::VERSION,
        'defaultMax'  => $fmSettings['defaultMaxDownloads'],
        'canZip'      => $fmCanZip,
        'canExtract'  => $fmCanExtract,
    )); ?>;

    var state = {
        fid: 0, type: '', keywords: '', sort: 'time', dir: 'desc',
        page: 1, pages: 1, total: 0,
        folders: [], totalFiles: 0, uncategorized: 0,
        selected: {}, selectedCount: 0,
        batchMode: false,
        view: 'grid', /* 首屏布局由 initChain 按「浏览器覆盖 + 默认布局」决定，见 initialView() */
        gridSize: '5x6', /* 同上，见 initialGridSize() */
        expanded: {}
    };
    var prefs = { gridDefault: '5x6', sortDefault: 'time-desc', uploadMode: 'current', uploadFid: 0, pasteMode: 'uncat', pasteFid: 0, startMode: 'all', startFid: 0, shortcut: '1', hoverPreview: '1', thumbCache: '1', hlTheme: 'github', imageHost: null, defaultUploadDest: 'site', uploadConv: '', uploadRenameDt: '0' };
    /* 插件级设置（回收站、编辑器视频 HTML），与插件设置页同源，由偏好设置一并读写 */
    var globalSet = { recycleEnabled: 1, recycleDays: 30, videoHtml: 0 };
    var thumbQueue = [], thumbBusy = false, thumbSeen = {}; /* 缩略图后台预热队列 */
    var HL_THEMES = {
        'none':            { label: '不启用高亮代码',      dark: false },
        'github':          { label: 'GitHub 浅色（默认）', dark: false },
        'atom-one-light':  { label: 'One Light 浅色',      dark: false },
        'atom-one-dark':   { label: 'One Dark 深色',       dark: true },
        'github-dark':     { label: 'GitHub Dark 深色',    dark: true },
        'vs2015':          { label: 'VS2015 深色',         dark: true },
        'monokai':         { label: 'Monokai 深色',        dark: true },
        'nord':            { label: 'Nord 深色',           dark: true }
    };
    function applyHlTheme() {
        var t = HL_THEMES[prefs.hlTheme] ? prefs.hlTheme : 'github';
        var sel = document.getElementById('fmTextEditTheme');
        if (sel && sel.value !== t) { sel.value = t; }
        var link = document.getElementById('fmHlThemeCss');
        var wrap = document.querySelector('#fmTextEditCard .fm-textedit-wrap');
        if (t === 'none') {
            if (link) { link.onerror = null; link.removeAttribute('href'); }
            if (wrap) { wrap.classList.remove('fm-textedit-dark'); }
            return;
        }
        if (link) {
            link.onerror = function () {
                link.onerror = null;
                link.href = cfg.pluginUrl + '/FileManager/assets/highlight-theme.min.css?v=' + cfg.version;
                var wrap2 = document.querySelector('#fmTextEditCard .fm-textedit-wrap');
                if (wrap2) { wrap2.classList.remove('fm-textedit-dark'); }
                toast('高亮主题「' + (HL_THEMES[t] ? HL_THEMES[t].label : t) + '」文件缺失，已回退默认主题');
            };
            link.href = cfg.pluginUrl + '/FileManager/assets/hljs-themes/' + t + '.min.css?v=' + cfg.version;
        }
        if (wrap) { wrap.classList.toggle('fm-textedit-dark', !!HL_THEMES[t].dark); }
    }
    var latestUploadFid = 0; /* 最近上传文件所在的文件夹（缓存，列表/上传完成后刷新） */

    function uploadModeOf(mode, fid) {
        if (['uncat', 'latest', 'custom'].indexOf(mode) > -1) { return mode; }
        return fid === -1 ? 'latest' : (fid > 0 ? 'custom' : 'uncat');
    }

    /* 布局的「浏览器覆盖」与「默认布局」：工具栏切换只算当前浏览器的覆盖，写入时记下
       当时的 prefs.gridDefault 作为 base；base 与现在的默认布局不一致，说明默认布局在别处
       改过，这条覆盖已过期、必须让位，否则用户改了默认布局也永远看不到效果。 */
    function lsGetJson(key) {
        try {
            var raw = localStorage.getItem(key);
            if (!raw) { return null; }
            var o = JSON.parse(raw);
            return (o && typeof o === 'object') ? o : null;
        } catch (e) { return null; }
    }
    function setLayoutOverride(key, v) {
        try { localStorage.setItem(key, JSON.stringify({ v: v, base: prefs.gridDefault })); } catch (e) {}
    }
    function lsDel(key) {
        try { localStorage.removeItem(key); } catch (e) {}
    }
    function layoutOverride(key, allowed) {
        var o = lsGetJson(key);
        if (!o || o.base !== prefs.gridDefault) { return null; } /* 旧格式字符串或过期覆盖一律作废 */
        return allowed.indexOf(o.v) > -1 ? o.v : null;
    }
    function initialGridSize() {
        return layoutOverride('fmGridSize', ['4x7', '5x6'])
            || (prefs.gridDefault === '4x7' ? '4x7' : '5x6');
    }
    function initialView() {
        var v = layoutOverride('fmView', ['grid', 'masonry', 'list']);
        if (v) { return v; }
        return (prefs.gridDefault === 'masonry' || prefs.gridDefault === 'list') ? prefs.gridDefault : 'grid';
    }

    function loadPrefs() {
        return api('prefsGet').then(function (data) {
            if (data && data.prefs) {
                prefs.gridDefault = ['5x6', '4x7', 'masonry', 'list'].indexOf(data.prefs.gridDefault) > -1
                    ? data.prefs.gridDefault : '5x6';
                prefs.sortDefault = ['time-desc', 'time-asc', 'name-desc', 'name-asc', 'size-desc', 'size-asc']
                    .indexOf(data.prefs.sortDefault) > -1 ? data.prefs.sortDefault : 'time-desc';
                prefs.uploadFid = parseInt(data.prefs.uploadFid, 10) || 0;
                prefs.uploadMode = uploadModeOf(data.prefs.uploadMode, prefs.uploadFid);
                prefs.pasteMode = ['uncat', 'current', 'latest', 'custom'].indexOf(data.prefs.pasteMode) > -1
                    ? data.prefs.pasteMode : 'uncat';
                prefs.pasteFid = parseInt(data.prefs.pasteFid, 10) || 0;
                prefs.startMode = String(data.prefs.startMode || 'all');
                prefs.startFid = parseInt(data.prefs.startFid, 10) || 0;
                prefs.shortcut = (data.prefs.shortcut === '0') ? '0' : '1';
                prefs.hoverPreview = (data.prefs.hoverPreview === '0') ? '0' : '1';
                prefs.thumbCache = (data.prefs.thumbCache === '0') ? '0' : '1';
                prefs.imageHost = (data.prefs.imageHost && typeof data.prefs.imageHost === 'object') ? data.prefs.imageHost : null;
                prefs.defaultUploadDest = (data.prefs.defaultUploadDest === 'imagehost') ? 'imagehost' : 'site';
                prefs.uploadConv = ['', 'webp', 'jpg', 'png'].indexOf(data.prefs.uploadConv) > -1 ? data.prefs.uploadConv : '';
                prefs.uploadRenameDt = (data.prefs.uploadRenameDt === '1') ? '1' : '0';
                prefs.hlTheme = HL_THEMES[data.prefs.hlTheme] ? data.prefs.hlTheme : 'github';
                applyHlTheme();
                applyDefaultSort(); /* 默认排序应用到列表状态 */
            }
            if (data && data.global) {
                globalSet.recycleEnabled = (parseInt(data.global.recycleEnabled, 10) === 0) ? 0 : 1;
                globalSet.recycleDays = Math.max(1, parseInt(data.global.recycleDays, 10) || 30);
                globalSet.videoHtml = (parseInt(data.global.videoHtml, 10) === 1) ? 1 : 0;
            }
        }).catch(function () { /* 读取失败按默认值走 */ });
    }

    function fetchLatestFid() {
        return api('list', { fid: 0, page: 1, pageSize: 1, sort: 'time', dir: 'desc' })
            .then(function (data) {
                latestUploadFid = (data.files && data.files.length) ? (data.files[0].fid || 0) : 0;
                return latestUploadFid;
            }).catch(function () { latestUploadFid = 0; return 0; });
    }

    function effectiveUploadFid() {
        if (prefs.uploadMode === 'latest') { return latestUploadFid > 0 ? latestUploadFid : 0; }
        if (prefs.uploadMode === 'custom') { return prefs.uploadFid > 0 ? prefs.uploadFid : 0; }
        return 0;
    }

    function effectivePasteFid() {
        if (prefs.pasteMode === 'current') { return state.fid > 0 ? state.fid : 0; }
        if (prefs.pasteMode === 'latest') { return latestUploadFid > 0 ? latestUploadFid : 0; }
        if (prefs.pasteMode === 'custom') { return prefs.pasteFid > 0 ? prefs.pasteFid : 0; }
        return 0; /* uncat */
    }
    var dragCids = []; /* 拖拽移动：正在拖动的文件 cid 列表 */
    var dragFid = 0; /* 拖拽移动：正在拖动的文件夹 fid（>0 表示文件夹拖拽中） */
    var dragStartX = 0; /* 文件夹拖拽起点 X（判断往左拖手势） */
    var dragStartY = 0; /* 文件夹拖拽起点 Y（排除上下搬动时的左偏误判） */
    var dragToLeft = false; /* 往左拖 ≥5px 激活的一级文件夹模式 */
    var fileMap = {}; /* cid -> 文件对象（灯箱用） */
    var LIGHTBOX_VIDEO_EXTS = { mp4: 1, webm: 1, m4v: 1, mov: 1, ogv: 1, ogg: 1 };

    function $(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fmtSize(bytes) {
        var units = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0, size = Number(bytes) || 0;
        while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
        return (i > 1 ? size.toFixed(2) : size.toFixed(1)) + ' ' + units[i];
    }
    function fmtDate(ts) {
        if (!ts) { return '-'; }
        var d = new Date(ts * 1000);
        function p(n) { return n < 10 ? '0' + n : n; }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }
    function lbCapText(cid) {
        var f = fileMap[cid];
        if (!f) { return ''; }
        var dim = lbDimCache[cid] ? ' · ' + lbDimCache[cid] : '';
        return f.name + '（' + fmtSize(f.size) + dim + ' · ' + fmtDate(f.created) + '）';
    }
    function lbTrackDims(img, cid) {
        if (!img) { return; }
        img.addEventListener('load', function () {
            if (lbCid !== cid || !img.naturalWidth) { return; }
            lbDimCache[cid] = img.naturalWidth + '×' + img.naturalHeight;
            var cap = lbEl ? lbEl.querySelector('#fmLbCaption') : null;
            if (cap && fileMap[cid]) { cap.textContent = lbCapText(cid); }
        }, { once: true });
    }
    var toastTimer = null;
    function toast(msg, ms) {
        var t = $('fmToast');
        t.textContent = msg;
        t.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.hidden = true; }, ms || 2600);
    }

    function toastActions(msg, actions, timeout) {
        var t = $('fmToast');
        t.textContent = msg;
        Array.prototype.forEach.call(t.querySelectorAll('.fm-toast-act'), function (b) { b.remove(); });
        (actions || []).forEach(function (a) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fm-toast-act';
            btn.textContent = a.label;
            btn.addEventListener('click', function () { a.onClick(); t.hidden = true; });
            t.appendChild(btn);
        });
        t.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.hidden = true; }, timeout || 8000);
    }
    function openModal(id) { $(id).hidden = false; }
    function closeModal(id) {
        if (id === 'fmTextEditModal') { $('fmTextEditCard').classList.remove('fm-textedit-full'); }
        $(id).hidden = true;
    }

    function isExtractableFile(f) {
        if (!cfg.canExtract) { return false; }
        var ext = String(f.ext || '').toLowerCase();
        var name = String(f.name || '').toLowerCase();
        if (ext === 'zip' || /\.zip$/.test(name)) { return true; }
        if (ext === 'tar' || /\.tar$/.test(name)) { return true; }
        if ((ext === 'gz' || /\.gz$/.test(name)) && (/\.tar\.gz$/.test(name) || /\.tgz$/.test(name))) { return true; }
        return false;
    }

    function api(action, params, opts) {
        opts = opts || {};
        var body;
        if (params instanceof FormData) {
            body = params; /* 文件上传等 multipart 场景 */
            body.append('do', action); /* 动作名两种载体都要带，否则分发报未知操作 */
        } else {
            body = new URLSearchParams();
            body.append('do', action);
            if (params) {
                Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
            }
        }
        var ctrl = opts.timeout ? new AbortController() : null;
        var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, opts.timeout) : 0;
        return fetch(cfg.url, { method: 'POST', body: body, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
            .then(function (res) {
                if (timer) { clearTimeout(timer); }
                return res.text().then(function (text) {
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) {}

                    if (!data || typeof data.code === 'undefined') {
                        console.error('[FileManager] 接口返回异常 action=' + action,
                            'HTTP ' + res.status, text);
                        var snippet = text.replace(/<[^>]*>/g, ' ')
                            .replace(/\s+/g, ' ').trim().slice(0, 200);
                        /* 响应体为空且状态码异常：基本是请求被掐断（超时 / 内存 / WAF / CDN） */
                        if (!snippet && (res.status >= 500 || res.status === 0)) {
                            snippet = '服务端没有返回任何内容，通常是处理超时、内存不足，或被服务器/WAF 中断了连接';
                        }
                        toast('接口异常 HTTP ' + res.status + (snippet ? '：' + snippet : ''), 8000);
                        throw new Error('接口异常 HTTP ' + res.status);
                    }

                    console.log('[FileManager] v' + data.ver, 'action=' + action, 'code=' + data.code);
                    if (data.code !== 200) {
                        var emsg = data.message || '操作失败';
                        /* 错误说明通常更长，多留阅读时间 */
                        toast(emsg, emsg.length > 24 ? 6000 : 0);
                        throw new Error(emsg);
                    }
                    return data;
                });
            });
    }

    function folderChildren(fid) {
        return state.folders.filter(function (f) { return f.parent === fid; });
    }

    function renderTree() {
        var html = '';
        html += '<li><div class="fm-node' + (state.fid === 0 ? ' current' : '') + '" data-fid="0">'
            + '<span class="fm-fname">所有文件</span>'
            + '<span class="fm-badge">' + state.totalFiles + '</span></div></li>';
        html += '<li><div class="fm-node' + (state.fid === -1 ? ' current' : '') + '" data-fid="-1">'
            + '<span class="fm-fname">未分类</span>'
            + '<span class="fm-badge">' + state.uncategorized + '</span></div></li>';

        html += renderFolderLevel(0);

        var tree = $('fmTree');
        tree.innerHTML = html;

        Array.prototype.forEach.call(tree.querySelectorAll('.fm-node'), function (node) {
            node.addEventListener('click', function (e) {
                if (e.target.className === 'fm-op') { return; }
                selectFolder(parseInt(node.getAttribute('data-fid'), 10));
            });
        });
        Array.prototype.forEach.call(tree.querySelectorAll('.fm-op'), function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var op = btn.getAttribute('data-op');
                var fid = parseInt(btn.getAttribute('data-fid'), 10);
                if (op === 'add') { createFolder(fid); }
                if (op === 'rename') { renameFolder(fid); }
                if (op === 'del') { deleteFolder(fid); }
                if (op === 'zipdl') { downloadFolder(fid); }
            });
        });
        Array.prototype.forEach.call(tree.querySelectorAll('.fm-arrow'), function (arrow) {
            arrow.addEventListener('click', function (e) {
                e.stopPropagation();
                var fid = parseInt(arrow.getAttribute('data-fid'), 10);
                state.expanded[fid] = !state.expanded[fid];
                renderTree();
            });
        });
        Array.prototype.forEach.call(tree.querySelectorAll('.fm-node[data-fid]'), function (node) {
            var fid = parseInt(node.getAttribute('data-fid'), 10);
            if (fid <= 0) { return; }
            node.setAttribute('draggable', 'true');
            node.addEventListener('dragstart', function (e) {
                dragFid = fid;
                dragStartX = e.clientX;
                dragStartY = e.clientY;
                dragToLeft = false;
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', 'fm-folder:' + fid);
                }
                var tip = $('fmDragTip');
                if (tip) {
                    tip.classList.remove('armed');
                    tip.innerHTML = '拖入文件夹 = 移入<br>往左拖松手 = 移为一级';
                    tip.classList.add('show');
                }
            });
            node.addEventListener('dragend', function () {
                dragFid = 0;
                dragToLeft = false;
                clearDropHints();
                var tip = $('fmDragTip');
                if (tip) { tip.classList.remove('show'); }
                var side = document.querySelector('.fm-side');
                if (side) { side.classList.remove('fm-root-hint'); }
            });
        });
        Array.prototype.forEach.call(tree.querySelectorAll('.fm-node'), function (node) {
            node.addEventListener('dragover', function (e) {
                var targetFid = parseInt(node.getAttribute('data-fid'), 10);
                var ok = false;
                if (dragCids.length) {
                    ok = targetFid !== 0; /* 「所有文件」不是文件的移动目标 */
                } else if (dragFid > 0) {
                    ok = !dragToLeft && targetFid > 0 && targetFid !== dragFid && !isDescendantFolder(dragFid, targetFid);
                }
                if (!ok) { return; }
                e.preventDefault();
                if (e.dataTransfer) { e.dataTransfer.dropEffect = 'move'; }
                node.classList.add('fm-drop-hint');
            });
            node.addEventListener('dragleave', function (e) {
                if (!node.contains(e.relatedTarget)) { node.classList.remove('fm-drop-hint'); }
            });
            node.addEventListener('drop', function (e) {
                e.preventDefault();
                node.classList.remove('fm-drop-hint');
                var targetFid = parseInt(node.getAttribute('data-fid'), 10);
                if (dragFid > 0) {
                    var moving = dragFid;
                    dragFid = 0;
                    if (dragToLeft) { moveFolder(moving, 0); return; } /* 往左拖手势：任意位置松手都移为一级 */
                    if (targetFid <= 0 || targetFid === moving || isDescendantFolder(moving, targetFid)) { return; }
                    moveFolder(moving, targetFid);
                    return;
                }
                var cids = dragCids.slice();
                if (!cids.length && e.dataTransfer) {
                    var raw = String(e.dataTransfer.getData('text/plain') || '').split(',');
                    cids = raw.map(function (v) { return parseInt(v, 10); })
                        .filter(function (v) { return v > 0; });
                }
                dragCids = [];
                if (!cids.length || targetFid === 0) { return; } /* 「所有文件」不是移动目标 */
                moveFiles(cids, targetFid === -1 ? 0 : targetFid);
            });
        });
    }

    function isDescendantFolder(ancestorFid, targetFid) {
        var map = {};
        state.folders.forEach(function (f) { map[f.fid] = f.parent; });
        var cur = targetFid, guard = 0;
        while (cur > 0 && guard++ < 64) {
            if (cur === ancestorFid) { return true; }
            cur = map[cur] || 0;
        }
        return false;
    }

    function clearDropHints() {
        Array.prototype.forEach.call(document.querySelectorAll('.fm-drop-hint'), function (n) {
            n.classList.remove('fm-drop-hint');
        });
    }

    function moveFiles(cids, fid) {
        var name = '未分类';
        if (fid > 0) {
            state.folders.forEach(function (f) { if (f.fid === fid) { name = f.name; } });
        }
        api('move', { cids: cids.join(','), fid: fid }).then(function (data) {
            cids.forEach(function (c) { delete state.selected[c]; });
            updateBatchBar();
            toast(data.message || ('已移动 ' + cids.length + ' 个文件到「' + name + '」'));
            loadFolders();
            loadFiles();
        });
    }

    function moveFolder(fid, parentFid) {
        var name = '', target = '根目录';
        state.folders.forEach(function (f) {
            if (f.fid === fid) { name = f.name; }
            if (f.fid === parentFid) { target = f.name; }
        });
        api('folderMove', { fid: fid, parent: parentFid }).then(function (data) {
            toast(data.message || ('已将「' + name + '」移动到「' + target + '」'));
            state.expanded[parentFid] = true;
            loadFolders();
        });
    }

    function downloadFolder(fid) {
        if (!cfg.canZip) { toast('服务器未启用 php_zip 扩展，无法打包下载'); return; }
        var name = '';
        state.folders.forEach(function (f) { if (f.fid === fid) { name = f.name; } });
        api('folderZip', { fid: fid, confirm: 0 }).then(function (d) {
            if (!d.count) { toast('文件夹内没有可下载的文件（物理文件均缺失或为空）'); return; }
            var tip = '将打包下载文件夹「' + (name || ('#' + fid)) + '」及其子文件夹：\n\n'
                + '· ' + d.count + ' 个文件，合计 ' + fmtSize(d.totalSize)
                + (d.folders > 1 ? '（含 ' + d.folders + ' 个文件夹）' : '') + '\n';
            if (d.missing && d.missing.length) {
                tip += '· ' + d.missing.length + ' 个文件物理缺失，将自动跳过\n';
            }
            if (d.warnBig) {
                tip += '· 体积较大，打包可能耗时较长\n';
            }
            tip += '\n压缩包仅用于本次下载，不会出现在文件列表中。确定继续吗？';
            if (!window.confirm(tip)) { return; }
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = cfg.url;
            form.style.display = 'none';
            [['do', 'folderZip'], ['fid', String(fid)], ['confirm', '1']].forEach(function (kv) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = kv[0];
                input.value = kv[1];
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
            setTimeout(function () { document.body.removeChild(form); }, 60000);
        });
    }

    function renderFolderLevel(parentFid) {
        var html = '';
        var folders = folderChildren(parentFid);
        if (!folders.length && parentFid !== 0) { return ''; }

        if (folders.length) {
            html += '<ul>';
            folders.forEach(function (f) {
                var hasChildren = folderChildren(f.fid).length > 0;
                var expanded = !!state.expanded[f.fid];
                html += '<li><div class="fm-node' + (state.fid === f.fid ? ' current' : '') + '" data-fid="' + f.fid + '">'
                    + '<span class="fm-arrow" data-fid="' + f.fid + '">' + (hasChildren ? (expanded ? '▾' : '▸') : '') + '</span>'
                    + '<span class="fm-ficon">' + folderIconSvg(16, expanded || state.fid === f.fid) + '</span>'
                    + '<span class="fm-fname" title="' + esc(f.name) + '">' + esc(f.name) + '</span>'
                    + '<span class="fm-ops">'
                    + '<button class="fm-op" data-op="zipdl" data-fid="' + f.fid + '" title="下载文件夹(zip)">⬇</button>'
                    + '<button class="fm-op" data-op="add" data-fid="' + f.fid + '" title="新建子文件夹">+</button>'
                    + '<button class="fm-op" data-op="rename" data-fid="' + f.fid + '" title="重命名">✎</button>'
                    + '<button class="fm-op" data-op="del" data-fid="' + f.fid + '" title="删除">✕</button>'
                    + '</span>'
                    + '<span class="fm-badge">' + f.count + '</span></div></li>';
                if (hasChildren && expanded) {
                    html += renderFolderLevel(f.fid);
                }
            });
            html += '</ul>';
        }
        return html;
    }

    function expandAncestors(fid) {
        if (!(fid > 0)) { return; }
        var map = {};
        state.folders.forEach(function (f) { map[f.fid] = f.parent; });
        var p = map[fid];
        while (p > 0) {
            state.expanded[p] = true;
            p = map[p];
        }
    }

    function selectFolder(fid) {
        state.fid = fid;
        state.page = 1;
        if (window.localStorage) { localStorage.setItem('fmLastFid', String(fid)); }
        expandAncestors(fid);
        renderTree();
        loadFiles();
    }

    function loadFolders() {
        return api('folders').then(function (data) {
            state.folders = data.folders;
            state.totalFiles = data.total;
            state.uncategorized = data.uncategorized;
            renderTree();
            renderMoveTarget();
        });
    }

    function createFolder(parentFid) {
        var name = window.prompt('请输入文件夹名称：');
        if (name === null) { return; }
        name = name.replace(/^\s+|\s+$/g, '');
        if (!name) { toast('名称不能为空'); return; }
        api('folderCreate', { name: name, parent: parentFid > 0 ? parentFid : 0 }).then(function (data) {
            toast('文件夹已创建');
            state.expanded[parentFid] = true;
            loadFolders();
        });
    }

    function renameFolder(fid) {
        var folder = null;
        state.folders.forEach(function (f) { if (f.fid === fid) { folder = f; } });
        var name = window.prompt('请输入新的文件夹名称：', folder ? folder.name : '');
        if (name === null) { return; }
        name = name.replace(/^\s+|\s+$/g, '');
        if (!name) { toast('名称不能为空'); return; }
        api('folderRename', { fid: fid, name: name }).then(function () {
            toast('已重命名');
            loadFolders();
        });
    }

    function deleteFolder(fid) {
        var folder = null;
        state.folders.forEach(function (f) { if (f.fid === fid) { folder = f; } });
        if (!folder) { return; }
        if (!window.confirm('确定删除文件夹「' + folder.name + '」吗？\n其中的文件（含子文件夹中的文件）将移动到「未分类」。')) { return; }
        api('folderDelete', { fid: fid }).then(function (data) {
            if (state.fid === fid) { state.fid = 0; }
            toast(data.message);
            loadFolders().then(loadFiles);
        });
    }

    function currentFolderName(fid) {
        if (fid === -1 || fid === 0) { return ''; }
        var name = '';
        state.folders.forEach(function (f) { if (f.fid === fid) { name = f.name; } });
        return name;
    }

    function loadFiles() {
        thumbSeen = {}; /* 重新拉列表时允许再次预热（旋转/替换后缓存需重建） */
        thumbQueue = []; /* 丢掉上一页还没轮到的预热任务 */
        $('fmLoading').hidden = false;
        $('fmEmpty').hidden = true;
        api('list', {
            fid: state.fid, type: state.type, keywords: state.keywords,
            sort: state.sort, dir: state.dir, page: state.page,
            pageSize: state.gridSize === '4x7' ? 28 : 30
        }).then(function (data) {
            $('fmLoading').hidden = true;
            state.total = data.total;
            state.pages = data.pages;
            var subs = currentSubFolders();
            if (!data.files.length && !subs.length) { $('fmEmpty').hidden = false; }
            if (state.view === 'grid') { renderGrid(data.files, subs); }
            else if (state.view === 'masonry') { renderMasonry(data.files, subs); }
            else { renderList(data.files, subs); }
            renderPager();
        }).catch(function () { $('fmLoading').hidden = true; });
    }

    function currentSubFolders() {
        if (!(state.fid > 0) || state.page !== 1 || state.keywords || state.type) { return []; }
        return folderChildren(state.fid);
    }

    function folderIconSvg(size, open) {
        if (open) {
            return '<svg viewBox="0 0 26 24" width="' + size + '" height="' + size + '" aria-hidden="true">'
                + '<path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z" fill="#e2a93b"/>'
                + '<path d="M2.4 19.9h17.8c.45 0 .85-.28 1-.7l5.1-6.1c.2-.55-.2-1.13-.79-1.13H7.4c-.45 0-.85.28-1 .7l-2.8 6.1c-.2.55.2 1.13.79 1.13z" fill="#f6c94f" stroke="#fff" stroke-width="0.7"/>'
                + '</svg>';
        }
        return '<svg viewBox="0 0 24 24" width="' + size + '" height="' + size + '" fill="#e2a93b" aria-hidden="true"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';
    }

    function bindFolderEntries(container) {
        Array.prototype.forEach.call(container.querySelectorAll('[data-fid]'), function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                selectFolder(parseInt(el.getAttribute('data-fid'), 10));
            });
        });
    }

    function ihBadge(f) {
        return (f && f.isIh) ? '<span class="fm-ih-badge">' + (f.ihSt === 's3' ? '存储' : '图床') + '</span>' : '';
    }

    function renderGrid(files, subs) {
        lbList = files.filter(isLbViewable); /* 灯箱左右切换序列（按当前页显示顺序） */
        $('fmListWrap').hidden = true;
        var grid = $('fmGrid');
        grid.hidden = false;
        grid.classList.toggle('cols-4', state.gridSize === '4x7'); /* 4 列×7 行布局 */
        var html = '';
        (subs || []).forEach(function (sf) {
            html += '<div class="fm-card fm-folder-card" data-fid="' + sf.fid + '" title="打开文件夹">'
                + '<div class="fm-thumb">' + folderIconSvg(72) + '</div>'
                + '<div class="fm-name" title="' + esc(sf.name) + '">' + esc(sf.name) + '</div>'
                + '<div class="fm-meta">' + (sf.count || 0) + ' 个文件</div>'
                + '</div>';
        });
        files.forEach(function (f) {
            fileMap[f.cid] = f;
            var thumb = f.isImage && f.url
                ? '<img loading="lazy" src="' + esc(thumbUrl(f)) + '"' + warmAttr(f) + ' alt="">'
                : '<span class="fm-exticon">' + esc(f.ext || '?') + '</span>';
            var selected = !!state.selected[f.cid];
            html += '<div class="fm-card' + (selected ? ' selected' : '') + '" data-cid="' + f.cid + '">'
                + '<label class="fm-check"><input type="checkbox"' + (selected ? ' checked' : '') + '></label>'
                + '<a class="fm-thumb" href="' + esc(f.url) + '" target="_blank" rel="noopener">' + thumb + ihBadge(f) + '</a>'
                + '<div class="fm-name" title="' + esc(f.name) + '">' + esc(f.name) + '</div>'
                + '<div class="fm-meta">' + esc(fmtSize(f.size)) + ' · ' + esc(fmtDate(f.created)) + '</div>'
                + '<div class="fm-actions">'
                + '<button type="button" data-act="share">分享</button>'
                + (isExtractableFile(f) ? '<button type="button" data-act="extract">解压</button>' : '')
                + '<a href="' + esc(f.url) + '" download="' + esc(f.name) + '" rel="noopener">下载</a>'
                + '<button type="button" data-act="edit">编辑</button>'
                + '<button type="button" data-act="del">删除</button>'
                + '</div></div>';
        });
        grid.innerHTML = html;
        bindCardEvents(grid);
        bindFolderEntries(grid);
    }

    function renderMasonry(files, subs) {
        lbList = files.filter(isLbViewable); /* 灯箱左右切换序列（按当前页显示顺序） */
        $('fmListWrap').hidden = true;
        var box = $('fmMasonry');
        box.hidden = false;
        var html = '';
        (subs || []).forEach(function (sf) {
            html += '<div class="fm-card fm-folder-card" data-fid="' + sf.fid + '" title="打开文件夹">'
                + '<div class="fm-mthumb">' + folderIconSvg(56) + '</div>'
                + '<div class="fm-name" title="' + esc(sf.name) + '">' + esc(sf.name) + '</div>'
                + '<div class="fm-meta">' + (sf.count || 0) + ' 个文件</div>'
                + '</div>';
        });
        files.forEach(function (f) {
            fileMap[f.cid] = f;
            var thumb = f.isImage && f.url
                ? '<a class="fm-mthumb" href="' + esc(f.url) + '" target="_blank" rel="noopener"><img loading="lazy" src="' + esc(thumbUrl(f)) + '"' + warmAttr(f) + ' alt="">' + ihBadge(f) + '</a>'
                : '<a class="fm-thumb" href="' + esc(f.url) + '" target="_blank" rel="noopener"><span class="fm-exticon">' + esc(f.ext || '?') + '</span></a>';
            var selected = !!state.selected[f.cid];
            html += '<div class="fm-card' + (selected ? ' selected' : '') + '" data-cid="' + f.cid + '">'
                + '<label class="fm-check"><input type="checkbox"' + (selected ? ' checked' : '') + '></label>'
                + thumb
                + '<div class="fm-name" title="' + esc(f.name) + '">' + esc(f.name) + '</div>'
                + '<div class="fm-meta">' + esc(fmtSize(f.size)) + ' · ' + esc(fmtDate(f.created)) + '</div>'
                + '<div class="fm-actions">'
                + '<button type="button" data-act="share">分享</button>'
                + (isExtractableFile(f) ? '<button type="button" data-act="extract">解压</button>' : '')
                + '<a href="' + esc(f.url) + '" download="' + esc(f.name) + '" rel="noopener">下载</a>'
                + '<button type="button" data-act="edit">编辑</button>'
                + '<button type="button" data-act="del">删除</button>'
                + '</div></div>';
        });
        box.innerHTML = html;
        bindCardEvents(box);
        bindFolderEntries(box);
    }

    function renderList(files, subs) {
        lbList = files.filter(isLbViewable); /* 灯箱左右切换序列（按当前页显示顺序） */
        $('fmGrid').hidden = true;
        var body = $('fmListBody');
        $('fmListWrap').hidden = false;
        var rows = '';
        (subs || []).forEach(function (sf) {
            rows += '<tr class="fm-folder-row" data-fid="' + sf.fid + '">'
                + '<td></td>'
                + '<td class="fm-name-cell" title="打开文件夹"><div class="fm-name-line">' + folderIconSvg(20) + ' <a href="javascript:void(0)">' + esc(sf.name) + '</a></div></td>'
                + '<td>' + (sf.count || 0) + ' 个文件</td>'
                + '<td>-</td>'
                + '<td>-</td>'
                + '<td></td>'
                + '</tr>';
        });
        files.forEach(function (f) {
            fileMap[f.cid] = f;
            var icon = f.isImage && f.url
                ? '<span class="fm-mini-wrap"><img class="fm-mini-thumb" loading="lazy" src="' + esc(thumbUrl(f)) + '"' + warmAttr(f) + ' alt="">' + ihBadge(f) + '</span>'
                : '<span class="fm-mini-icon">' + esc(f.ext || '?') + '</span>';
            var selected = !!state.selected[f.cid];
            rows += '<tr data-cid="' + f.cid + '">'
                + '<td><input type="checkbox"' + (selected ? ' checked' : '') + '></td>'
                + '<td class="fm-name-cell" title="' + esc(f.name) + '"><div class="fm-name-line">' + icon + ' <a href="' + esc(f.url) + '" target="_blank" rel="noopener">' + esc(f.name) + '</a></div></td>'
                + '<td>' + esc(fmtSize(f.size)) + '</td>'
                + '<td>' + esc(f.ext || '-') + '</td>'
                + '<td>' + esc(fmtDate(f.created)) + '</td>'
                + '<td><button type="button" class="btn btn-s" data-act="share">分享</button> '
                + (isExtractableFile(f) ? '<button type="button" class="btn btn-s" data-act="extract">解压</button> ' : '')
                + '<a class="btn btn-s" href="' + esc(f.url) + '" download="' + esc(f.name) + '" rel="noopener">下载</a> '
                + '<button type="button" class="btn btn-s" data-act="edit">编辑</button> '
                + '<button type="button" class="btn btn-s btn-del" data-act="del">删除</button></td>'
                + '</tr>';
        });
        body.innerHTML = rows;
        bindCardEvents(body);
        bindFolderEntries(body);
    }

    function toggleCardSelect(card, cid) {
        var cb = card.querySelector('input[type=checkbox]');
        var next = !state.selected[cid];
        if (next) { state.selected[cid] = 1; } else { delete state.selected[cid]; }
        if (cb) { cb.checked = next; }
        card.classList.toggle('selected', next);
        updateBatchBar();
    }

    function bindCardEvents(container) {
        Array.prototype.forEach.call(container.querySelectorAll('[data-cid]'), function (card) {
            var cid = parseInt(card.getAttribute('data-cid'), 10);

            var isRow = card.tagName === 'TR';
            if (isRow) {
                var handle = card.querySelector('.fm-mini-thumb, .fm-mini-icon');
                if (handle) {
                    handle.classList.add('fm-drag-handle');
                    handle.setAttribute('title', '按住拖动到左侧文件夹可移动');
                    handle.addEventListener('mousedown', function () { card.setAttribute('draggable', 'true'); });
                    handle.addEventListener('mouseup', function () { card.removeAttribute('draggable'); });
                }
            } else {
                card.setAttribute('draggable', 'true');
            }
            card.addEventListener('dragstart', function (e) {
                var cids = [];
                var cb = card.querySelector('input[type=checkbox]');
                if ((cb && cb.checked) || state.selected[cid]) {
                    var keys = Object.keys(state.selected);
                    if (cb && cb.checked && keys.indexOf(String(cid)) < 0) { keys.push(String(cid)); }
                    if (keys.length > 1) {
                        cids = keys.map(function (k) { return parseInt(k, 10); });
                    }
                }
                if (cids.length < 2) { cids = [cid]; }
                dragCids = cids;
                if (cids.length > 1) { toast('拖拽移动：将移动勾选的 ' + cids.length + ' 个文件'); }
                if (e.dataTransfer) {
                    e.dataTransfer.setData('text/plain', dragCids.join(','));
                    e.dataTransfer.effectAllowed = 'move';
                }
            });
            card.addEventListener('dragend', function () {
                dragCids = [];
                if (isRow) { card.removeAttribute('draggable'); } /* 手柄拖完还原，让位给框选 */
            });

            var checkbox = card.querySelector('input[type=checkbox]');
            if (checkbox) {
                checkbox.addEventListener('click', function (e) { e.stopPropagation(); });
                checkbox.addEventListener('change', function () {
                    if (checkbox.checked) {
                        state.selected[cid] = 1;
                        if (!state.batchMode) { setBatchMode(true); } /* 勾选即自动进入批量选择模式 */
                    } else {
                        delete state.selected[cid];
                    }
                    card.classList.toggle('selected', checkbox.checked);
                    updateBatchBar();
                });
            }

            card.addEventListener('click', function (e) {
                if (!state.batchMode) { return; }
                if (e.target.closest('button') || e.target.closest('a:not(.fm-thumb):not(.fm-name-link)')) { return; }
                e.preventDefault();
                toggleCardSelect(card, cid);
            });

            var viewLinks = fileMap[cid]
                ? card.querySelectorAll('a[href="' + CSS.escape(fileMap[cid].url) + '"]:not([download])')
                : [];
            Array.prototype.forEach.call(viewLinks, function (link) {
                link.addEventListener('click', function (e) {
                    var f = fileMap[cid];
                    if (!f || state.batchMode) { return; }
                    var ext = String(f.ext || '').toLowerCase();
                    if (f.isImage || LIGHTBOX_VIDEO_EXTS[ext]) {
                        e.preventDefault();
                        openLightbox(f);
                    }
                });
            });

            Array.prototype.forEach.call(card.querySelectorAll('button[data-act]'), function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var act = btn.getAttribute('data-act');
                    if (act === 'share') { openShareModal(cid); }
                    if (act === 'extract') { openExtractModal(cid); }
                    if (act === 'edit') {
                        if (isTextEditableFile(fileMap[cid])) { openTextEditModal(cid); }
                        else { openEditModal(cid); }
                    }
                    if (act === 'del') { deleteFiles([cid]); }
                });
            });
        });
        bindThumbs(container);
    }

    function renderPager() {
        $('fmPageInfo').textContent = '第 ' + state.page + ' / ' + state.pages + ' 页（共 ' + state.total + ' 个）';
        $('fmPrev').disabled = state.page <= 1;
        $('fmNext').disabled = state.page >= state.pages;
    }

    var editCid = 0;
    var editOrigBase = '';   /* 打开弹窗时的物理文件名（不含扩展名），用于判断是否真的改动了物理名 */

    var TEXT_EDIT_EXTS = { txt: 1, md: 1, html: 1, htm: 1, css: 1, js: 1, json: 1, xml: 1, svg: 1, log: 1, ini: 1, conf: 1, yml: 1, yaml: 1, csv: 1, sql: 1 };
    function isTextEditableFile(f) {
        return !!f && !!TEXT_EDIT_EXTS[String(f.ext || '').toLowerCase()];
    }

    function openEditModal(cid, isSub) {
        var f = fileMap[cid];
        if (!f) { toast('文件信息不存在'); return; }
        editCid = cid;
        editOrigBase = String(f.name || '').replace(/\.[^.]+$/, '');
        /* 从在线编辑器里打开要抬到上一层（z 20200），否则与它同级被盖住，得先关编辑器才看得见 */
        $('fmEditModal').classList.toggle('fm-modal-sub', !!isSub);
        $('fmEditFileName').textContent = f.url || f.name;
        $('fmEditFileName').title = f.url || f.name;
        $('fmEditName').value = editOrigBase;
        $('fmEditTitle').value = f.name;
        $('fmEditName').disabled = true;
        $('fmEditTitle').disabled = true;
        var saveBtn = $('fmEditSave');
        saveBtn.disabled = false;
        saveBtn.textContent = '保存修改';
        openModal('fmEditModal');

        api('fileGet', { cid: cid }).then(function (data) {
            if (data.code !== 200) { toast(data.message || '读取文件信息失败'); closeModal('fmEditModal'); return; }
            editOrigBase = String(data.name || f.name || '').replace(/\.[^.]+$/, '');
            $('fmEditName').value = editOrigBase;
            $('fmEditTitle').value = data.title || f.name;
            $('fmEditName').disabled = false;
            $('fmEditTitle').disabled = false;
        }).catch(function () {
            $('fmEditName').disabled = false;
            $('fmEditTitle').disabled = false;
        });
    }

    $('fmEditSave').addEventListener('click', function () {
        if (!editCid) { return; }
        var btn = this;
        var title = $('fmEditTitle').value.trim();
        var newName = $('fmEditName').value.trim();
        if (!title) { toast('文件标题不能为空'); return; }

        /* 仅当物理文件名（不含扩展名）真的变了才二次确认；只改标题时不打断 */
        if ('' !== newName && newName !== editOrigBase) {
            var tip = '修改文件的物理文件名称会把文章/页面中的旧路径一并替换为新路径，确认保存修改吗？';
            /* 改名后要重开编辑器，编辑器里没落盘的内容会先自动保存，必须提前告知 */
            if (!$('fmTextEditModal').hidden && $('fmTextEditArea').value !== textEditLoaded) {
                tip = '在线编辑器里有未保存的修改，将随改名一并保存，并重新打开改名后的文件。\n\n' + tip;
            }
            if (!window.confirm(tip)) {
                return;
            }
        }

        btn.disabled = true;
        btn.textContent = '保存中…';
        api('fileUpdate', { cid: editCid, title: title, name: newName }).then(function (data) {
            btn.disabled = false;
            btn.textContent = '保存修改';
            if (data.code !== 200) { toast(data.message || '保存失败'); return; }
            /* name 是文件名、title 是标题，改名只回写文件名，免得列表与灯箱标题被标题顶掉 */
            var renamedFile = data.newPath ? String(data.newPath).split('/').pop() : '';
            if (renamedFile && fileMap[editCid]) {
                fileMap[editCid].name = renamedFile;
                if (data.url) { fileMap[editCid].url = data.url; }
            }
            if (lbEl && !lbEl.hidden && lbCid === editCid && fileMap[editCid]) {
                lbEl.querySelector('#fmLbCaption').textContent = lbCapText(editCid);
            }
            var reopenEditor = textEditCid === editCid && !$('fmTextEditModal').hidden;
            toast(data.message || '文件信息已保存');
            closeModal('fmEditModal');
            loadFiles();
            /* 只改标题时文件名没动，无需重开；真改名才重开，让标题跟着新文件名走 */
            if (reopenEditor && data.newPath) { reopenTextEditAfterRename(editCid); }
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = '保存修改';
        });
    });

    var textEditCid = 0;
    var textEditLoaded = '';   /* 最近一次从服务器加载的内容（用于检测未保存修改） */
    var textEditEnc = 'auto';  /* 当前生效的编码选择 */
    var textEditHl = false;    /* 是否处于高亮预览模式 */

    var TEXT_HL_LANGS = { html: 'xml', htm: 'xml', svg: 'xml', xml: 'xml', md: 'markdown', css: 'css', js: 'javascript', json: 'json', ini: 'ini', conf: 'ini', yml: 'yaml', yaml: 'yaml', sql: 'sql', php: 'php' };

    var textEditGutterLines = 0;
    var textEditGutterTimer = 0;

    function textEditCountLines(text) {
        var n = 1;
        for (var i = 0; i < text.length; i++) { if (text.charCodeAt(i) === 10) { n++; } }
        return n;
    }

    function textEditRenderGutterSimple(n) {
        var parts = new Array(n);
        for (var i = 0; i < n; i++) { parts[i] = i + 1; }
        $('fmTextEditGutter').textContent = parts.join('\n');
    }

    function textEditRenderGutterWrapped() {
        var area = $('fmTextEditArea');
        var cs = getComputedStyle(area);
        var lh = parseFloat(cs.lineHeight) || 20;
        var lines = area.value.split('\n');
        var mirror = document.createElement('div');
        mirror.style.cssText = 'position:absolute;left:-99999px;top:0;visibility:hidden;box-sizing:border-box;'
            + 'width:' + area.clientWidth + 'px;padding:10px 12px;border:0;margin:0;'
            + 'font-family:' + cs.fontFamily + ';font-size:' + cs.fontSize + ';line-height:' + cs.lineHeight
            + ';white-space:pre-wrap;word-break:break-all;tab-size:4;';
        var frag = document.createDocumentFragment();
        var spans = new Array(lines.length);
        for (var i = 0; i < lines.length; i++) {
            var sp = document.createElement('span');
            sp.textContent = lines[i];
            frag.appendChild(sp);
            frag.appendChild(document.createElement('br'));
            spans[i] = sp;
        }
        mirror.appendChild(frag);
        document.body.appendChild(mirror);
        var rows = new Array(lines.length);
        var total = 0;
        for (var j = 0; j < lines.length; j++) {
            rows[j] = Math.max(1, Math.round(spans[j].getBoundingClientRect().height / lh));
            total += rows[j];
        }
        document.body.removeChild(mirror);

        var width = String(lines.length).length;
        var out = new Array(total);
        for (var k = 0; k < total; k++) { out[k] = ''; }
        var pos = 0;
        for (var m = 0; m < lines.length; m++) {
            var num = String(m + 1);
            while (num.length < width) { num = ' ' + num; }
            out[pos] = num;
            pos += rows[m];
        }
        $('fmTextEditGutter').textContent = out.join('\n');
        textEditGutterLines = lines.length;
    }

    function textEditScheduleGutter() {
        if (textEditGutterTimer) { clearTimeout(textEditGutterTimer); }
        textEditGutterTimer = setTimeout(function () {
            textEditGutterTimer = 0;
            if (textEditWrapOn && textEditCid && !$('fmTextEditModal').hidden) { textEditRenderGutterWrapped(); }
        }, 300);
    }

    function textEditGutterRebuild() {
        if (!textEditCid || $('fmTextEditModal').hidden) { return; }
        if (textEditWrapOn) {
            textEditRenderGutterWrapped();
        } else {
            var n = textEditCountLines($('fmTextEditArea').value);
            textEditGutterLines = n;
            textEditRenderGutterSimple(n);
        }
    }

    function textEditUpdateMeta() {
        var area = $('fmTextEditArea');
        var n = textEditCountLines(area.value);
        $('fmTextEditStats').textContent = area.disabled ? '' : '共 ' + n + ' 行 · ' + area.value.length + ' 个字符';
        if (area.disabled) { return; }
        if (textEditWrapOn) {
            textEditScheduleGutter(); /* 输入防抖后重测折行 */
        } else if (n !== textEditGutterLines) {
            textEditGutterLines = n;
            textEditRenderGutterSimple(n);
        }
    }

    function textEditSyncScroll() {
        var area = $('fmTextEditArea');
        var pre = $('fmTextEditHl');
        /* 用平移而非 pre.scrollLeft：pre 的可滚动范围永远与 textarea 不等（滚动条槽 + scrollWidth 口径），末端会卡住甩开一截 */
        if (textEditHl && !pre.hidden) {
            pre.querySelector('code').style.transform =
                'translate(' + (-area.scrollLeft) + 'px,' + (-area.scrollTop) + 'px)';
        }
        $('fmTextEditGutter').scrollTop = area.scrollTop;
    }

    var textEditHlTimer = 0;
    var textEditHlLast = ''; /* 上次已高亮的文本，内容没变就跳过重渲染 */
    function textEditRenderHl() {
        var area = $('fmTextEditArea');
        var pre = $('fmTextEditHl');
        if (area.value === textEditHlLast) { return; }
        textEditHlLast = area.value;
        var lang = TEXT_HL_LANGS[String((fileMap[textEditCid] || {}).ext || '').toLowerCase()] || 'plaintext';
        var code = pre.querySelector('code');
        code.className = 'hljs language-' + lang;
        try {
            if (window.hljs && lang !== 'plaintext' && hljs.getLanguage(lang)) {
                code.innerHTML = hljs.highlight(area.value, { language: lang, ignoreIllegals: true }).value;
                return;
            }
            if (window.hljs) {
                code.innerHTML = hljs.highlightAuto(area.value).value;
                return;
            }
        } catch (e) { /* 高亮失败按纯文本显示 */ }
        code.textContent = area.value;
    }
    function textEditScheduleHl() {
        var delay = $('fmTextEditArea').value.length > 60000 ? 300 : 80;
        if (textEditHlTimer) { clearTimeout(textEditHlTimer); }
        textEditHlTimer = setTimeout(function () {
            textEditHlTimer = 0;
            if (textEditHl && textEditCid && !$('fmTextEditModal').hidden) { textEditRenderHl(); }
        }, delay);
    }

    function textEditSetMode(hl) {
        var area = $('fmTextEditArea');
        var pre = $('fmTextEditHl');
        var code = pre.querySelector('code');
        if (hl && !window.hljs) { toast('高亮组件未加载，请刷新页面重试'); return; }
        textEditHl = !!hl;
        if (textEditHl) {
            textEditRenderHl();
            pre.classList.add('fm-textedit-overlaying');
            area.classList.add('fm-textedit-glass');
            pre.hidden = false;
            pre.scrollTop = 0;
            pre.scrollLeft = 0;
            textEditSyncScroll();
        } else {
            area.classList.remove('fm-textedit-glass');
            pre.classList.remove('fm-textedit-overlaying');
            pre.hidden = true;
            code.textContent = ''; /* 释放大文件的高亮 DOM */
            code.style.transform = '';
            textEditHlLast = ''; /* DOM 已清空，重进高亮时必须强制重渲染 */
            if (textEditHlTimer) { clearTimeout(textEditHlTimer); textEditHlTimer = 0; }
            textEditSyncScroll();
        }
    }

    (function () {
        var sel = document.getElementById('fmTextEditTheme');
        if (!sel) { return; }
        Object.keys(HL_THEMES).forEach(function (k) {
            var o = document.createElement('option');
            o.value = k;
            o.textContent = HL_THEMES[k].label;
            sel.appendChild(o);
        });
        sel.value = prefs.hlTheme;
        sel.addEventListener('change', function () {
            prefs.hlTheme = HL_THEMES[sel.value] ? sel.value : 'github';
            applyHlTheme();
            if (!$('fmTextEditModal').hidden) {
                textEditSetMode(prefs.hlTheme !== 'none');
            }
            api('prefsSave', { prefs: JSON.stringify(prefs) }).then(function (res) {
                if (res && res.prefs && HL_THEMES[res.prefs.hlTheme]) {
                    prefs.hlTheme = res.prefs.hlTheme;
                    applyHlTheme();
                }
                toast(prefs.hlTheme === 'none'
                    ? '已退出高亮编辑'
                    : '高亮主题已切换：' + (HL_THEMES[prefs.hlTheme] ? HL_THEMES[prefs.hlTheme].label : prefs.hlTheme));
            }).catch(function () { /* api() 已弹过错误提示；本地切换仍生效 */ });
        });
    })();

    $('fmTextEditArea').addEventListener('input', function () {
        textEditUpdateMeta();
        if (textEditHl) { textEditScheduleHl(); } /* 高亮模式下随输入防抖刷新高亮 */
    });
    $('fmTextEditArea').addEventListener('scroll', textEditSyncScroll);
    window.addEventListener('resize', function () {
        if (textEditWrapOn && textEditCid) { textEditScheduleGutter(); }
    });

    var textEditWrapOn = false;
    function textEditApplyWrap() {
        $('fmTextEditArea').wrap = textEditWrapOn ? 'soft' : 'off';
        document.querySelector('#fmTextEditCard .fm-textedit-wrap').classList.toggle('fm-textedit-soft', textEditWrapOn);
        $('fmTextEditWrapBtn').classList.toggle('fm-btn-on', textEditWrapOn);
        textEditGutterRebuild();
    }
    $('fmTextEditWrapBtn').addEventListener('click', function () {
        textEditWrapOn = !textEditWrapOn;
        textEditApplyWrap();
    });

    function loadTextEditContent(cid, enc) {
        var area = $('fmTextEditArea');
        area.value = '';
        area.disabled = true;
        textEditGutterLines = 0;
        textEditGutterLines = 1;
        textEditRenderGutterSimple(1);
        $('fmTextEditGutter').scrollTop = 0;
        $('fmTextEditStats').textContent = '';
        textEditSetMode(false); /* 复位回编辑模式 */
        $('fmTextEditInfo').textContent = '加载中…';
        return api('fileTextGet', { cid: cid, enc: enc }).then(function (d) {
            if (textEditCid !== cid) { return; } /* 弹窗已切到别的文件，丢弃 */
            textEditLoaded = d.content || '';
            area.value = textEditLoaded;
            area.disabled = false;
            textEditUpdateMeta();
            if (d.enc) { $('fmTextEditEnc').value = d.enc; textEditEnc = d.enc; }
            textEditSetMode(prefs.hlTheme !== 'none');
            $('fmTextEditInfo').textContent = '编码 ' + (d.enc || 'UTF-8') + ' · 大小 ' + fmtSize(d.size)
                + ' · 修改于 ' + fmtDate(d.mtime) + ' · 保存将按所选编码写回原文件';
        }).catch(function () {
            if (textEditCid === cid) {
                textEditLoaded = '';
                $('fmTextEditInfo').textContent = '加载失败：可尝试在下方切换编码后重新加载';
            }
        });
    }

    function convertTextEditEnc(cid, from, to) {
        return api('fileTextConvert', { cid: cid, from: from, to: to }).then(function () {
            loadFiles();
            return loadTextEditContent(cid, 'auto').then(function () {
                var now = String($('fmTextEditEnc').value || '').toUpperCase();
                if (now === String(to).toUpperCase()) {
                    toast('已转换为 ' + to + ' 编码');
                } else {
                    toast('已转换为 ' + to + ' 编码；因内容为纯 ASCII，与 ' + now + ' 字节相同，检测显示为 ' + now);
                }
            });
        });
    }

    function openTextEditModal(cid) {
        var f = fileMap[cid];
        if (!f) { toast('文件信息不存在'); return; }
        textEditCid = cid;
        textEditLoaded = '';
        textEditEnc = 'auto';
        applyHlTheme(); /* 主题/深色底跟随偏好 */
        $('fmTextEditTitle').textContent = f.name; /* 窗口标题 = 文件名（含后缀） */
        $('fmTextEditEnc').value = 'auto';
        var saveBtn = $('fmTextEditSave');
        saveBtn.disabled = false;
        saveBtn.textContent = '保存';
        openModal('fmTextEditModal');
        return loadTextEditContent(cid, 'auto');
    }

    /* 改名成功后重开编辑器：未保存的内容先落盘（cid 不变，改名只挪路径），重载后回到原滚动位置 */
    function reopenTextEditAfterRename(cid) {
        var area = $('fmTextEditArea');
        var top = area.scrollTop, left = area.scrollLeft;
        var reopen = function () {
            var p = openTextEditModal(cid);
            if (p && p.then) {
                p.then(function () {
                    area.scrollTop = Math.min(top, Math.max(0, area.scrollHeight - area.clientHeight));
                    area.scrollLeft = Math.min(left, Math.max(0, area.scrollWidth - area.clientWidth));
                    textEditSyncScroll();
                });
            }
        };
        if (area.disabled || area.value === textEditLoaded) { reopen(); return; }
        api('fileTextSave', { cid: cid, content: area.value, enc: textEditEnc === 'auto' ? 'UTF-8' : textEditEnc })
            .then(function (d) {
                /* 存不下就别重开：重载会把编辑器里这份还没落盘的内容冲掉 */
                if (d && d.code !== 200) { toast(d.message || '文件内容保存失败，已保留当前编辑内容'); return; }
                reopen();
            })
            .catch(function () { /* api() 已提示失败，保留现场 */ });
    }

    function toggleTextEditFull() {
        $('fmTextEditCard').classList.toggle('fm-textedit-full');
        textEditGutterRebuild(); /* 宽度变了，换行模式下需重测折行行号 */
    }
    $('fmTextEditFullBtn').addEventListener('click', toggleTextEditFull);
    $('fmTextEditModal').addEventListener('keydown', function (e) {
        if (e.key === 'F11') {
            e.preventDefault();
            toggleTextEditFull();
        }
    });

    $('fmTextEditEnc').addEventListener('change', function () {
        if (!textEditCid) { return; }
        var next = this.value;
        var cid = textEditCid;
        if (next === textEditEnc) { return; }

        if (next === 'auto') {
            loadTextEditContent(cid, 'auto'); /* 重新按自动检测打开 */
            return;
        }

        var from = textEditEnc === 'auto' ? 'auto' : textEditEnc; /* 服务端对 auto 自动检测来源 */
        var hasEdits = $('fmTextEditArea').value !== textEditLoaded;
        var msg;
        if (hasEdits) {
            msg = '转换编码要先保存内容，您确定要保存当前内容并将文件从 '
                + (from === 'auto' ? '当前编码' : from) + ' 转换为 ' + next + ' 编码吗？';
        } else {
            msg = '将文件从 ' + (from === 'auto' ? '当前编码' : from) + ' 转换为 ' + next + ' 编码吗？';
        }
        if (/^[\x00-\x7F]*$/.test($('fmTextEditArea').value)) {
            msg = '提示：文件内容是纯 ASCII（英文/数字/符号），各编码下字节完全相同，转换后重新检测仍会显示 UTF-8。\n' + msg;
        }
        if (!window.confirm(msg)) { this.value = textEditEnc; return; }

        var doConvert = function () { convertTextEditEnc(cid, from, next); };
        if (hasEdits) {
            api('fileTextSave', { cid: cid, content: $('fmTextEditArea').value, enc: textEditEnc === 'auto' ? 'UTF-8' : textEditEnc }).then(doConvert);
        } else {
            doConvert();
        }
    });

    $('fmTextEditSave').addEventListener('click', function () {
        if (!textEditCid) { return; }
        var btn = this;
        btn.disabled = true;
        btn.textContent = '保存中…';
        api('fileTextSave', { cid: textEditCid, content: $('fmTextEditArea').value, enc: textEditEnc === 'auto' ? 'UTF-8' : textEditEnc }).then(function (d) {
            btn.disabled = false;
            btn.textContent = '保存';
            if (fileMap[textEditCid]) { fileMap[textEditCid].size = d.newSize; }
            toast('文件内容已保存');
            textEditCid = 0;
            closeModal('fmTextEditModal');
            loadFiles();
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = '保存';
        });
    });

    $('fmTextEditTitleBtn').addEventListener('click', function () {
        if (!textEditCid) { return; }
        openEditModal(textEditCid, true);
    });

    function renderMoveTarget() {
        var html = '<option value="0">未分类</option>';
        function walk(parentFid, depth) {
            state.folders.forEach(function (f) {
                if (f.parent === parentFid) {
                    html += '<option value="' + f.fid + '">' + new Array(depth + 1).join('　') + esc(f.name) + ' (' + f.count + ')</option>';
                    walk(f.fid, depth + 1);
                }
            });
        }
        walk(0, 1);
        $('fmMoveTarget').innerHTML = html;
    }

    var prevSelectedCount = 0; /* 用于检测「取消最后一个勾选」自动退出批量模式 */

    function updateBatchBar() {
        var count = Object.keys(state.selected).length;
        if (state.batchMode && count === 0 && prevSelectedCount > 0) {
            setBatchMode(false); /* 最后一个勾选被取消 → 自动退出批量选择模式 */
        }
        prevSelectedCount = count;
        $('fmBatch').hidden = false; /* 功能条永久显示 */
        $('fmBatchCount').textContent = '已选 ' + count + ' 项';
        $('fmBatchAllBtn').textContent = count > 0 ? '取消全选' : '全选';
    }

    function setBatchMode(on, silent) {
        if (state.batchMode === on) { return; } /* 幂等守卫，防递归 */
        state.batchMode = on;
        document.body.classList.toggle('fm-select-mode', on);
        if (!on) {
            state.selected = {};
            prevSelectedCount = 0;
            Array.prototype.forEach.call(
                document.querySelectorAll('#fmGrid [data-cid], #fmMasonry [data-cid], #fmListBody [data-cid]'),
                function (card) {
                    var cb = card.querySelector('input[type=checkbox]');
                    if (cb) { cb.checked = false; }
                    card.classList.remove('selected');
                }
            );
        }
        updateBatchBar();
        if (on && !silent) { toast('批量选择模式：点击文件卡片即可勾选或取消'); }
    }

    $('fmBatchAllBtn').addEventListener('click', function () {
        var isAll = $('fmBatchAllBtn').textContent === '取消全选';
        var cards = document.querySelectorAll('#fmGrid [data-cid], #fmMasonry [data-cid], #fmListBody [data-cid]');
        var count = 0;

        if (isAll) {
            state.selected = {};
            Array.prototype.forEach.call(cards, function (card) {
                var cb = card.querySelector('input[type=checkbox]');
                if (cb) { cb.checked = false; }
                card.classList.remove('selected');
            });
            if (state.batchMode) { setBatchMode(false); } else { prevSelectedCount = 0; updateBatchBar(); }
            toast('已取消全部勾选');
            return;
        }

        if (!state.batchMode) { setBatchMode(true, true); }
        var seen = {}; /* 三个视图容器保留同一页数据，按 cid 去重，避免计数翻倍 */
        Array.prototype.forEach.call(cards, function (card) {
            var cid = parseInt(card.getAttribute('data-cid'), 10);
            if (!cid || seen[cid]) { return; }
            seen[cid] = 1;
            state.selected[cid] = 1;
            var cb = card.querySelector('input[type=checkbox]');
            if (cb) { cb.checked = true; }
            card.classList.add('selected');
            count++;
        });
        prevSelectedCount = Object.keys(state.selected).length;
        updateBatchBar();
        toast(count ? ('已全选当前列表 ' + count + ' 个文件') : '当前列表没有可选择的文件');
    });

    (function () {
        var band = document.createElement('div');
        band.className = 'fm-marquee';
        document.body.appendChild(band);

        var active = false, moved = false, sx = 0, sy = 0, snap = null;

        function items() {
            var sel = state.view === 'grid' ? '#fmGrid' : (state.view === 'masonry' ? '#fmMasonry' : '#fmListBody');
            return document.querySelectorAll(sel + ' [data-cid]');
        }

        function applyRect(x1, y1, x2, y2) {
            var l = Math.min(x1, x2), r = Math.max(x1, x2);
            var t = Math.min(y1, y2), b = Math.max(y1, y2);
            band.style.left = l + 'px';
            band.style.top = t + 'px';
            band.style.width = (r - l) + 'px';
            band.style.height = (b - t) + 'px';
            Array.prototype.forEach.call(items(), function (card) {
                var rc = card.getBoundingClientRect();
                var hit = rc.left < r && rc.right > l && rc.top < b && rc.bottom > t;
                var cid = card.getAttribute('data-cid');
                var next = hit || !!snap[cid]; /* 矩形外恢复按下前的状态 */
                if (!!state.selected[cid] === next) { return; }
                var cb = card.querySelector('input[type=checkbox]');
                if (next) { state.selected[cid] = 1; } else { delete state.selected[cid]; }
                if (cb) { cb.checked = next; }
                card.classList.toggle('selected', next);
            });
        }

        function onDown(e) {
            if (e.button !== 0) { return; }
            if (e.target.closest('button, a, input, select, textarea, label')) { return; }
            if (e.target.closest('.fm-side, .fm-toolbar, .fm-batch, .fm-pager, .fm-modal, #fmLightbox, header, .typecho-head-nav, .typecho-page-title')) { return; }
            var card = e.target.closest('[data-cid]');
            if (card) {
                if (card.tagName !== 'TR') { return; }
                if (e.target.closest('.fm-drag-handle')) { return; }
            }
            active = true;
            moved = false;
            sx = e.clientX;
            sy = e.clientY;
            snap = {};
            for (var k in state.selected) { snap[k] = 1; }
            document.body.classList.add('fm-marqueeing');
            e.preventDefault(); /* 阻止原生文本选择/拖拽，框选接管 */
        }

        function onMove(e) {
            if (!active) { return; }
            if (!moved) {
                if (Math.abs(e.clientX - sx) + Math.abs(e.clientY - sy) <= 4) { return; }
                moved = true; /* 超过 4px 才算框选，纯点击不画框 */
                band.style.display = 'block';
            }
            e.preventDefault();
            applyRect(sx, sy, e.clientX, e.clientY);
        }

        function onUp() {
            if (!active) { return; }
            active = false;
            band.style.display = 'none';
            document.body.classList.remove('fm-marqueeing');
            if (!moved) { return; }
            var count = Object.keys(state.selected).length;
            if (count > 0 && !state.batchMode) {
                setBatchMode(true, true);
                toast('框选完成：已选 ' + count + ' 个文件');
            } else {
                updateBatchBar();
            }
        }

        document.body.addEventListener('mousedown', onDown);
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    })();

    var lbEl = null, lbPrevOverflow = '';
    var lbCid = 0; /* 当前灯箱展示的附件 cid（EXIF 渲染防串图） */
    var exifCache = {}; /* cid -> EXIF 接口返回（按需加载） */
    var lbDimCache = {}; /* cid -> 「宽×高」（图片 onload 时从 naturalWidth/Height 采集，旋转后随新图刷新） */
    var lbList = [], lbIndex = -1; /* 灯箱导航：当前页可展示文件（图片/视频）及当前位置 */
    var lbZoom = 1, lbPanX = 0, lbPanY = 0, lbRot = 0;
    var lbClosing = false; /* 关闭动画播放中（防重复关闭 / 关闭途中翻图） */
    var lbAnimateDir = 0; /* 翻图滑入方向：1=下一张 -1=上一张 0=无 */

    function isLbViewable(f) {
        return f.isImage || !!LIGHTBOX_VIDEO_EXTS[String(f.ext || '').toLowerCase()];
    }

    /* 读 CSS 算出的可用区域（只认 px）：避免把 0.92 / 限高公式硬编码进 JS */
    function lbPx(v) {
        var n = parseFloat(v);
        return (/px\s*$/i.test(String(v)) && n > 0) ? n : 0;
    }

    function lbFitBox(img) {
        var cs = window.getComputedStyle ? window.getComputedStyle(img) : null;
        var W = cs ? lbPx(cs.maxWidth) : 0;
        var H = cs ? lbPx(cs.maxHeight) : 0;
        if (!(W > 0)) { W = (window.innerWidth || 1080) * 0.92; }
        if (!(H > 0)) { H = (window.innerHeight || 800) * 0.82; }
        return [W, H];
    }

    function lbApplyView() {
        var img = lbEl ? lbEl.querySelector('#fmLbContent img') : null;
        if (!img) { return; }
        var s = lbZoom;
        if (lbRot % 180 === 90) {
            /* 旋转 90/270 后宽高互换，按旋转后的包围盒重新 fit：s = min(W/h, H/w) */
            var w = img.clientWidth, h = img.clientHeight;
            var box = lbFitBox(img);
            var W = box[0], H = box[1];
            if (w > 0 && h > 0 && W > 0 && H > 0) {
                s *= Math.min(W / h, H / w);
            }
        }
        img.style.transform = 'translate(' + lbPanX + 'px,' + lbPanY + 'px) rotate(' + lbRot + 'deg) scale(' + s + ')';
        img.classList.toggle('fm-zoomed', lbZoom > 1 || lbRot !== 0);
    }

    /* 旋转后尺寸变了，平移量一并归零，保证居中铺满 */
    function lbRotate90() {
        if (!lbEl || !lbEl.querySelector('#fmLbContent img')) { return; }
        lbRot = (lbRot + 90) % 360;
        lbPanX = 0;
        lbPanY = 0;
        lbApplyView();
        syncLbRotSave();
    }

    function lbResetView() {
        lbZoom = 1; lbPanX = 0; lbPanY = 0; lbRot = 0;
        var img = lbEl ? lbEl.querySelector('#fmLbContent img') : null;
        if (img) {
            img.style.transform = '';
            img.style.visibility = ''; /* 万一上次保存半途退出，别把新图也留着隐藏 */
            img.classList.remove('fm-zoomed', 'fm-dragging');
            img.draggable = false; /* 禁用浏览器原生图片拖拽，让按住拖动变成平移 */
        }
        var line = lbEl ? lbEl.querySelector('#fmLbExifLine') : null;
        if (line) { line.hidden = true; line.innerHTML = ''; }
    }

    function renderExifLine(cid) {
        if (!lbEl || lbCid !== cid) { return; }
        var line = lbEl.querySelector('#fmLbExifLine');
        var d = exifCache[cid];
        var map = {};
        ((d && d.items) || []).forEach(function (it) { map[it[0]] = it[1]; });
        var parts = [];
        ['光圈', '快门', 'ISO'].forEach(function (k) {
            if (map[k]) { parts.push(map[k]); }
        });
        var fl = map['等效焦距'] || map['焦距'];
        if (fl) { parts.push(fl); }
        if (parts.length >= 2) {
            line.textContent = parts.join(' · ');
            line.hidden = false;
        } else {
            line.hidden = true; /* 参数太少（截图/网图）就不占位 */
        }
    }

    function openLightbox(f) {
        if (!lbEl) { buildLightbox(); }
        if (lbClosing) { return; }
        if (!lbEl.hidden && lbAnimateDir) {
            var dir = lbAnimateDir;
            lbAnimateDir = 0;
            var box = lbEl.querySelector('#fmLbContent');
            box.classList.remove('fm-lb-in-next', 'fm-lb-in-prev');
            void box.offsetWidth; /* 强制重排，连续翻图也能重启动画 */
            box.classList.add('fm-lb-in-' + (dir > 0 ? 'next' : 'prev'));
            setTimeout(function () { box.classList.remove('fm-lb-in-next', 'fm-lb-in-prev'); }, 260);
        }
        var content = lbEl.querySelector('#fmLbContent');
        var caption = lbEl.querySelector('#fmLbCaption');
        var exifBtn = lbEl.querySelector('#fmLbExifBtn');
        var exifPanel = lbEl.querySelector('#fmLbExif');
        lbCid = f.cid || 0;
        lbIndex = lbList.findIndex(function (it) { return it.cid === lbCid; });
        exifPanel.hidden = true;
        exifPanel.innerHTML = '';
        exifBtn.classList.remove('on');
        var isVideo = !!LIGHTBOX_VIDEO_EXTS[String(f.ext || '').toLowerCase()];
        exifBtn.hidden = isVideo;
        var webpBtn = lbEl.querySelector('#fmLbWebp');
        var extLc = String(f.ext || '').toLowerCase();
        webpBtn.hidden = !(f.isImage && (extLc === 'jpg' || extLc === 'jpeg' || extLc === 'png' || extLc === 'bmp'));
        webpBtn.disabled = false;
        webpBtn.textContent = '转 WebP';
        if (isVideo) {
            content.innerHTML = '<video src="' + esc(imgSrc(f)) + '" controls autoplay playsinline></video>';
        } else {
            content.innerHTML = '<img src="' + esc(imgSrc(f)) + '" alt="' + esc(f.name) + '">';
            lbTrackDims(content.querySelector('img'), f.cid); /* 图片加载完成后把宽×高补进标题 */
        }
        lbResetView(); /* 复位缩放/平移/旋转与摘要条（必须在 loadExif 之前：缓存命中时摘要条同步渲染，先复位会把它藏掉） */
        syncLbRotSave();
        if (!isVideo) { loadExif(lbCid); }
        caption.textContent = lbCapText(f.cid);
        var dl = lbEl.querySelector('#fmLbDl');
        dl.href = f.url;
        dl.setAttribute('download', f.name);
        var navOk = lbList.length > 1 && lbIndex >= 0;
        lbEl.querySelector('#fmLbPrev').hidden = !navOk;
        lbEl.querySelector('#fmLbNext').hidden = !navOk;
        if (lbEl.hidden) {
            lbPrevOverflow = document.body.style.overflow;
            lbEl.classList.add('fm-lb-opening');
            setTimeout(function () { lbEl.classList.remove('fm-lb-opening'); }, 280);
        }
        document.body.style.overflow = 'hidden';
        lbEl.hidden = false;
    }

    function lbNav(delta) {
        if (!lbEl || lbEl.hidden || lbClosing || lbList.length < 2 || lbIndex < 0) { return; }
        lbAnimateDir = delta > 0 ? 1 : -1;
        var next = (lbIndex + delta + lbList.length) % lbList.length;
        openLightbox(lbList[next]);
    }

    function lbConvertWebp(cid, btn) {
        var f = fileMap[cid];
        if (!f) { return; }
        if (!window.confirm('将「' + f.name + '」转换为 WebP？\n文章/评论中的引用链接会同步替换；转换后体积没有变小会跳过。')) { return; }
        btn.disabled = true;
        btn.textContent = '转换中…';
        api('batchWebpOne', { cid: cid, wm: '0', refs: '1' }).then(function (d) {
            btn.disabled = false;
            btn.textContent = '转 WebP';
            if (d.status === 'ok' && d.kind === 'webp') {
                btn.hidden = true; /* 已是 WebP，本次浏览不再显示转换按钮 */
                toast('已转 WebP：' + fmtSize(d.oldSize) + ' → ' + fmtSize(d.newSize)
                    + (d.saved > 0 ? '（省 ' + fmtSize(d.saved) + '）' : ''));
                var newUrl = String(f.url || '').replace(/\.(jpe?g|png|bmp)(\?|$)/i, '.webp$2');
                f.url = newUrl;
                f.ext = 'webp';
                f.size = d.newSize;
                if (/\.(jpe?g|png|bmp)$/i.test(f.name)) { f.name = f.name.replace(/\.(jpe?g|png|bmp)$/i, '.webp'); }
                lbList.forEach(function (it) {
                    if (it.cid === cid) { it.url = newUrl; it.ext = 'webp'; it.size = d.newSize; it.name = f.name; }
                });
                if (lbCid === cid) {
                    var img = lbEl.querySelector('#fmLbContent img');
                    if (img) { img.src = newUrl; }
                    lbEl.querySelector('#fmLbCaption').textContent = lbCapText(cid);
                    var dl = lbEl.querySelector('#fmLbDl');
                    dl.href = newUrl;
                    dl.setAttribute('download', f.name);
                }
                delete exifCache[cid];
                loadExif(cid);
            } else if (d.status === 'ok') {
                toast(d.note || '已按原格式重新编码');
            } else {
                toast('跳过：' + (d.reason || '无法转换'));
            }
            loadFiles(); /* 刷新列表缩略图与大小 */
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = '转 WebP';
        });
    }

    /* 原图/视频/缩略图共用同一版本号（mtime+size），否则同一张图会有两个 URL、刷新后重下整图 */
    function verOf(f) {
        if (!f || !f.mtime) { return ''; }
        return String(f.mtime) + (f.size ? '-' + f.size : '');
    }
    function withVer(url, f) {
        var v = verOf(f);
        if (!v) { return url; }
        return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'v=' + v;
    }
    function imgSrc(f) {
        if (!f || !f.url) { return ''; }
        return withVer(String(f.url), f);
    }
    function thumbUrl(f) {
        if (prefs.thumbCache === '0' || !f.isImage || !f.url) { return imgSrc(f); }
        if (!f.hasThumb) { return imgSrc(f); } /* 还没缩略图就先用原图，别让服务端现场生成 */
        /* 缩略图同用 mtime+size：只带 mtime 时同秒保存会继续用旧缩略图 */
        return cfg.url.split('?')[0] + '?do=thumb&cid=' + f.cid + '&w=640&v=' + (verOf(f) || '0');
    }

    var thumbSkip = {}; /* "cid:mtime" -> 1：服务端判定无需缩略图（小图/格式不支持），本会话不再请求 */
    try { thumbSkip = JSON.parse(window.sessionStorage.getItem('fmThumbSkip') || '{}') || {}; } catch (e) { thumbSkip = {}; }
    function thumbKey(cid, mtime) { return cid + ':' + (mtime || 0); }
    function markThumbSkip(cid, mtime) {
        thumbSkip[thumbKey(cid, mtime)] = 1;
        try { window.sessionStorage.setItem('fmThumbSkip', JSON.stringify(thumbSkip)); } catch (e) {}
    }

    function warmAttr(f) {
        var on = prefs.thumbCache !== '0' && f.isImage && f.url && !f.isIh && !f.hasThumb
            && !thumbSkip[thumbKey(f.cid, f.mtime)];
        return on ? ' data-warm="' + f.cid + '" data-warm-mtime="' + (f.mtime || 0) + '"' : '';
    }

    function warmThumb(cid, mtime) {
        var key = thumbKey(cid, mtime);
        if (!(cid > 0) || thumbSeen[key]) { return; }
        thumbSeen[key] = 1;
        thumbQueue.push({ cid: cid, mtime: mtime || 0 });
        pumpThumb();
    }
    function pumpThumb() {
        if (thumbBusy || !thumbQueue.length) { return; }
        thumbBusy = true;
        var job = thumbQueue.shift();
        fetch(cfg.url.split('?')[0] + '?do=thumb&cid=' + job.cid + '&w=640&warm=1', { credentials: 'same-origin' })
            .then(function (res) {
                var tag = res.headers.get('X-FM-Thumb') || '';
                if (tag === 'origin' && job.mtime > 0) { markThumbSkip(job.cid, job.mtime); }
            })
            .catch(function () {})
            .then(function () {
                thumbBusy = false;
                setTimeout(pumpThumb, 80); /* 串行+间隔，避免一次打满 PHP-FPM */
            });
    }
    function bindThumbs(container) {
        Array.prototype.forEach.call(container.querySelectorAll('img[data-warm]'), function (im) {
            var cid = parseInt(im.getAttribute('data-warm'), 10);
            var mtime = parseInt(im.getAttribute('data-warm-mtime'), 10) || 0;
            if (im.complete && im.naturalWidth > 0) { warmThumb(cid, mtime); return; }
            im.addEventListener('load', function () { warmThumb(cid, mtime); }, { once: true });
        });
    }
    /* 只对 jpg/png/webp 露「保存旋转」，与后端白名单一致；扩展名读不到时放行交后端判定 */
    function lbCanRotSave(f) {
        var ext = f && f.ext ? String(f.ext).toLowerCase() : '';
        return !ext || ['jpg', 'jpeg', 'png', 'webp'].indexOf(ext) >= 0;
    }
    function syncLbRotSave() {
        var b = lbEl ? lbEl.querySelector('[data-lbact="rotsave"]') : null;
        if (!b) { return; }
        b.hidden = (lbRot % 360) === 0 || !lbCanRotSave(fileMap[lbCid]);
    }
    function lbSaving(show, text, veil) {
        if (!lbEl) { return; }
        /* 挂到灯箱根节点而不是 #fmLbContent：旋转后的图片会超出内容盒，只盖内容盒会露出图片边缘 */
        var mask = lbEl.querySelector('.fm-lb-saving');
        if (show) {
            if (!mask) {
                mask = document.createElement('div');
                mask.className = 'fm-lb-saving';
                mask.innerHTML = '<i class="fm-lb-spin"></i><span>正在保存...</span>';
                lbEl.appendChild(mask);
            }
            /* 遮罩从点按钮盖到新图可画，挡住所有位图/角度不匹配的中间帧 */
            mask.classList.toggle('is-veil', !!veil);
            var span = mask.querySelector('span');
            if (span) { span.textContent = text || '正在保存...'; }
            mask.hidden = false;
        } else if (mask) {
            mask.hidden = true;
            mask.classList.remove('is-veil');
        }
    }
    var rotProbeCache = {}; /* cid -> 无损能力探测结果（同一文件重复打开弹窗只探一次） */
    function lbRotSave() {
        var ang = lbRot % 360;
        if (!ang || !lbCid) { return; }
        var f = fileMap[lbCid];
        /* 兜底拦截（键盘等异常路径）：不打开弹窗，避免白等一次探针往返 */
        if (!lbCanRotSave(f)) {
            var rotExt = f && f.ext ? String(f.ext).toLowerCase() : '';
            toast('该格式（' + rotExt.toUpperCase() + '）不支持旋转保存，仅限 jpg/png/webp');
            return;
        }
        $('fmRotSaveInfo').textContent = '将「' + (f ? f.name : '当前图片') + '」顺时针旋转 ' + ang + '°。';
        var box = $('fmRotLossless');
        box.className = 'fm-rot-lossless';
        box.hidden = false;
        box.textContent = '正在检测无损旋转能力…';
        var q = $('fmRotSrc');
        if (q) { q.hidden = true; q.innerHTML = ''; } /* 上一张图的画质别串到这张上 */
        var iccBox = $('fmRotIcc');
        if (iccBox) { iccBox.hidden = true; iccBox.innerHTML = ''; } /* 同理，上一张图的色彩配置提示 */
        rotSaveEnabled(true);
        openModal('fmRotSaveModal');
        rotProbe(lbCid);
    }
    function rotSaveEnabled(on) {
        ['fmRotSaveOk', 'fmRotSaveAs'].forEach(function (id) {
            var b = $(id);
            if (b) { b.disabled = !on; }
        });
    }
    function rotProbe(cid) {
        var hit = rotProbeCache[cid];
        if (hit) { renderRotProbe(hit); return; }
        var fd = new FormData();
        fd.append('cid', cid);
        api('rotProbe', fd).then(function (data) {
            rotProbeCache[cid] = data;
            if (lbCid === cid) { renderRotProbe(data); } /* 探测回来时可能已关窗/换图 */
        }).catch(function () {
            var box = $('fmRotLossless');
            if (box) { box.hidden = true; }
            var iccBox = $('fmRotIcc');
            if (iccBox) { iccBox.hidden = true; }
        });
    }
    function renderRotProbe(d) {
        var box = $('fmRotLossless');
        if (!box || !d) { return; }
        var dead = 'none' === d.channel;
        var lvl = d.level || (d.lossless ? 'ok' : (dead ? 'bad' : 'warn'));
        var cls = { ok: 'is-ok', near: 'is-near', warn: 'is-warn', bad: 'is-bad' }[lvl] || 'is-warn';
        var head = d.label || (d.lossless ? '无损旋转' : (dead ? '无法保存' : '有损旋转'));
        /* 只留「标签：正文」；chain/version 等排查字段不再展示 */
        /* 画质优先开关已删除：有损图再往高档编只会涨体积，策略只剩一条 */
        box.className = 'fm-rot-lossless ' + cls;
        box.innerHTML = '<b>' + esc(head) + '</b>：' + esc(d.tip || '');
        /* ICC 提示单独一行：只在探针明确说「会丢」时出现 */
        var iccBox = $('fmRotIcc');
        if (iccBox) {
            iccBox.hidden = !d.icc;
            iccBox.innerHTML = d.icc ? '<b>色彩配置会丢失</b>：' + esc(d.icc) : '';
        }
        rotSaveEnabled(!dead);
        renderRotSrc(d.srcInfo, d);
    }
    /* 源图画质：JPEG 从量化表反推（一律带「约」），WebP 按 bpp 估算档位，两者性质不同 */
    function renderRotSrc(s, d) {
        var box = $('fmRotSrc');
        if (!box) { return; }
        if (!s || !s.kind || 'unknown' === s.kind) { box.hidden = true; return; }
        var estQ = d && d.estQ ? parseInt(d.estQ, 10) : 0;
        var nextQ = d && d.nextQ ? parseInt(d.nextQ, 10) : 0;
        var parts = [];
        if ('jpeg' === s.kind) {
            parts.push('约 q' + s.quality + (s.exact ? '' : '（非标准量化表，仅近似）'));
            if (s.sub) { parts.push(s.sub + ' 色度抽样'); }
            /* 「基线」指 baseline 顺序编码，与「质量基线」（体积目标）不是一回事 */
            parts.push(s.progressive ? '渐进式编码' : '基线编码');
        } else {
            /* WebP/PNG 无质量档位可读，改用 bpp（体积×8÷像素数）表达压缩强度 */
            if ('png' === s.kind) {
                parts.push('PNG 无损压缩' + (s.alpha ? '（含透明通道）' : ''));
            } else if ('webp-lossless' === s.kind) {
                parts.push('WebP 无损编码' + (s.alpha ? '（含透明通道）' : ''));
            } else if ('webp-anim' === s.kind) {
                parts.push('WebP 动图');
            } else if (estQ > 0) {
                parts.push('WebP 有损编码，估算约 q' + estQ);
            } else {
                parts.push('WebP 有损编码（无质量档位可读）');
            }
            if (s.w > 0 && s.h > 0) { parts.push(s.w + '×' + s.h); }
            if (s.bpp) { parts.push(s.bpp + ' bpp'); }
        }
        if (s.bytes) { parts.push(fmtSize(s.bytes)); }
        var html = '<b>当前画质</b>：' + esc(parts.join(' · '));
        if (nextQ > 0) {
            /* 估算档与本次实存档必须能对上，否则用户会当成两个矛盾的数字 */
            var qNote = (estQ > 0 && nextQ === estQ)
                ? '与当前画质档相同，不再额外降档'
                : (estQ > 0 && nextQ > estQ
                    ? '按最初原图体积定档，略高于当前文件'
                    : '按原图压缩强度定档，不是每次重新试算');
            html += '<br><b>本次保存</b>：' + esc('q' + nextQ + ' 重编码（' + qNote + '）');
            /* 母版已在＝从最初原图重算；另有原始 jpg 备份时说明源是哪一张（约高 2 dB） */
            if (d && d.hasBase) {
                html += d.srcOrig
                    ? '<br><b>画质</b>：从<b>原始 jpg</b> 重算（损失只发生一次），不会在上一代成品上继续叠加'
                    : '<br><b>画质</b>：从最初的原图基准重算，不会在上一代成品上继续叠加损失';
            } else if (d && d.srcOrig) {
                html += '<br><b>画质</b>：本次会改用<b>原始 jpg</b> 重算（损失只发生一次），不会叠加到当前成品上';
            }
        }
        box.hidden = false;
        box.innerHTML = html;
    }

    function doLbRotSave(saveas) {
        var ang = lbRot % 360;
        if (!ang || !lbCid) { return; }
        closeModal('fmRotSaveModal');
        var cid = lbCid, el = lbEl;
        var content = el.querySelector('#fmLbContent');
        var fin = false;
        /* 收尾统一出口：撤蒙版 + 刷新标题/下载链接/EXIF，避免遮罩挂死 */
        var finish = function () {
            if (fin) { return; }
            fin = true;
            lbSaving(false);
            loadFiles(); /* 刷新列表缩略图与大小（新 URL 天然绕过缓存） */
            if (!lbEl || lbCid !== cid) { return; } /* 保存途中翻图/关灯箱：标题、下载链接、EXIF 都属于别的图，别改 */
            var cap = el.querySelector('#fmLbCaption');
            if (cap && fileMap[cid]) { cap.textContent = lbCapText(cid); }
            var dl = el.querySelector('#fmLbDl');
            if (dl && fileMap[cid]) { dl.href = fileMap[cid].url; dl.setAttribute('download', fileMap[cid].name); }
            if (fileMap[cid]) { delete exifCache[cid]; loadExif(cid); } /* EXIF 已随重编码丢失，刷新摘要条 */
        };
        /* 用全新 <img> 重建画面：旧元素已摘出文档，不可能出现「旧图被转动」的帧 */
        var reload = function (url, toZero) {
            var img = new Image();
            var armed = false;
            var commit = function () {
                if (armed) { return; }
                armed = true;
                img.onload = null;
                img.onerror = null;
                if (!lbEl || lbCid !== cid) { finish(); return; } /* 保存途中翻图/关灯箱：别把别人的画面顶掉 */
                if (toZero) { lbRot = 0; lbPanX = 0; lbPanY = 0; }
                img.alt = (fileMap[cid] && fileMap[cid].name) || '';
                img.draggable = false; /* 禁用浏览器原生图片拖拽，让按住拖动变成平移 */
                if (img.naturalWidth) { lbDimCache[cid] = img.naturalWidth + '×' + img.naturalHeight; }
                content.innerHTML = '';
                content.appendChild(img);
                /* 打印真正请求的绝对地址：不含 .fmcache 就证明灯箱没读过缓存目录 */
                if (window.console) {
                    console.log('[FileManager] 灯箱实际加载：' + (img.currentSrc || img.src || url));
                }
                lbApplyView(); /* 元素已在文档里、位图已解码，这里读 clientWidth 拿到的是新尺寸 */
                syncLbRotSave();
                /* 连等两帧再揭蒙版，保证揭开时新位图已经画过一次 */
                requestAnimationFrame(function () { requestAnimationFrame(finish); });
            };
            var settle = function () {
                /* decode() 保证「解码完成、可立即绘制」，比 load 更贴近真正上屏的时刻 */
                if (typeof img.decode !== 'function') { commit(); return; }
                var p;
                try { p = img.decode(); } catch (e) { commit(); return; }
                if (!p || typeof p.then !== 'function') { commit(); return; }
                p.then(commit, commit); /* 解码失败也照样呈现，别把遮罩挂死 */
            };
            img.onload = settle;
            img.onerror = commit; /* 取图失败也别把遮罩挂死 */
            img.src = url;
            if (img.complete && img.naturalWidth) { settle(); } /* 缓存命中时 load 可能不再触发 */
            setTimeout(commit, 8000); /* 极端情况下事件不触发时的兜底 */
        };
        /* 失败：把摘掉的元素原样挂回，transform 与位图都没动过 */
        var restore = function () {
            if (!lbEl || lbCid !== cid) { return lbSaving(false); } /* 已切图/关灯箱：灯箱里是别人的画面 */
            if (!cur) { return finish(); }
            content.innerHTML = '';
            content.appendChild(cur);
            lbApplyView(); /* clientWidth 要在入文档之后再读 */
            syncLbRotSave();
            requestAnimationFrame(function () { requestAnimationFrame(function () { lbSaving(false); }); }); /* 原图画上屏后再撤蒙版 */
        };
        /* 先整屏盖上；原地保存再把图片元素摘掉，消除能显示旧位图的载体 */
        var cur = saveas ? null : content.querySelector('img');
        lbSaving(true, '正在保存...', true);
        if (cur) { content.innerHTML = ''; }
        var form = new FormData();
        form.append('cid', cid);
        form.append('angle', ang);
        form.append('rename', $('fmRotRename').checked ? '1' : '0');
        form.append('refs', $('fmRotRefs').checked ? '1' : '0');
        if (saveas) { form.append('saveas', '1'); }
        /* 大图重编码本来就慢，给它足够的时间；超时也只是「先不等了」，服务端会继续跑完 */
        api('imageRotateSave', form, { timeout: 180000 }).then(function (data) {
            if (saveas) {
                var tip2 = '已另存为新图片' + (data && data.name ? '「' + data.name + '」' : '');
                if (data && data.label) { tip2 += '（' + data.label + '）'; }
                else if (data && data.lossless) { tip2 += '（无损旋转）'; }
                toast(tip2);
                lbSaving(false);
                loadFiles(); /* 列表出现新图片；灯箱保持原图预览不变 */
                return;
            }
            /* 原地保存不弹底部提示，遮罩撤掉看到的就是成品 */
            lbSaving(true, '已保存，正在重新加载图片...', true);
            /* channel=restore 仅 RESTORE_ON_ZERO=true 时出现（当前关闭），保留以便开关一开即生效 */
            if (data && 'restore' === data.channel) {
                toast('已转回原位：按原始字节还原，未重新编码（体积回到 '
                    + fmtSize(data.size) + '，画质与最初完全一致）', 8000);
            }
            /* 不自造时间戳：版本号一律按文件元数据算，与刷新后一致 */
            /* 文件内容已变：探测结果依赖容器头（webp 是否仍为无损、动图标志），下次开弹窗必须重探 */
            delete rotProbeCache[cid];
            var oldUrl = fileMap[cid] ? (fileMap[cid].url || '') : '';
            if (data && data.url && fileMap[cid]) {
                var cf = fileMap[cid];
                cf.url = data.url;
                cf.name = data.name || cf.name;
                cf.size = data.size || cf.size;
                if (data.mtime) { cf.mtime = data.mtime; } /* 版本号跟着走，下次开灯箱不会拿旧缓存 */
                lbList.forEach(function (it) {
                    if (it.cid === cid) { it.url = cf.url; it.name = cf.name; it.size = cf.size; it.mtime = cf.mtime; }
                });
            }
            /* 打印这行即可区分：后端没换名 / 灯箱用旧地址 / 地址新但内容不对 */
            if (window.console && data && data.url) {
                console.log('[FileManager] 旋转已保存 → ' + data.path + '　v=' + verOf(fileMap[cid] || {})
                    + (data.label ? '（' + data.label + '）' : '')
                    + (data.enc && data.enc.q ? '　q=' + data.enc.q + '（' + (data.enc.chan || '?') + ' 通道）' : '')
                    + (data.enc && data.enc.err ? '　⚠ ' + data.enc.err : '')
                    + (data.baseSize ? '　质量基线=' + fmtSize(data.baseSize) : '')
                    + (data.srcSize && data.size ? '　本次输入 ' + fmtSize(data.srcSize) + ' → ' + fmtSize(data.size) : '')
                    + (data.baseKept
                        ? (data.srcOrig
                            ? '　基准=保留　源=原始 jpg（损失只发生一次）'
                            : '　基准=保留　源=当前成品（损失只发生一次）')
                        : '　基准=无（已还原为原始字节）')
                    + (oldUrl && data.url === oldUrl ? '　（原地覆盖：地址不变，仅版本号变化）' : ''));
            }
            /* 目标地址须与刷新后重开灯箱算出的完全一致，才能命中保存前那次请求的缓存 */
            var target = (fileMap[cid] && fileMap[cid].url) ? imgSrc(fileMap[cid])
                : ((data && data.url) ? data.url : '');
            if (!target) { restore(); return; } /* 后端没给地址：别把灯箱留空 */
            reload(target, true);
        }).catch(function (e) {
            /* 失败原因 api() 已经弹过。原地保存时图已被摘掉，必须把原元素按原角度挂回去，灯箱不能空着 */
            if (e && e.name === 'AbortError') {
                toast('等待超时：服务端仍在继续处理，过一会儿刷新列表看看这张图是否已经转好', 8000);
            }
            if (saveas) { return lbSaving(false); }
            restore();
        });
    }

    function closeLightbox() {
        if (!lbEl || lbEl.hidden || lbClosing) { return; }
        lbClosing = true;
        lbEl.classList.add('fm-lb-closing');
        setTimeout(function () {
            lbEl.classList.remove('fm-lb-closing');
            lbEl.querySelector('#fmLbContent').innerHTML = ''; /* 卸载元素即停止视频播放 */
            var m = lbEl.querySelector('.fm-lb-saving'); /* 遮罩挂在灯箱根上，关灯箱必须清掉，否则下次打开还挂着 */
            if (m) { m.parentNode.removeChild(m); }
            lbCid = 0;
            document.body.style.overflow = lbPrevOverflow;
            lbEl.hidden = true;
            lbClosing = false;
        }, 170);
    }

    function loadExif(cid) {
        if (!cid) { return; }
        if (exifCache[cid]) { renderExif(cid); renderExifLine(cid); return; }
        api('exif', { cid: cid }).then(function (d) {
            exifCache[cid] = d;
            renderExif(cid);
            renderExifLine(cid);
        }).catch(function () {
            exifCache[cid] = { items: [], source: '', note: 'EXIF 读取失败，请稍后重试' };
            renderExif(cid);
        });
    }

    function renderExif(cid) {
        if (!lbEl || lbCid !== cid) { return; } /* 已切到别的图/灯箱已关（lbCid=0），丢弃过期渲染 */
        var panel = lbEl.querySelector('#fmLbExif');
        if (panel.hidden) { return; } /* 面板未展开时静默等用户点开 */
        var d = exifCache[cid];
        if (!d) {
            panel.innerHTML = '<div class="fm-exif-note">EXIF 读取中…</div>';
            return;
        }
        var html = '';
        (d.items || []).forEach(function (it) {
            html += '<div class="fm-exif-row"><span class="fm-exif-label">' + esc(it[0])
                + '</span><span class="fm-exif-value">' + esc(it[1]) + '</span></div>';
        });
        if (d.note) { html += '<div class="fm-exif-note">' + esc(d.note) + '</div>'; }
        if (d.source) { html += '<div class="fm-exif-src">数据来源：' + esc(d.source) + '</div>'; }
        panel.innerHTML = html || '<div class="fm-exif-note">没有可显示的 EXIF 信息</div>';
    }

    function buildLightbox() {
        lbEl = document.createElement('div');
        lbEl.id = 'fmLightbox';
        lbEl.hidden = true;
        lbEl.innerHTML = '<div class="fm-lb-backdrop"></div>'
            + '<button type="button" class="fm-lb-close" title="关闭（Esc）">×</button>'
            + '<button type="button" class="fm-lb-nav fm-lb-prev" id="fmLbPrev" title="上一张（←）" hidden>‹</button>'
            + '<button type="button" class="fm-lb-nav fm-lb-next" id="fmLbNext" title="下一张（→）" hidden>›</button>'
            + '<button type="button" class="fm-lb-exif-btn" id="fmLbExifBtn" title="查看拍摄参数（EXIF）">EXIF</button>'
            + '<div class="fm-lb-exif" id="fmLbExif" hidden></div>'
            + '<div class="fm-lb-body"><div id="fmLbContent"></div></div>'
            /* 文件信息 + EXIF 一行 + 按钮行，整摞放在 .fm-lb-body 之外并固定在页面底部：
               留在 body 里就会随图片高度跑，小图时浮在画面中间、竖图/旋转后被挤出屏幕 */
            + '<div class="fm-lb-foot">'
            + '<div class="fm-lb-caption" id="fmLbCaption"></div>'
            + '<div class="fm-lb-exifline" id="fmLbExifLine" hidden></div>'
            + '<div class="fm-lb-toolbar">'
            + '<button type="button" data-lbact="share" title="创建分享链接">分享</button>'
            + '<a href="#" download id="fmLbDl" data-lbact="download" title="下载原文件">下载</a>'
            + '<button type="button" data-lbact="edit" title="编辑标题 / 缩略名">编辑</button>'
            + '<button type="button" id="fmLbWebp" data-lbact="towebp" title="转换为 WebP（引用链接同步替换）" hidden>转 WebP</button>'
            + '<button type="button" data-lbact="rotate" title="旋转预览（R，不修改文件）">旋转</button>'
            + '<button type="button" data-lbact="rotsave" title="把当前旋转角度保存为新文件名（同步替换引用）" hidden>保存旋转</button>'
            + '<button type="button" class="fm-lb-act-del" data-lbact="del" title="删除此文件（Delete）">删除</button>'
            + '</div></div>';
        document.body.appendChild(lbEl);
        lbEl.querySelector('.fm-lb-backdrop').addEventListener('click', closeLightbox);
        lbEl.querySelector('.fm-lb-close').addEventListener('click', closeLightbox);
        lbEl.querySelector('#fmLbPrev').addEventListener('click', function () { lbNav(-1); });
        lbEl.querySelector('#fmLbNext').addEventListener('click', function () { lbNav(1); });
        lbEl.querySelector('.fm-lb-toolbar').addEventListener('click', function (e) {
            var el = e.target.closest('[data-lbact]');
            if (!el || !lbCid) { return; }
            var act = el.getAttribute('data-lbact');
            if (act === 'share') { openShareModal(lbCid); }
            if (act === 'edit') { openEditModal(lbCid); }
            if (act === 'towebp') { lbConvertWebp(lbCid, el); }
            if (act === 'rotate') {
                lbRotate90();
            }
            if (act === 'rotsave') { lbRotSave(); }
            if (act === 'del') {
                deleteFiles([lbCid], function () { closeLightbox(); });
            }
        });
        lbEl.addEventListener('wheel', function (e) {
            if (e.target.closest('.fm-lb-exif')) { return; }
            var img = lbEl.querySelector('#fmLbContent img');
            if (!img || lbEl.hidden) { return; }
            e.preventDefault();
            var old = lbZoom;
            lbZoom = Math.min(10, Math.max(1, lbZoom * (e.deltaY < 0 ? 1.25 : 0.8)));
            if (lbZoom <= 1) {
                lbZoom = 1;
                lbPanX = 0; lbPanY = 0; /* 缩回最小（1 倍）时自动重置图片位置 */
            } else {
                lbPanX = lbPanX * lbZoom / old;
                lbPanY = lbPanY * lbZoom / old;
            }
            lbApplyView();
        }, { passive: false });
        lbEl.addEventListener('mousedown', function (e) {
            var img = lbEl.querySelector('#fmLbContent img');
            if (!img || e.target !== img || lbZoom <= 1) { return; }
            e.preventDefault();
            var sx = e.clientX, sy = e.clientY, ox = lbPanX, oy = lbPanY;
            img.classList.add('fm-dragging');
            function mv(ev) {
                lbPanX = ox + ev.clientX - sx;
                lbPanY = oy + ev.clientY - sy;
                lbApplyView();
            }
            function up() {
                img.classList.remove('fm-dragging');
                document.removeEventListener('mousemove', mv);
                document.removeEventListener('mouseup', up);
            }
            document.addEventListener('mousemove', mv);
            document.addEventListener('mouseup', up);
        });
        lbEl.addEventListener('dblclick', function (e) {
            var img = lbEl.querySelector('#fmLbContent img');
            if (!img || e.target !== img) { return; }
            lbZoom = lbZoom > 1 ? 1 : 2.5;
            lbPanX = 0; lbPanY = 0;
            lbApplyView();
        });
        lbEl.querySelector('#fmLbExifBtn').addEventListener('click', function () {
            var panel = lbEl.querySelector('#fmLbExif');
            panel.hidden = !panel.hidden;
            this.classList.toggle('on', !panel.hidden);
            if (!panel.hidden) { renderExif(lbCid); }
        });
        document.addEventListener('keydown', function (e) {
            if (!lbEl || lbEl.hidden) { return; }
            if (document.querySelector('.fm-modal:not([hidden])')) { return; }
            if (e.key === 'Escape') { closeLightbox(); return; }
            var tag = e.target && e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') { return; }
            if (e.key === 'ArrowLeft') { lbNav(-1); }
            if (e.key === 'ArrowRight') { lbNav(1); }
            if (e.key === 'r' || e.key === 'R') {
                lbRotate90();
            }
            if ((e.key === 'Delete' || e.key === 'Backspace') && lbCid) {
                deleteFiles([lbCid], function () { closeLightbox(); });
            }
        });
    }

    function deleteFiles(cids, onDeleted) {
        if (!window.confirm('确定删除选中的 ' + cids.length + ' 个附件吗？\n将同时删除物理文件，此操作不可恢复！')) { return; }
        api('fileDelete', { cids: cids.join(',') }).then(function (data) {
            cids.forEach(function (cid) { delete state.selected[cid]; });
            if (onDeleted) { onDeleted(); }
            updateBatchBar();
            toast(data.message);
            loadFolders();
            loadFiles();
        });
    }

    var uploadPending = [];

    function fillUploadFolderSelect() {
        var sel = $('fmUploadFolder');
        var html = '<option value="0">未分类</option>';
        (function walk(pid, depth) {
            state.folders.forEach(function (f) {
                if (f.parent !== pid) { return; }
                var indent = '';
                for (var d = 0; d < depth; d++) { indent += '　'; }
                html += '<option value="' + f.fid + '">' + indent + esc(f.name) + '</option>';
                walk(f.fid, depth + 1);
            });
        })(0, 0);
        sel.innerHTML = html;
        var prefFid = effectiveUploadFid();
        if (prefFid > 0) {
            sel.value = String(prefFid);
        } else {
            sel.value = String(state.fid > 0 ? state.fid : 0);
        }
        if (sel.selectedIndex === -1) { sel.value = '0'; }
    }

    function setUploadFiles(files) {
        uploadPending = files || [];
        $('fmUploadInfo').textContent = uploadPending.length
            ? ('已选择 ' + uploadPending.length + ' 个文件，点击「开始上传」')
            : '';
        $('fmUploadStart').disabled = !uploadPending.length;
    }

    $('fmUploadBtn').addEventListener('click', function () {
        var ihReady = imageHostReady();
        Array.prototype.forEach.call($('fmUploadDest').options, function (o) {
            o.hidden = (o.value === 'imagehost' && !ihReady);
        });
        $('fmUploadDest').value = (ihReady && prefs.defaultUploadDest === 'imagehost') ? 'imagehost' : 'site';
        fmUploadApplyDest();
        $('fmUploadTo').value = prefs.uploadConv;
        if ($('fmUploadTo').selectedIndex === -1) { $('fmUploadTo').value = ''; }
        $('fmUploadRenameDt').checked = prefs.uploadRenameDt === '1';
        fillUploadFolderSelect();
        setUploadFiles([]);
        openModal('fmUploadModal');
    });

    function fmUploadApplyDest() {
        var isIh = $('fmUploadDest').value === 'imagehost';
        $('fmUploadKeepField').hidden = !isIh;
        var s3All = imageHostConf().type === 's3';
        $('fmUploadInput').accept = (isIh && !s3All) ? 'image/*' : '';
    }
    $('fmUploadDest').addEventListener('change', fmUploadApplyDest);

    $('fmUploadPick').addEventListener('click', function () { $('fmUploadInput').click(); });

    $('fmUploadInput').addEventListener('change', function () {
        setUploadFiles(Array.prototype.slice.call(this.files));
        this.value = '';
    });

    (function () {
        var drop = $('fmUploadDrop');
        ['dragover', 'dragenter'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('dragover'); });
        });
        ['dragleave', 'dragend'].forEach(function (ev) {
            drop.addEventListener(ev, function () { drop.classList.remove('dragover'); });
        });
        drop.addEventListener('drop', function (e) {
            e.preventDefault();
            drop.classList.remove('dragover');
            if (e.dataTransfer && e.dataTransfer.files.length) {
                setUploadFiles(Array.prototype.slice.call(e.dataTransfer.files));
            }
        });
    })();

    $('fmUploadStart').addEventListener('click', function () {
        if (!uploadPending.length) { return; }
        this.disabled = true;
        var toImageHost = $('fmUploadDest').value === 'imagehost';
        var keep = toImageHost ? $('fmUploadKeep').value : '';
        var fid = parseInt($('fmUploadFolder').value, 10) || 0;
        var ok = 0, fail = 0, ihOk = 0;
        var ihUrls = [];                         /* 图床返回的外链，完成时展示 */
        var failMsgs = [];                       /* 失败原因（文件名：原因），展示用 */
        var okFlags = uploadPending.map(function () { return false; });

        function finish() {
            if (ok > 0) { latestUploadFid = fid; } /* 「最近上传文件所在的文件夹」缓存刷新 */
            if (fail) {
                toast('上传完成：成功 ' + ok + ' 个，失败 ' + fail + ' 个');
                $('fmUploadInfo').textContent = failMsgs.join('；') + '（点「开始上传」仅重试失败文件）';
                uploadPending = uploadPending.filter(function (f, idx) {
                    return !okFlags[idx];
                });
                $('fmUploadStart').disabled = !uploadPending.length;
            } else {
                if (toImageHost && keep === 'del' && ihOk > 0 && ihOk === ok) {
                    toast('已上传到图床/对象存储 ' + ihOk + ' 个文件');
                    $('fmUploadInfo').textContent = '外链：' + ihUrls.join('　')
                        + '（原图已删除，文件记录保留在列表）';
                    uploadPending = [];
                    $('fmUploadStart').disabled = true;
                    loadFiles(); /* 记录保留在列表，刷新便于后续管理 */
                    return; /* 不关弹窗方便复制外链 */
                }
                toast('已成功上传 ' + ok + ' 个文件'
                    + (toImageHost && ihOk < ok ? '（非图片文件仅上传到本站）' : ''));
                closeModal('fmUploadModal');
            }
            loadFiles();
        }

        function next(i) {
            if (i >= uploadPending.length) { finish(); return; }
            $('fmUploadInfo').textContent = '正在上传 ' + (i + 1) + ' / ' + uploadPending.length + ' …';
            var f = uploadPending[i];
            var isImg = (toImageHost && imageHostConf().type === 's3') || /^image\//.test(f.type || '');
            var body = new FormData();
            body.append('do', 'upload');
            body.append('fid', fid);
            body.append('renameDt', $('fmUploadRenameDt').checked ? '1' : '0');
            body.append('uploadTo', $('fmUploadTo').value);
            body.append('uploadWm', $('fmUploadWm').checked ? '1' : '0');
            body.append('imgCtl', '1'); /* 明确按弹窗选项执行，取消勾选水印也要覆盖插件设置 */
            body.append('file', f);
            fetch(cfg.url, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (d1) {
                    if (!(d1 && d1.code === 200 && d1.cid)) {
                        fail++;
                        failMsgs.push((f.name || ('文件' + (i + 1))) + '：'
                            + (d1 && d1.message ? d1.message : '本站上传失败'));
                        return;
                    }
                    if (!toImageHost || !isImg) { okFlags[i] = true; ok++; return; }
                    var body2 = new FormData();
                    body2.append('do', 'imageHostUpload');
                    body2.append('cid', String(d1.cid));
                    body2.append('delLocal', keep === 'del' ? '1' : '0');
                    body2.append('useUrl', keep === 'keep-site' ? 'site' : 'ih');
                    return fetch(cfg.url, { method: 'POST', body: body2, credentials: 'same-origin' })
                        .then(function (res2) { return res2.json(); })
                        .then(function (d2) {
                            if (d2 && d2.code === 200 && d2.url) {
                                okFlags[i] = true; ok++; ihOk++;
                                ihUrls.push(d2.url);
                            } else {
                                fail++;
                                failMsgs.push((f.name || ('文件' + (i + 1))) + '：'
                                    + (d2 && d2.message ? d2.message : '转发图床失败'));
                            }
                        });
                })
                .catch(function () {
                    fail++;
                    failMsgs.push((f.name || ('文件' + (i + 1))) + '：请求未到达服务器');
                })
                .then(function () { next(i + 1); });
        }

        next(0);
    });

    (function () {
        function stampName(ext, seq) {
            var d = new Date();
            function p(n) { return (n < 10 ? '0' : '') + n; }
            var name = '截图-' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate())
                + '-' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
            return name + (seq > 1 ? '-' + seq : '') + '.' + ext;
        }

        function extOfType(type) {
            var m = String(type).match(/^image\/(png|jpeg|webp|gif|bmp)$/i);
            if (!m) { return 'png'; }
            return m[1].toLowerCase() === 'jpeg' ? 'jpg' : m[1].toLowerCase();
        }

        document.addEventListener('paste', function (e) {
            if (!e.clipboardData || !e.clipboardData.items) { return; }
            var files = [];
            for (var i = 0; i < e.clipboardData.items.length; i++) {
                var it = e.clipboardData.items[i];
                if (it.kind === 'file' && String(it.type).indexOf('image/') === 0) {
                    var f = it.getAsFile();
                    if (f) { files.push(f); }
                }
            }
            if (!files.length) { return; }
            var t = e.target;
            var editable = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
            if (editable && String(e.clipboardData.getData('text/plain') || '').length) { return; }

            e.preventDefault();

            var startPaste = function () {
            var fid = effectivePasteFid();
            var fname = '未分类';
            if (fid > 0) {
                state.folders.forEach(function (f) { if (f.fid === fid) { fname = f.name; } });
            }
            var ok = 0, okNames = [], failMsgs = [], remaining = files.length;
            toast(files.length > 1
                ? '正在上传 ' + files.length + ' 张截图到「' + fname + '」…'
                : '正在上传截图到「' + fname + '」…');

            files.forEach(function (blob, idx) {
                var ext = extOfType(blob.type);
                var named = new File([blob], stampName(ext, idx + 1), { type: blob.type });
                var body = new FormData();
                body.append('do', 'upload');
                body.append('fid', fid);
                body.append('file', named);
                fetch(cfg.url, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (res) {
                        return res.text().then(function (text) {
                            var data = null;
                            try { data = JSON.parse(text); } catch (err) {}
                            if (data && data.code === 200) {
                                ok++;
                                okNames.push(named.name);
                                return;
                            }
                            var reason = (data && data.message)
                                ? data.message
                                : ('HTTP ' + res.status + '：'
                                    + String(text).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120));
                            failMsgs.push(named.name + '：' + reason);
                        });
                    })
                    .catch(function () {
                        failMsgs.push(named.name + '：请求未到达服务器');
                    })
                    .then(function () {
                        remaining--;
                        if (remaining > 0) { return; }
                        if (ok > 0) { latestUploadFid = fid; } /* 「最近上传」缓存刷新 */
                        if (ok && !failMsgs.length) {
                            toast(ok > 1 ? ('已上传 ' + ok + ' 张截图') : ('已上传截图：' + okNames[0]));
                        } else {
                            toast('截图上传：成功 ' + ok + ' 张，失败 ' + failMsgs.length + ' 张（' + failMsgs.join('；') + '）');
                        }
                        if (ok > 0) {
                            if (fid !== state.fid) { selectFolder(fid); }
                            else { loadFiles(); }
                        } else {
                            loadFiles();
                        }
                    });
            });
            };
            if (prefs.pasteMode === 'latest' && latestUploadFid <= 0) {
                fetchLatestFid().then(startPaste);
            } else {
                startPaste();
            }
        });
    })();

    var webpCids = [], webpRunning = false, webpCancel = false;

    var webpSrcMap = { webp: 'jpg/jpeg/png/bmp', jpg: 'webp/png/bmp', png: 'jpg/jpeg/webp/bmp' };

    function setWebpInfo(msg, state) {
        var el = $('fmWebpInfo');
        el.textContent = msg;
        el.style.color = state === 'ok' ? '#1e7e34' : (state === 'bad' ? '#b02418' : '');
    }

    function webpSrcText() {
        var to = $('fmWebpTo').value || 'webp';
        var exts = webpSrcMap[to] || 'jpg/jpeg/png/bmp';
        if ($('fmWebpWm').checked) {
            exts += '/' + (to === 'jpg' ? 'jpg/jpeg' : to);
        }
        return exts;
    }

    function scanWebp() {
        var scope = $('fmWebpScope').value;
        var params = { scope: scope, to: $('fmWebpTo').value, wm: $('fmWebpWm').checked ? 1 : 0 };
        if (scope === 'folder') { params.fid = state.fid; }
        if (scope === 'selected') { params.cids = Object.keys(state.selected).join(','); }
        $('fmWebpInfo').textContent = '正在扫描…';
        $('fmWebpInfo').style.color = '';
        $('fmWebpStart').disabled = true;
        webpCids = [];
        api('batchWebpScan', params).then(function (d) {
            webpCids = d.items || [];
            var total = 0;
            webpCids.forEach(function (it) { total += it.size || 0; });
            setWebpInfo(webpCids.length
                ? ('找到 ' + webpCids.length + ' 张可转换图片，当前共 ' + fmtSize(total))
                : '没有可转换的图片（目标 ' + ($('fmWebpTo').value || 'webp').toUpperCase()
                  + ' 仅支持 ' + webpSrcText() + '）', webpCids.length ? 'ok' : 'bad');
            $('fmWebpStart').disabled = !webpCids.length || webpRunning;
            if (d.cfg) {
                if (!$('fmWebpQuality').value) { $('fmWebpQuality').value = d.cfg.quality || ''; }
                if (!$('fmWebpMaxw').value) { $('fmWebpMaxw').value = d.cfg.max_width || ''; }
                if (!$('fmWebpMaxh').value) { $('fmWebpMaxh').value = d.cfg.max_height || ''; }
                $('fmWebpPolicy').value = d.cfg.size_policy === 'continue' ? 'continue' : 'keep';
                $('fmWebpOrigin').value = d.cfg.origin_policy === 'backup' ? 'backup' : 'delete';
            }
        }).catch(function () {
            setWebpInfo('扫描失败，请重试', 'bad');
        });
    }

    $('fmWebpBtn').addEventListener('click', function () {
        $('fmWebpScope').value = Object.keys(state.selected).length > 0
            ? 'selected' : (state.fid > 0 ? 'folder' : 'all');
        $('fmWebpRefs').checked = true;
        $('fmWebpWm').checked = false;
            $('fmWebpInfo').textContent = '';
            $('fmWebpInfo').style.color = '';
            $('fmWebpProgress').textContent = '';
            $('fmWebpStart').textContent = '开始转换';
            webpCancel = false;
            syncWebpPolicy();
            openModal('fmWebpModal');
            scanWebp();
        });

    function syncWebpPolicy() {
        $('fmWebpPolicy').disabled = $('fmWebpWm').checked;
    }

    $('fmWebpScope').addEventListener('change', scanWebp);
    $('fmWebpTo').addEventListener('change', scanWebp);
    $('fmWebpWm').addEventListener('change', function () { syncWebpPolicy(); scanWebp(); });

    $('fmWebpModal').addEventListener('click', function (e) {
        if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-fm-close')) {
            webpCancel = true;
        }
    });

    $('fmRotSaveOk').addEventListener('click', function () { doLbRotSave(false); });
    $('fmRotSaveAs').addEventListener('click', function () { doLbRotSave(true); });

    $('fmWebpStart').addEventListener('click', function () {
        if (!webpCids.length || webpRunning) { return; }
        webpRunning = true;
        webpCancel = false;
        var btn = this;
        btn.disabled = true;
        btn.textContent = '转换中…';
        var refs = $('fmWebpRefs').checked ? 1 : 0;
        var wm = $('fmWebpWm').checked ? 1 : 0;
        var batchQ = parseInt($('fmWebpQuality').value, 10);
        var batchMw = parseInt($('fmWebpMaxw').value, 10);
        var batchMh = parseInt($('fmWebpMaxh').value, 10);
        var batchParams = {
            to: $('fmWebpTo').value,
            renameDt: $('fmWebpRenameDt').checked ? '1' : '0',
            policy: $('fmWebpPolicy').value === 'continue' ? 'continue' : 'keep',
            origin: $('fmWebpOrigin').value === 'backup' ? 'backup' : 'delete'
        };
        if (batchQ >= 1 && batchQ <= 100) { batchParams.quality = batchQ; }
        if (batchMw > 0) { batchParams.maxw = batchMw; }
        if (batchMh > 0) { batchParams.maxh = batchMh; }
        var toTxt = ($('fmWebpTo').value || 'webp').toUpperCase();
        var i = 0, ok = 0, delta = 0, refN = 0, skips = [];

        function finish() {
            webpRunning = false;
            btn.disabled = false;
            btn.textContent = '开始转换';
            $('fmWebpProgress').textContent = webpCancel ? '已停止（已完成的部分不受影响）' : '';
            var sizePart = delta > 0 ? ('节省约 ' + fmtSize(delta))
                : (delta < 0 ? ('体积增大 ' + fmtSize(-delta)) : '体积几乎无变化');
            var msg = ok ? ('已转换 ' + ok + ' 张为 ' + toTxt + '，' + sizePart) : '没有图片被转换';
            if (refN) { msg += '，替换引用 ' + refN + ' 处'; }
            if (skips.length) {
                msg += '；跳过 ' + skips.length + ' 张（' + skips.slice(0, 3).join('；')
                    + (skips.length > 3 ? ' 等' : '') + '）';
            }
            setWebpInfo(msg, ok > 0 ? 'ok' : 'bad');
            toast(msg);
            loadFiles();
        }

        function step() {
            if (webpCancel || i >= webpCids.length) { finish(); return; }
            var it = webpCids[i];
            $('fmWebpProgress').textContent = '正在转换 ' + (i + 1) + ' / ' + webpCids.length
                + '：' + (it.name || ('CID ' + it.cid));
            api('batchWebpOne', Object.assign({ cid: it.cid, wm: wm, refs: refs }, batchParams)).then(function (d) {
                if (d.status === 'ok') {
                    ok++;
                    delta += (d.oldSize || 0) - (d.newSize || 0); /* 可为负：转换后变大要如实汇报 */
                    refN += (d.refs || 0);
                } else {
                    skips.push((it.name || ('CID ' + it.cid)) + '：' + (d.reason || '跳过'));
                }
                i++;
                step();
            }).catch(function (e) {
                /* api() 报错时已经 toast 过真实原因，这里别再写死「请求失败」把它盖掉 */
                var why = (e && e.message) ? String(e.message) : '请求失败';
                skips.push((it.name || ('CID ' + it.cid)) + '：' + why);
                i++;
                step();
            });
        }

        step();
    });

    var shareCid = 0;
    var shareEditId = 0; // 0 = 创建模式；>0 = 编辑该分享

    /* 视图复位：创建成功后表单会被收起，再打开时必须恢复，否则整张表单消失 */
    function shareResetView() {
        var card = $('fmShareCard');
        card.classList.remove('fm-share-done');
        card.scrollTop = 0;
        $('fmShareResult').hidden = true;
        $('fmShareLink').value = '';
        $('fmShareCancel').textContent = '取消';
        $('fmShareCreateBtn').hidden = false;
    }

    function openShareModal(cid) {
        shareCid = cid;
        shareEditId = 0;
        shareResetView();
        $('fmShareModalTitle').textContent = '创建分享链接';
        $('fmShareDisplayName').value = '';
        $('fmShareToken').value = '';
        $('fmShareTokenLabel').textContent = '自定义链接（留空则系统随机生成）';
        $('fmShareNote').value = '';
        $('fmSharePassword').value = '';
        $('fmSharePwdLabel').textContent = '提取密码（留空则无需密码）';
        $('fmSharePassword').placeholder = '可留空';
        $('fmShareExpire').value = '0';
        $('fmShareMax').value = cfg.defaultMax;
        $('fmShareLogin').checked = false;
        $('fmSharePreview').checked = false;
        $('fmShareCreateBtn').hidden = false;
        $('fmShareUpdateBtn').hidden = true;
        $('fmShareCreateBtn').disabled = false;
        var nameEl = document.querySelector('[data-cid="' + cid + '"] .fm-name, [data-cid="' + cid + '"] .fm-name-cell');
        $('fmShareFileName').textContent = nameEl ? nameEl.textContent.replace(/^\s+/, '') : ('CID: ' + cid);
        openModal('fmShareModal');
    }

    function openShareEditModal(s) {
        shareCid = 0;
        shareEditId = s.id;
        shareResetView();
        $('fmShareModalTitle').textContent = '编辑分享链接';
        $('fmShareDisplayName').value = s.displayName || '';
        $('fmShareToken').value = s.token || '';
        $('fmShareTokenLabel').textContent = '自定义链接（修改后旧链接立即失效）';
        $('fmShareNote').value = s.note || '';
        $('fmSharePassword').value = '';
        $('fmSharePwdLabel').textContent = s.hasPwd ? '提取密码（当前已设置，留空则清除）' : '提取密码（当前无密码，留空则无需密码）';
        $('fmSharePassword').placeholder = s.hasPwd ? '输入新密码可替换' : '可留空';
        $('fmShareExpire').value = 'keep';
        $('fmShareMax').value = s.max > 0 ? s.max : '0';
        $('fmShareLogin').checked = !!s.loginRequired;
        $('fmSharePreview').checked = !!s.preview;
        $('fmShareCreateBtn').hidden = true;
        $('fmShareUpdateBtn').hidden = false;
        $('fmShareUpdateBtn').disabled = false;
        $('fmShareFileName').textContent = (s.displayName ? s.displayName + '（原文件名：' + s.title + '）' : s.title);
        openModal('fmShareModal');
    }

    $('fmShareCreateBtn').addEventListener('click', function () {
        $('fmShareCreateBtn').disabled = true;
        api('shareCreate', {
            cid: shareCid,
            password: $('fmSharePassword').value,
            expire: $('fmShareExpire').value === 'keep' ? '0' : $('fmShareExpire').value,
            maxDownloads: $('fmShareMax').value || '0',
            loginRequired: $('fmShareLogin').checked ? '1' : '0',
            preview: $('fmSharePreview').checked ? '1' : '0',
            displayName: $('fmShareDisplayName').value,
            token: $('fmShareToken').value,
            note: $('fmShareNote').value
        }).then(function (data) {
            var card = $('fmShareCard');
            $('fmShareLink').value = data.url || '';
            $('fmShareResult').hidden = false;
            card.classList.add('fm-share-done');
            card.scrollTop = 0; /* 之前可能正滚在表单底部，回到顶部才看得到链接 */
            $('fmShareModalTitle').textContent = '分享链接已创建';
            /* 表单已收起，创建按钮留着会被误点；下次打开由 shareResetView() 复位 */
            $('fmShareCreateBtn').hidden = true;
            $('fmShareCreateBtn').disabled = false;
            $('fmShareCancel').textContent = '关闭';
            toast('分享链接已创建');
        }).catch(function () { $('fmShareCreateBtn').disabled = false; });
    });

    $('fmShareUpdateBtn').addEventListener('click', function () {
        $('fmShareUpdateBtn').disabled = true;
        api('shareUpdate', {
            id: shareEditId,
            password: $('fmSharePassword').value,
            expire: $('fmShareExpire').value,
            maxDownloads: $('fmShareMax').value || '0',
            loginRequired: $('fmShareLogin').checked ? '1' : '0',
            preview: $('fmSharePreview').checked ? '1' : '0',
            displayName: $('fmShareDisplayName').value,
            token: $('fmShareToken').value,
            note: $('fmShareNote').value
        }).then(function (data) {
            toast(data.message || '分享已更新');
            closeModal('fmShareModal');
            loadShareList();
        }).catch(function () { $('fmShareUpdateBtn').disabled = false; });
    });

    $('fmShareCopy').addEventListener('click', function () {
        copyText($('fmShareLink').value);
    });

    function copyText(text) {
        function done() { toast('已复制到剪贴板'); }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
        } else {
            fallbackCopy(text); done();
        }
    }
    function fallbackCopy(text) {
        var input = document.createElement('textarea');
        input.value = text;
        document.body.appendChild(input);
        input.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(input);
    }

    var shareRows = [];

    function loadShareList() {
        $('fmShareListBody').innerHTML = '<p class="fm-loading">加载中…</p>';
        api('shareList').then(function (data) {
            shareRows = data.shares || [];
            if (!shareRows.length) {
                $('fmShareListBody').innerHTML = '<p class="fm-loading">暂无分享记录</p>';
                return;
            }
            var statusText = { active: '有效', expired: '已过期', exhausted: '已达上限' };
            var rows = '';
            shareRows.forEach(function (s) {
                rows += '<div class="fm-share-item">'
                    + '<div class="fm-share-line1">'
                    + '<span class="fm-share-name">' + esc(s.title) + (s.loginRequired ? ' <span class="fm-tag">需登录</span>' : '') + '</span>'
                    + '<span class="fm-status fm-status-' + s.status + '">' + statusText[s.status] + '</span>'
                    + '</div>'
                    + (s.displayName || s.note
                        ? '<div class="fm-share-sub">'
                            + (s.displayName ? '展示名：' + esc(s.displayName) : '')
                            + (s.displayName && s.note ? '<br>' : '')
                            + (s.note ? '备注：' + esc(s.note.replace(/<[^>]*>/g, '').slice(0, 80)) + (s.note.length > 80 ? '…' : '') : '')
                            + '</div>'
                        : '')
                    + '<div class="fm-share-line2">'
                    + '<span class="fm-share-meta">有效期至 ' + (s.expire > 0 ? esc(fmtDate(s.expire)) : '永久') + '</span>'
                    + '<span class="fm-share-meta">提取密码 ' + (s.hasPwd ? '有' : '无') + '</span>'
                    + '<span class="fm-share-meta">已下载 ' + s.downloads + (s.max > 0 ? ' / ' + s.max : '') + '</span>'
                    + '<span class="fm-share-meta">浏览 ' + (s.views || 0) + '</span>'
                    + '<span class="fm-share-acts">'
                    + '<button type="button" class="btn btn-s btn-share-blue" data-copy="' + esc(s.url) + '" data-pwd="' + esc(s.password || '') + '">复制链接</button> '
                    + '<button type="button" class="btn btn-s" data-qr="' + s.id + '">二维码</button> '
                    + '<button type="button" class="btn btn-s" data-edit="' + s.id + '">编辑</button> '
                    + '<button type="button" class="btn btn-s" data-log="' + s.id + '">日志</button> '
                    + '<button type="button" class="btn btn-s btn-share-red" data-del="' + s.id + '">删除</button>'
                    + '</span></div></div>';
            });
            $('fmShareListBody').innerHTML = rows;

            Array.prototype.forEach.call($('fmShareListBody').querySelectorAll('[data-copy]'), function (btn) {
                btn.addEventListener('click', function () {
                    var text = btn.getAttribute('data-copy');
                    var pwd = btn.getAttribute('data-pwd') || '';
                    if (pwd) { text += '（提取密码：' + pwd + '）'; }
                    copyText(text);
                });
            });
            Array.prototype.forEach.call($('fmShareListBody').querySelectorAll('[data-edit]'), function (btn) {
                btn.addEventListener('click', function () {
                    var id = parseInt(btn.getAttribute('data-edit'), 10);
                    for (var i = 0; i < shareRows.length; i++) {
                        if (shareRows[i].id === id) { openShareEditModal(shareRows[i]); break; }
                    }
                });
            });
            Array.prototype.forEach.call($('fmShareListBody').querySelectorAll('[data-qr]'), function (btn) {
                btn.addEventListener('click', function () {
                    var id = parseInt(btn.getAttribute('data-qr'), 10);
                    for (var i = 0; i < shareRows.length; i++) {
                        if (shareRows[i].id !== id) { continue; }
                        if (typeof QRCode === 'undefined') { toast('二维码组件加载失败，请刷新页面重试'); return; }
                        var box = $('fmQrBox');
                        box.innerHTML = '';
                        new QRCode(box, { text: shareRows[i].url, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M });
                        $('fmQrText').textContent = shareRows[i].url;
                        openModal('fmQrModal');
                        break;
                    }
                });
            });
            Array.prototype.forEach.call($('fmShareListBody').querySelectorAll('[data-log]'), function (btn) {
                btn.addEventListener('click', function () {
                    var id = parseInt(btn.getAttribute('data-log'), 10);
                    for (var i = 0; i < shareRows.length; i++) {
                        if (shareRows[i].id === id) { openShareLogModal(shareRows[i]); break; }
                    }
                });
            });
            Array.prototype.forEach.call($('fmShareListBody').querySelectorAll('[data-del]'), function (btn) {
                btn.addEventListener('click', function () {
                    if (!window.confirm('确定删除该分享吗？删除后链接立即失效且无法恢复。')) { return; }
                    api('shareDelete', { id: btn.getAttribute('data-del') }).then(function () {
                        toast('分享已删除');
                        loadShareList();
                    });
                });
            });
        });
    }

    $('fmShareListBtn').addEventListener('click', function () {
        openModal('fmShareListModal');
        loadShareList();
    });

    var shareLogId = 0;
    var shareLogPage = 1;
    var shareLogPages = 1;

    function loadShareLogs() {
        $('fmShareLogBody').innerHTML = '<p class="fm-loading">加载中…</p>';
        api('shareLogs', { id: shareLogId, page: shareLogPage }).then(function (data) {
            var logs = data.logs || [];
            if (!logs.length) {
                $('fmShareLogBody').innerHTML = '<p class="fm-loading">暂无访问记录</p>';
            } else {
                var rows = '<table class="fm-log-table"><thead><tr><th>时间</th><th>动作</th><th>IP</th><th>User-Agent</th></tr></thead><tbody>';
                logs.forEach(function (g) {
                    var act = '浏览';
                    var cls = 'fm-log-view';
                    if ('clicked' === g.action) { act = '点击下载'; cls = 'fm-log-clicked'; }
                    if ('download' === g.action) { act = '下载完成'; cls = 'fm-log-download'; }
                    rows += '<tr><td style="white-space:nowrap">' + esc(fmtDate(g.created)) + '</td>'
                        + '<td><span class="fm-log-badge ' + cls + '">' + act + '</span></td>'
                        + '<td>' + esc(g.ip || '-') + '</td>'
                        + '<td class="fm-log-ua" title="' + esc(g.ua || '') + '">' + esc(g.ua || '-') + '</td></tr>';
                });
                rows += '</tbody></table>';
                $('fmShareLogBody').innerHTML = rows;
            }
            shareLogPage = data.page || 1;
            shareLogPages = data.pages || 1;
            $('fmShareLogPage').textContent = '第 ' + shareLogPage + ' / ' + shareLogPages + ' 页 · 共 ' + (data.total || 0) + ' 条';
            $('fmShareLogPrev').disabled = shareLogPage <= 1;
            $('fmShareLogNext').disabled = shareLogPage >= shareLogPages;
        }).catch(function () {
            $('fmShareLogBody').innerHTML = '<p class="fm-loading">加载失败，请重试</p>';
        });
    }

    function openShareLogModal(s) {
        shareLogId = s.id;
        shareLogPage = 1;
        shareLogPages = 1;
        $('fmShareLogTitle').textContent = s.displayName ? s.displayName + '（' + s.title + '）' : s.title;
        openModal('fmShareLogModal');
        loadShareLogs();
    }

    $('fmShareLogPrev').addEventListener('click', function () {
        if (shareLogPage > 1) { shareLogPage--; loadShareLogs(); }
    });
    $('fmShareLogNext').addEventListener('click', function () {
        if (shareLogPage < shareLogPages) { shareLogPage++; loadShareLogs(); }
    });

    function loadStorageStats() {
        $('fmStatsBody').innerHTML = '<p class="fm-loading">加载中…</p>';
        api('storageStats').then(function (data) {
            var total = data.total || { count: 0, size: 0 };
            var groups = data.groups || [];
            var folders = data.folders || [];
            var exts = data.exts || [];
            var top = data.top || [];
            var html = '<p class="fm-stats-total">共 <b>' + total.count + '</b> 个附件，总占用 <b>' + fmtSize(total.size) + '</b></p>';
            if (groups.length) {
                var maxSize = groups[0].size || 1;
                groups.forEach(function (g) {
                    var pct = Math.max(2, Math.round((g.size / maxSize) * 100));
                    html += '<div class="fm-stats-row"><span class="fm-stats-label" title="' + esc(g.name) + '">' + esc(g.name) + '</span>'
                        + '<span class="fm-stats-barwrap"><span class="fm-stats-bar" style="width:' + pct + '%"></span></span>'
                        + '<span class="fm-stats-val">' + fmtSize(g.size) + ' · ' + g.count + ' 个</span></div>';
                });
            } else {
                html += '<p class="fm-loading">暂无附件</p>';
            }
            if (folders.length) {
                html += '<p class="fm-modal-desc" style="margin:12px 0 4px">按文件夹统计</p>';
                var maxFolder = folders[0].size || 1;
                folders.forEach(function (g) {
                    var pct = Math.max(2, Math.round((g.size / maxFolder) * 100));
                    html += '<div class="fm-stats-row fm-stats-row-folder"><span class="fm-stats-label" title="' + esc(g.name) + '">' + esc(g.name) + '</span>'
                        + '<span class="fm-stats-barwrap"><span class="fm-stats-bar" style="width:' + pct + '%"></span></span>'
                        + '<span class="fm-stats-val">' + fmtSize(g.size) + ' · ' + g.count + ' 个</span></div>';
                });
            }
            if (exts.length) {
                html += '<p class="fm-modal-desc" style="margin:12px 0 4px">按文件类型统计（扩展名体积总计）</p>';
                var maxExt = exts[0].size || 1;
                exts.forEach(function (g) {
                    var pct = Math.max(2, Math.round((g.size / maxExt) * 100));
                    html += '<div class="fm-stats-row"><span class="fm-stats-label" title="' + esc(g.ext) + '">' + esc(g.ext.toUpperCase()) + '</span>'
                        + '<span class="fm-stats-barwrap"><span class="fm-stats-bar fm-stats-bar-ext" style="width:' + pct + '%"></span></span>'
                        + '<span class="fm-stats-val">' + fmtSize(g.size) + ' · ' + g.count + ' 个</span></div>';
                });
            }
            if (top.length) {
                html += '<p class="fm-modal-desc" style="margin:12px 0 4px">占用最大的 ' + top.length + ' 个文件</p>';
                html += '<table class="fm-stats-table"><thead><tr><th>文件名</th><th width="120">所在文件夹</th><th width="90">大小</th></tr></thead><tbody>';
                top.forEach(function (f) {
                    var nameHtml = esc(f.name || ('#' + f.cid));
                    if (f.url) { nameHtml = '<a href="' + esc(f.url) + '" target="_blank">' + nameHtml + '</a>'; }
                    html += '<tr><td class="fm-name-cell">' + nameHtml + '</td>'
                        + '<td>' + esc(f.folder || '未分类') + '</td>'
                        + '<td>' + fmtSize(f.size) + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            $('fmStatsBody').innerHTML = html;
        }).catch(function () {
            $('fmStatsBody').innerHTML = '<p class="fm-loading">加载失败，请关闭后重试</p>';
        });
    }
    $('fmStatsBtn').addEventListener('click', function () {
        openModal('fmStatsModal');
        loadStorageStats();
    });

    function loadTrash() {
        $('fmTrashBody').innerHTML = '<p class="fm-loading">加载中…</p>';
        $('fmTrashClearBtn').hidden = true;
        $('fmTrashDesc').hidden = true;
        api('trashList').then(function (data) {
            var rows = data.rows || [];
            if (!rows.length) {
                $('fmTrashBody').innerHTML = '<p class="fm-loading">回收站是空的</p>';
                return;
            }
            $('fmTrashClearBtn').hidden = false;
            $('fmTrashDesc').hidden = false;
            var html = '<table class="fm-trash-table">'
                + '<colgroup><col>'
                + '<col style="width:96px"><col style="width:76px"><col style="width:132px">'
                + '<col style="width:88px"><col style="width:156px"></colgroup>'
                + '<thead><tr>'
                + '<th>文件名</th><th>所在文件夹</th><th>大小</th><th>删除时间</th><th>剩余天数</th><th>操作</th>'
                + '</tr></thead><tbody>';
            rows.forEach(function (t) {
                var name = t.name || ('#' + t.id);
                var folder = t.folder || '未分类';
                var act = '<div class="fm-trash-act">'
                    + '<button type="button" class="btn btn-s" data-trash-restore="' + t.id + '">还原</button>'
                    + '<button type="button" class="btn btn-s btn-share-red" data-trash-del="' + t.id + '">彻底删除</button>'
                    + '</div>';
                html += '<tr>'
                    + '<td class="fm-trash-name" title="' + esc(name) + '">' + esc(name) + '</td>'
                    + '<td class="fm-trash-dim" title="' + esc(folder) + '">' + esc(folder) + '</td>'
                    + '<td>' + fmtSize(t.size) + '</td>'
                    + '<td>' + esc(fmtDate(t.created)) + '</td>'
                    + '<td>' + (t.remainDays > 0 ? t.remainDays + ' 天' : '<span class="fm-trash-dim">不足 1 天</span>') + '</td>'
                    + '<td>' + act + '</td></tr>';
            });
            html += '</tbody></table>';
            $('fmTrashBody').innerHTML = html;

            Array.prototype.forEach.call($('fmTrashBody').querySelectorAll('[data-trash-restore]'), function (b) {
                b.addEventListener('click', function () {
                    b.disabled = true;
                    api('trashRestore', { id: b.getAttribute('data-trash-restore') }).then(function () {
                        toast('已还原，文件回到原位置');
                        loadTrash();
                        loadFiles();
                    }).catch(function () { b.disabled = false; });
                });
            });
            Array.prototype.forEach.call($('fmTrashBody').querySelectorAll('[data-trash-del]'), function (b) {
                b.addEventListener('click', function () {
                    if (!window.confirm('彻底删除后无法恢复，确定吗？')) { return; }
                    b.disabled = true;
                    api('trashDelete', { id: b.getAttribute('data-trash-del') }).then(function (d) {
                        toast(d.message || '已彻底删除');
                        loadTrash();
                    }).catch(function () { b.disabled = false; });
                });
            });
        }).catch(function () {
            $('fmTrashBody').innerHTML = '<p class="fm-loading">加载失败，请关闭后重试</p>';
        });
    }
    $('fmTrashBtn').addEventListener('click', function () {
        openModal('fmTrashModal');
        loadTrash();
    });
    $('fmTrashClearBtn').addEventListener('click', function () {
        if (!window.confirm('确定清空回收站吗？\n所有回收站内的文件将被物理删除，无法恢复！')) { return; }
        api('trashClear').then(function (d) {
            toast(d.message || '已清空回收站');
            loadTrash();
        });
    });

    function healthSection(key, title, emptyTip, cols, rows, totalNote) {
        if (!rows.length) {
            return '<p class="fm-modal-desc" style="margin:12px 0 0"><b>' + title + '</b>：' + emptyTip + '</p>';
        }
        var shown = rows.slice(0, 300);
        var totalSize = 0;
        rows.forEach(function (r) { totalSize += (r.size || 0); });
        var html = '<div style="margin:16px 0 6px;overflow:hidden"><b>' + title + '</b>（共 ' + rows.length + ' 项'
            + (totalNote ? '，' + totalNote : (totalSize > 0 ? '，合计 <b>' + fmtSize(totalSize) + '</b>' : '')) + '）'
            + '<button type="button" class="btn btn-s btn-share-red" data-health-clean="' + key + '" style="float:right">清理所选</button></div>';
        html += '<table class="fm-stats-table"><thead><tr><th width="30"><input type="checkbox" data-health-all="' + key + '"></th>';
        cols.forEach(function (c) { html += '<th' + (c.w ? ' width="' + c.w + '"' : '') + (c.right ? ' class="fm-act-h"' : '') + '>' + c.t + '</th>'; });
        html += '</tr></thead><tbody>';
        shown.forEach(function (r) {
            html += '<tr><td><input type="checkbox" data-health-item="' + key + '" data-health-ref="' + (r.ref ? '1' : '0') + '"'
                + (r.group ? ' data-health-group="' + r.group + '" data-health-reg="' + (r.reg ? '1' : '0') + '"' : '')
                + ' value="' + esc(r.id) + '"></td>';
            var delBtn = '<button type="button" class="btn btn-s btn-share-red" data-health-del1="' + key + '"'
                + ' data-health-id="' + esc(r.id) + '" data-health-ref="' + (r.ref ? '1' : '0') + '"'
                + (r.group ? ' data-health-group="' + r.group + '" data-health-reg="' + (r.reg ? '1' : '0') + '"' : '')
                + ' title="删除此项">删除</button>';
            cols.forEach(function (c, i) {
                var cell = c.render ? c.render(r) : esc(r[c.k] || '-');
                html += '<td>' + (i === cols.length - 1
                    ? '<span style="white-space:nowrap">' + cell + (cell ? ' ' : '') + delBtn + '</span>'
                    : cell) + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table>';
        if (rows.length > shown.length) {
            html += '<p class="fm-modal-desc" style="margin:6px 0 0">仅显示前 ' + shown.length + ' 项，清理后可再次扫描</p>';
        }
        return html;
    }

    function bindHealthEvents() {
        function dupGroupMembers(key, group) {
            return Array.prototype.filter.call(
                $('fmHealthBody').querySelectorAll('input[data-health-item="' + key + '"][data-health-group="' + group + '"]'),
                function (c) { return true; }
            );
        }
        Array.prototype.forEach.call($('fmHealthBody').querySelectorAll('[data-health-all]'), function (all) {
            all.addEventListener('change', function () {
                var key = all.getAttribute('data-health-all');
                if (key === 'duplicates' && all.checked) {
                    var boxes = Array.prototype.slice.call($('fmHealthBody').querySelectorAll('input[data-health-item="' + key + '"]'));
                    var seenFirst = {};
                    boxes.forEach(function (c) {
                        var g = c.getAttribute('data-health-group');
                        if (!seenFirst[g]) { seenFirst[g] = c; }
                        c.checked = true;
                    });
                    Object.keys(seenFirst).forEach(function (g) { seenFirst[g].checked = false; });
                    return;
                }
                Array.prototype.forEach.call($('fmHealthBody').querySelectorAll('input[data-health-item="' + key + '"]'), function (c) {
                    c.checked = all.checked && c.getAttribute('data-health-ref') !== '1';
                });
            });
        });
        Array.prototype.forEach.call($('fmHealthBody').querySelectorAll('input[data-health-item="duplicates"]'), function (c) {
            c.addEventListener('change', function () {
                if (!c.checked) { return; }
                var g = c.getAttribute('data-health-group');
                var members = dupGroupMembers('duplicates', g);
                var others = members.filter(function (m) { return m !== c; });
                var allOthersChecked = others.length > 0 && others.every(function (m) { return m.checked; });
                if (allOthersChecked) {
                    c.checked = false;
                    toast('每组重复文件至少要保留一份，已取消勾选');
                }
            });
        });
        Array.prototype.forEach.call($('fmHealthBody').querySelectorAll('[data-health-clean]'), function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-health-clean');
                var checked = Array.prototype.filter.call(
                    $('fmHealthBody').querySelectorAll('input[data-health-item="' + key + '"]'),
                    function (c) { return c.checked; }
                ).map(function (c) { return c.value; });
                if (!checked.length) { toast('请先勾选要清理的项'); return; }
                var refCount = key === 'unregistered'
                    ? checked.filter(function (v) {
                        var box = $('fmHealthBody').querySelector('input[data-health-item="' + key + '"][value="' + CSS.escape(v) + '"]');
                        return box && box.getAttribute('data-health-ref') === '1';
                    }).length : 0;
                var regCount = key === 'duplicates'
                    ? checked.filter(function (v) {
                        var box = $('fmHealthBody').querySelector('input[data-health-item="' + key + '"][value="' + CSS.escape(v) + '"]');
                        return box && box.getAttribute('data-health-reg') === '1';
                    }).length : 0;
                var tips = {
                    unregistered: '选中的 ' + checked.length + ' 个未登记文件将被直接删除（无附件记录、不可还原）'
                        + (refCount ? '，其中 ' + refCount + ' 个仍被文章/评论/配置引用，删除后相关内容会裂图' : '')
                        + '，确定吗？',
                    lost: '选中的 ' + checked.length + ' 条失效附件记录将被删除（物理文件本已不存在），确定吗？',
                    unused: '选中的 ' + checked.length + ' 个未使用附件将被删除（遵循回收站设置，默认可还原），确定吗？',
                    duplicates: '选中的 ' + checked.length + ' 个重复文件将被删除'
                        + (regCount ? '，其中 ' + regCount + ' 个为已登记附件（遵循回收站设置）' : '（均为无记录文件，直接删除）')
                        + '，每组至少保留一份；确定吗？'
                };
                if (!window.confirm(tips[key] || '确定清理吗？')) { return; }
                btn.disabled = true;
                api('healthClean', { type: key, items: JSON.stringify(checked) }).then(function (d) {
                    toast(d.message || '已清理');
                    loadFiles();
                    loadHealth();
                }).catch(function () { btn.disabled = false; });
            });
        });
        Array.prototype.forEach.call($('fmHealthBody').querySelectorAll('[data-health-reg1]'), function (btn) {
            btn.addEventListener('click', function () {
                var path = btn.getAttribute('data-health-reg1');
                btn.disabled = true;
                api('healthClean', { type: 'unregisteredRegister', items: JSON.stringify([path]) }).then(function (d) {
                    toast(d.message || '已登记');
                    loadFiles();
                    loadHealth();
                }).catch(function () { btn.disabled = false; });
            });
        });
        Array.prototype.forEach.call($('fmHealthBody').querySelectorAll('[data-health-del1]'), function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-health-del1');
                var id = btn.getAttribute('data-health-id');
                var ref = btn.getAttribute('data-health-ref') === '1';
                var reg = btn.getAttribute('data-health-reg') === '1';
                if (key === 'duplicates') {
                    var g = btn.getAttribute('data-health-group');
                    var members = $('fmHealthBody').querySelectorAll('input[data-health-item="duplicates"][data-health-group="' + g + '"]');
                    if (members.length <= 1) { toast('每组重复文件至少保留一份，不能删除该组的最后一份'); return; }
                }
                var tips = {
                    unregistered: '该未登记文件将被直接删除（无附件记录、不可还原）'
                        + (ref ? '，且仍被文章/评论/配置引用，删除后相关内容会裂图' : '')
                        + '，确定删除这个文件吗？',
                    lost: '该失效附件记录将被删除（物理文件本已不存在），确定吗？',
                    unused: '该未使用附件将被删除（遵循回收站设置，默认可还原），确定吗？',
                    duplicates: '该重复文件将被删除'
                        + (reg ? '（已登记附件，遵循回收站设置）' : '（无记录文件，直接删除）')
                        + '，确定吗？'
                };
                if (!window.confirm(tips[key] || '确定删除吗？')) { return; }
                btn.disabled = true;
                api('healthClean', { type: key, items: JSON.stringify([id]) }).then(function (d) {
                    toast(d.message || '已删除');
                    loadFiles();
                    loadHealth();
                }).catch(function () { btn.disabled = false; });
            });
        });
    }

    function loadHealth() {
        $('fmHealthBody').innerHTML = '<p class="fm-loading">正在扫描，请稍候…</p>';
        api('healthCheck').then(function (data) {
            var unreg = data.unregistered || [];
            var lost = data.lost || [];
            var unused = data.unused || [];
            var dups = data.duplicates || [];
            if (!unreg.length && !lost.length && !unused.length && !dups.length) {
                $('fmHealthBody').innerHTML = '<p class="fm-loading" style="color:#2a9d5c">体检通过：未发现任何问题</p>';
                return;
            }
            var dupGroups = {}, dupSavable = 0;
            dups.forEach(function (r) {
                dupGroups[r.group] = dupGroups[r.group] || { count: 0, size: 0 };
                dupGroups[r.group].count++;
                dupGroups[r.group].size += (r.size || 0);
            });
            Object.keys(dupGroups).forEach(function (g) {
                dupSavable += dupGroups[g].size * (dupGroups[g].count - 1) / dupGroups[g].count;
            });
            var html = '';
            html += healthSection('unregistered', '未登记文件', '没有未登记文件', [
                { k: 'path', t: '文件路径', render: function (r) {
                    return esc(r.path) + (r.ref ? ' <span style="color:#d9534f;white-space:nowrap">⚠ 被引用</span>' : '');
                } },
                { k: 'sizeText', t: '大小', w: '90' },
                { k: 'mtimeText', t: '修改时间', w: '135' },
                { k: 'regAct', t: '操作', w: '60', right: true, render: function (r) {
                    return '<button type="button" class="btn btn-s" data-health-reg1="' + esc(r.path) + '" title="登记为附件，纳入文件管理（可在后台编辑）">登记</button>';
                } }
            ], unreg.map(function (r) {
                return { id: r.path, path: r.path, ref: r.ref, size: r.size, sizeText: fmtSize(r.size), mtimeText: fmtDate(r.mtime) };
            }));
            html += healthSection('lost', '失效附件', '没有失效附件', [
                { k: 'name', t: '文件名' },
                { k: 'path', t: '记录路径' },
                { k: 'sizeText', t: '大小', w: '90' }
            ], lost.map(function (r) {
                return { id: String(r.cid), name: r.name || ('#' + r.cid), path: r.path, size: r.size, sizeText: fmtSize(r.size) };
            }));
            html += healthSection('unused', '未使用附件', '没有未使用附件', [
                { k: 'name', t: '文件名' },
                { k: 'sizeText', t: '大小', w: '90' },
                { k: 'createdText', t: '上传时间', w: '135' }
            ], unused.map(function (r) {
                return { id: String(r.cid), name: r.name || ('#' + r.cid), size: r.size, sizeText: fmtSize(r.size), createdText: fmtDate(r.created) };
            }));
            html += healthSection('duplicates', '重复文件', '没有重复文件', [
                { k: 'groupText', t: '重复组', w: '70' },
                { k: 'path', t: '文件路径' },
                { k: 'sizeText', t: '大小', w: '90' },
                { k: 'regText', t: '状态', w: '70' },
                { k: 'mtimeText', t: '修改时间', w: '135' }
            ], dups.map(function (r) {
                return { id: r.path, group: r.group, reg: r.reg, path: r.path, size: r.size, sizeText: fmtSize(r.size),
                    groupText: '#' + r.group, regText: r.reg ? '已登记' : '未登记', mtimeText: fmtDate(r.mtime) };
            }), Object.keys(dupGroups).length + ' 组重复，清理后可释放约 <b>' + fmtSize(dupSavable) + '</b>');
            html += '<div class="fm-health-tips">'
                + '<p><b>未登记文件</b>：上传目录中有文件、后台无附件记录；标 <span style="color:#d9534f">⚠ 被引用</span> 的文件仍被文章引用（全选时已排除），点「登记」可纳入附件管理。</p>'
                + '<p><b>失效附件</b>：有附件记录但文件已丢失，清理只删记录。</p>'
                + '<p><b>未使用附件</b>：未插入文章、也未被内容引用的附件。</p>'
                + '<p><b>重复文件</b>：内容完全相同的文件组，每组至少保留一份，已登记的清理时进回收站。</p>'
                + '<p>每行的「删除」与「清理所选」效果相同；重复文件组内仅剩一项时不可删除。</p>'
                + '</div>';
            $('fmHealthBody').innerHTML = html;
            bindHealthEvents();
        }).catch(function () {
            $('fmHealthBody').innerHTML = '<p class="fm-loading">扫描失败，请关闭后重试</p>';
        });
    }
    $('fmHealthBtn').addEventListener('click', function () {
        openModal('fmHealthModal');
        loadHealth();
    });

    if ($('fmClassifyBtn')) {
        $('fmClassifyBtn').addEventListener('click', function () {
            openModal('fmClassifyModal');
            applyClassifyMode();
            $('fmClassifyRunBtn').disabled = true;
            $('fmClassifyPreview').innerHTML = '加载中…';
            loadClassifyPreview();
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('input[name=fmClassifyScope]'), function (radio) {
        radio.addEventListener('change', loadClassifyPreview);
    });

    Array.prototype.forEach.call(document.querySelectorAll('input[name=fmClassifyMode]'), function (radio) {
        radio.addEventListener('change', function () {
            applyClassifyMode();
            loadClassifyPreview();
        });
    });

    function classifyMode() {
        var checked = document.querySelector('input[name=fmClassifyMode]:checked');
        return checked ? checked.value : 'type';
    }

    function applyClassifyMode() {
        var mode = classifyMode();
        $('fmClassifyDescType').hidden = mode !== 'type';
        $('fmClassifyDescCategory').hidden = mode !== 'category';
        $('fmClassifyDescYm').hidden = mode !== 'ym';
    }

    function classifyScope() {
        var checked = document.querySelector('input[name=fmClassifyScope]:checked');
        return checked ? checked.value : '0';
    }

    function loadClassifyPreview() {
        api('classifyPreview', { mode: classifyMode(), force: classifyScope() }).then(function (data) {
            var total = 0;
            data.items.forEach(function (item) { total += item.count; });
            if (!total) {
                $('fmClassifyPreview').innerHTML = '<p class="fm-loading">没有符合条件的文件，无需归类。</p>';
                $('fmClassifyRunBtn').disabled = true;
                return;
            }
            var rows = '<table><thead><tr><th>' + (classifyMode() === 'ym' ? '年/月' : '类型') + '</th><th>文件夹</th><th width="90">文件数</th></tr></thead><tbody>';
            data.items.forEach(function (item) {
                if (!item.count) {
                    rows += '<tr class="fm-zero"><td>' + esc(item.name) + '</td><td>—</td><td>0（跳过）</td></tr>';
                    return;
                }
                var tag = item.note
                    ? '<span class="' + (item.fid > 0 ? 'fm-reuse' : 'fm-new') + '">' + esc(item.note) + '</span>'
                    : (item.fid > 0 ? '<span class="fm-reuse">（复用已有文件夹）</span>' : '<span class="fm-new">（将新建）</span>');
                rows += '<tr><td>' + esc(item.name) + '</td><td>「' + esc(item.name) + '」' + tag + '</td><td>' + item.count + '</td></tr>';
            });
            rows += '</tbody></table>';
            $('fmClassifyPreview').innerHTML = rows;
            $('fmClassifyRunBtn').disabled = false;
        }).catch(function () {
            $('fmClassifyPreview').innerHTML = '加载失败';
        });
    }

    $('fmClassifyRunBtn').addEventListener('click', function () {
        var modeTextMap = {
            type: '将按文件类型归类',
            category: '将按文章分类归类（不属于分类文章的文件归入「未归属」）',
            ym: '将按上传年月归类（仿 Typecho 原生 /年/月 目录）'
        };
        var modeText = modeTextMap[classifyMode()] || modeTextMap.type;
        var scopeText = classifyScope() === '1' ? '全部文件将重新归类（覆盖现有归属）' : '仅归类未分类的文件';
        if (!window.confirm('确认开始归类吗？\n' + modeText + '\n' + scopeText)) { return; }
        $('fmClassifyRunBtn').disabled = true;
        api('classifyRun', { mode: classifyMode(), force: classifyScope() }).then(function (data) {
            var summary = [];
            data.results.forEach(function (r) {
                summary.push(r.name + '：移动 ' + r.moved + ' 个');
            });
            closeModal('fmClassifyModal');
            toast('归类完成 ' + summary.join('，'));
            loadFolders();
            loadFiles();
        }).catch(function () { $('fmClassifyRunBtn').disabled = false; });
    });

    function fillZipFolderSelect() {
        var sel = $('fmZipFolder');
        var html = '<option value="0">未分类</option>';
        (function walk(pid, depth) {
            state.folders.forEach(function (f) {
                if (f.parent !== pid) { return; }
                var indent = '';
                for (var d = 0; d < depth; d++) { indent += '　'; }
                html += '<option value="' + f.fid + '">' + indent + esc(f.name) + '</option>';
                walk(f.fid, depth + 1);
            });
        })(0, 0);
        sel.innerHTML = html;
    }

    function zipStampName() {
        var d = new Date();
        function p(n) { return n < 10 ? '0' + n : n; }
        return '' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate())
            + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds()) + '.zip';
    }

    function zipRadio(name) {
        var el = document.querySelector('input[name="' + name + '"]:checked');
        return el ? el.value : '';
    }

    function openZipModal() {
        if (!cfg.canZip) { toast('服务器未启用 php_zip 扩展，无法在线压缩'); return; }
        var cids = Object.keys(state.selected).map(Number);
        if (!cids.length) { toast('请先勾选要打包的文件'); return; }

        var total = 0;
        cids.forEach(function (c) { if (fileMap[c]) { total += (Number(fileMap[c].size) || 0); } });

        $('fmZipName').value = zipStampName();
        $('fmZipDelete').checked = false;
        $('fmZipInfo').textContent = '已选 ' + cids.length + ' 个文件 · 总大小 ' + fmtSize(total);
        $('fmZipWarnBig').hidden = total <= 200 * 1024 * 1024;
        $('fmZipMissing').hidden = true;
        fillZipFolderSelect();
        $('fmZipFolder').hidden = true; /* 默认当前文件夹，隐藏下拉框 */
        openModal('fmZipModal');
    }

    if ($('fmCompressBtn')) {
        $('fmCompressBtn').addEventListener('click', openZipModal);
    }

    Array.prototype.forEach.call(document.querySelectorAll('input[name=fmZipPlace]'), function (radio) {
        radio.addEventListener('change', function () {
            $('fmZipFolder').hidden = zipRadio('fmZipPlace') !== 'pick';
        });
    });
    $('fmZipFolder').addEventListener('change', function () {
        var pick = document.querySelector('input[name=fmZipPlace][value=pick]');
        if (pick) { pick.checked = true; }
    });

    function zipTargetFid() {
        var place = zipRadio('fmZipPlace');
        if (place === 'pick') {
            return parseInt($('fmZipFolder').value, 10) || 0;
        }
        return state.fid > 0 ? state.fid : 0;
    }

    $('fmZipStart').addEventListener('click', function () {
        var btn = this;
        var cids = Object.keys(state.selected).map(Number);
        if (!cids.length) { toast('请先勾选要打包的文件'); return; }

        var params = {
            cids: cids.join(','),
            name: $('fmZipName').value,
            level: zipRadio('fmZipLevel') || '0',
            structure: zipRadio('fmZipStructure') || 'folder',
            fid: zipTargetFid(),
            deleteOriginal: $('fmZipDelete').checked ? '1' : '0'
        };

        function execute() {
            btn.disabled = true;
            toast('正在打包…');
            api('zipCreate', Object.assign({ confirm: 1 }, params)).then(function (data) {
                btn.disabled = false;
                closeModal('fmZipModal');
                var summary = '已生成压缩包 ' + data.name + '（' + data.count + ' 个文件，' + fmtSize(data.size) + '）'
                    + (data.removed > 0 ? '，已删除原文件 ' + data.removed + ' 个' : '');
                toastActions(summary, [
                    {
                        label: '分享',
                        onClick: function () { openShareModal(data.cid); }
                    },
                    {
                        label: '下载',
                        onClick: function () {
                            var a = document.createElement('a');
                            a.href = data.url;
                            a.download = data.name;
                            document.body.appendChild(a);
                            a.click();
                            a.remove();
                        }
                    }
                ]);
                if (data.removed > 0) { state.selected = {}; updateBatchBar(); }
                loadFolders();
                loadFiles();
            }).catch(function () { btn.disabled = false; });
        }

        btn.disabled = true;
        api('zipCreate', params).then(function (data) {
            btn.disabled = false;
            if (data.missing && data.missing.length) {
                $('fmZipMissing').hidden = false;
                $('fmZipMissingList').textContent = data.missing
                    .map(function (m) { return m.title + '（' + m.reason + '）'; }).join('\n');
                if (!window.confirm('有 ' + data.missing.length + ' 个文件物理文件缺失，无法打包。\n点击「确定」跳过缺失文件继续打包其余文件，点击「取消」返回。')) {
                    return;
                }
                params.cids = cids.filter(function (c) {
                    for (var i = 0; i < data.missing.length; i++) {
                        if (Number(data.missing[i].cid) === c) { return false; }
                    }
                    return true;
                }).join(',');
                if (!params.cids) { toast('没有可打包的文件'); return; }
                $('fmZipMissing').hidden = true;
            }
            execute();
        }).catch(function () { btn.disabled = false; });
    });

    var extractCid = 0;
    var extractReady = false; /* 预检完成后才允许执行 */

    function openExtractModal(cid) {
        var f = fileMap[cid];
        if (!f) { toast('文件信息缺失，请刷新页面'); return; }
        if (!cfg.canExtract) { toast('服务器未启用 php_zip / phar 扩展，无法在线解压'); return; }

        extractCid = cid;
        extractReady = false;
        $('fmExtractFileName').textContent = f.name + '（' + fmtSize(f.size) + '）';
        $('fmExtractPassword').value = '';
        $('fmExtractPwdField').hidden = true;
        $('fmExtractDelete').checked = false;
        $('fmExtractSkipped').hidden = true;
        $('fmExtractInfo').textContent = '正在读取压缩包…';
        $('fmExtractStart').disabled = true;
        $('fmExtractStart').textContent = '开始解压';
        $('fmExtractSubName').textContent = String(f.name || '').replace(/\.(zip|tar|tar\.gz|tgz)$/i, '') || '压缩包';
        openModal('fmExtractModal');

        api('extract', { cid: cid, confirm: 0 }).then(function (data) {
            if (extractCid !== cid) { return; }
            var kindText = data.kind === 'zip' ? 'zip' : 'tar' + (String(f.name || '').toLowerCase().indexOf('.gz') > 0 ? '.gz' : '');
            var info = '格式：' + kindText + ' · 将解压出 ' + data.entries + ' 个文件 · 总大小 ' + fmtSize(data.totalSize)
                + (data.encrypted ? ' · 该压缩包已加密' : '');
            $('fmExtractInfo').textContent = info;
            if (data.skipped && data.skipped.length) {
                $('fmExtractSkipped').textContent = '⚠ 包内含 ' + data.skipped.length + ' 个脚本类文件（如 ' + data.skipped.slice(0, 3).join('、') + '），出于安全将自动跳过。';
                $('fmExtractSkipped').hidden = false;
            }
            if (data.encrypted) {
                $('fmExtractPwdField').hidden = false;
                $('fmExtractPassword').focus();
            }
            extractReady = true;
            $('fmExtractStart').disabled = false;
        }).catch(function () {
            $('fmExtractInfo').textContent = '预检失败：压缩包无法读取或已损坏';
        });
    }

    $('fmExtractStart').addEventListener('click', function () {
        var btn = this;
        if (!extractReady) { return; }

        var mode = (function () {
            var el = document.querySelector('input[name=fmExtractMode]:checked');
            return el ? el.value : 'current';
        })();
        var params = {
            cid: extractCid,
            mode: mode,
            deleteOriginal: $('fmExtractDelete').checked ? '1' : '0',
            fid: state.fid > 0 ? state.fid : 0
        };
        var pwdField = $('fmExtractPwdField');
        if (!pwdField.hidden) {
            params.password = $('fmExtractPassword').value;
            if (!params.password) { toast('请输入解压密码'); return; }
        }

        btn.disabled = true;
        toast('正在解压…');
        api('extract', Object.assign({ confirm: 1 }, params)).then(function (data) {
            btn.disabled = false;
            closeModal('fmExtractModal');
            toast(data.message || ('已解压 ' + data.count + ' 个文件'));
            if ($('fmExtractDelete').checked) {
                delete state.selected[extractCid];
                updateBatchBar();
            }
            loadFolders();
            loadFiles();
        }).catch(function () {
            btn.disabled = false;
        });
    });

    $('fmCleanBtn').addEventListener('click', function () {
        api('unattachedCount').then(function (data) {
            var n = data.count || 0;
            if (!n) {
                toast('没有未归档文件，无需清理');
                return;
            }
            var msg = '发现 ' + n + ' 个未归档文件（未挂到任何文章或页面的附件）。\n'
                + '物理文件将一并删除，操作不可恢复！\n\n确定清理吗？';
            if (!window.confirm(msg)) { return; }
            api('cleanUnattached').then(function (res) {
                toast(res.message || '清理完成');
                loadFolders();
                loadFiles();
            }).catch(function (e) { toast('清理失败：' + (e.message || '未知错误')); });
        }).catch(function (e) { toast('统计失败：' + (e.message || '未知错误')); });
    });

    function applyView() {
        $('fmGrid').hidden = state.view !== 'grid';
        $('fmMasonry').hidden = state.view !== 'masonry';
        $('fmListWrap').hidden = state.view !== 'list';
        $('fmViewGrid').className = state.view === 'grid' ? 'current' : '';
        $('fmViewMasonry').className = state.view === 'masonry' ? 'current' : '';
        $('fmViewList').className = state.view === 'list' ? 'current' : '';
        $('fmGridSizeBtn').style.display = state.view === 'grid' ? '' : 'none'; /* 网格布局仅网格视图有意义 */
        if (!$('fmGridSizeMenu').hidden) { $('fmGridSizeMenu').hidden = true; }
        setLayoutOverride('fmView', state.view);
        loadFiles();
    }

    function renderGridSizeMenu() {
        Array.prototype.forEach.call($('fmGridSizeMenu').querySelectorAll('button[data-gs]'), function (b) {
            b.className = b.getAttribute('data-gs') === state.gridSize ? 'current' : '';
        });
    }

    function setGridSize(gs) {
        state.gridSize = gs === '4x7' ? '4x7' : '5x6';
        setLayoutOverride('fmGridSize', state.gridSize);
        renderGridSizeMenu();
        state.page = 1;
        loadFiles();
    }

    $('fmGridSizeBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        renderGridSizeMenu();
        $('fmGridSizeMenu').hidden = !$('fmGridSizeMenu').hidden;
    });

    Array.prototype.forEach.call($('fmGridSizeMenu').querySelectorAll('button[data-gs]'), function (b) {
        b.addEventListener('click', function () {
            $('fmGridSizeMenu').hidden = true;
            setGridSize(this.getAttribute('data-gs'));
        });
    });

    document.addEventListener('click', function (e) {
        if (!$('fmGridSizeMenu').hidden && !e.target.closest('.fm-view-wrap')) {
            $('fmGridSizeMenu').hidden = true;
        }
    });

    $('fmViewGrid').addEventListener('click', function () { state.view = 'grid'; applyView(); });
    $('fmViewMasonry').addEventListener('click', function () { state.view = 'masonry'; applyView(); });
    $('fmViewList').addEventListener('click', function () { state.view = 'list'; applyView(); });

    document.addEventListener('keydown', function (e) {
        if (prefs.shortcut === '0') { return; }
        if (lbEl && !lbEl.hidden) { return; } /* 灯箱打开时由灯箱自己的快捷键处理 */
        var t = e.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) { return; }
        if (e.ctrlKey || e.metaKey) {
            if (e.key === 'a' || e.key === 'A') {
                e.preventDefault(); /* 覆盖浏览器默认「全选页面文本」 */
                $('fmBatchAllBtn').click();
            }
            return;
        }
        if (e.key === 'Delete') {
            var cids = Object.keys(state.selected);
            if (!cids.length) { return; }
            e.preventDefault();
            deleteFiles(cids, function () {});
        }
    });
    $('fmType').addEventListener('change', function () { state.type = this.value; state.page = 1; loadFiles(); });
    function sortStateOf(v) {
        var p = String(v || 'time-desc').split('-');
        return {
            sort: ['time', 'name', 'size'].indexOf(p[0]) > -1 ? p[0] : 'time',
            dir: p[1] === 'asc' ? 'asc' : 'desc'
        };
    }
    function syncSortSelect() {
        $('fmSort').value = state.sort + '-' + state.dir;
        if ($('fmSort').selectedIndex === -1) { $('fmSort').value = 'time-desc'; }
    }
    function applyDefaultSort() {
        var s = sortStateOf(prefs.sortDefault);
        state.sort = s.sort;
        state.dir = s.dir;
        syncSortSelect();
    }
    syncSortSelect();
    $('fmSort').addEventListener('change', function () {
        var s = sortStateOf(this.value);
        state.sort = s.sort;
        state.dir = s.dir;
        state.page = 1;
        loadFiles();
    });
    var fmCSearchSuspended = false;
    if (window.MutationObserver) {
        new MutationObserver(function () {
            if (fmCSearchSuspended && $('fmTextEditModal').hidden) {
                fmCSearchSuspended = false;
                openModal('fmCSearchModal');
            }
        }).observe($('fmTextEditModal'), { attributes: true, attributeFilter: ['hidden'] });
    }

    function runContentSearch(kw) {
        kw = String(kw || '').trim();
        if (!kw) { toast('请输入要搜索的内容关键字'); return; }
        if (kw.length > 64) { toast('关键字过长（最多 64 字）'); return; }
        $('fmCSearchKw').textContent = kw;
        $('fmCSearchBody').innerHTML = '<p class="fm-loading">搜索中…（仅扫描文本类文件，最多 300 个）</p>';
        openModal('fmCSearchModal');
        api('contentSearch', { kw: kw }).then(function (data) {
            var list = data.matches || [];
            if (!list.length) {
                $('fmCSearchBody').innerHTML = '<p class="fm-loading">未找到包含「' + esc(kw) + '」的文本文件</p>';
                return;
            }
            var escKw = kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            var hlRe = new RegExp('(' + escKw + ')', 'gi');
            var html = '<div class="fm-cs-meta">共 ' + list.length + ' 个匹配'
                + (data.truncated ? '（已达结果上限，可细化关键字）' : '') + '</div>';
            list.forEach(function (m) {
                var sn = esc(m.snippet || '').replace(hlRe, '<mark>$1</mark>');
                html += '<div class="fm-cs-row"><div class="fm-cs-head">'
                    + '<a href="' + esc(m.url) + '" target="_blank" rel="noopener">' + esc(m.name) + '</a>'
                    + '<span class="fm-tag">' + esc(m.ext || '') + '</span>'
                    + '<button type="button" class="btn btn-s" data-csedit="' + m.cid + '">编辑</button></div>'
                    + '<div class="fm-cs-snip">…' + sn + '…</div></div>';
            });
            $('fmCSearchBody').innerHTML = html;
            Array.prototype.forEach.call($('fmCSearchBody').querySelectorAll('[data-csedit]'), function (btn) {
                btn.addEventListener('click', function () {
                    fmCSearchSuspended = true;
                    closeModal('fmCSearchModal');
                    openTextEditModal(parseInt(btn.getAttribute('data-csedit'), 10));
                });
            });
        }).catch(function () {});
    }

    $('fmSearchBtn').addEventListener('click', function () {
        if ($('fmContentSearch').checked) { runContentSearch($('fmKeywords').value); return; }
        state.keywords = $('fmKeywords').value; state.page = 1; loadFiles();
    });
    $('fmKeywords').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            if ($('fmContentSearch').checked) { runContentSearch(this.value); return; }
            state.keywords = this.value; state.page = 1; loadFiles();
        }
    });
    $('fmPrev').addEventListener('click', function () { if (state.page > 1) { state.page--; loadFiles(); } });
    $('fmNext').addEventListener('click', function () { if (state.page < state.pages) { state.page++; loadFiles(); } });
    $('fmNewFolder').addEventListener('click', function () {
        createFolder(state.fid > 0 ? state.fid : 0);
    });
    $('fmCleanEmptyBtn').addEventListener('click', function () {
        api('cleanEmptyFolders').then(function (data) {
            var n = data.found || 0;
            if (!n) {
                toast(data.message || '没有空文件夹');
                return;
            }
            if (!window.confirm('发现 ' + n + ' 个空文件夹（不含任何文件，且没有存留的子文件夹）。\n确定全部删除吗？此操作不可恢复！')) {
                return;
            }
            api('cleanEmptyFolders', { confirm: 1 }).then(function (data2) {
                toast(data2.message || '已删除空文件夹');
                loadFolders();
            });
        });
    });
    (function () {
        var sideEl = document.querySelector('.fm-side');
        function setArmed(armed) {
            dragToLeft = armed;
            var tip = $('fmDragTip');
            if (tip) {
                tip.innerHTML = armed
                    ? '松开鼠标 = 移为一级文件夹'
                    : '拖入文件夹 = 移入<br>往左拖松手 = 移为一级';
                tip.classList.toggle('armed', armed);
            }
            if (sideEl) { sideEl.classList.toggle('fm-root-hint', armed); }
        }
        document.addEventListener('dragover', function (e) {
            if (dragFid <= 0) { return; }
            e.preventDefault();
            if (e.dataTransfer) { e.dataTransfer.dropEffect = 'move'; }
            var dx = dragStartX - e.clientX;
            var dy = Math.abs(e.clientY - dragStartY);
            var inside = false;
            if (sideEl) {
                var r = sideEl.getBoundingClientRect();
                inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
            }
            var armed = inside && dx >= 25 && dx > dy;
            if (armed !== dragToLeft) { setArmed(armed); }
        });
        document.addEventListener('drop', function (e) {
            if (dragFid <= 0) { return; }
            e.preventDefault();
            if (!dragToLeft) { return; }
            var moving = dragFid;
            dragFid = 0;
            moveFolder(moving, 0);
        });
    })();
    $('fmMoveBtn').addEventListener('click', function () {
        var cids = Object.keys(state.selected);
        if (!cids.length) { return; }
        api('move', { cids: cids.join(','), fid: $('fmMoveTarget').value }).then(function (data) {
            state.selected = {};
            updateBatchBar();
            toast(data.message);
            loadFolders();
            loadFiles();
        });
    });
    $('fmBatchDeleteBtn').addEventListener('click', function () {
        deleteFiles(Object.keys(state.selected).map(Number));
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-fm-close]'), function (el) {
        el.addEventListener('click', function () {
            var modal = el.closest('.fm-modal');
            if (modal) { modal.hidden = true; }
        });
    });

    function fillPrefFolderSelect(sel, withRoot) {
        var html = withRoot ? '<option value="0">未分类</option>' : '';
        (function walk(pid, depth) {
            state.folders.forEach(function (f) {
                if (f.parent !== pid) { return; }
                var indent = '';
                for (var d = 0; d < depth; d++) { indent += '　'; }
                html += '<option value="' + f.fid + '">' + indent + esc(f.name) + '</option>';
                walk(f.fid, depth + 1);
            });
        })(0, 0);
        sel.innerHTML = html;
    }

    $('fmPrefsBtn').addEventListener('click', function () {
        $('fmPrefUploadMode').value = prefs.uploadMode;
        fillPrefFolderSelect($('fmPrefUploadFolder'), false);
        $('fmPrefUploadFolder').value = String(prefs.uploadFid > 0 ? prefs.uploadFid : 0);
        if ($('fmPrefUploadFolder').selectedIndex === -1) { $('fmPrefUploadFolder').value = '0'; }
        $('fmPrefUploadFolder').hidden = prefs.uploadMode !== 'custom';

        $('fmPrefPasteMode').value = prefs.pasteMode;
        fillPrefFolderSelect($('fmPrefPasteFolder'), false);
        $('fmPrefPasteFolder').value = String(prefs.pasteFid > 0 ? prefs.pasteFid : 0);
        if ($('fmPrefPasteFolder').selectedIndex === -1) { $('fmPrefPasteFolder').value = '0'; }
        $('fmPrefPasteFolder').hidden = prefs.pasteMode !== 'custom';

        fillPrefFolderSelect($('fmPrefStartFolder'), false);
        $('fmPrefStartFolder').value = String(prefs.startFid > 0 ? prefs.startFid : 0);
        if ($('fmPrefStartFolder').selectedIndex === -1) { $('fmPrefStartFolder').value = '0'; }

        (function () {
            var ihReady = imageHostReady();
            Array.prototype.forEach.call($('fmPrefUploadDest').options, function (o) {
                o.hidden = (o.value === 'imagehost' && !ihReady);
            });
            $('fmPrefUploadDest').value = (ihReady && prefs.defaultUploadDest === 'imagehost') ? 'imagehost' : 'site';
        })();
        $('fmPrefUploadConv').value = prefs.uploadConv;
        if ($('fmPrefUploadConv').selectedIndex === -1) { $('fmPrefUploadConv').value = ''; }
        $('fmPrefUploadDt').checked = prefs.uploadRenameDt === '1';
        $('fmPrefGrid').value = prefs.gridDefault;
        $('fmPrefSort').value = prefs.sortDefault;
        if ($('fmPrefSort').selectedIndex === -1) { $('fmPrefSort').value = 'time-desc'; }
        $('fmPrefStart').value = ['all', 'uncat', 'latest', 'last', 'custom'].indexOf(prefs.startMode) > -1 ? prefs.startMode : 'all';
        $('fmPrefStartFolder').hidden = $('fmPrefStart').value !== 'custom';
        $('fmPrefShortcut').checked = prefs.shortcut !== '0';
        $('fmPrefHoverPreview').checked = prefs.hoverPreview !== '0';
        $('fmPrefThumbCache').checked = prefs.thumbCache !== '0';
        $('fmPrefRecycle').checked = globalSet.recycleEnabled !== 0;
        $('fmPrefRecycleDays').value = globalSet.recycleDays;
        $('fmPrefVideoHtml').checked = globalSet.videoHtml === 1;
        $('fmThumbStatus').textContent = '';
        $('fmPrefHlTheme').value = prefs.hlTheme;
        $('fmPrefsInfo').textContent = '';
        openModal('fmPrefsModal');
    });

    $('fmPrefStart').addEventListener('change', function () {
        $('fmPrefStartFolder').hidden = this.value !== 'custom';
    });

    $('fmPrefUploadMode').addEventListener('change', function () {
        $('fmPrefUploadFolder').hidden = this.value !== 'custom';
    });

    $('fmPrefPasteMode').addEventListener('change', function () {
        $('fmPrefPasteFolder').hidden = this.value !== 'custom';
    });

    $('fmPrefsSave').addEventListener('click', function () {
        var obj = {
            gridDefault: ['5x6', '4x7', 'masonry', 'list'].indexOf($('fmPrefGrid').value) > -1
                ? $('fmPrefGrid').value : '5x6',
            sortDefault: ['time-desc', 'time-asc', 'name-desc', 'name-asc', 'size-desc', 'size-asc']
                .indexOf($('fmPrefSort').value) > -1 ? $('fmPrefSort').value : 'time-desc',
            uploadMode: $('fmPrefUploadMode').value,
            uploadFid: parseInt($('fmPrefUploadFolder').value, 10) || 0,
            pasteMode: $('fmPrefPasteMode').value,
            pasteFid: parseInt($('fmPrefPasteFolder').value, 10) || 0,
            startMode: $('fmPrefStart').value,
            startFid: parseInt($('fmPrefStartFolder').value, 10) || 0,
            shortcut: $('fmPrefShortcut').checked ? '1' : '0',
            hoverPreview: $('fmPrefHoverPreview').checked ? '1' : '0',
            thumbCache: $('fmPrefThumbCache').checked ? '1' : '0',
            defaultUploadDest: $('fmPrefUploadDest').value === 'imagehost' ? 'imagehost' : 'site',
            uploadConv: ['', 'webp', 'jpg', 'png'].indexOf($('fmPrefUploadConv').value) > -1 ? $('fmPrefUploadConv').value : '',
            uploadRenameDt: $('fmPrefUploadDt').checked ? '1' : '0',
            hlTheme: $('fmPrefHlTheme').value
        };
        if (obj.uploadMode === 'custom' && obj.uploadFid <= 0) {
            $('fmPrefsInfo').textContent = '请选择上传文件的默认文件夹';
            $('fmPrefsInfo').style.color = '#d9534f';
            return;
        }
        if (obj.startMode === 'custom' && obj.startFid <= 0) {
            $('fmPrefsInfo').textContent = '请选择进入文件管理页时打开的文件夹';
            $('fmPrefsInfo').style.color = '#d9534f';
            return;
        }
        if (obj.pasteMode === 'custom' && obj.pasteFid <= 0) {
            $('fmPrefsInfo').textContent = '请选择粘贴截图上传的默认文件夹';
            $('fmPrefsInfo').style.color = '#d9534f';
            return;
        }
        var daysRaw = String($('fmPrefRecycleDays').value || '').replace(/[^\d]/g, '');
        var days = parseInt(daysRaw, 10);
        if (!days || days < 1 || days > 3650) {
            $('fmPrefsInfo').textContent = '回收站保留天数需为 1–3650 之间的整数';
            $('fmPrefsInfo').style.color = '#d9534f';
            return;
        }
        var globalObj = {
            recycleEnabled: $('fmPrefRecycle').checked ? 1 : 0,
            recycleDays: days,
            videoHtml: $('fmPrefVideoHtml').checked ? 1 : 0
        };
        var btn = this;
        btn.disabled = true;
        api('prefsSave', { prefs: JSON.stringify(obj), global: JSON.stringify(globalObj) }).then(function (res) {
            globalSet.recycleEnabled = globalObj.recycleEnabled;
            globalSet.recycleDays = globalObj.recycleDays;
            globalSet.videoHtml = globalObj.videoHtml;
            if (res.prefs) {
                prefs.gridDefault = ['5x6', '4x7', 'masonry', 'list'].indexOf(res.prefs.gridDefault) > -1
                    ? res.prefs.gridDefault : '5x6';
                prefs.sortDefault = ['time-desc', 'time-asc', 'name-desc', 'name-asc', 'size-desc', 'size-asc']
                    .indexOf(res.prefs.sortDefault) > -1 ? res.prefs.sortDefault : 'time-desc';
                applyDefaultSort();
                prefs.uploadFid = parseInt(res.prefs.uploadFid, 10) || 0;
                prefs.uploadMode = uploadModeOf(res.prefs.uploadMode, prefs.uploadFid);
                prefs.pasteMode = ['uncat', 'current', 'latest', 'custom'].indexOf(res.prefs.pasteMode) > -1
                    ? res.prefs.pasteMode : 'uncat';
                prefs.pasteFid = parseInt(res.prefs.pasteFid, 10) || 0;
                prefs.startMode = String(res.prefs.startMode || 'all');
                prefs.startFid = parseInt(res.prefs.startFid, 10) || 0;
                prefs.shortcut = (res.prefs.shortcut === '0') ? '0' : '1';
                prefs.hoverPreview = (res.prefs.hoverPreview === '0') ? '0' : '1';
                prefs.thumbCache = (res.prefs.thumbCache === '0') ? '0' : '1';
                prefs.imageHost = (res.prefs.imageHost && typeof res.prefs.imageHost === 'object') ? res.prefs.imageHost : null;
                prefs.defaultUploadDest = (res.prefs.defaultUploadDest === 'imagehost') ? 'imagehost' : 'site';
                prefs.uploadConv = ['', 'webp', 'jpg', 'png'].indexOf(res.prefs.uploadConv) > -1 ? res.prefs.uploadConv : '';
                prefs.uploadRenameDt = (res.prefs.uploadRenameDt === '1') ? '1' : '0';
                prefs.hlTheme = HL_THEMES[res.prefs.hlTheme] ? res.prefs.hlTheme : 'github';
                applyHlTheme();
            }
            /* 默认布局刚改过：清掉浏览器覆盖，否则旧覆盖会把新默认值挡在外面 */
            lsDel('fmGridSize');
            lsDel('fmView');
            if (prefs.gridDefault === 'masonry' || prefs.gridDefault === 'list') {
                state.view = prefs.gridDefault;
            } else {
                state.view = 'grid';
                state.gridSize = prefs.gridDefault;
                renderGridSizeMenu();
            }
            state.page = 1;
            applyView();
            closeModal('fmPrefsModal');
            toast(res.message || '偏好设置已保存');
        }).catch(function (e) {
            $('fmPrefsInfo').textContent = e.message || '保存失败';
            $('fmPrefsInfo').style.color = '#d9534f';
        }).then(function () { btn.disabled = false; });
    });

    $('fmThumbWarmup').addEventListener('click', function () {
        var btn = this;
        var status = $('fmThumbStatus');
        btn.disabled = true;
        status.style.color = '#7d8b99';
        status.textContent = '正在读取文件列表…';

        function fetchPage(p, ps) { return api('list', { fid: 0, page: p, pageSize: ps, sort: 'time', dir: 'desc' }); }

        fetchPage(1, 100).then(function (first) {
            var ps = parseInt(first.pageSize, 10) || 100;
            var pages = [Promise.resolve(first.files)];
            for (var p = 2; p <= (first.pages || 1); p++) {
                pages.push(fetchPage(p, ps).then(function (res) { return res.files; }));
            }
            return Promise.all(pages);
        }).then(function (chunks) {
            var imgs = [];
            chunks.forEach(function (files) {
                files.forEach(function (f) {
                    if (f.isImage && f.url) { imgs.push(f); }
                });
            });
            if (!imgs.length) {
                status.textContent = '没有可处理的图片';
                btn.disabled = false;
                return;
            }
            var total = imgs.length, done = 0, fail = 0, cursor = 0;
            function update() {
                status.textContent = '生成中 ' + done + ' / ' + total + (fail ? '（失败 ' + fail + '）' : '');
            }
            function finish() {
                btn.disabled = false;
                status.style.color = '#4a8a4a';
                status.textContent = '完成：' + (total - fail) + ' 张已缓存' + (fail ? '，' + fail + ' 张失败（多为损坏文件，不影响使用）' : '');
                toast('缩略图预生成完成：' + (total - fail) + ' / ' + total);
            }
            function next() {
                if (cursor >= total) { if (done >= total) { finish(); } return; }
                var f = imgs[cursor++];
                var im = new Image();
                im.onload = im.onerror = function () {
                    done++;
                    if (im.complete && im.naturalWidth === 0) { fail++; }
                    update();
                    next();
                };
                im.src = thumbUrl(f);
            }
            update();
            next(); next(); next(); next(); /* 并发 4 路 */
        }).catch(function () {
            btn.disabled = false;
            status.textContent = '';
        });
    });

    $('fmThumbClear').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        api('thumbClear').then(function (res) {
            $('fmThumbStatus').textContent = '';
            toast(res.message || '缓存已清空');
        }).catch(function () { /* api() 已弹过提示 */ }).then(function () { btn.disabled = false; });
    });

    function imageHostConf() {
        return (prefs.imageHost && typeof prefs.imageHost === 'object') ? prefs.imageHost : {};
    }
    function imageHostReady() {
        var c = imageHostConf();
        if (!c || c.enabled !== '1') { return false; }
        if (c.type === 's3') {
            return !!(c.s3Endpoint && c.s3Bucket && c.s3AccessKey && c.s3SecretKey);
        }
        return !!(c.api && c.token);
    }
    function imageHostFillStrategy(sel, strategies, currentId) {
        sel.innerHTML = '<option value="0">图床默认策略</option>';
        (strategies || []).forEach(function (s) {
            var o = document.createElement('option');
            o.value = String(s.id);
            o.textContent = s.name ? (s.name + '（ID ' + s.id + '）') : ('策略 ID ' + s.id);
            sel.appendChild(o);
        });
        sel.value = String(currentId || 0);
        if (sel.selectedIndex === -1) { sel.value = '0'; }
    }

    function imageHostToggleFields() {
        var isS3 = $('fmIhType').value === 's3';
        $('fmIhLskyFields').hidden = isS3;
        $('fmIhS3Fields').hidden = !isS3;
    }
    $('fmIhType').addEventListener('change', imageHostToggleFields);

    $('fmImgHostBtn').addEventListener('click', function () {
        var c = imageHostConf();
        $('fmIhType').value = (c.type === 's3') ? 's3' : 'lsky';
        imageHostToggleFields();
        $('fmIhEnabled').checked = !!(c && c.enabled === '1');
        $('fmIhApi').value = c.api || '';
        $('fmIhToken').value = c.token || '';
        $('fmIhAlbum').value = String(c.albumId || 0);
        $('fmIhPermission').value = (c.permission === '0') ? '0' : '1';
        imageHostFillStrategy($('fmIhStrategy'), [], c.strategyId);
        $('fmIhS3Endpoint').value = (c.s3Endpoint || '');
        $('fmIhS3Region').value = (c.s3Region || '');
        $('fmIhS3Bucket').value = (c.s3Bucket || '');
        $('fmIhS3Ak').value = (c.s3AccessKey || '');
        $('fmIhS3Sk').value = (c.s3SecretKey || '');
        $('fmIhS3Prefix').value = (c.s3Prefix || '');
        $('fmIhS3BaseUrl').value = (c.s3BaseUrl || '');
        $('fmIhInfo').textContent = '';
        openModal('fmImgHostModal');
    });

    $('fmIhTest').addEventListener('click', function () {
        var btn = this;
        var info = $('fmIhInfo');
        var isS3 = $('fmIhType').value === 's3';
        var prev = imageHostConf() || {};
        btn.disabled = true;
        info.style.color = '#7d8b99';
        if (isS3) {
            var endpoint = $('fmIhS3Endpoint').value.trim();
            var bucket = $('fmIhS3Bucket').value.trim();
            var ak = $('fmIhS3Ak').value.trim();
            var sk = $('fmIhS3Sk').value.trim() || (prev.s3SecretKey || '');
            if (!endpoint || !bucket || !ak || !sk) { toast('请先填写完整的 Endpoint、Bucket、AccessKey ID 和 Secret'); btn.disabled = false; return; }
            info.textContent = '正在连接对象存储…';
            api('imageHostTest', {
                type: 's3', endpoint: endpoint, region: $('fmIhS3Region').value.trim(),
                bucket: bucket, ak: ak, sk: sk
            }, { timeout: 25000 }).then(function (res) {
                btn.disabled = false;
                info.style.color = '#2e7d43';
                info.textContent = '✓ Bucket 「' + res.bucket + '」可访问 · Region ' + (res.region || 'us-east-1');
            }).catch(function (e) {
                btn.disabled = false;
                info.style.color = '#d9534f';
                info.textContent = (e && e.name === 'AbortError')
                    ? '连接超时：多为服务器 DNS 解析不了存储端点，或出站请求被防火墙拦截'
                    : '连接失败，请检查 Endpoint / Bucket / 密钥';
            });
            return;
        }
        var api = $('fmIhApi').value.trim();
        var token = $('fmIhToken').value.trim();
        if (!api || !token) { toast('请先填写接口地址和访问令牌'); btn.disabled = false; return; }
        info.textContent = '正在连接图床…';
        api('imageHostTest', { api: api, token: token }, { timeout: 20000 }).then(function (res) {
            btn.disabled = false;
            imageHostFillStrategy($('fmIhStrategy'), res.strategies, imageHostConf().strategyId);
            info.style.color = '#2e7d43';
            var cap = '';
            if (res.capacity > 0) { cap = ' · 已用 ' + fmtSize(res.size * 1024) + ' / ' + fmtSize(res.capacity * 1024); }
            info.textContent = '✓ ' + (res.name || res.username) + ' · 图片 ' + res.imageNum + ' 张' + cap
                + ' · 策略 ' + (res.strategies || []).length + ' 个';
        }).catch(function (e) {
            btn.disabled = false;
            info.style.color = '#d9534f';
            if (e && e.name === 'AbortError') {
                info.textContent = '连接超时（20 秒）：多为服务器 DNS 解析不了图床域名，或出站请求被防火墙拦截。'
                    + '可在服务器终端执行 curl -m 10 -I https://wmimg.com 自测。';
            } else {
                info.textContent = '连接失败，请检查接口地址与令牌';
            }
        });
    });

    $('fmIhSave').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        var prev = imageHostConf() || {};
        var ihToken = $('fmIhToken').value.trim();
        if (!ihToken && prev.token) { ihToken = prev.token; }
        var ihSk = $('fmIhS3Sk').value.trim();
        if (!ihSk && prev.s3SecretKey) { ihSk = prev.s3SecretKey; }
        prefs.imageHost = {
            type:       $('fmIhType').value === 's3' ? 's3' : 'lsky',
            enabled:    $('fmIhEnabled').checked ? '1' : '0',
            api:        $('fmIhApi').value.trim(),
            token:      ihToken,
            strategyId: parseInt($('fmIhStrategy').value, 10) || 0,
            albumId:    parseInt($('fmIhAlbum').value, 10) || 0,
            permission: $('fmIhPermission').value,
            s3Endpoint:  $('fmIhS3Endpoint').value.trim(),
            s3Region:    $('fmIhS3Region').value.trim(),
            s3Bucket:    $('fmIhS3Bucket').value.trim(),
            s3AccessKey: $('fmIhS3Ak').value.trim(),
            s3SecretKey: ihSk,
            s3Prefix:    $('fmIhS3Prefix').value.trim(),
            s3BaseUrl:   $('fmIhS3BaseUrl').value.trim()
        };
        api('prefsSave', { prefs: JSON.stringify(prefs) }).then(function (res) {
            if (res.prefs && res.prefs.imageHost && typeof res.prefs.imageHost === 'object') {
                prefs.imageHost = res.prefs.imageHost;
            }
            closeModal('fmImgHostModal');
            toast(prefs.imageHost.enabled === '1' ? '图床配置已保存，图床上传已启用' : '图床配置已保存（未启用）');
        }).catch(function () { /* api() 已弹过提示 */ }).then(function () { btn.disabled = false; });
    });

    var fmMigrateType = 'lsky';
    var fmMigrateFiles = [];
    var fmMigrateFails = [];
    var fmMigrating = false;
    var fmMigrateChecked = {}; /* 勾选状态按 cid 记录，跨搜索筛选保留 */

    function ihBadgeText() { return fmMigrateType === 's3' ? '存储' : '图床'; }

    function migrateFiltered() {
        var kw = ($('fmMigrateKw') ? $('fmMigrateKw').value.trim().toLowerCase() : '');
        if (!kw) { return fmMigrateFiles; }
        return fmMigrateFiles.filter(function (f) {
            return ((f.name || '') + ' ' + (f.title || '') + ' ' + (f.path || '')).toLowerCase().indexOf(kw) > -1;
        });
    }

    function renderMigrateRows() {
        var wrap = $('fmMigrateListWrap');
        if (!wrap) { return; }
        if (!fmMigrateFiles.length) {
            wrap.innerHTML = '<p class="fm-loading" style="color:#2a9d5c">没有需要迁移的附件：全部都已迁移过（或当前类型无可迁移文件）</p>';
            return;
        }
        var list = migrateFiltered();
        var canCount = list.filter(function (f) { return !f.missing; }).length;
        var missVisible = list.length - canCount;
        var allChecked = canCount > 0 && list.every(function (f) { return f.missing || fmMigrateChecked[f.cid]; });
        var html = '<div style="margin:10px 0 4px;overflow:hidden"><b style="float:left">待迁移 ' + fmMigrateFiles.length + ' 个</b>'
            + '<span style="float:left;margin-left:12px;color:#7d8b99">筛选显示 ' + list.length + ' 个'
            + (missVisible ? '（' + missVisible + ' 个文件缺失）' : '') + '</span>'
            + '<label style="float:right"><input type="checkbox" id="fmMigrateAll"' + (allChecked ? ' checked' : '') + '> 全选可迁移项（当前筛选）</label></div>';
        html += '<table class="fm-stats-table"><thead><tr><th width="30"></th><th>文件名</th><th width="90">大小</th><th width="70">状态</th></tr></thead><tbody>';
        list.forEach(function (f) {
            html += '<tr data-mig-row="' + f.cid + '"><td><input type="checkbox" data-mig-item="' + f.cid + '"'
                + (f.missing ? ' disabled>' : (fmMigrateChecked[f.cid] ? ' checked>' : '>'))
                + '</td><td>' + esc(f.name || f.title)
                + (f.missing ? ' <span style="color:#d9534f;white-space:nowrap">⚠ 本站文件缺失</span>' : '')
                + '</td><td>' + fmtSize(f.size) + '</td><td data-mig-st>' + (f.missing ? '不可迁移' : '待迁移') + '</td></tr>';
        });
        html += '</tbody></table>';
        if (fmMigrateFails.length) {
            html += '<p class="fm-modal-desc" style="margin:10px 0 0;color:#d9534f">上次迁移失败 ' + fmMigrateFails.length
                + ' 个：' + esc(fmMigrateFails.join('；')) + '</p>';
        }
        wrap.innerHTML = html;

        var all = $('fmMigrateAll');
        if (all) {
            all.addEventListener('change', function () {
                list.forEach(function (f) {
                    if (f.missing) { return; }
                    if (all.checked) { fmMigrateChecked[f.cid] = 1; } else { delete fmMigrateChecked[f.cid]; }
                });
                renderMigrateRows();
            });
        }
        Array.prototype.forEach.call(wrap.querySelectorAll('input[data-mig-item]'), function (c) {
            c.addEventListener('change', function () {
                var cid = c.getAttribute('data-mig-item');
                if (c.checked) { fmMigrateChecked[cid] = 1; } else { delete fmMigrateChecked[cid]; }
                var a = $('fmMigrateAll');
                if (a) {
                    var vis = migrateFiltered().filter(function (f) { return !f.missing; });
                    a.checked = vis.length > 0 && vis.every(function (f) { return fmMigrateChecked[f.cid]; });
                }
            });
        });
    }

    function renderMigratePanel() {
        var s3 = fmMigrateType === 's3';
        var html = '<p class="fm-modal-desc">把本站存量附件批量迁移到' + (s3 ? '对象存储' : '兰空图床')
            + '：勾选文件后点「开始迁移」，逐个转发（已转换/已加水印的成品直接迁移）。'
            + (s3 ? '当前为 S3 协议对象存储，所有类型文件都可迁移。' : '当前为兰空图床，仅图片文件可迁移。') + '</p>'
            + '<div class="fm-field"><label class="fm-flabel">迁移后原图处理</label><select id="fmMigrateKeep" style="width:100%">'
            + '<option value="keep-site">保留原图（使用本站域名）</option>'
            + '<option value="keep-ih">保留原图（使用' + ihBadgeText() + '域名）</option>'
            + '<option value="del">删除原图（使用' + ihBadgeText() + '域名）</option>'
            + '</select></div>'
            + '<div class="fm-field"><label class="fm-flabel">搜索文件名 / URL 路径</label>'
            + '<input type="text" id="fmMigrateKw" style="width:100%" placeholder="如 IMG_2024 或 2024/11（匹配文件名与附件 URL 路径，筛选后勾选保留）"></div>'
            + '<div id="fmMigrateListWrap"><p class="fm-loading">加载中…</p></div>'
            + '<p class="fm-modal-desc" id="fmMigrateInfo" style="margin:10px 0 0"></p>';
        $('fmMigrateBody').innerHTML = html;
        $('fmMigrateKw').addEventListener('input', renderMigrateRows);
        renderMigrateRows();
    }

    function loadMigrate() {
        $('fmMigrateBody').innerHTML = '<p class="fm-loading">正在扫描未迁移附件…</p>';
        api('migrateList', {}, { timeout: 30000 }).then(function (d) {
            fmMigrateType = (d.type === 's3') ? 's3' : 'lsky';
            fmMigrateFiles = d.files || [];
            fmMigrateFails = [];
            fmMigrateChecked = {};
            renderMigratePanel();
        }).catch(function (e) {
            $('fmMigrateBody').innerHTML = '<p class="fm-loading">' + esc((e && e.message) || '扫描失败') + '</p>';
        });
    }

    $('fmMigrateBtn').addEventListener('click', function () {
        if (!imageHostReady()) { toast('请先在「图床/对象存储」中配置并启用'); return; }
        openModal('fmMigrateModal');
        loadMigrate();
    });

    $('fmMigrateStart').addEventListener('click', function () {
        if (fmMigrating) { return; }
        var items = fmMigrateFiles.filter(function (f) { return !f.missing && fmMigrateChecked[f.cid]; })
            .map(function (f) { return { cid: String(f.cid), title: f.name || f.title }; });
        if (!items.length) { toast('请先勾选要迁移的文件'); return; }
        var keep = $('fmMigrateKeep').value;
        var delLocal = keep === 'del' ? '1' : '0';
        var useUrl = keep === 'keep-site' ? 'site' : 'ih';
        var btn = this;
        fmMigrating = true;
        btn.disabled = true;
        var done = 0, ok = 0;
        fmMigrateFails = [];
        (function next(i) {
            if (i >= items.length || $('fmMigrateModal').hidden) {
                fmMigrating = false;
                btn.disabled = false;
                var aborted = $('fmMigrateModal').hidden;
                var msg = aborted
                    ? '迁移已中止：成功 ' + ok + ' 个，剩余文件下次继续'
                    : '迁移完成：成功 ' + ok + ' / ' + items.length + ' 个'
                        + (fmMigrateFails.length ? '，失败 ' + fmMigrateFails.length + ' 个' : '');
                toast(msg);
                loadFiles();
                return;
            }
            var it = items[i];
            $('fmMigrateInfo').textContent = '正在迁移 ' + (i + 1) + ' / ' + items.length + '：' + it.title;
            api('imageHostUpload', { cid: it.cid, delLocal: delLocal, useUrl: useUrl }, { timeout: 150000 })
                .then(function () {
                    ok++;
                    delete fmMigrateChecked[it.cid];
                    var row = $('fmMigrateBody').querySelector('tr[data-mig-row="' + it.cid + '"]');
                    if (row) {
                        var c = row.querySelector('input[data-mig-item]');
                        if (c) { c.checked = false; c.disabled = true; }
                        var st = row.querySelector('[data-mig-st]');
                        if (st) { st.textContent = '已迁移 ✓'; }
                    }
                })
                .catch(function (e) {
                    fmMigrateFails.push(it.title + '：' + ((e && e.message) || '迁移失败'));
                })
                .then(function () { next(i + 1); });
        })(0);
    });

    function resolveStartFid() {
        var mode = ['all', 'uncat', 'latest', 'last', 'custom'].indexOf(prefs.startMode) > -1 ? prefs.startMode : 'all';
        if (mode === 'uncat') { return Promise.resolve(-1); }
        if (mode === 'last') {
            var v = window.localStorage ? parseInt(localStorage.getItem('fmLastFid'), 10) : NaN;
            if (!isNaN(v)) {
                if (v === -1 || v === 0) { return Promise.resolve(v); }
                for (var i = 0; i < state.folders.length; i++) {
                    if (state.folders[i].fid === v) { return Promise.resolve(v); }
                }
            }
            return Promise.resolve(0); /* 上次的文件夹已被删除 → 所有文件 */
        }
        if (mode === 'custom') {
            if (prefs.startFid > 0) {
                for (var j = 0; j < state.folders.length; j++) {
                    if (state.folders[j].fid === prefs.startFid) { return Promise.resolve(prefs.startFid); }
                }
            }
            return Promise.resolve(0); /* 指定文件夹不存在 → 所有文件 */
        }
        if (mode === 'latest') {
            return fetchLatestFid().then(function (fid) { return fid || 0; });
        }
        return Promise.resolve(0); /* all */
    }

    (function initChain() {
        loadPrefs().then(function () {
            state.gridSize = initialGridSize();
            renderGridSizeMenu();
            state.view = initialView();
            return loadFolders();
        }).then(function () {
            if ((prefs.uploadMode === 'latest' || prefs.pasteMode === 'latest') && prefs.startMode !== 'latest') { return fetchLatestFid(); }
            return null;
        }).then(function () {
            return resolveStartFid();
        }).then(function (fid) {
            if (fid !== null && fid !== undefined && state.fid !== fid) {
                state.fid = fid;
            }
            if (state.fid > 0) {
                expandAncestors(state.fid); /* 偏好指定的文件夹在树中沿路径全部展开 */
                renderTree();
            }
            renderMoveTarget();
            applyView();
        });
    })();
})();
</script>
<?php

FileManager_ImageTool::editorPanel();
FileManager_ImageTool::editorScript();

require __TYPECHO_ROOT_DIR__ . '/admin/copyright.php';
require __TYPECHO_ROOT_DIR__ . '/admin/common-js.php';
require __TYPECHO_ROOT_DIR__ . '/admin/footer.php';
