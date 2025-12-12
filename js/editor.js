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
    const { SelectControl } = wp.components;
    const { useEntityProp } = wp.coreData;

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
                const countBadge = existingAlert.querySelector('.ic-naked-url-count');

                if (list && countBadge) {
                    // リストの内容を更新
                    list.innerHTML = nakedUrls.map(item => `<li class="ic-naked-url-item"><code>${this.escapeHtml(item.anchorText)}</code></li>`).join('');
                    // カウント表示を更新
                    countBadge.textContent = `${nakedUrls.length}件`;
                    // リスト表示状態を維持
                    list.style.display = self.isListOpen ? 'block' : 'none';
                    return;
                }
            }

            // 新規作成
            if (existingAlert) {
                existingAlert.remove();
            }

            const alertContainer = document.createElement('div');
            alertContainer.id = 'ic-naked-url-alert';
            alertContainer.className = 'ic-naked-url-alert-banner';

            alertContainer.innerHTML = `
                <div class="ic-naked-url-alert-content">
                    <div class="ic-naked-url-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                        </svg>
                    </div>
                    <div class="ic-naked-url-alert-text">
                        <p class="ic-naked-url-alert-title">
                            <strong>${l10n.nakedUrlTitle || 'URLが直書きでリンクされている箇所があります'}</strong>
                        </p>
                        <p class="ic-naked-url-alert-desc">
                            ${l10n.nakedUrlDesc || '意図せずURLになっていないかを確認してください'}
                        </p>
                        <ul class="ic-naked-url-list" style="display: ${self.isListOpen ? 'block' : 'none'};">
                            ${nakedUrls.map(item => `<li class="ic-naked-url-item"><code>${this.escapeHtml(item.anchorText)}</code></li>`).join('')}
                        </ul>
                    </div>
                </div>
                <button class="ic-naked-url-alert-button" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    ${l10n.nakedUrlDetail || '該当箇所'} <span class="ic-naked-url-count">${nakedUrls.length}件</span>
                </button>
            `;

            titleWrapper.parentNode.insertBefore(alertContainer, titleWrapper.nextSibling);

            const toggleBtn = alertContainer.querySelector('.ic-naked-url-alert-button');
            const list = alertContainer.querySelector('.ic-naked-url-list');

            toggleBtn.addEventListener('click', () => {
                self.isListOpen = !self.isListOpen;
                list.style.display = self.isListOpen ? 'block' : 'none';
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
         * ブロックが除外クラスの配下にあるかチェック
         */
        isExcluded: function(clientId) {
            const excludeClasses = config.longParagraphExcludeClasses || [];
            if (excludeClasses.length === 0) return false;

            const blockElement = document.querySelector(`[data-block="${clientId}"]`);
            if (!blockElement) return false;

            // 親要素を辿って除外クラスを持つ要素があるかチェック
            let parent = blockElement.parentElement;
            while (parent) {
                for (const className of excludeClasses) {
                    if (parent.classList && parent.classList.contains(className)) {
                        return true;
                    }
                }
                parent = parent.parentElement;
            }

            return false;
        },

        /**
         * 長い段落ブロックを検出
         */
        findLongParagraphs: function() {
            const self = this;
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
                            // 除外クラスの配下でないかチェック
                            if (!self.isExcluded(block.clientId)) {
                                issues.push({
                                    clientId: block.clientId,
                                    charCount: plainText.length
                                });
                            }
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
    // Forbidden Keyword Checker Module
    // ========================================
    const ForbiddenKeywordChecker = {
        /**
         * タイトル内に含まれる禁止キーワードを検出
         */
        findForbiddenKeywords: function(title) {
            const keywords = config.forbiddenKeywords || [];
            const found = [];

            keywords.forEach(keyword => {
                if (!keyword) return;

                // キーワードが含まれているかチェック
                if (title.includes(keyword)) {
                    found.push(keyword);
                }
            });

            return found;
        },

        /**
         * アラートバナーを更新
         */
        updateAlertBanner: function(title) {
            // 既存のバナーを削除
            document.querySelectorAll('.ic-forbidden-keyword-alert-banner').forEach(el => el.remove());

            const foundKeywords = this.findForbiddenKeywords(title);

            // 禁止キーワードがなければ何もしない
            if (foundKeywords.length === 0) {
                return;
            }

            const keywordsDisplay = foundKeywords.map(k => `「${k}」`).join('、');

            const banner = document.createElement('div');
            banner.className = 'ic-forbidden-keyword-alert-banner';
            banner.innerHTML = `
                <div class="ic-forbidden-keyword-alert-content">
                    <div class="ic-forbidden-keyword-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                        </svg>
                    </div>
                    <div class="ic-forbidden-keyword-alert-text">
                        <p class="ic-forbidden-keyword-alert-title">
                            <strong>${l10n.forbiddenKeywordTitle || 'タイトルに使用できないキーワードが含まれています'}</strong>
                        </p>
                        <p class="ic-forbidden-keyword-alert-desc">
                            ${l10n.forbiddenKeywordDesc || '以下のキーワードはタイトルに使用できません。別の表現に変更してください。'}
                            <br>
                            <span class="ic-forbidden-keyword-list">${l10n.forbiddenKeywordList || '禁止キーワード'}: ${keywordsDisplay}</span>
                        </p>
                    </div>
                </div>
            `;

            // タイトル入力欄の後に挿入
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (titleWrapper) {
                titleWrapper.parentNode.insertBefore(banner, titleWrapper.nextSibling);
            } else {
                const titleBlock = document.querySelector('.editor-post-title');
                if (titleBlock) {
                    titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                }
            }
        }
    };

    // ========================================
    // Caution Keyword Checker Module
    // ========================================
    const CautionKeywordChecker = {
        /**
         * タイトル内に含まれる要注意キーワードを検出
         */
        findCautionKeywords: function(title) {
            const keywords = config.cautionKeywords || [];
            const found = [];

            keywords.forEach(keyword => {
                if (!keyword) return;

                // キーワードが含まれているかチェック
                if (title.includes(keyword)) {
                    found.push(keyword);
                }
            });

            return found;
        },

        /**
         * アラートバナーを更新
         */
        updateAlertBanner: function(title) {
            // 既存のバナーを削除
            document.querySelectorAll('.ic-caution-keyword-alert-banner').forEach(el => el.remove());

            const foundKeywords = this.findCautionKeywords(title);

            // 要注意キーワードがなければ何もしない
            if (foundKeywords.length === 0) {
                return;
            }

            const keywordsDisplay = foundKeywords.map(k => `「${k}」`).join('、');

            const banner = document.createElement('div');
            banner.className = 'ic-caution-keyword-alert-banner';
            banner.innerHTML = `
                <div class="ic-caution-keyword-alert-content">
                    <div class="ic-caution-keyword-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                    <div class="ic-caution-keyword-alert-text">
                        <p class="ic-caution-keyword-alert-title">
                            <strong>${l10n.cautionKeywordTitle || 'タイトルに要注意キーワードが含まれています'}</strong>
                        </p>
                        <p class="ic-caution-keyword-alert-desc">
                            ${l10n.cautionKeywordDesc || '以下のキーワードが含まれています。問題がないか確認してください。'}
                            <br>
                            <span class="ic-caution-keyword-list">${l10n.cautionKeywordList || '要注意キーワード'}: ${keywordsDisplay}</span>
                        </p>
                    </div>
                </div>
            `;

            // タイトル入力欄の後に挿入
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (titleWrapper) {
                titleWrapper.parentNode.insertBefore(banner, titleWrapper.nextSibling);
            } else {
                const titleBlock = document.querySelector('.editor-post-title');
                if (titleBlock) {
                    titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                }
            }
        }
    };

    // ========================================
    // Heading Caution Keyword Checker Module
    // ========================================
    const HeadingCautionKeywordChecker = {
        /**
         * H2見出しから要注意キーワードを含むものを検出
         */
        findH2WithCautionKeywords: function() {
            const keywords = config.cautionKeywordsHeading || [];
            const blocks = select('core/block-editor').getBlocks();
            const issues = [];

            function checkBlocks(blocks) {
                blocks.forEach(block => {
                    if (block.name === 'core/heading') {
                        const level = block.attributes.level || 2;
                        // H2のみを対象とする
                        if (level === 2) {
                            const content = block.attributes.content || '';
                            const plainText = content.replace(/<[^>]*>/g, '').trim();

                            // 要注意キーワードをチェック
                            const foundKeywords = [];
                            keywords.forEach(keyword => {
                                if (!keyword) return;
                                if (plainText.includes(keyword)) {
                                    foundKeywords.push(keyword);
                                }
                            });

                            if (foundKeywords.length > 0) {
                                issues.push({
                                    clientId: block.clientId,
                                    text: plainText,
                                    keywords: foundKeywords
                                });
                            }
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
         * H2ブロックにハイライト表示
         */
        updateHighlights: function(issues) {
            // 既存のハイライトを削除
            document.querySelectorAll('.ic-heading-caution-highlight').forEach(el => {
                el.classList.remove('ic-heading-caution-highlight');
            });

            // 該当ブロックにハイライトを追加
            issues.forEach(issue => {
                const blockElement = document.querySelector(`[data-block="${issue.clientId}"]`);
                if (blockElement) {
                    blockElement.classList.add('ic-heading-caution-highlight');
                }
            });
        },

        /**
         * アラートバナーを更新
         */
        updateAlertBanner: function() {
            // 既存のバナーを削除
            document.querySelectorAll('.ic-heading-caution-keyword-alert-banner').forEach(el => el.remove());

            const issues = this.findH2WithCautionKeywords();

            // ハイライトを更新
            this.updateHighlights(issues);

            // 問題がなければ何もしない
            if (issues.length === 0) {
                return;
            }

            // 見出しごとの表示を作成
            const issuesList = issues.map(issue => {
                const keywordsDisplay = issue.keywords.map(k => `「${k}」`).join('、');
                return `<li class="ic-heading-caution-item" data-client-id="${issue.clientId}">
                    <span class="ic-heading-caution-text">${issue.text}</span>
                    <span class="ic-heading-caution-keywords">${keywordsDisplay}</span>
                </li>`;
            }).join('');

            const banner = document.createElement('div');
            banner.className = 'ic-heading-caution-keyword-alert-banner';
            banner.innerHTML = `
                <div class="ic-heading-caution-keyword-alert-content">
                    <div class="ic-heading-caution-keyword-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                    <div class="ic-heading-caution-keyword-alert-text">
                        <p class="ic-heading-caution-keyword-alert-title">
                            <strong>${l10n.headingCautionKeywordTitle || 'H2見出しに要注意キーワードが含まれています'}</strong>
                        </p>
                        <p class="ic-heading-caution-keyword-alert-desc">
                            ${l10n.headingCautionKeywordDesc || '以下の見出しに要注意キーワードが含まれています。問題がないか確認してください。'}
                        </p>
                        <ul class="ic-heading-caution-list">
                            ${issuesList}
                        </ul>
                    </div>
                </div>
                <button class="ic-heading-caution-keyword-alert-button" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                    ${l10n.headingCautionKeywordCheck || '見出しを確認'}
                </button>
            `;

            // ボタンクリックで最初の問題のある見出しにスクロール
            banner.querySelector('.ic-heading-caution-keyword-alert-button').addEventListener('click', function() {
                if (issues.length > 0) {
                    const firstIssue = issues[0];
                    dispatch('core/block-editor').selectBlock(firstIssue.clientId);

                    const blockElement = document.querySelector(`[data-block="${firstIssue.clientId}"]`);
                    if (blockElement) {
                        blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });

            // リスト項目クリックで該当見出しにスクロール
            banner.querySelectorAll('.ic-heading-caution-item').forEach(item => {
                item.addEventListener('click', function() {
                    const clientId = this.getAttribute('data-client-id');
                    if (clientId) {
                        dispatch('core/block-editor').selectBlock(clientId);

                        const blockElement = document.querySelector(`[data-block="${clientId}"]`);
                        if (blockElement) {
                            blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                });
            });

            // タイトル入力欄の後に挿入
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (titleWrapper) {
                titleWrapper.parentNode.insertBefore(banner, titleWrapper.nextSibling);
            } else {
                const titleBlock = document.querySelector('.editor-post-title');
                if (titleBlock) {
                    titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                }
            }
        }
    };

    // ========================================
    // Heading Forbidden Keyword Checker Module
    // ========================================
    const HeadingForbiddenKeywordChecker = {
        /**
         * H2見出しから禁止キーワードを含むものを検出
         */
        findH2WithForbiddenKeywords: function() {
            const keywords = config.forbiddenKeywordsHeading || [];
            const blocks = select('core/block-editor').getBlocks();
            const issues = [];

            function checkBlocks(blocks) {
                blocks.forEach(block => {
                    if (block.name === 'core/heading') {
                        const level = block.attributes.level || 2;
                        // H2のみを対象とする
                        if (level === 2) {
                            const content = block.attributes.content || '';
                            const plainText = content.replace(/<[^>]*>/g, '').trim();

                            // 禁止キーワードをチェック
                            const foundKeywords = [];
                            keywords.forEach(keyword => {
                                if (!keyword) return;
                                if (plainText.includes(keyword)) {
                                    foundKeywords.push(keyword);
                                }
                            });

                            if (foundKeywords.length > 0) {
                                issues.push({
                                    clientId: block.clientId,
                                    text: plainText,
                                    keywords: foundKeywords
                                });
                            }
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
         * H2ブロックにハイライト表示
         */
        updateHighlights: function(issues) {
            // 既存のハイライトを削除
            document.querySelectorAll('.ic-heading-forbidden-highlight').forEach(el => {
                el.classList.remove('ic-heading-forbidden-highlight');
            });

            // 該当ブロックにハイライトを追加
            issues.forEach(issue => {
                const blockElement = document.querySelector(`[data-block="${issue.clientId}"]`);
                if (blockElement) {
                    blockElement.classList.add('ic-heading-forbidden-highlight');
                }
            });
        },

        /**
         * アラートバナーを更新
         */
        updateAlertBanner: function() {
            // 既存のバナーを削除
            document.querySelectorAll('.ic-heading-forbidden-keyword-alert-banner').forEach(el => el.remove());

            const issues = this.findH2WithForbiddenKeywords();

            // ハイライトを更新
            this.updateHighlights(issues);

            // 問題がなければ何もしない
            if (issues.length === 0) {
                return;
            }

            // 見出しごとの表示を作成
            const issuesList = issues.map(issue => {
                const keywordsDisplay = issue.keywords.map(k => `「${k}」`).join('、');
                return `<li class="ic-heading-forbidden-item" data-client-id="${issue.clientId}">
                    <span class="ic-heading-forbidden-text">${issue.text}</span>
                    <span class="ic-heading-forbidden-keywords">${keywordsDisplay}</span>
                </li>`;
            }).join('');

            const banner = document.createElement('div');
            banner.className = 'ic-heading-forbidden-keyword-alert-banner';
            banner.innerHTML = `
                <div class="ic-heading-forbidden-keyword-alert-content">
                    <div class="ic-heading-forbidden-keyword-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="15" y1="9" x2="9" y2="15"></line>
                            <line x1="9" y1="9" x2="15" y2="15"></line>
                        </svg>
                    </div>
                    <div class="ic-heading-forbidden-keyword-alert-text">
                        <p class="ic-heading-forbidden-keyword-alert-title">
                            <strong>${l10n.headingForbiddenKeywordTitle || 'H2見出しに禁止キーワードが含まれています'}</strong>
                        </p>
                        <p class="ic-heading-forbidden-keyword-alert-desc">
                            ${l10n.headingForbiddenKeywordDesc || '以下の見出しに禁止キーワードが含まれています。別の表現に変更してください。'}
                        </p>
                        <ul class="ic-heading-forbidden-list">
                            ${issuesList}
                        </ul>
                    </div>
                </div>
                <button class="ic-heading-forbidden-keyword-alert-button" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                    ${l10n.headingForbiddenKeywordCheck || '見出しを確認'}
                </button>
            `;

            // ボタンクリックで最初の問題のある見出しにスクロール
            banner.querySelector('.ic-heading-forbidden-keyword-alert-button').addEventListener('click', function() {
                if (issues.length > 0) {
                    const firstIssue = issues[0];
                    dispatch('core/block-editor').selectBlock(firstIssue.clientId);

                    const blockElement = document.querySelector(`[data-block="${firstIssue.clientId}"]`);
                    if (blockElement) {
                        blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });

            // リスト項目クリックで該当見出しにスクロール
            banner.querySelectorAll('.ic-heading-forbidden-item').forEach(item => {
                item.addEventListener('click', function() {
                    const clientId = this.getAttribute('data-client-id');
                    if (clientId) {
                        dispatch('core/block-editor').selectBlock(clientId);

                        const blockElement = document.querySelector(`[data-block="${clientId}"]`);
                        if (blockElement) {
                            blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                });
            });

            // タイトル入力欄の後に挿入
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (titleWrapper) {
                titleWrapper.parentNode.insertBefore(banner, titleWrapper.nextSibling);
            } else {
                const titleBlock = document.querySelector('.editor-post-title');
                if (titleBlock) {
                    titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                }
            }
        }
    };

    // ========================================
    // Featured Image Checker Module
    // ========================================
    const FeaturedImageChecker = {
        /**
         * アイキャッチ画像が設定されているかチェック
         */
        hasFeaturedImage: function() {
            const featuredImageId = select('core/editor').getEditedPostAttribute('featured_media');
            return featuredImageId && featuredImageId > 0;
        },

        /**
         * アラートバナーを更新
         */
        updateAlertBanner: function() {
            // 既存のバナーを削除
            document.querySelectorAll('.ic-featured-image-alert-banner').forEach(el => el.remove());

            // アイキャッチ画像が設定されていれば何もしない
            if (this.hasFeaturedImage()) {
                return;
            }

            const banner = document.createElement('div');
            banner.className = 'ic-featured-image-alert-banner';
            banner.innerHTML = `
                <div class="ic-featured-image-alert-content">
                    <div class="ic-featured-image-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                            <polyline points="21 15 16 10 5 21"></polyline>
                        </svg>
                    </div>
                    <div class="ic-featured-image-alert-text">
                        <p class="ic-featured-image-alert-title">
                            <strong>${l10n.featuredImageTitle || 'アイキャッチ画像が設定されていません'}</strong>
                        </p>
                        <p class="ic-featured-image-alert-desc">
                            ${l10n.featuredImageDesc || '記事の見栄えを良くするため、アイキャッチ画像を設定してください'}
                        </p>
                    </div>
                </div>
            `;

            // タイトル入力欄の後に挿入
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (titleWrapper) {
                titleWrapper.parentNode.insertBefore(banner, titleWrapper.nextSibling);
            } else {
                const titleBlock = document.querySelector('.editor-post-title');
                if (titleBlock) {
                    titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                }
            }
        }
    };

    // ========================================
    // Banned Patterns Checker Module
    // ========================================
    const BannedPatternsChecker = {
        /**
         * 投稿全体から禁止文字・文言を検出
         */
        findBannedPatterns: function() {
            const patterns = config.bannedPatterns || [];
            if (patterns.length === 0) return [];

            const blocks = select('core/block-editor').getBlocks();
            const title = select('core/editor').getEditedPostAttribute('title') || '';
            const issues = [];

            // タイトルをチェック
            patterns.forEach(pattern => {
                if (!pattern) return;
                if (title.includes(pattern)) {
                    issues.push({
                        type: 'title',
                        pattern: pattern,
                        text: title,
                        clientId: null
                    });
                }
            });

            // ブロックコンテンツをチェック
            function checkBlocks(blocks) {
                blocks.forEach(block => {
                    let content = '';
                    let blockType = '';

                    if (block.name === 'core/heading') {
                        content = block.attributes.content || '';
                        blockType = 'heading';
                    } else if (block.name === 'core/paragraph') {
                        content = block.attributes.content || '';
                        blockType = 'paragraph';
                    } else if (block.name === 'core/list-item') {
                        content = block.attributes.content || '';
                        blockType = 'list';
                    }

                    if (content) {
                        const plainText = content.replace(/<[^>]*>/g, '');
                        patterns.forEach(pattern => {
                            if (!pattern) return;
                            if (plainText.includes(pattern)) {
                                issues.push({
                                    type: blockType,
                                    pattern: pattern,
                                    text: plainText.substring(0, 50) + (plainText.length > 50 ? '...' : ''),
                                    clientId: block.clientId
                                });
                            }
                        });
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
         * 該当ブロックにハイライト表示
         */
        updateHighlights: function(issues) {
            // 既存のハイライトを削除
            document.querySelectorAll('.ic-banned-pattern-highlight').forEach(el => {
                el.classList.remove('ic-banned-pattern-highlight');
            });

            // 該当ブロックにハイライトを追加
            issues.forEach(issue => {
                if (issue.clientId) {
                    const blockElement = document.querySelector(`[data-block="${issue.clientId}"]`);
                    if (blockElement) {
                        blockElement.classList.add('ic-banned-pattern-highlight');
                    }
                }
            });
        },

        /**
         * アラートバナーを更新
         */
        updateAlertBanner: function() {
            // 既存のバナーを削除
            document.querySelectorAll('.ic-banned-patterns-alert-banner').forEach(el => el.remove());

            const issues = this.findBannedPatterns();

            // ハイライトを更新
            this.updateHighlights(issues);

            // 問題がなければ何もしない
            if (issues.length === 0) {
                return;
            }

            // 検出されたパターンをユニークにまとめる
            const uniquePatterns = [...new Set(issues.map(i => i.pattern))];
            const patternsDisplay = uniquePatterns.map(p => `「${p}」`).join('、');

            const banner = document.createElement('div');
            banner.className = 'ic-banned-patterns-alert-banner';
            banner.innerHTML = `
                <div class="ic-banned-patterns-alert-content">
                    <div class="ic-banned-patterns-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="15" y1="9" x2="9" y2="15"></line>
                            <line x1="9" y1="9" x2="15" y2="15"></line>
                        </svg>
                    </div>
                    <div class="ic-banned-patterns-alert-text">
                        <p class="ic-banned-patterns-alert-title">
                            <strong>${l10n.bannedPatternsTitle || '投稿内に禁止文字・文言が含まれています'}</strong>
                        </p>
                        <p class="ic-banned-patterns-alert-desc">
                            ${l10n.bannedPatternsDesc || '以下の禁止文字・文言が検出されました。削除または修正してください。'}
                            <br>
                            <span class="ic-banned-patterns-list">検出: ${patternsDisplay}（${issues.length}箇所）</span>
                        </p>
                    </div>
                </div>
                <button class="ic-banned-patterns-alert-button" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    ${l10n.bannedPatternsCheck || '該当箇所を確認'}
                </button>
            `;

            // ボタンクリックで最初の問題箇所にスクロール
            banner.querySelector('.ic-banned-patterns-alert-button').addEventListener('click', () => {
                const firstIssueWithBlock = issues.find(i => i.clientId);
                if (firstIssueWithBlock) {
                    dispatch('core/block-editor').selectBlock(firstIssueWithBlock.clientId);

                    const blockElement = document.querySelector(`[data-block="${firstIssueWithBlock.clientId}"]`);
                    if (blockElement) {
                        blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });

            // タイトル入力欄の後に挿入
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (titleWrapper) {
                titleWrapper.parentNode.insertBefore(banner, titleWrapper.nextSibling);
            } else {
                const titleBlock = document.querySelector('.editor-post-title');
                if (titleBlock) {
                    titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                }
            }
        }
    };

    // ========================================
    // H2 Direct H3 Checker Module
    // ========================================
    const H2H3DirectChecker = {
        /**
         * H2見出しの直下にH3見出しがある箇所を検出
         */
        findH2H3DirectIssues: function() {
            const blocks = select('core/block-editor').getBlocks();
            const flatBlocks = this.flattenBlocks(blocks);
            const issues = [];

            for (let i = 0; i < flatBlocks.length - 1; i++) {
                const current = flatBlocks[i];
                const next = flatBlocks[i + 1];

                // 現在のブロックがH2見出し
                if (current.name === 'core/heading') {
                    const currentLevel = current.attributes.level || 2;
                    if (currentLevel === 2) {
                        // 次のブロックがH3見出し
                        if (next.name === 'core/heading') {
                            const nextLevel = next.attributes.level || 2;
                            if (nextLevel === 3) {
                                const h2Content = current.attributes.content || '';
                                const h3Content = next.attributes.content || '';
                                issues.push({
                                    h2ClientId: current.clientId,
                                    h3ClientId: next.clientId,
                                    h2Text: h2Content.replace(/<[^>]*>/g, '').substring(0, 30),
                                    h3Text: h3Content.replace(/<[^>]*>/g, '').substring(0, 30)
                                });
                            }
                        }
                        // 次のブロックが画像で、その次がH3見出し
                        else if (next.name === 'core/image' && i + 2 < flatBlocks.length) {
                            const afterImage = flatBlocks[i + 2];
                            if (afterImage.name === 'core/heading') {
                                const afterImageLevel = afterImage.attributes.level || 2;
                                if (afterImageLevel === 3) {
                                    const h2Content = current.attributes.content || '';
                                    const h3Content = afterImage.attributes.content || '';
                                    issues.push({
                                        h2ClientId: current.clientId,
                                        h3ClientId: afterImage.clientId,
                                        h2Text: h2Content.replace(/<[^>]*>/g, '').substring(0, 30),
                                        h3Text: h3Content.replace(/<[^>]*>/g, '').substring(0, 30)
                                    });
                                }
                            }
                        }
                    }
                }
            }

            return issues;
        },

        /**
         * ブロックをフラット化
         */
        flattenBlocks: function(blocks) {
            const flat = [];

            blocks.forEach(block => {
                if (!block.name) return;

                flat.push(block);

                if (block.innerBlocks && block.innerBlocks.length > 0) {
                    const innerFlat = this.flattenBlocks(block.innerBlocks);
                    flat.push(...innerFlat);
                }
            });

            return flat;
        },

        /**
         * 該当ブロックにハイライト表示
         */
        updateHighlights: function(issues) {
            // 既存のハイライトを削除
            document.querySelectorAll('.ic-h2-h3-direct-highlight').forEach(el => {
                el.classList.remove('ic-h2-h3-direct-highlight');
            });

            // 該当ブロックにハイライトを追加
            issues.forEach(issue => {
                const h2Element = document.querySelector(`[data-block="${issue.h2ClientId}"]`);
                const h3Element = document.querySelector(`[data-block="${issue.h3ClientId}"]`);
                if (h2Element) {
                    h2Element.classList.add('ic-h2-h3-direct-highlight');
                }
                if (h3Element) {
                    h3Element.classList.add('ic-h2-h3-direct-highlight');
                }
            });
        },

        /**
         * アラートバナーを更新
         */
        updateAlertBanner: function() {
            // 既存のバナーを削除
            document.querySelectorAll('.ic-h2-h3-direct-alert-banner').forEach(el => el.remove());

            const issues = this.findH2H3DirectIssues();

            // ハイライトを更新
            this.updateHighlights(issues);

            // 問題がなければ何もしない
            if (issues.length === 0) {
                return;
            }

            const banner = document.createElement('div');
            banner.className = 'ic-h2-h3-direct-alert-banner';
            banner.innerHTML = `
                <div class="ic-h2-h3-direct-alert-content">
                    <div class="ic-h2-h3-direct-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                    </div>
                    <div class="ic-h2-h3-direct-alert-text">
                        <p class="ic-h2-h3-direct-alert-title">
                            <strong>${l10n.h2H3DirectTitle || 'H2見出しの直下にH3見出しが続いています'}</strong>
                            <span class="ic-h2-h3-direct-count">（${issues.length}箇所）</span>
                        </p>
                        <p class="ic-h2-h3-direct-alert-desc">
                            ${l10n.h2H3DirectDesc || 'H2見出しとH3見出しの間に本文（段落など）を入れることを検討してください。'}
                        </p>
                    </div>
                </div>
                <button class="ic-h2-h3-direct-alert-button" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    ${l10n.h2H3DirectCheck || '該当箇所を確認'}
                </button>
            `;

            // ボタンクリックで最初の問題箇所にスクロール
            banner.querySelector('.ic-h2-h3-direct-alert-button').addEventListener('click', () => {
                if (issues.length > 0) {
                    dispatch('core/block-editor').selectBlock(issues[0].h2ClientId);

                    const blockElement = document.querySelector(`[data-block="${issues[0].h2ClientId}"]`);
                    if (blockElement) {
                        blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });

            // タイトル入力欄の後に挿入
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (titleWrapper) {
                titleWrapper.parentNode.insertBefore(banner, titleWrapper.nextSibling);
            } else {
                const titleBlock = document.querySelector('.editor-post-title');
                if (titleBlock) {
                    titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                }
            }
        }
    };

    // ========================================
    // Duplicate Heading Checker Module
    // ========================================
    const DuplicateHeadingChecker = {
        /**
         * 重複している見出し文言を検出
         */
        findDuplicateHeadings: function() {
            const blocks = select('core/block-editor').getBlocks();
            const flatBlocks = H2H3DirectChecker.flattenBlocks(blocks);
            const headingMap = new Map();

            // 全ての見出しを収集
            flatBlocks.forEach(block => {
                if (block.name === 'core/heading') {
                    const content = block.attributes.content || '';
                    const text = content.replace(/<[^>]*>/g, '').trim();
                    if (text) {
                        if (!headingMap.has(text)) {
                            headingMap.set(text, []);
                        }
                        headingMap.get(text).push({
                            clientId: block.clientId,
                            level: block.attributes.level || 2
                        });
                    }
                }
            });

            // 重複している見出しを返す
            const duplicates = [];
            headingMap.forEach((items, text) => {
                if (items.length >= 2) {
                    duplicates.push({
                        text: text,
                        count: items.length,
                        items: items
                    });
                }
            });

            return duplicates;
        },

        /**
         * 該当ブロックにハイライト表示
         */
        updateHighlights: function(duplicates) {
            // 既存のハイライトを削除
            document.querySelectorAll('.ic-duplicate-heading-highlight').forEach(el => {
                el.classList.remove('ic-duplicate-heading-highlight');
            });

            // 該当ブロックにハイライトを追加
            duplicates.forEach(dup => {
                dup.items.forEach(item => {
                    const blockElement = document.querySelector(`[data-block="${item.clientId}"]`);
                    if (blockElement) {
                        blockElement.classList.add('ic-duplicate-heading-highlight');
                    }
                });
            });
        },

        /**
         * アラートバナーを更新
         */
        updateAlertBanner: function() {
            // 既存のバナーを削除
            document.querySelectorAll('.ic-duplicate-heading-alert-banner').forEach(el => el.remove());

            const duplicates = this.findDuplicateHeadings();

            // ハイライトを更新
            this.updateHighlights(duplicates);

            // 問題がなければ何もしない
            if (duplicates.length === 0) {
                return;
            }

            const totalDuplicates = duplicates.reduce((sum, d) => sum + d.count, 0);
            const duplicateTexts = duplicates.map(d => `「${d.text.substring(0, 20)}${d.text.length > 20 ? '...' : ''}」`).join('、');

            const banner = document.createElement('div');
            banner.className = 'ic-duplicate-heading-alert-banner';
            banner.innerHTML = `
                <div class="ic-duplicate-heading-alert-content">
                    <div class="ic-duplicate-heading-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                    </div>
                    <div class="ic-duplicate-heading-alert-text">
                        <p class="ic-duplicate-heading-alert-title">
                            <strong>${l10n.duplicateHeadingTitle || '同じ文言の見出しが複数あります'}</strong>
                            <span class="ic-duplicate-heading-count">（${duplicates.length}種類・計${totalDuplicates}件）</span>
                        </p>
                        <p class="ic-duplicate-heading-alert-desc">
                            ${l10n.duplicateHeadingDesc || '見出しの文言が重複しています。異なる表現に変更することを検討してください。'}
                            <br>
                            <span class="ic-duplicate-heading-list">重複: ${duplicateTexts}</span>
                        </p>
                    </div>
                </div>
                <button class="ic-duplicate-heading-alert-button" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    ${l10n.duplicateHeadingCheck || '該当箇所を確認'}
                </button>
            `;

            // ボタンクリックで最初の問題箇所にスクロール
            banner.querySelector('.ic-duplicate-heading-alert-button').addEventListener('click', () => {
                if (duplicates.length > 0 && duplicates[0].items.length > 0) {
                    dispatch('core/block-editor').selectBlock(duplicates[0].items[0].clientId);

                    const blockElement = document.querySelector(`[data-block="${duplicates[0].items[0].clientId}"]`);
                    if (blockElement) {
                        blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });

            // タイトル入力欄の後に挿入
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (titleWrapper) {
                titleWrapper.parentNode.insertBefore(banner, titleWrapper.nextSibling);
            } else {
                const titleBlock = document.querySelector('.editor-post-title');
                if (titleBlock) {
                    titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                }
            }
        }
    };

    // ========================================
    // H2 Required Keyword Checker Module
    // ========================================
    const H2RequiredKeywordChecker = {
        /**
         * タイトルに含まれているがH2に含まれていないキーワードを検出
         */
        findMissingKeywordsInH2: function() {
            const keywords = config.h2RequiredKeywords || [];
            if (keywords.length === 0) {
                return [];
            }

            const title = select('core/editor').getEditedPostAttribute('title') || '';
            if (!title) {
                return [];
            }

            // H2見出しのテキストを収集
            const blocks = select('core/block-editor').getBlocks();
            const flatBlocks = H2H3DirectChecker.flattenBlocks(blocks);
            const h2Texts = [];

            flatBlocks.forEach(block => {
                if (block.name === 'core/heading') {
                    const level = block.attributes.level || 2;
                    if (level === 2) {
                        const content = block.attributes.content || '';
                        const text = content.replace(/<[^>]*>/g, '').trim();
                        if (text) {
                            h2Texts.push(text);
                        }
                    }
                }
            });

            // タイトルに含まれているがH2に含まれていないキーワードを検出
            const missingKeywords = [];

            keywords.forEach(keyword => {
                if (!keyword) return;

                // タイトルにキーワードが含まれているか
                if (title.includes(keyword)) {
                    // H2のいずれかにキーワードが含まれているか
                    const foundInH2 = h2Texts.some(h2Text => h2Text.includes(keyword));
                    if (!foundInH2) {
                        missingKeywords.push(keyword);
                    }
                }
            });

            return missingKeywords;
        },

        /**
         * アラートバナーを更新
         */
        updateAlertBanner: function() {
            // 既存のバナーを削除
            document.querySelectorAll('.ic-h2-required-keyword-alert-banner').forEach(el => el.remove());

            const missingKeywords = this.findMissingKeywordsInH2();

            // 問題がなければ何もしない
            if (missingKeywords.length === 0) {
                return;
            }

            const keywordList = missingKeywords.map(kw => `「${kw}」`).join('、');

            const banner = document.createElement('div');
            banner.className = 'ic-h2-required-keyword-alert-banner';
            banner.innerHTML = `
                <div class="ic-h2-required-keyword-alert-content">
                    <div class="ic-h2-required-keyword-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                    </div>
                    <div class="ic-h2-required-keyword-alert-text">
                        <p class="ic-h2-required-keyword-alert-title">
                            <strong>${l10n.h2RequiredKeywordTitle || 'タイトルのキーワードがH2見出しに含まれていません'}</strong>
                            <span class="ic-h2-required-keyword-count">（${missingKeywords.length}件）</span>
                        </p>
                        <p class="ic-h2-required-keyword-alert-desc">
                            ${l10n.h2RequiredKeywordDesc || 'タイトルに含まれているキーワードはH2見出しにも入れることを検討してください。'}
                            <br>
                            <span class="ic-h2-required-keyword-list">${l10n.h2RequiredKeywordList || '不足キーワード'}: ${keywordList}</span>
                        </p>
                    </div>
                </div>
            `;

            // タイトル入力欄の後に挿入
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (titleWrapper) {
                titleWrapper.parentNode.insertBefore(banner, titleWrapper.nextSibling);
            } else {
                const titleBlock = document.querySelector('.editor-post-title');
                if (titleBlock) {
                    titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                }
            }
        }
    };

    // ========================================
    // Duplicate Keyword Checker Module
    // ========================================
    const DuplicateKeywordChecker = {
        /**
         * タイトル内で重複しているキーワードを検出
         */
        findDuplicateKeywords: function(title) {
            const keywords = config.duplicateKeywords || [];
            const duplicates = [];

            keywords.forEach(keyword => {
                if (!keyword) return;

                // キーワードの出現回数をカウント
                const regex = new RegExp(keyword, 'gi');
                const matches = title.match(regex);
                const count = matches ? matches.length : 0;

                if (count >= 2) {
                    duplicates.push({
                        keyword: keyword,
                        count: count
                    });
                }
            });

            return duplicates;
        },

        /**
         * アラートバナーを更新
         */
        updateAlertBanner: function(title) {
            // 既存のバナーを削除
            document.querySelectorAll('.ic-duplicate-keyword-alert-banner').forEach(el => el.remove());

            const duplicates = this.findDuplicateKeywords(title);

            // 重複がなければ何もしない
            if (duplicates.length === 0) {
                return;
            }

            const duplicateDisplay = duplicates.map(d => `「${d.keyword}」(${d.count}回)`).join('、');

            const banner = document.createElement('div');
            banner.className = 'ic-duplicate-keyword-alert-banner';
            banner.innerHTML = `
                <div class="ic-duplicate-keyword-alert-content">
                    <div class="ic-duplicate-keyword-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                    <div class="ic-duplicate-keyword-alert-text">
                        <p class="ic-duplicate-keyword-alert-title">
                            <strong>${l10n.duplicateKeywordTitle || 'タイトルに同じキーワードが複数回使用されています'}</strong>
                        </p>
                        <p class="ic-duplicate-keyword-alert-desc">
                            ${l10n.duplicateKeywordDesc || '同じキーワードを複数回使用するのは冗長です。1つに減らすことを検討してください。'}
                            <br>
                            <span class="ic-duplicate-keyword-list">${l10n.duplicateKeywordList || '重複キーワード'}: ${duplicateDisplay}</span>
                        </p>
                    </div>
                </div>
            `;

            // タイトル入力欄の後に挿入
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (titleWrapper) {
                titleWrapper.parentNode.insertBefore(banner, titleWrapper.nextSibling);
            } else {
                const titleBlock = document.querySelector('.editor-post-title');
                if (titleBlock) {
                    titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                }
            }
        }
    };

    // ========================================
    // Slug Checker Module
    // ========================================
    const SlugChecker = {
        /**
         * スラッグが有効かどうかを判定（英数字とハイフンのみ許可、数字のみは不可）
         */
        isValidSlug: function(slug) {
            if (!slug || slug === '') {
                return true; // 空は問題なし
            }
            // 英数字とハイフンのみ許可（アンダーバーは不可）
            if (!/^[a-zA-Z0-9-]+$/.test(slug)) {
                return false;
            }
            // 数字のみは不可
            if (/^[0-9]+$/.test(slug)) {
                return false;
            }
            return true;
        },

        /**
         * 数字のみかどうかを判定
         */
        isNumbersOnly: function(slug) {
            return /^[0-9]+$/.test(slug);
        },

        /**
         * 無効な文字を検出して返す
         */
        getInvalidChars: function(slug) {
            if (!slug) return [];
            const invalidChars = slug.match(/[^a-zA-Z0-9-]/g) || [];
            return [...new Set(invalidChars)]; // 重複を除去
        },

        /**
         * アラートバナーを更新
         */
        updateAlertBanner: function(slug) {
            // 既存のバナーを削除
            document.querySelectorAll('.ic-slug-alert-banner').forEach(el => el.remove());

            // スラッグ入力欄のハイライトを削除
            document.querySelectorAll('.ic-slug-invalid').forEach(el => {
                el.classList.remove('ic-slug-invalid');
            });

            // 有効なスラッグなら何もしない
            if (this.isValidSlug(slug)) {
                return;
            }

            const isNumbersOnly = this.isNumbersOnly(slug);
            const invalidChars = this.getInvalidChars(slug);
            const invalidCharsDisplay = invalidChars.map(c => `「${c}」`).join(' ');

            let alertTitle, alertDesc;
            if (isNumbersOnly) {
                alertTitle = l10n.slugNumbersOnlyTitle || 'スラッグが数字のみになっています';
                alertDesc = l10n.slugNumbersOnlyDesc || 'スラッグには英字を含めてください';
            } else if (invalidChars.length > 0) {
                alertTitle = l10n.slugAlertTitle || 'スラッグに使用できない文字が含まれています';
                alertDesc = `${l10n.slugAlertDesc || '英数字とハイフン（-）のみ使用できます'}<br><span class="ic-slug-invalid-chars">${l10n.slugInvalidChars || '無効な文字'}: ${invalidCharsDisplay}</span>`;
            } else {
                alertTitle = l10n.slugAlertTitle || 'スラッグに使用できない文字が含まれています';
                alertDesc = l10n.slugAlertDesc || '英数字とハイフン（-）のみ使用できます';
            }

            const banner = document.createElement('div');
            banner.className = 'ic-slug-alert-banner';
            banner.innerHTML = `
                <div class="ic-slug-alert-content">
                    <div class="ic-slug-alert-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="15" y1="9" x2="9" y2="15"></line>
                            <line x1="9" y1="9" x2="15" y2="15"></line>
                        </svg>
                    </div>
                    <div class="ic-slug-alert-text">
                        <p class="ic-slug-alert-title">
                            <strong>${alertTitle}</strong>
                        </p>
                        <p class="ic-slug-alert-desc">
                            ${alertDesc}
                        </p>
                    </div>
                </div>
            `;

            // タイトル入力欄の後に挿入
            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper');
            if (titleWrapper) {
                titleWrapper.parentNode.insertBefore(banner, titleWrapper.nextSibling);
            } else {
                const titleBlock = document.querySelector('.editor-post-title');
                if (titleBlock) {
                    titleBlock.parentNode.insertBefore(banner, titleBlock.nextSibling);
                }
            }

            // スラッグ入力欄をハイライト
            this.highlightSlugInput();
        },

        /**
         * スラッグ入力欄をハイライト
         */
        highlightSlugInput: function() {
            // パーマリンクパネル内のスラッグ入力欄を探す
            const slugInputs = document.querySelectorAll('.editor-post-url input, .edit-post-post-url__input, input[id*="post-slug"], .editor-post-slug input');
            slugInputs.forEach(input => {
                input.classList.add('ic-slug-invalid');
            });

            // サイドバーのURLパネル
            const urlPanel = document.querySelector('.editor-post-url__panel-content');
            if (urlPanel) {
                const input = urlPanel.querySelector('input');
                if (input) {
                    input.classList.add('ic-slug-invalid');
                }
            }
        }
    };

    // ========================================
    // Heading Structure Checker Module
    // ========================================
    const HeadingStructureChecker = {
        /**
         * H2とH3の見出しブロックを取得
         */
        getAllHeadings: function() {
            const blocks = select('core/block-editor').getBlocks();
            const headings = [];

            function checkBlocks(blocks) {
                blocks.forEach(block => {
                    if (block.name === 'core/heading') {
                        const level = block.attributes.level || 2;
                        // H2とH3のみを対象とする
                        if (level === 2 || level === 3) {
                            const content = block.attributes.content || '';
                            const plainText = content.replace(/<[^>]*>/g, '').trim();

                            headings.push({
                                clientId: block.clientId,
                                level: level,
                                text: plainText,
                                content: content
                            });
                        }
                    }

                    if (block.innerBlocks && block.innerBlocks.length > 0) {
                        checkBlocks(block.innerBlocks);
                    }
                });
            }

            checkBlocks(blocks);
            return headings;
        },

        /**
         * 見出しをツリー構造に変換
         */
        buildHeadingTree: function(headings) {
            const tree = [];
            const stack = [{ level: 1, children: tree }];

            headings.forEach(heading => {
                const node = {
                    ...heading,
                    children: []
                };

                // 現在のレベルより大きいか等しいスタックを除去
                while (stack.length > 1 && stack[stack.length - 1].level >= heading.level) {
                    stack.pop();
                }

                // 親の children に追加
                stack[stack.length - 1].children.push(node);

                // スタックに追加
                stack.push(node);
            });

            return tree;
        },

        /**
         * H2一覧をクリップボードにコピー
         */
        copyH2ToClipboard: function(headings, callback) {
            const h2Headings = headings.filter(h => h.level === 2);
            const h2Texts = h2Headings.map(h => h.text).join('\n');

            if (!h2Texts) {
                if (callback) callback(false);
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(h2Texts)
                    .then(() => callback && callback(true))
                    .catch(() => this.fallbackCopy(h2Texts, callback));
            } else {
                this.fallbackCopy(h2Texts, callback);
            }
        },

        /**
         * フォールバックコピー（旧ブラウザ対応）
         */
        fallbackCopy: function(text, callback) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                if (callback) callback(true);
            } catch (err) {
                if (callback) callback(false);
            }
            document.body.removeChild(textarea);
        }
    };

    // ========================================
    // Main Plugin Component
    // ========================================
    // HeadingTreeItem コンポーネント（再帰的にツリーをレンダリング）
    function HeadingTreeItem({ heading, depth }) {
        const handleClick = () => {
            dispatch('core/block-editor').selectBlock(heading.clientId);
            const blockElement = document.querySelector(`[data-block="${heading.clientId}"]`);
            if (blockElement) {
                blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        };

        const isEmpty = !heading.text;

        return createElement(
            'div',
            { className: 'ic-heading-tree-item' },
            createElement(
                'div',
                {
                    className: `ic-heading-item ic-heading-level-${heading.level}${isEmpty ? ' empty' : ''}`,
                    style: { paddingLeft: `${depth * 16 + 10}px` },
                    onClick: handleClick
                },
                createElement('span', { className: 'ic-heading-level-badge' }, `H${heading.level}`),
                createElement('span', { className: 'ic-heading-text' },
                    heading.text || (l10n.emptyHeading || '(空の見出し)')
                )
            ),
            heading.children && heading.children.length > 0 &&
                createElement(
                    'div',
                    { className: 'ic-heading-children' },
                    heading.children.map(child =>
                        createElement(HeadingTreeItem, {
                            key: child.clientId,
                            heading: child,
                            depth: depth + 1
                        })
                    )
                )
        );
    }

    function IntelligentCheckerPlugin() {
        const [nakedUrls, setNakedUrls] = useState([]);
        const [images, setImages] = useState([]);
        const [missingAltCount, setMissingAltCount] = useState(0);
        // 見出し構造用のstate
        const [headings, setHeadings] = useState([]);
        const [headingTree, setHeadingTree] = useState([]);
        const [copyFeedback, setCopyFeedback] = useState(null);

        // 作成者用のstate
        const [users, setUsers] = useState([]);
        const postType = wp.data.useSelect(sel => sel('core/editor').getCurrentPostType(), []);
        const [meta, setMeta] = useEntityProp('postType', postType, 'meta');
        const creatorId = meta?._ic_creator || 0;

        // ユーザー一覧を取得
        useEffect(() => {
            if (postType !== 'post') return;

            wp.apiFetch({ path: '/intelligent-checker/v1/users' })
                .then(data => {
                    setUsers(data || []);
                })
                .catch(err => {
                    console.error('Failed to fetch users:', err);
                });
        }, [postType]);

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

                // Heading Structure Checker
                if (config.headingStructureEnabled) {
                    const allHeadings = HeadingStructureChecker.getAllHeadings();
                    setHeadings(allHeadings);
                    setHeadingTree(HeadingStructureChecker.buildHeadingTree(allHeadings));
                }

                // Slug Checker
                if (config.slugCheckerEnabled) {
                    // パーマリンクからスラッグを抽出（データベースの値を使用）
                    const permalink = select('core/editor').getPermalink();
                    const editedSlug = select('core/editor').getEditedPostAttribute('slug');
                    const currentPost = select('core/editor').getCurrentPost();

                    let slugToCheck = '';

                    // 編集中のスラッグがあればそれを使用
                    if (editedSlug) {
                        slugToCheck = editedSlug;
                    }
                    // パーマリンクからスラッグを抽出
                    else if (permalink) {
                        // パーマリンクからスラッグ部分を抽出
                        const url = new URL(permalink);
                        const pathParts = url.pathname.split('/').filter(p => p);
                        if (pathParts.length > 0) {
                            slugToCheck = pathParts[pathParts.length - 1];
                        }
                    }
                    // 投稿オブジェクトからスラッグを取得
                    else if (currentPost && currentPost.slug) {
                        slugToCheck = currentPost.slug;
                    }

                    SlugChecker.updateAlertBanner(slugToCheck);
                }

                // Duplicate Keyword Checker
                if (config.duplicateKeywordEnabled) {
                    const currentTitle = select('core/editor').getEditedPostAttribute('title') || '';
                    DuplicateKeywordChecker.updateAlertBanner(currentTitle);
                }

                // Featured Image Checker
                if (config.featuredImageCheckerEnabled) {
                    FeaturedImageChecker.updateAlertBanner();
                }

                // Forbidden Keyword Checker
                if (config.forbiddenKeywordEnabled) {
                    const currentTitle = select('core/editor').getEditedPostAttribute('title') || '';
                    ForbiddenKeywordChecker.updateAlertBanner(currentTitle);
                }

                // Caution Keyword Checker
                if (config.cautionKeywordEnabled) {
                    const currentTitle = select('core/editor').getEditedPostAttribute('title') || '';
                    CautionKeywordChecker.updateAlertBanner(currentTitle);
                }

                // Heading Forbidden Keyword Checker (H2見出しの禁止キーワードチェック)
                if (config.forbiddenKeywordsHeading && config.forbiddenKeywordsHeading.length > 0) {
                    HeadingForbiddenKeywordChecker.updateAlertBanner();
                }

                // Heading Caution Keyword Checker (H2見出しの要注意キーワードチェック)
                if (config.cautionKeywordsHeading && config.cautionKeywordsHeading.length > 0) {
                    HeadingCautionKeywordChecker.updateAlertBanner();
                }

                // Banned Patterns Checker (禁止文字・文言チェック)
                if (config.bannedPatternsEnabled && config.bannedPatterns && config.bannedPatterns.length > 0) {
                    BannedPatternsChecker.updateAlertBanner();
                }

                // H2 Direct H3 Checker (H2直下H3チェック)
                if (config.h2H3DirectEnabled) {
                    H2H3DirectChecker.updateAlertBanner();
                }

                // Duplicate Heading Checker (見出し重複チェック)
                if (config.duplicateHeadingEnabled) {
                    DuplicateHeadingChecker.updateAlertBanner();
                }

                // H2 Required Keyword Checker (H2必須キーワードチェック)
                if (config.h2RequiredKeywordEnabled && config.h2RequiredKeywords && config.h2RequiredKeywords.length > 0) {
                    H2RequiredKeywordChecker.updateAlertBanner();
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

        // 画像クリックハンドラー
        const handleImageClick = (clientId) => {
            dispatch('core/block-editor').selectBlock(clientId);
            const blockElement = document.querySelector(`[data-block="${clientId}"]`);
            if (blockElement) {
                blockElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        };

        // H2コピーハンドラー
        const handleCopyH2 = () => {
            HeadingStructureChecker.copyH2ToClipboard(headings, (success) => {
                setCopyFeedback(success ? 'success' : 'error');
                setTimeout(() => setCopyFeedback(null), 2000);
            });
        };

        // パネル配列を構築
        const panels = [];

        // 作成者パネル（投稿のみ）
        if (postType === 'post') {
            const userOptions = [
                { value: '0', label: '-- 選択してください --' },
                ...users.map(user => ({
                    value: String(user.id),
                    label: user.display_name || user.user_login
                }))
            ];

            panels.push(
                createElement(
                    PluginDocumentSettingPanel,
                    {
                        key: 'creator-panel',
                        name: 'intelligent-checker-creator-panel',
                        title: '作成者',
                        className: 'ic-creator-panel'
                    },
                    createElement(
                        SelectControl,
                        {
                            label: '作成者を選択',
                            value: String(creatorId),
                            options: userOptions,
                            onChange: (value) => {
                                setMeta({ ...meta, _ic_creator: parseInt(value, 10) });
                            }
                        }
                    )
                )
            );
        }

        // ALTパネル
        if (config.altCheckerEnabled && images.length > 0) {
            panels.push(
                createElement(
                    PluginDocumentSettingPanel,
                    {
                        key: 'alt-panel',
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
                )
            );
        }

        // 見出し構造パネル
        if (config.headingStructureEnabled) {
            const h2Count = headings.filter(h => h.level === 2).length;

            panels.push(
                createElement(
                    PluginDocumentSettingPanel,
                    {
                        key: 'heading-panel',
                        name: 'intelligent-checker-heading-panel',
                        title: l10n.headingPanelTitle || '見出し構造',
                        className: 'ic-heading-panel'
                    },
                    createElement(
                        'div',
                        { className: 'ic-heading-panel-content' },
                        // コピー結果のフィードバック
                        copyFeedback && createElement(
                            'div',
                            { className: `ic-copy-feedback ${copyFeedback}` },
                            copyFeedback === 'success'
                                ? (l10n.copySuccess || 'コピーしました')
                                : (l10n.copyError || 'コピーに失敗しました')
                        ),
                        // H2コピーボタン
                        h2Count > 0 && createElement(
                            'button',
                            {
                                className: 'ic-heading-copy-btn',
                                onClick: handleCopyH2,
                                type: 'button'
                            },
                            `📋 ${l10n.copyH2Button || 'H2一覧をコピー'} (${h2Count}件)`
                        ),
                        // 見出しツリー
                        headingTree.length > 0
                            ? createElement(
                                'div',
                                { className: 'ic-heading-tree' },
                                headingTree.map(heading =>
                                    createElement(HeadingTreeItem, {
                                        key: heading.clientId,
                                        heading: heading,
                                        depth: 0
                                    })
                                )
                            )
                            : createElement(
                                'div',
                                { className: 'ic-heading-empty' },
                                l10n.noHeadings || '見出しがありません'
                            )
                    )
                )
            );
        }

        // パネルがない場合はnullを返す
        if (panels.length === 0) {
            return null;
        }

        // 複数パネルをFragmentでラップして返す
        return createElement(wp.element.Fragment, null, ...panels);
    }

    // プラグインを登録
    registerPlugin('intelligent-checker', {
        render: IntelligentCheckerPlugin
    });

})(window.wp);
