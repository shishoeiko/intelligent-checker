<?php
/**
 * IC_Settings - 設定関連クラス
 *
 * @package Intelligent_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 設定管理クラス
 */
class IC_Settings {

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
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * デフォルト設定値を取得
     */
    public function get_defaults() {
        return array(
            // ALT Checker設定
            'alt_checker_enabled' => true,
            // Naked URL Alert設定
            'naked_url_enabled'   => true,
            // タイトルチェック設定
            'title_checker_enabled' => true,
            'char_min'            => 28,
            'char_max'            => 40,
            'required_kw'         => "詐欺\n口コミ\n評判",
            'recommended_kw'      => "返金",
            'checklist'           => "振り込め詐欺・タスク詐欺・投資詐欺など、一般系の記事と重複するようなキーワードが入っていないか\n同じKWを複数回使用していないか？（無駄なので避けたい）",
            // 長文段落チェック設定
            'long_paragraph_enabled'   => true,
            'long_paragraph_threshold' => 200,
            'long_paragraph_exclude_classes' => 'swell-block-accordion__body',
            // 見出し構造チェック設定
            'heading_structure_enabled' => true,
            // スラッグチェック設定
            'slug_checker_enabled' => true,
            // 重複キーワードチェック設定
            'duplicate_keyword_enabled' => true,
            'duplicate_keywords' => "詐欺\n口コミ\n評判\n返金\n弁護士\n手口",
            // アイキャッチ画像チェック設定
            'featured_image_checker_enabled' => true,
            // 禁止キーワードチェック設定（タイトル用）
            'forbidden_keyword_enabled' => true,
            'forbidden_keywords' => "|\n｜",
            // 禁止キーワードチェック設定（H2見出し用）
            'forbidden_keywords_heading' => "",
            // 要注意キーワードチェック設定（タイトル用）
            'caution_keyword_enabled' => true,
            'caution_keywords' => "投資\n副業\nネットショップ",
            // 要注意キーワードチェック設定（H2見出し用）
            'caution_keywords_heading' => "投資\n副業\nネットショップ",
            // 禁止文字・文言チェック設定（投稿全体）
            'banned_patterns_enabled' => true,
            'banned_patterns' => "**",
            // H2直下H3チェック設定
            'h2_h3_direct_enabled' => true,
            // 見出し重複チェック設定
            'duplicate_heading_enabled' => true,
            // 投稿一覧エラー表示設定
            'post_list_error_column_enabled' => true,
            'post_list_show_forbidden_keyword_title' => true,
            'post_list_show_forbidden_keyword_heading' => true,
            'post_list_show_caution_keyword_title' => true,
            'post_list_show_caution_keyword_heading' => true,
            'post_list_show_duplicate_keyword' => true,
            'post_list_show_slug_error' => true,
            'post_list_show_featured_image' => true,
            'post_list_show_alt_missing' => true,
            'post_list_show_long_paragraph' => true,
            'post_list_show_banned_patterns' => true,
            'post_list_show_h2_h3_direct' => true,
            'post_list_show_duplicate_heading' => true,
        );
    }

    /**
     * 設定値を取得
     */
    public function get_settings() {
        $defaults = $this->get_defaults();
        $settings = get_option( 'intelligent_checker_settings', array() );
        return wp_parse_args( $settings, $defaults );
    }

    /**
     * 改行区切りテキストを配列に変換
     */
    public function text_to_array( $text ) {
        if ( empty( $text ) ) {
            return array();
        }
        $lines = explode( "\n", $text );
        $lines = array_map( 'trim', $lines );
        $lines = array_filter( $lines, function( $line ) {
            return $line !== '';
        });
        return array_values( $lines );
    }

    /**
     * 管理画面メニューを追加
     */
    public function add_admin_menu() {
        add_options_page(
            'Intelligent Checker 設定',
            'Intelligent Checker',
            'manage_options',
            'intelligent-checker-settings',
            array( $this, 'settings_page' )
        );
    }

    /**
     * 設定を登録
     */
    public function register_settings() {
        register_setting(
            'intelligent_checker_settings_group',
            'intelligent_checker_settings',
            array( $this, 'sanitize_settings' )
        );
    }

    /**
     * 設定値のサニタイズ
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        // 各機能の有効/無効
        $sanitized['alt_checker_enabled']   = ! empty( $input['alt_checker_enabled'] );
        $sanitized['naked_url_enabled']     = ! empty( $input['naked_url_enabled'] );
        $sanitized['title_checker_enabled'] = ! empty( $input['title_checker_enabled'] );

        // 文字数設定
        $sanitized['char_min'] = isset( $input['char_min'] ) ? absint( $input['char_min'] ) : 28;
        $sanitized['char_max'] = isset( $input['char_max'] ) ? absint( $input['char_max'] ) : 40;

        // キーワード・チェックリスト
        $sanitized['required_kw']    = isset( $input['required_kw'] ) ? sanitize_textarea_field( $input['required_kw'] ) : '';
        $sanitized['recommended_kw'] = isset( $input['recommended_kw'] ) ? sanitize_textarea_field( $input['recommended_kw'] ) : '';
        $sanitized['checklist']      = isset( $input['checklist'] ) ? sanitize_textarea_field( $input['checklist'] ) : '';

        // 長文段落チェック設定
        $sanitized['long_paragraph_enabled']   = ! empty( $input['long_paragraph_enabled'] );
        $sanitized['long_paragraph_threshold'] = isset( $input['long_paragraph_threshold'] ) ? absint( $input['long_paragraph_threshold'] ) : 200;
        $sanitized['long_paragraph_exclude_classes'] = isset( $input['long_paragraph_exclude_classes'] ) ? sanitize_textarea_field( $input['long_paragraph_exclude_classes'] ) : '';

        // 見出し構造チェック設定
        $sanitized['heading_structure_enabled'] = ! empty( $input['heading_structure_enabled'] );

        // スラッグチェック設定
        $sanitized['slug_checker_enabled'] = ! empty( $input['slug_checker_enabled'] );

        // 重複キーワードチェック設定
        $sanitized['duplicate_keyword_enabled'] = ! empty( $input['duplicate_keyword_enabled'] );
        $sanitized['duplicate_keywords'] = isset( $input['duplicate_keywords'] ) ? sanitize_textarea_field( $input['duplicate_keywords'] ) : '';

        // アイキャッチ画像チェック設定
        $sanitized['featured_image_checker_enabled'] = ! empty( $input['featured_image_checker_enabled'] );

        // 禁止キーワードチェック設定（タイトル用）
        $sanitized['forbidden_keyword_enabled'] = ! empty( $input['forbidden_keyword_enabled'] );
        $sanitized['forbidden_keywords'] = isset( $input['forbidden_keywords'] ) ? sanitize_textarea_field( $input['forbidden_keywords'] ) : '';

        // 禁止キーワードチェック設定（H2見出し用）
        $sanitized['forbidden_keywords_heading'] = isset( $input['forbidden_keywords_heading'] ) ? sanitize_textarea_field( $input['forbidden_keywords_heading'] ) : '';

        // 要注意キーワードチェック設定（タイトル用）
        $sanitized['caution_keyword_enabled'] = ! empty( $input['caution_keyword_enabled'] );
        $sanitized['caution_keywords'] = isset( $input['caution_keywords'] ) ? sanitize_textarea_field( $input['caution_keywords'] ) : '';

        // 要注意キーワードチェック設定（H2見出し用）
        $sanitized['caution_keywords_heading'] = isset( $input['caution_keywords_heading'] ) ? sanitize_textarea_field( $input['caution_keywords_heading'] ) : '';

        // 禁止文字・文言チェック設定（投稿全体）
        $sanitized['banned_patterns_enabled'] = ! empty( $input['banned_patterns_enabled'] );
        $sanitized['banned_patterns'] = isset( $input['banned_patterns'] ) ? sanitize_textarea_field( $input['banned_patterns'] ) : '';

        // H2直下H3チェック設定
        $sanitized['h2_h3_direct_enabled'] = ! empty( $input['h2_h3_direct_enabled'] );

        // 見出し重複チェック設定
        $sanitized['duplicate_heading_enabled'] = ! empty( $input['duplicate_heading_enabled'] );

        // 投稿一覧エラー表示設定
        $sanitized['post_list_error_column_enabled'] = ! empty( $input['post_list_error_column_enabled'] );
        $sanitized['post_list_show_forbidden_keyword_title'] = ! empty( $input['post_list_show_forbidden_keyword_title'] );
        $sanitized['post_list_show_forbidden_keyword_heading'] = ! empty( $input['post_list_show_forbidden_keyword_heading'] );
        $sanitized['post_list_show_caution_keyword_title'] = ! empty( $input['post_list_show_caution_keyword_title'] );
        $sanitized['post_list_show_caution_keyword_heading'] = ! empty( $input['post_list_show_caution_keyword_heading'] );
        $sanitized['post_list_show_duplicate_keyword'] = ! empty( $input['post_list_show_duplicate_keyword'] );
        $sanitized['post_list_show_slug_error'] = ! empty( $input['post_list_show_slug_error'] );
        $sanitized['post_list_show_featured_image'] = ! empty( $input['post_list_show_featured_image'] );
        $sanitized['post_list_show_alt_missing'] = ! empty( $input['post_list_show_alt_missing'] );
        $sanitized['post_list_show_long_paragraph'] = ! empty( $input['post_list_show_long_paragraph'] );
        $sanitized['post_list_show_banned_patterns'] = ! empty( $input['post_list_show_banned_patterns'] );
        $sanitized['post_list_show_h2_h3_direct'] = ! empty( $input['post_list_show_h2_h3_direct'] );
        $sanitized['post_list_show_duplicate_heading'] = ! empty( $input['post_list_show_duplicate_heading'] );

        return $sanitized;
    }

    /**
     * 設定ページの表示
     */
    public function settings_page() {
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>Intelligent Checker 設定</h1>

            <?php if ( isset( $_GET['update_checked'] ) && $_GET['update_checked'] === '1' ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>更新を確認しました。新しいバージョンがある場合は<a href="<?php echo admin_url( 'plugins.php' ); ?>">プラグイン一覧</a>に表示されます。</p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'intelligent_checker_settings_group' ); ?>

                <style>
                    .ic-settings {
                        max-width: 700px;
                    }
                    .ic-settings .form-section {
                        background: #fff;
                        border: 1px solid #ccd0d4;
                        border-radius: 4px;
                        padding: 20px;
                        margin-bottom: 20px;
                    }
                    .ic-settings .form-section h2 {
                        margin-top: 0;
                        padding-bottom: 10px;
                        border-bottom: 1px solid #eee;
                        font-size: 16px;
                    }
                    .ic-settings .toggle-row {
                        display: flex;
                        align-items: center;
                        gap: 12px;
                        padding: 12px 0;
                        border-bottom: 1px solid #f0f0f0;
                    }
                    .ic-settings .toggle-row:last-child {
                        border-bottom: none;
                    }
                    .ic-settings .toggle-label {
                        flex: 1;
                    }
                    .ic-settings .toggle-label strong {
                        display: block;
                        margin-bottom: 4px;
                    }
                    .ic-settings .toggle-label span {
                        color: #666;
                        font-size: 13px;
                    }
                    .ic-settings .char-limit-inputs {
                        display: flex;
                        gap: 20px;
                        align-items: center;
                    }
                    .ic-settings .char-limit-inputs label {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                    }
                    .ic-settings .char-limit-inputs input {
                        width: 80px;
                    }
                    .ic-settings textarea {
                        width: 100%;
                        min-height: 100px;
                        font-family: inherit;
                    }
                    .ic-settings .description {
                        color: #666;
                        font-size: 13px;
                        margin-top: 8px;
                    }
                </style>

                <div class="ic-settings">

                    <!-- 機能の有効/無効 -->
                    <div class="form-section">
                        <h2>機能の有効/無効</h2>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>画像ALTチェッカー</strong>
                                <span>ALT属性が未設定の画像をハイライト表示し、アラートで通知します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[alt_checker_enabled]" value="1" <?php checked( $settings['alt_checker_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>URL直書きアラート</strong>
                                <span>URLがそのままアンカーテキストになっている場合にアラートを表示します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[naked_url_enabled]" value="1" <?php checked( $settings['naked_url_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>タイトルチェッカー</strong>
                                <span>タイトル入力欄直下にキーワードチェック・文字数チェック・セルフチェックリストを表示します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[title_checker_enabled]" value="1" <?php checked( $settings['title_checker_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>長文段落チェッカー</strong>
                                <span>段落ブロック内のテキストが指定文字数以上の場合にアラートを表示します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[long_paragraph_enabled]" value="1" <?php checked( $settings['long_paragraph_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>見出し構造チェッカー</strong>
                                <span>サイドバーに見出し（H2・H3）の階層構造を表示し、H2一覧のコピー機能を提供します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[heading_structure_enabled]" value="1" <?php checked( $settings['heading_structure_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>スラッグチェッカー</strong>
                                <span>記事URLのスラッグに英数字・ハイフン以外の文字（アンダーバー等）が含まれている場合にエラーを表示します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[slug_checker_enabled]" value="1" <?php checked( $settings['slug_checker_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>重複キーワードチェッカー</strong>
                                <span>タイトル内で同じキーワードが複数回使用されている場合にアラートを表示します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[duplicate_keyword_enabled]" value="1" <?php checked( $settings['duplicate_keyword_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>アイキャッチ画像チェッカー</strong>
                                <span>アイキャッチ画像が設定されていない場合にアラートを表示します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[featured_image_checker_enabled]" value="1" <?php checked( $settings['featured_image_checker_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>禁止キーワードチェッカー</strong>
                                <span>タイトルに使用してはいけないキーワードが含まれている場合にアラートを表示します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[forbidden_keyword_enabled]" value="1" <?php checked( $settings['forbidden_keyword_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>要注意キーワードチェッカー</strong>
                                <span>タイトルに要注意キーワードが含まれている場合にアラートを表示します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[caution_keyword_enabled]" value="1" <?php checked( $settings['caution_keyword_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>禁止文字・文言チェッカー</strong>
                                <span>投稿全体（タイトル、見出し、本文）に禁止文字・文言が含まれている場合にアラートを表示します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[banned_patterns_enabled]" value="1" <?php checked( $settings['banned_patterns_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>H2直下H3チェッカー</strong>
                                <span>H2見出しの直下に本文がなくH3見出しが続いている場合にアラートを表示します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[h2_h3_direct_enabled]" value="1" <?php checked( $settings['h2_h3_direct_enabled'] ); ?>>
                                有効
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-label">
                                <strong>見出し重複チェッカー</strong>
                                <span>同じ文言の見出しが複数ある場合にアラートを表示します</span>
                            </div>
                            <label>
                                <input type="checkbox" name="intelligent_checker_settings[duplicate_heading_enabled]" value="1" <?php checked( $settings['duplicate_heading_enabled'] ); ?>>
                                有効
                            </label>
                        </div>
                    </div>

                    <!-- タイトルチェック: 文字数設定 -->
                    <div class="form-section">
                        <h2>タイトルチェック: 文字数設定</h2>
                        <div class="char-limit-inputs">
                            <label>
                                最小文字数:
                                <input type="number" name="intelligent_checker_settings[char_min]" value="<?php echo esc_attr( $settings['char_min'] ); ?>" min="0" max="200">
                            </label>
                            <span>〜</span>
                            <label>
                                最大文字数:
                                <input type="number" name="intelligent_checker_settings[char_max]" value="<?php echo esc_attr( $settings['char_max'] ); ?>" min="0" max="200">
                            </label>
                        </div>
                        <p class="description">推奨文字数の範囲を設定します。</p>
                    </div>

                    <!-- タイトルチェック: 必須キーワード -->
                    <div class="form-section">
                        <h2>タイトルチェック: 必須キーワード</h2>
                        <textarea name="intelligent_checker_settings[required_kw]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['required_kw'] ); ?></textarea>
                        <p class="description">1行に1つずつキーワードを入力してください。含まれていない場合は赤色で表示されます。</p>
                    </div>

                    <!-- タイトルチェック: 推奨キーワード -->
                    <div class="form-section">
                        <h2>タイトルチェック: 推奨キーワード</h2>
                        <textarea name="intelligent_checker_settings[recommended_kw]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['recommended_kw'] ); ?></textarea>
                        <p class="description">1行に1つずつキーワードを入力してください。含まれていない場合はオレンジ色で表示されます。</p>
                    </div>

                    <!-- タイトルチェック: セルフチェックリスト -->
                    <div class="form-section">
                        <h2>タイトルチェック: セルフチェックリスト</h2>
                        <textarea name="intelligent_checker_settings[checklist]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['checklist'] ); ?></textarea>
                        <p class="description">1行に1つずつチェック項目を入力してください。ライターが手動でチェックする項目です。</p>
                    </div>

                    <!-- 重複チェック: 対象キーワード -->
                    <div class="form-section">
                        <h2>重複チェック: 対象キーワード</h2>
                        <textarea name="intelligent_checker_settings[duplicate_keywords]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['duplicate_keywords'] ); ?></textarea>
                        <p class="description">1行に1つずつキーワードを入力してください。タイトル内でこれらのキーワードが2回以上使用されている場合にアラートを表示します。</p>
                    </div>

                    <!-- 禁止キーワード（タイトル） -->
                    <div class="form-section">
                        <h2>禁止キーワード（タイトル）</h2>
                        <textarea name="intelligent_checker_settings[forbidden_keywords]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['forbidden_keywords'] ); ?></textarea>
                        <p class="description">1行に1つずつキーワードを入力してください。タイトルにこれらのキーワードが含まれている場合に赤色のアラートを表示します。</p>
                    </div>

                    <!-- 禁止キーワード（H2見出し） -->
                    <div class="form-section">
                        <h2>禁止キーワード（H2見出し）</h2>
                        <textarea name="intelligent_checker_settings[forbidden_keywords_heading]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['forbidden_keywords_heading'] ); ?></textarea>
                        <p class="description">1行に1つずつキーワードを入力してください。H2見出しにこれらのキーワードが含まれている場合に赤色のアラートを表示します。空の場合はチェックされません。</p>
                    </div>

                    <!-- 要注意キーワード（タイトル） -->
                    <div class="form-section">
                        <h2>要注意キーワード（タイトル）</h2>
                        <textarea name="intelligent_checker_settings[caution_keywords]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['caution_keywords'] ); ?></textarea>
                        <p class="description">1行に1つずつキーワードを入力してください。タイトルにこれらのキーワードが含まれている場合に黄色のアラートを表示します。</p>
                    </div>

                    <!-- 要注意キーワード（H2見出し） -->
                    <div class="form-section">
                        <h2>要注意キーワード（H2見出し）</h2>
                        <textarea name="intelligent_checker_settings[caution_keywords_heading]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['caution_keywords_heading'] ); ?></textarea>
                        <p class="description">1行に1つずつキーワードを入力してください。H2見出しにこれらのキーワードが含まれている場合に黄色のアラートを表示します。空の場合はチェックされません。</p>
                    </div>

                    <!-- 禁止文字・文言（投稿全体） -->
                    <div class="form-section">
                        <h2>禁止文字・文言（投稿全体）</h2>
                        <textarea name="intelligent_checker_settings[banned_patterns]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['banned_patterns'] ); ?></textarea>
                        <p class="description">1行に1つずつ文字・文言を入力してください。投稿全体（タイトル、見出し、本文）にこれらが含まれている場合に赤色のアラートを表示します。例: ** （Markdown太字記法の消し忘れ検出）</p>
                    </div>

                    <!-- 長文段落チェック: 閾値設定 -->
                    <div class="form-section">
                        <h2>長文段落チェック: 閾値設定</h2>
                        <label>
                            <input type="number" name="intelligent_checker_settings[long_paragraph_threshold]" value="<?php echo esc_attr( $settings['long_paragraph_threshold'] ); ?>" min="50" max="1000" style="width: 100px;">
                            文字以上
                        </label>
                        <p class="description">段落内のテキストがこの文字数以上の場合にアラートを表示します。視認性向上のため改行を追加することを推奨します。</p>
                    </div>

                    <!-- 長文段落チェック: 除外クラス -->
                    <div class="form-section">
                        <h2>長文段落チェック: 除外する親要素のクラス</h2>
                        <textarea name="intelligent_checker_settings[long_paragraph_exclude_classes]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['long_paragraph_exclude_classes'] ); ?></textarea>
                        <p class="description">1行に1つずつクラス名を入力してください。これらのクラスを持つ要素の配下にある段落はチェック対象から除外されます。</p>
                    </div>

                    <!-- 投稿一覧のエラー表示 -->
                    <div class="form-section">
                        <h2>投稿一覧のエラー表示</h2>
                        <label>
                            <input type="checkbox" name="intelligent_checker_settings[post_list_error_column_enabled]" value="1" <?php checked( $settings['post_list_error_column_enabled'] ); ?>>
                            投稿一覧にエラー数カラムを表示
                        </label>
                        <p class="description" style="margin-top: 15px; margin-bottom: 8px;">カウントするエラー:</p>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_forbidden_keyword_title]" value="1" <?php checked( $settings['post_list_show_forbidden_keyword_title'] ); ?>>
                            禁止キーワード（タイトル）
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_forbidden_keyword_heading]" value="1" <?php checked( $settings['post_list_show_forbidden_keyword_heading'] ); ?>>
                            禁止キーワード（H2見出し）
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_caution_keyword_title]" value="1" <?php checked( $settings['post_list_show_caution_keyword_title'] ); ?>>
                            要注意キーワード（タイトル）
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_caution_keyword_heading]" value="1" <?php checked( $settings['post_list_show_caution_keyword_heading'] ); ?>>
                            要注意キーワード（H2見出し）
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_duplicate_keyword]" value="1" <?php checked( $settings['post_list_show_duplicate_keyword'] ); ?>>
                            重複キーワード（タイトル）
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_slug_error]" value="1" <?php checked( $settings['post_list_show_slug_error'] ); ?>>
                            スラッグエラー
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_featured_image]" value="1" <?php checked( $settings['post_list_show_featured_image'] ); ?>>
                            アイキャッチ画像未設定
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_alt_missing]" value="1" <?php checked( $settings['post_list_show_alt_missing'] ); ?>>
                            ALT未設定画像
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_long_paragraph]" value="1" <?php checked( $settings['post_list_show_long_paragraph'] ); ?>>
                            長文段落
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_banned_patterns]" value="1" <?php checked( $settings['post_list_show_banned_patterns'] ); ?>>
                            禁止文字・文言
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_h2_h3_direct]" value="1" <?php checked( $settings['post_list_show_h2_h3_direct'] ); ?>>
                            H2直下H3
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="intelligent_checker_settings[post_list_show_duplicate_heading]" value="1" <?php checked( $settings['post_list_show_duplicate_heading'] ); ?>>
                            見出し重複
                        </label>
                        <p style="margin-top: 15px;">
                            <a href="<?php echo wp_nonce_url( admin_url( 'options-general.php?page=intelligent-checker-settings&ic_recalculate_errors=1' ), 'ic_recalculate_errors' ); ?>" class="button button-secondary">エラー数を再計算</a>
                            <span style="margin-left: 10px; color: #666;">全投稿のエラー数を再計算します（ソート機能に必要）</span>
                            <?php if ( isset( $_GET['ic_recalculated'] ) ) : ?>
                                <span style="margin-left: 10px; color: #2e7d32; font-weight: 500;"><?php echo esc_html( absint( $_GET['ic_recalculated'] ) ); ?>件の投稿を再計算しました</span>
                            <?php endif; ?>
                        </p>
                    </div>

                </div>

                <?php submit_button( '設定を保存' ); ?>
            </form>

            <div class="ic-settings" style="margin-top: 30px;">
                <div class="form-section">
                    <h2>プラグインの更新</h2>
                    <p>GitHubから最新バージョンを確認します。新しいバージョンがある場合は、プラグイン一覧ページで更新できます。</p>
                    <p>
                        <a href="<?php echo wp_nonce_url( admin_url( 'options-general.php?page=intelligent-checker-settings&ic_check_update=1' ), 'ic_check_update' ); ?>" class="button button-secondary">更新を確認</a>
                        <span style="margin-left: 10px; color: #666;">現在のバージョン: <?php echo Intelligent_Checker::VERSION; ?></span>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
}
