<?php
/**
 * IC_Error_Checker - エラーチェッククラス
 *
 * @package Intelligent_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * エラーチェック管理クラス
 */
class IC_Error_Checker {

    private static $instance = null;

    /**
     * シングルトンインスタンス取得
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        // このクラスはフックを登録しない（他のクラスから呼び出される）
    }

    /**
     * 投稿のエラー数を取得
     */
    public function get_post_errors( $post_id ) {
        $errors = array();
        $post = get_post( $post_id );
        if ( ! $post ) {
            return $errors;
        }

        $settings = IC_Settings::get_instance()->get_settings();

        // 禁止キーワード（タイトル）チェック
        if ( $settings['post_list_show_forbidden_keyword_title'] ) {
            $count = $this->check_forbidden_keywords_in_text( $post->post_title );
            if ( $count > 0 ) {
                $errors['forbidden_title'] = $count;
            }
        }

        // 禁止キーワード（H2見出し）チェック
        if ( $settings['post_list_show_forbidden_keyword_heading'] ) {
            $count = $this->check_forbidden_keywords_in_headings( $post->post_content );
            if ( $count > 0 ) {
                $errors['forbidden_h2'] = $count;
            }
        }

        // 要注意キーワード（タイトル）チェック
        if ( $settings['post_list_show_caution_keyword_title'] ) {
            $count = $this->check_caution_keywords_in_text( $post->post_title );
            if ( $count > 0 ) {
                $errors['caution_title'] = $count;
            }
        }

        // 要注意キーワード（H2見出し）チェック
        if ( $settings['post_list_show_caution_keyword_heading'] ) {
            $count = $this->check_caution_keywords_in_headings( $post->post_content );
            if ( $count > 0 ) {
                $errors['caution_h2'] = $count;
            }
        }

        // 重複キーワード（タイトル）チェック
        if ( $settings['post_list_show_duplicate_keyword'] ) {
            $count = $this->check_duplicate_keywords_in_title( $post->post_title );
            if ( $count > 0 ) {
                $errors['duplicate'] = $count;
            }
        }

        // スラッグエラーチェック
        if ( $settings['post_list_show_slug_error'] ) {
            if ( $this->check_slug_error( $post->post_name ) ) {
                $errors['slug'] = 1;
            }
        }

        // アイキャッチ画像チェック
        if ( $settings['post_list_show_featured_image'] ) {
            if ( ! has_post_thumbnail( $post_id ) ) {
                $errors['featured_image'] = 1;
            }
        }

        // ALT未設定画像チェック
        if ( $settings['post_list_show_alt_missing'] ) {
            $count = $this->check_alt_missing_in_content( $post->post_content );
            if ( $count > 0 ) {
                $errors['alt_missing'] = $count;
            }
        }

        // 長文段落チェック
        if ( $settings['post_list_show_long_paragraph'] ) {
            $count = $this->check_long_paragraphs_in_content( $post->post_content );
            if ( $count > 0 ) {
                $errors['long_paragraph'] = $count;
            }
        }

        // 禁止文字・文言チェック
        if ( $settings['post_list_show_banned_patterns'] ) {
            $count = $this->check_banned_patterns_in_post( $post->post_title, $post->post_content );
            if ( $count > 0 ) {
                $errors['banned_patterns'] = $count;
            }
        }

        // H2直下H3チェック
        if ( $settings['post_list_show_h2_h3_direct'] ) {
            $count = $this->check_h2_h3_direct_in_content( $post->post_content );
            if ( $count > 0 ) {
                $errors['h2_h3_direct'] = $count;
            }
        }

        // 見出し重複チェック
        if ( $settings['post_list_show_duplicate_heading'] ) {
            $count = $this->check_duplicate_headings_in_content( $post->post_content );
            if ( $count > 0 ) {
                $errors['duplicate_heading'] = $count;
            }
        }

        // H2必須キーワードチェック
        if ( $settings['post_list_show_h2_required_keyword'] ) {
            $count = $this->check_h2_required_keywords( $post->post_title, $post->post_content );
            if ( $count > 0 ) {
                $errors['h2_required_keyword'] = $count;
            }
        }

        // パターン重複使用チェック
        if ( $settings['post_list_show_duplicate_pattern'] ) {
            $count = $this->check_duplicate_patterns_in_content( $post->post_content );
            if ( $count > 0 ) {
                $errors['duplicate_pattern'] = $count;
            }
        }

        return $errors;
    }

    /**
     * 禁止キーワードをチェック
     */
    private function check_forbidden_keywords_in_text( $text ) {
        $settings = IC_Settings::get_instance()->get_settings();
        $keywords = IC_Settings::get_instance()->text_to_array( $settings['forbidden_keywords'] );
        $count = 0;

        foreach ( $keywords as $keyword ) {
            if ( empty( $keyword ) ) {
                continue;
            }
            if ( mb_strpos( $text, $keyword ) !== false ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 要注意キーワードをチェック
     */
    private function check_caution_keywords_in_text( $text ) {
        $settings = IC_Settings::get_instance()->get_settings();
        $keywords = IC_Settings::get_instance()->text_to_array( $settings['caution_keywords'] );
        $count = 0;

        foreach ( $keywords as $keyword ) {
            if ( empty( $keyword ) ) {
                continue;
            }
            if ( mb_strpos( $text, $keyword ) !== false ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * H2見出し内の要注意キーワードをチェック
     */
    private function check_caution_keywords_in_headings( $content ) {
        $settings = IC_Settings::get_instance()->get_settings();
        $keywords = IC_Settings::get_instance()->text_to_array( $settings['caution_keywords_heading'] );
        $count = 0;

        // H2見出しを抽出（ブロックとクラシック両対応）
        if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $content, $matches ) ) {
            foreach ( $matches[1] as $heading ) {
                $plain_text = wp_strip_all_tags( $heading );
                foreach ( $keywords as $keyword ) {
                    if ( empty( $keyword ) ) {
                        continue;
                    }
                    if ( mb_strpos( $plain_text, $keyword ) !== false ) {
                        $count++;
                        break; // 1つの見出しで複数のキーワードがあっても1カウント
                    }
                }
            }
        }

        return $count;
    }

    /**
     * H2見出し内の禁止キーワードをチェック
     */
    private function check_forbidden_keywords_in_headings( $content ) {
        $settings = IC_Settings::get_instance()->get_settings();
        $keywords = IC_Settings::get_instance()->text_to_array( $settings['forbidden_keywords_heading'] );
        $count = 0;

        // H2見出しを抽出（ブロックとクラシック両対応）
        if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $content, $matches ) ) {
            foreach ( $matches[1] as $heading ) {
                $plain_text = wp_strip_all_tags( $heading );
                foreach ( $keywords as $keyword ) {
                    if ( empty( $keyword ) ) {
                        continue;
                    }
                    if ( mb_strpos( $plain_text, $keyword ) !== false ) {
                        $count++;
                        break;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * 禁止文字・文言をチェック（投稿全体）
     */
    private function check_banned_patterns_in_post( $title, $content ) {
        $settings = IC_Settings::get_instance()->get_settings();
        $patterns = IC_Settings::get_instance()->text_to_array( $settings['banned_patterns'] );
        $count = 0;

        if ( empty( $patterns ) ) {
            return 0;
        }

        // タイトルをチェック
        foreach ( $patterns as $pattern ) {
            if ( empty( $pattern ) ) {
                continue;
            }
            if ( mb_strpos( $title, $pattern ) !== false ) {
                $count++;
            }
        }

        // コンテンツをチェック（ブロックを解析）
        $blocks = parse_blocks( $content );
        foreach ( $blocks as $block ) {
            $count += $this->check_banned_patterns_in_block( $block, $patterns );
        }

        return $count;
    }

    /**
     * ブロック内の禁止文字・文言を再帰的にチェック
     */
    private function check_banned_patterns_in_block( $block, $patterns ) {
        $count = 0;
        $content = '';

        // テキストを含むブロックタイプをチェック
        if ( in_array( $block['blockName'], array( 'core/heading', 'core/paragraph', 'core/list-item' ), true ) ) {
            $inner_html = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';
            $content = wp_strip_all_tags( $inner_html );
        }

        if ( ! empty( $content ) ) {
            foreach ( $patterns as $pattern ) {
                if ( empty( $pattern ) ) {
                    continue;
                }
                if ( mb_strpos( $content, $pattern ) !== false ) {
                    $count++;
                }
            }
        }

        // 内部ブロックを再帰的にチェック
        if ( ! empty( $block['innerBlocks'] ) ) {
            foreach ( $block['innerBlocks'] as $inner_block ) {
                $count += $this->check_banned_patterns_in_block( $inner_block, $patterns );
            }
        }

        return $count;
    }

    /**
     * H2見出しの直下にH3見出しがあるかチェック
     * H2 → H3 または H2 → 画像 → H3 のパターンを検出
     */
    private function check_h2_h3_direct_in_content( $content ) {
        $blocks = parse_blocks( $content );
        $flat_blocks = $this->flatten_blocks( $blocks );
        $count = 0;
        $block_count = count( $flat_blocks );

        for ( $i = 0; $i < $block_count - 1; $i++ ) {
            $current = $flat_blocks[ $i ];
            $next = $flat_blocks[ $i + 1 ];

            // 現在のブロックがH2見出し
            if ( $current['blockName'] === 'core/heading' ) {
                $current_level = isset( $current['attrs']['level'] ) ? $current['attrs']['level'] : 2;
                if ( $current_level === 2 ) {
                    // 次のブロックがH3見出し
                    if ( $next['blockName'] === 'core/heading' ) {
                        $next_level = isset( $next['attrs']['level'] ) ? $next['attrs']['level'] : 2;
                        if ( $next_level === 3 ) {
                            $count++;
                        }
                    }
                    // 次のブロックが画像で、その次がH3見出し
                    elseif ( $next['blockName'] === 'core/image' && $i + 2 < $block_count ) {
                        $after_image = $flat_blocks[ $i + 2 ];
                        if ( $after_image['blockName'] === 'core/heading' ) {
                            $after_image_level = isset( $after_image['attrs']['level'] ) ? $after_image['attrs']['level'] : 2;
                            if ( $after_image_level === 3 ) {
                                $count++;
                            }
                        }
                    }
                }
            }
        }

        return $count;
    }

    /**
     * ブロックをフラット化（ネストを解除）
     */
    private function flatten_blocks( $blocks ) {
        $flat = array();

        foreach ( $blocks as $block ) {
            // 空のブロックはスキップ
            if ( empty( $block['blockName'] ) ) {
                continue;
            }

            $flat[] = $block;

            if ( ! empty( $block['innerBlocks'] ) ) {
                $inner_flat = $this->flatten_blocks( $block['innerBlocks'] );
                $flat = array_merge( $flat, $inner_flat );
            }
        }

        return $flat;
    }

    /**
     * 見出し文言の重複をチェック
     */
    private function check_duplicate_headings_in_content( $content ) {
        $blocks = parse_blocks( $content );
        $flat_blocks = $this->flatten_blocks( $blocks );
        $headings = array();

        // 全ての見出しを収集
        foreach ( $flat_blocks as $block ) {
            if ( $block['blockName'] === 'core/heading' ) {
                $inner_html = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';
                $text = trim( wp_strip_all_tags( $inner_html ) );
                if ( ! empty( $text ) ) {
                    $headings[] = $text;
                }
            }
        }

        // 重複をカウント
        $counts = array_count_values( $headings );
        $duplicates = 0;

        foreach ( $counts as $text => $count ) {
            if ( $count >= 2 ) {
                $duplicates++;
            }
        }

        return $duplicates;
    }

    /**
     * タイトル内の重複キーワードをチェック
     */
    private function check_duplicate_keywords_in_title( $title ) {
        $settings = IC_Settings::get_instance()->get_settings();
        $keywords = IC_Settings::get_instance()->text_to_array( $settings['duplicate_keywords'] );
        $count = 0;

        foreach ( $keywords as $keyword ) {
            if ( empty( $keyword ) ) {
                continue;
            }
            $matches = mb_substr_count( $title, $keyword );
            if ( $matches >= 2 ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * スラッグエラーをチェック
     */
    private function check_slug_error( $slug ) {
        if ( empty( $slug ) ) {
            return false;
        }

        // 数字のみはエラー
        if ( preg_match( '/^[0-9]+$/', $slug ) ) {
            return true;
        }

        // 英数字とハイフン以外を含む場合はエラー（アンダーバーはNG）
        if ( ! preg_match( '/^[a-zA-Z0-9-]+$/', $slug ) ) {
            return true;
        }

        return false;
    }

    /**
     * コンテンツ内のALT未設定画像をチェック
     */
    private function check_alt_missing_in_content( $content ) {
        $count = 0;

        // ブロックエディタのコンテンツをパース
        $blocks = parse_blocks( $content );

        foreach ( $blocks as $block ) {
            $count += $this->check_alt_in_block( $block );
        }

        return $count;
    }

    /**
     * ブロック内のALT未設定画像を再帰的にチェック
     */
    private function check_alt_in_block( $block ) {
        $count = 0;
        $inner_html = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';

        if ( $block['blockName'] === 'core/image' ) {
            // まずブロック属性をチェック
            if ( ! empty( $block['attrs']['alt'] ) ) {
                // 属性にALTがあればOK
            } elseif ( $this->has_alt_in_html( $inner_html ) ) {
                // HTMLにALTがあればOK
            } else {
                $count++;
            }
        } elseif ( $block['blockName'] === 'core/gallery' ) {
            if ( ! empty( $block['attrs']['images'] ) ) {
                foreach ( $block['attrs']['images'] as $image ) {
                    if ( empty( $image['alt'] ) ) {
                        $count++;
                    }
                }
            }
        } elseif ( $block['blockName'] === 'core/cover' ) {
            if ( ! empty( $block['attrs']['url'] ) ) {
                if ( ! empty( $block['attrs']['alt'] ) ) {
                    // 属性にALTがあればOK
                } elseif ( $this->has_alt_in_html( $inner_html ) ) {
                    // HTMLにALTがあればOK
                } else {
                    $count++;
                }
            }
        } elseif ( $block['blockName'] === 'core/media-text' ) {
            if ( ! empty( $block['attrs']['mediaUrl'] ) ) {
                if ( ! empty( $block['attrs']['mediaAlt'] ) ) {
                    // 属性にALTがあればOK
                } elseif ( $this->has_alt_in_html( $inner_html ) ) {
                    // HTMLにALTがあればOK
                } else {
                    $count++;
                }
            }
        }

        // 内部ブロックを再帰的にチェック
        if ( ! empty( $block['innerBlocks'] ) ) {
            foreach ( $block['innerBlocks'] as $inner_block ) {
                $count += $this->check_alt_in_block( $inner_block );
            }
        }

        return $count;
    }

    /**
     * HTML内にALT属性が設定されているかチェック
     */
    private function has_alt_in_html( $html ) {
        if ( empty( $html ) ) {
            return false;
        }

        // imgタグのalt属性を検出（空でない値があるかチェック）
        if ( preg_match( '/<img[^>]+alt\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
            return ! empty( trim( $matches[1] ) );
        }

        return false;
    }

    /**
     * コンテンツ内の長文段落をチェック
     */
    private function check_long_paragraphs_in_content( $content ) {
        $settings = IC_Settings::get_instance()->get_settings();
        $threshold = isset( $settings['long_paragraph_threshold'] ) ? absint( $settings['long_paragraph_threshold'] ) : 200;
        $exclude_classes = IC_Settings::get_instance()->text_to_array( $settings['long_paragraph_exclude_classes'] );
        $count = 0;

        // ブロックエディタのコンテンツをパース
        $blocks = parse_blocks( $content );

        foreach ( $blocks as $block ) {
            $count += $this->check_long_paragraph_in_block( $block, $threshold, $exclude_classes, false );
        }

        return $count;
    }

    /**
     * ブロック内の長文段落を再帰的にチェック
     */
    private function check_long_paragraph_in_block( $block, $threshold, $exclude_classes = array(), $is_excluded = false ) {
        $count = 0;

        // このブロックが除外クラスを持っているかチェック
        $block_is_excluded = $is_excluded;
        if ( ! $block_is_excluded && ! empty( $exclude_classes ) ) {
            // 1. attrs.className をチェック（ユーザーが追加したクラス）
            $block_class = isset( $block['attrs']['className'] ) ? $block['attrs']['className'] : '';
            foreach ( $exclude_classes as $exclude_class ) {
                if ( ! empty( $exclude_class ) && strpos( $block_class, $exclude_class ) !== false ) {
                    $block_is_excluded = true;
                    break;
                }
            }

            // 2. innerHTML をチェック（テーマが自動で追加するクラス、例: swell-block-accordion__body）
            if ( ! $block_is_excluded ) {
                $inner_html = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';
                foreach ( $exclude_classes as $exclude_class ) {
                    if ( ! empty( $exclude_class ) && strpos( $inner_html, 'class=' ) !== false && strpos( $inner_html, $exclude_class ) !== false ) {
                        $block_is_excluded = true;
                        break;
                    }
                }
            }
        }

        // 除外されていない段落ブロックのみチェック
        if ( ! $block_is_excluded && $block['blockName'] === 'core/paragraph' ) {
            $inner_html = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';
            $plain_text = wp_strip_all_tags( $inner_html );
            $char_count = mb_strlen( $plain_text );

            if ( $char_count >= $threshold ) {
                $count++;
            }
        }

        // 内部ブロックを再帰的にチェック（除外状態を引き継ぐ）
        if ( ! empty( $block['innerBlocks'] ) ) {
            foreach ( $block['innerBlocks'] as $inner_block ) {
                $count += $this->check_long_paragraph_in_block( $inner_block, $threshold, $exclude_classes, $block_is_excluded );
            }
        }

        return $count;
    }

    /**
     * H2必須キーワードをチェック
     * タイトルに含まれているキーワードがH2見出しに含まれているかチェック
     */
    private function check_h2_required_keywords( $title, $content ) {
        $settings = IC_Settings::get_instance()->get_settings();
        $keywords = IC_Settings::get_instance()->text_to_array( $settings['h2_required_keywords'] );
        $count = 0;

        if ( empty( $keywords ) || empty( $title ) ) {
            return 0;
        }

        // H2見出しを抽出
        $h2_texts = array();
        if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $content, $matches ) ) {
            foreach ( $matches[1] as $heading ) {
                $h2_texts[] = wp_strip_all_tags( $heading );
            }
        }

        // 各キーワードをチェック
        foreach ( $keywords as $keyword ) {
            if ( empty( $keyword ) ) {
                continue;
            }

            // タイトルにキーワードが含まれているか
            if ( mb_strpos( $title, $keyword ) !== false ) {
                // H2のいずれかにキーワードが含まれているか
                $found_in_h2 = false;
                foreach ( $h2_texts as $h2_text ) {
                    if ( mb_strpos( $h2_text, $keyword ) !== false ) {
                        $found_in_h2 = true;
                        break;
                    }
                }
                if ( ! $found_in_h2 ) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * コンテンツ内のパターン重複をチェック
     */
    private function check_duplicate_patterns_in_content( $content ) {
        $settings = IC_Settings::get_instance()->get_settings();
        $target_patterns = IC_Settings::get_instance()->text_to_array( $settings['duplicate_pattern_names'] );

        if ( empty( $target_patterns ) ) {
            return 0;
        }

        $blocks = parse_blocks( $content );
        $pattern_usage = array();

        $this->collect_pattern_usage( $blocks, $target_patterns, $pattern_usage );

        // 2回以上使用されているパターンの数をカウント
        $count = 0;
        foreach ( $pattern_usage as $name => $client_ids ) {
            if ( count( $client_ids ) >= 2 ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * ブロック内の同期パターン使用状況を再帰的に収集
     * 同期パターンは core/block ブロックとして挿入され、ref属性に投稿IDが含まれる
     */
    private function collect_pattern_usage( $blocks, $target_patterns, &$pattern_usage ) {
        foreach ( $blocks as $block ) {
            // 同期パターン（core/block）をチェック
            if ( $block['blockName'] === 'core/block' && isset( $block['attrs']['ref'] ) ) {
                $ref_id = (string) $block['attrs']['ref'];

                if ( in_array( $ref_id, $target_patterns, true ) ) {
                    if ( ! isset( $pattern_usage[ $ref_id ] ) ) {
                        $pattern_usage[ $ref_id ] = array();
                    }
                    $pattern_usage[ $ref_id ][] = true;
                }
            }

            // 内部ブロックを再帰的にチェック
            if ( ! empty( $block['innerBlocks'] ) ) {
                $this->collect_pattern_usage( $block['innerBlocks'], $target_patterns, $pattern_usage );
            }
        }
    }
}
