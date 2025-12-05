/**
 * Intelligent Checker - Gutenberg Editor Script
 * 画像ALTチェック、URL直書きアラート、タイトルセルフチェックを統合
 */
(function(wp) {
    'use strict';

    const { subscribe, select, dispatch } = wp.data;
    const { createElement, useState, useEffect } = wp.element;
    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel } = wp.editPost;

    // 設定を取得
    const config = window.intelligentCheckerConfig || {};
    const l10n = config.l10n || {};

    // ========================================
    // ALT Checker Module
    // ========================================
    const AltChecker = {
        /**
         * ALT未設定の画像ブロックを取得
         */
        getImagesWithoutAlt: function() {
            const blocks = select('core/block-editor').getBlocks();
            const imagesWithoutAlt = [];

            function checkBlocks(blocks) {
                blocks.forEach(block => {
                    if (block.name === 'core/image') {
                        if (!block.attributes.alt || block.attributes.alt.trim() === '') {
                            imagesWithoutAlt.push({
                                clientId: block.clientId,
                                id: block.attributes.id
                            });
                        }
                    }

                    if (block.name === 'core/gallery') {
                        const images = block.attributes.images || [];
                        images.forEach((image) => {
                            if (!image.alt || image.alt.trim() === '') {
                                imagesWithoutAlt.push({
                                    clientId: block.clientId,
                                    id: image.id,
                                    isGallery: true
                                });
                            }
                        });
                    }

                    if (block.name === 'core/cover' && block.attributes.url) {
                        if (!block.attributes.alt || block.attributes.alt.trim() === '') {
                            imagesWithoutAlt.push({
                                clientId: block.clientId,
                                id: block.attributes.id
                            });
                        }
                    }

                    if (block.name === 'core/media-text' && block.attributes.mediaType === 'image') {
                        if (!block.attributes.mediaAlt || block.attributes.mediaAlt.trim() === '') {
                            imagesWithoutAlt.push({
                                clientId: block.clientId,
                                id: block.attributes.mediaId
                            });
                        }
                    }

                    if (block.innerBlocks && block.innerBlocks.length > 0) {
                        checkBlocks(block.innerBlocks);
                    }
                });
            }

            checkBlocks(blocks);
            return imagesWithoutAlt;
        },

        /**
         * すべての画像ブロックを取得
         */
        getAllImages: function() {
            const blocks = select('core/block-editor').getBlocks();
            const allImages = [];

            function checkBlocks(blocks) {
                blocks.forEach(block => {
                    if (block.name === 'core/image') {
                        allImages.push({
                            clientId: block.clientId,
                            id: block.attributes.id,
                            alt: block.attributes.alt || '',
                            hasAlt: !!(block.attributes.alt && block.attributes.alt.trim() !== '')
                        });
                    }

                    if (block.innerBlocks && block.innerBlocks.length > 0) {
                        checkBlocks(block.innerBlocks);
                    }
                });
            }

            checkBlocks(blocks);
            return allImages;
        },

        /**
         * 画像ブロックにバッジを追加
         */
        updateImageBadges: function() {
            const imagesWithoutAlt = this.getImagesWithoutAlt();

            document.querySelectorAll('.ic-alt-badge').forEach(el => el.remove());
            document.querySelectorAll('.ic-alt-highlight').forEach(el => {
                el.classList.remove('ic-alt-highlight');
            });

            imagesWithoutAlt.forEach(image => {
                const blockElement = document.querySelector(`[data-block="${image.clientId}"]`);
                if (blockElement) {
                    blockElement.classList.add('ic-alt-highlight');

                    const imgContainer = blockElement.querySelector('.wp-block-image, .components-resizable-box__container, figure');
                    if (imgContainer && !imgContainer.querySelector('.ic-alt-badge')) {
                        const badge = document.createElement('div');
                        badge.className = 'ic-alt-badge';
                        badge.innerHTML = `
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            <span>${l10n.altBadgeText || 'ALT未設定'}</span>
                        `;
                        imgContainer.style.position = 'relative';
                        imgContainer.appendChild(badge);
                    }
                }
            });
        },

        /**
         * タイトル下にアラートバナーを表示
         */
        updateAlertBanner: function() {
            const imagesWithoutAlt = this.getImagesWithoutAlt();
            const count = imagesWithoutAlt.length;

            document.querySelectorAll('.ic-alt-alert-banner').forEach(el => el.remove());

            if (count === 0) return;

            const banner = document.createElement('div');
            banner.className = 'ic-alt-alert-banner';
            banner.innerHTML = `
                <div class="ic-alt-alert-content">
                    <div class="ic-alt-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                    <div class="ic-alt-alert-text">
                        <p class="ic-alt-alert-title">
                            <strong>${count}${l10n.altAlertTitle || '件の画像にALT属性が設定されていません'}</strong>
                        </p>
                        <p class="ic-alt-alert-desc">
                            ${l10n.altAlertDesc || 'アクセシビリティ向上のため、すべての画像に代替テキストを設定してください'}
                        </p>
                    </div>
                </div>
                <button class="ic-alt-alert-button" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                        <polyline points="21 15 16 10 5 21"></polyline>
                    </svg>
                    ${l10n.altCheckImages || '画像を確認'}
                </button>
            `;

            banner.querySelector('.ic-alt-alert-button').addEventListener('click', function() {
                if (imagesWithoutAlt.length > 0) {
                    const firstImage = imagesWithoutAlt[0];
                    dispatch('core/block-editor').selectBlock(firstImage.clientId);

                    const blockElement = document.querySelector(`[data-block="${firstImage.clientId}"]`);
                    if (blockElement) {
                        blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });

            const titleBlock = document.querySelector('.editor-post-title');
            if (titleBlock) {
                titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                return;
            }

            const editorContent = document.querySelector('.editor-styles-wrapper');
            if (editorContent) {
                editorContent.insertBefore(banner, editorContent.firstChild);
            }
        }
    };

    // ========================================
    // Naked URL Alert Module
    // ========================================
    const NakedUrlAlert = {
        urlPattern: /https?:\/\/[^\s<>"']+/gi,
        isListOpen: false, // 開閉状態を保持

        /**
         * HTMLからURL直書きリンクを検出
         */
        findNakedUrls: function(content) {
            if (!content) return [];

            const nakedUrls = [];
            const linkPattern = /<a\s+[^>]*href\s*=\s*["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi;

            let match;
            while ((match = linkPattern.exec(content)) !== null) {
                const href = match[1];
                const anchorText = match[2].replace(/<[^>]*>/g, '').trim();

                if (this.isNakedUrl(href, anchorText)) {
                    nakedUrls.push({
                        href: href,
                        anchorText: anchorText
                    });
                }
            }

            return nakedUrls;
        },

        /**
         * アンカーテキストがURL直書きかどうかを判定
         */
        isNakedUrl: function(href, anchorText) {
            if (!anchorText) return false;

            const isUrl = this.urlPattern.test(anchorText);
            this.urlPattern.lastIndex = 0;

            if (!isUrl) return false;

            const normalizeUrl = (url) => {
                return url
                    .toLowerCase()
                    .replace(/^https?:\/\//, '')
                    .replace(/^www\./, '')
                    .replace(/\/+$/, '')
                    .trim();
            };

            const normalizedHref = normalizeUrl(href);
            const normalizedAnchor = normalizeUrl(anchorText);

            return normalizedHref === normalizedAnchor ||
                   normalizedHref.includes(normalizedAnchor) ||
                   normalizedAnchor.includes(normalizedHref);
        },

        /**
         * ブロックからコンテンツを抽出
         */
        extractContentFromBlocks: function(blocks) {
            let content = '';

            blocks.forEach(block => {
                if (block.attributes) {
                    if (block.attributes.content) {
                        content += block.attributes.content + ' ';
                    }
                    if (block.attributes.value) {
                        content += block.attributes.value + ' ';
                    }
                    if (block.attributes.citation) {
                        content += block.attributes.citation + ' ';
                    }
                }

                if (block.innerBlocks && block.innerBlocks.length > 0) {
                    content += this.extractContentFromBlocks(block.innerBlocks);
                }
            });

            return content;
        },

        /**
         * HTMLエスケープ
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * アラートを更新
         */
        updateAlert: function(nakedUrls) {
            const self = this;
            const existingAlert = document.getElementById('ic-naked-url-alert');

            // URLがない場合は削除して終了
            if (nakedUrls.length === 0) {
                if (existingAlert) {
                    existingAlert.remove();
                }
                return;
            }

            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (!titleWrapper) return;

            // 既存のアラートがある場合は内容だけ更新
            if (existingAlert) {
                const list = existingAlert.querySelector('.ic-naked-url-list');
                const toggleBtn = existingAlert.querySelector('.ic-naked-url-toggle');

                if (list && toggleBtn) {
                    // リストの内容を更新
                    list.innerHTML = nakedUrls.map(item => `<li class="ic-naked-url-item"><code>${this.escapeHtml(item.anchorText)}</code></li>`).join('');
                    // ボタンテキストを更新（開閉状態を維持）
                    toggleBtn.textContent = `${l10n.nakedUrlDetail || '該当箇所'} (${nakedUrls.length}件) ${self.isListOpen ? '▲' : '▼'}`;
                    return;
                }
            }

            // 新規作成
            if (existingAlert) {
                existingAlert.remove();
            }

            const alertContainer = document.createElement('div');
            alertContainer.id = 'ic-naked-url-alert';
            alertContainer.className = 'ic-naked-url-notice';

            alertContainer.innerHTML = `
                <div class="ic-naked-url-header">
                    <span class="ic-naked-url-icon">⚠️</span>
                    <span class="ic-naked-url-message">${l10n.nakedUrlMessage || 'URLが直書きでリンクされている箇所があります。'}</span>
                </div>
                <button class="ic-naked-url-toggle" type="button">
                    ${l10n.nakedUrlDetail || '該当箇所'} (${nakedUrls.length}件) ${self.isListOpen ? '▲' : '▼'}
                </button>
                <ul class="ic-naked-url-list" style="display: ${self.isListOpen ? 'block' : 'none'};">
                    ${nakedUrls.map(item => `<li class="ic-naked-url-item"><code>${this.escapeHtml(item.anchorText)}</code></li>`).join('')}
                </ul>
            `;

            titleWrapper.parentNode.insertBefore(alertContainer, titleWrapper.nextSibling);

            const toggleBtn = alertContainer.querySelector('.ic-naked-url-toggle');
            const list = alertContainer.querySelector('.ic-naked-url-list');

            toggleBtn.addEventListener('click', () => {
                self.isListOpen = !self.isListOpen;
                list.style.display = self.isListOpen ? 'block' : 'none';
                toggleBtn.textContent = `${l10n.nakedUrlDetail || '該当箇所'} (${nakedUrls.length}件) ${self.isListOpen ? '▲' : '▼'}`;
            });
        }
    };

    // ========================================
    // Title Checker Module
    // ========================================
    const TitleChecker = {
        /**
         * 文字数の状態を判定
         */
        getCharCountStatus: function(charCount) {
            const charLimit = config.charLimit || { min: 28, max: 40 };

            if (charCount === 0) {
                return { icon: '⚪', message: '未入力', className: 'status-empty' };
            } else if (charCount < charLimit.min) {
                return { icon: '⚠️', message: '短すぎます', className: 'status-warning' };
            } else if (charCount > charLimit.max) {
                return { icon: '⚠️', message: '長すぎます', className: 'status-warning' };
            } else {
                return { icon: '✅', message: '適切', className: 'status-ok' };
            }
        },

        /**
         * キーワードチェック結果を生成
         */
        getKeywordResults: function(keywords, title) {
            return keywords.map(function(keyword) {
                const isIncluded = title.includes(keyword);
                return {
                    keyword: keyword,
                    isIncluded: isIncluded,
                    icon: isIncluded ? '✅' : '❌'
                };
            });
        },

        /**
         * パネルHTMLを生成
         */
        generatePanelHTML: function(postTitle) {
            const charLimit = config.charLimit || { min: 28, max: 40 };
            const keywords = config.keywords || { required: [], recommended: [] };
            const checklistItems = config.checklistItems || [];

            const charCount = postTitle.length;
            const charStatus = this.getCharCountStatus(charCount);
            const requiredResults = this.getKeywordResults(keywords.required || [], postTitle);
            const recommendedResults = this.getKeywordResults(keywords.recommended || [], postTitle);
            const requiredIncludedCount = requiredResults.filter(r => r.isIncluded).length;
            const recommendedIncludedCount = recommendedResults.filter(r => r.isIncluded).length;

            const requiredItemsHTML = requiredResults.map(result => {
                const className = result.isIncluded ? 'keyword-included' : 'keyword-missing';
                return `<span class="ic-keyword-item ${className}">${result.icon} ${result.keyword}</span>`;
            }).join('');

            const recommendedItemsHTML = recommendedResults.map(result => {
                const className = result.isIncluded ? 'keyword-included' : 'keyword-optional';
                return `<span class="ic-keyword-item ${className}">${result.icon} ${result.keyword}</span>`;
            }).join('');

            const checklistItemsHTML = checklistItems.map((item, index) => `
                <label class="ic-selfcheck-item">
                    <input type="checkbox" class="ic-selfcheck-checkbox" id="ic-selfcheck-${index}">
                    <span class="ic-selfcheck-text">${item}</span>
                </label>
            `).join('');

            return `
                <div class="ic-title-checker-inner">
                    <div class="ic-title-checker-header">
                        <span class="ic-title-checker-icon">📝</span>
                        <span class="ic-title-checker-title">タイトルチェック</span>
                    </div>
                    <div class="ic-title-checker-content">
                        <div class="ic-check-section ic-char-count-section">
                            <div class="ic-section-label">文字数（目安なので絶対条件ではありません）</div>
                            <div class="ic-section-value ${charStatus.className}">
                                <span class="ic-char-count-number">${charCount}</span>文字
                                <span class="ic-char-status-icon">${charStatus.icon}</span>
                                <span class="ic-char-status-message">${charStatus.message}</span>
                                <span class="ic-char-limit-info">（推奨: ${charLimit.min}〜${charLimit.max}文字）</span>
                            </div>
                        </div>
                        <div class="ic-check-section ic-keyword-section">
                            <div class="ic-section-label">必須KW <span class="ic-keyword-count">${requiredIncludedCount}/${requiredResults.length}</span></div>
                            <div class="ic-keyword-list">
                                ${requiredItemsHTML}
                            </div>
                        </div>
                        <div class="ic-check-section ic-keyword-section">
                            <div class="ic-section-label">推奨KW <span class="ic-keyword-count">${recommendedIncludedCount}/${recommendedResults.length}</span></div>
                            <div class="ic-keyword-list">
                                ${recommendedItemsHTML}
                            </div>
                        </div>
                        <div class="ic-check-section ic-selfcheck-section">
                            <div class="ic-section-label">📋 セルフチェックリスト</div>
                            <div class="ic-selfcheck-list">
                                ${checklistItemsHTML}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        /**
         * パネルを更新
         */
        updatePanel: function(postTitle) {
            const existingPanel = document.getElementById('ic-title-checker-panel');
            if (existingPanel) {
                existingPanel.innerHTML = this.generatePanelHTML(postTitle);
                return;
            }

            // まだ挿入されていない場合は挿入
            this.insertPanel(postTitle);
        },

        /**
         * パネルを挿入
         */
        insertPanel: function(postTitle) {
            // 既に存在する場合は挿入しない
            if (document.getElementById('ic-title-checker-panel')) {
                return;
            }

            const titleBlock = document.querySelector('.editor-post-title__block');
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            const targetElement = titleBlock || titleWrapper;

            if (!targetElement) return;

            const panel = document.createElement('div');
            panel.id = 'ic-title-checker-panel';
            panel.innerHTML = this.generatePanelHTML(postTitle);

            targetElement.parentNode.insertBefore(panel, targetElement.nextSibling);
        }
    };

    // ========================================
    // Long Paragraph Checker Module
    // ========================================
    const LongParagraphChecker = {
        /**
         * 長い段落ブロックを検出
         */
        findLongParagraphs: function() {
            const blocks = select('core/block-editor').getBlocks();
            const threshold = config.longParagraphThreshold || 200;
            const issues = [];

            function checkBlocks(blocks) {
                blocks.forEach(block => {
                    if (block.name === 'core/paragraph' && block.attributes.content) {
                        const content = block.attributes.content;
                        // HTMLタグを除去してプレーンテキストの文字数を取得
                        const plainText = content.replace(/<[^>]*>/g, '');

                        if (plainText.length >= threshold) {
                            issues.push({
                                clientId: block.clientId,
                                charCount: plainText.length
                            });
                        }
                    }

                    if (block.innerBlocks && block.innerBlocks.length > 0) {
                        checkBlocks(block.innerBlocks);
                    }
                });
            }

            checkBlocks(blocks);
            return issues;
        },

        /**
         * 段落ブロックにハイライト表示
         */
        updateHighlights: function(issues) {
            // 既存のハイライトを削除
            document.querySelectorAll('.ic-long-paragraph-highlight').forEach(el => {
                el.classList.remove('ic-long-paragraph-highlight');
            });

            // 該当ブロックにハイライトを追加
            issues.forEach(issue => {
                const blockElement = document.querySelector(`[data-block="${issue.clientId}"]`);
                if (blockElement) {
                    blockElement.classList.add('ic-long-paragraph-highlight');
                }
            });
        },

        /**
         * タイトル下にアラートバナーを表示
         */
        updateAlertBanner: function(issues) {
            const count = issues.length;

            // 既存のバナーを削除
            document.querySelectorAll('.ic-long-paragraph-alert-banner').forEach(el => el.remove());

            if (count === 0) return;

            const banner = document.createElement('div');
            banner.className = 'ic-long-paragraph-alert-banner';
            banner.innerHTML = `
                <div class="ic-long-paragraph-alert-content">
                    <div class="ic-long-paragraph-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                    <div class="ic-long-paragraph-alert-text">
                        <p class="ic-long-paragraph-alert-title">
                            <strong>${count}${l10n.longParagraphAlertTitle || '件の段落が長すぎます'}</strong>
                        </p>
                        <p class="ic-long-paragraph-alert-desc">
                            ${l10n.longParagraphAlertDesc || '視認性向上のため、適切な箇所で改行を追加してください'}
                        </p>
                    </div>
                </div>
                <button class="ic-long-paragraph-alert-button" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                    ${l10n.longParagraphCheck || '段落を確認'}
                </button>
            `;

            banner.querySelector('.ic-long-paragraph-alert-button').addEventListener('click', function() {
                if (issues.length > 0) {
                    const firstIssue = issues[0];
                    dispatch('core/block-editor').selectBlock(firstIssue.clientId);

                    const blockElement = document.querySelector(`[data-block="${firstIssue.clientId}"]`);
                    if (blockElement) {
                        blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });

            // タイトル入力欄の後に挿入
            const titleBlock = document.querySelector('.editor-post-title');
            if (titleBlock) {
                titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                return;
            }

            const editorContent = document.querySelector('.editor-styles-wrapper');
            if (editorContent) {
                editorContent.insertBefore(banner, editorContent.firstChild);
            }
        }
    };

    // ========================================
    // Main Plugin Component
    // ========================================
    function IntelligentCheckerPlugin() {
        const [nakedUrls, setNakedUrls] = useState([]);
        const [images, setImages] = useState([]);
        const [missingAltCount, setMissingAltCount] = useState(0);

        // タイトルを監視
        const postTitle = wp.data.useSelect(function(sel) {
            return sel('core/editor').getEditedPostAttribute('title') || '';
        }, []);

        // 初期化と更新
        useEffect(() => {
            let debounceTimer = null;

            const updateAll = () => {
                // ALT Checker
                if (config.altCheckerEnabled) {
                    AltChecker.updateImageBadges();
                    AltChecker.updateAlertBanner();
                    const allImages = AltChecker.getAllImages();
                    setImages(allImages);
                    setMissingAltCount(allImages.filter(img => !img.hasAlt).length);
                }

                // Naked URL Alert
                if (config.nakedUrlEnabled) {
                    const blocks = select('core/block-editor').getBlocks();
                    const content = NakedUrlAlert.extractContentFromBlocks(blocks);
                    const found = NakedUrlAlert.findNakedUrls(content);
                    setNakedUrls(found);
                }

                // Long Paragraph Checker
                if (config.longParagraphEnabled) {
                    const longParagraphs = LongParagraphChecker.findLongParagraphs();
                    LongParagraphChecker.updateHighlights(longParagraphs);
                    LongParagraphChecker.updateAlertBanner(longParagraphs);
                }
            };

            const debouncedUpdate = () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(updateAll, 300);
            };

            // 初期実行
            setTimeout(updateAll, 1000);

            // 変更を監視
            const unsubscribe = subscribe(debouncedUpdate);

            return () => {
                unsubscribe();
                clearTimeout(debounceTimer);
            };
        }, []);

        // Naked URL Alert DOM更新
        useEffect(() => {
            if (config.nakedUrlEnabled) {
                const timer = setTimeout(() => {
                    NakedUrlAlert.updateAlert(nakedUrls);
                }, 100);
                return () => clearTimeout(timer);
            }
        }, [nakedUrls]);

        // Title Checker DOM更新
        useEffect(() => {
            if (config.titleCheckerEnabled) {
                const timer = setTimeout(() => {
                    TitleChecker.updatePanel(postTitle);
                }, 100);
                return () => clearTimeout(timer);
            }
        }, [postTitle]);

        // Title Checker初期挿入
        useEffect(() => {
            if (config.titleCheckerEnabled) {
                const insertChecker = () => {
                    const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
                    if (!titleWrapper) {
                        setTimeout(insertChecker, 500);
                        return;
                    }
                    TitleChecker.insertPanel(postTitle);
                };
                setTimeout(insertChecker, 1000);
            }
        }, []);

        // サイドバーパネル（ALT Checker用）
        if (!config.altCheckerEnabled || images.length === 0) {
            return null;
        }

        const handleImageClick = (clientId) => {
            dispatch('core/block-editor').selectBlock(clientId);
            const blockElement = document.querySelector(`[data-block="${clientId}"]`);
            if (blockElement) {
                blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        };

        return createElement(
            PluginDocumentSettingPanel,
            {
                name: 'intelligent-checker-alt-panel',
                title: l10n.altPanelTitle || '画像ALTチェック',
                className: 'ic-alt-panel'
            },
            createElement(
                'div',
                { className: 'ic-alt-panel-content' },
                missingAltCount > 0
                    ? createElement('div', { className: 'ic-alt-panel-warning' },
                        `⚠️ ${missingAltCount}件のALT未設定`
                    )
                    : createElement('div', { className: 'ic-alt-panel-success' },
                        `✓ ${l10n.altAllSet || 'すべての画像にALTが設定されています'}`
                    ),
                createElement(
                    'div',
                    { className: 'ic-alt-image-list' },
                    images.map((image, index) =>
                        createElement(
                            'div',
                            {
                                key: image.clientId,
                                className: `ic-alt-image-item ${image.hasAlt ? 'set' : 'missing'}`,
                                onClick: () => handleImageClick(image.clientId)
                            },
                            createElement('span', { className: 'ic-alt-image-label' },
                                `${l10n.altImageLabel || '画像'} ${index + 1}`
                            ),
                            createElement('span', { className: `ic-alt-status ${image.hasAlt ? 'set' : 'missing'}` },
                                image.hasAlt
                                    ? `✓ ${l10n.altStatusSet || '設定済み'}`
                                    : `⚠️ ${l10n.altStatusMissing || '未設定'}`
                            )
                        )
                    )
                )
            )
        );
    }

    // プラグインを登録
    registerPlugin('intelligent-checker', {
        render: IntelligentCheckerPlugin
    });

})(window.wp);
