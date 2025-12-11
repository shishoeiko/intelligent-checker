<?php
/**
 * Plugin Name: Intelligent Checker
 * Description: 投稿編集画面で画像ALT属性チェック、URL直書きアラート、タイトルセルフチェックを行う統合プラグイン
 * Version: 1.5.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: intelligent-checker
 */

// 直接アクセス禁止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Intelligent Checker メインクラス
 */
class Intelligent_Checker {

    const VERSION = '1.5.0';

    // GitHub自動更新用定数
    const GITHUB_USERNAME = 'shishoeiko';
    const GITHUB_REPO = 'intelligent-checker';
    const GITHUB_API_URL = 'https://api.github.com/repos/shishoeiko/intelligent-checker/releases/latest';
    const CACHE_KEY = 'intelligent_checker_github_release';
    const CACHE_EXPIRATION = 43200; // 12時間

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
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );

        // GitHub自動更新
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_plugin_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
        add_action( 'admin_init', array( $this, 'handle_check_update' ) );

        // 作成者機能
        add_action( 'init', array( $this, 'register_creator_meta' ) );
        add_action( 'wp_insert_post', array( $this, 'set_default_creator' ), 10, 3 );
        add_action( 'rest_api_init', array( $this, 'register_creator_rest_routes' ) );

        // 投稿一覧・クイック編集
        add_filter( 'manage_posts_columns', array( $this, 'add_creator_column' ) );
        add_action( 'manage_posts_custom_column', array( $this, 'render_creator_column' ), 10, 2 );
        add_action( 'quick_edit_custom_box', array( $this, 'add_creator_quick_edit' ), 10, 2 );
        add_action( 'save_post', array( $this, 'save_creator_quick_edit' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // 作成者フィルター
        add_action( 'restrict_manage_posts', array( $this, 'add_creator_filter_dropdown' ) );
        add_action( 'pre_get_posts', array( $this, 'filter_posts_by_creator' ) );

        // 一括編集
        add_action( 'bulk_edit_custom_box', array( $this, 'add_creator_bulk_edit' ), 10, 2 );
        add_action( 'wp_ajax_save_bulk_creator', array( $this, 'save_bulk_creator' ) );
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
            'long_paragraph_exclude_classes' => '',
            // 見出し構造チェック設定
            'heading_structure_enabled' => true,
            // スラッグチェック設定
            'slug_checker_enabled' => true,
            // 重複キーワードチェック設定
            'duplicate_keyword_enabled' => true,
            'duplicate_keywords' => "詐欺\n口コミ\n評判\n返金\n弁護士\n手口",
            // アイキャッチ画像チェック設定
            'featured_image_checker_enabled' => true,
            // 禁止キーワードチェック設定
            'forbidden_keyword_enabled' => true,
            'forbidden_keywords' => '',
            // 要注意キーワードチェック設定
            'caution_keyword_enabled' => true,
            'caution_keywords' => '',
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

        // 禁止キーワードチェック設定
        $sanitized['forbidden_keyword_enabled'] = ! empty( $input['forbidden_keyword_enabled'] );
        $sanitized['forbidden_keywords'] = isset( $input['forbidden_keywords'] ) ? sanitize_textarea_field( $input['forbidden_keywords'] ) : '';

        // 要注意キーワードチェック設定
        $sanitized['caution_keyword_enabled'] = ! empty( $input['caution_keyword_enabled'] );
        $sanitized['caution_keywords'] = isset( $input['caution_keywords'] ) ? sanitize_textarea_field( $input['caution_keywords'] ) : '';

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

                    <!-- 禁止キーワード -->
                    <div class="form-section">
                        <h2>禁止キーワード</h2>
                        <textarea name="intelligent_checker_settings[forbidden_keywords]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['forbidden_keywords'] ); ?></textarea>
                        <p class="description">1行に1つずつキーワードを入力してください。タイトルにこれらのキーワードが含まれている場合に赤色のアラートを表示します。</p>
                    </div>

                    <!-- 要注意キーワード -->
                    <div class="form-section">
                        <h2>要注意キーワード</h2>
                        <textarea name="intelligent_checker_settings[caution_keywords]" placeholder="1行に1つずつ入力"><?php echo esc_textarea( $settings['caution_keywords'] ); ?></textarea>
                        <p class="description">1行に1つずつキーワードを入力してください。タイトルにこれらのキーワードが含まれている場合に黄色のアラートを表示します。</p>
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

                </div>

                <?php submit_button( '設定を保存' ); ?>
            </form>

            <div class="ic-settings" style="margin-top: 30px;">
                <div class="form-section">
                    <h2>プラグインの更新</h2>
                    <p>GitHubから最新バージョンを確認します。新しいバージョンがある場合は、プラグイン一覧ページで更新できます。</p>
                    <p>
                        <a href="<?php echo wp_nonce_url( admin_url( 'options-general.php?page=intelligent-checker-settings&ic_check_update=1' ), 'ic_check_update' ); ?>" class="button button-secondary">更新を確認</a>
                        <span style="margin-left: 10px; color: #666;">現在のバージョン: <?php echo self::VERSION; ?></span>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * エディター用アセットを読み込む
     */
    public function enqueue_editor_assets() {
        $screen = get_current_screen();

        // 投稿編集画面でのみ読み込む
        if ( ! $screen || $screen->base !== 'post' || ! $screen->is_block_editor() ) {
            return;
        }

        $settings = $this->get_settings();

        // メインスクリプト
        wp_enqueue_script(
            'intelligent-checker-editor',
            plugin_dir_url( __FILE__ ) . 'js/editor.js',
            array( 'wp-plugins', 'wp-data', 'wp-element', 'wp-components', 'wp-edit-post', 'wp-blocks', 'wp-dom-ready', 'wp-compose' ),
            self::VERSION,
            true
        );

        // メインスタイル
        wp_enqueue_style(
            'intelligent-checker-editor',
            plugin_dir_url( __FILE__ ) . 'css/editor.css',
            array(),
            self::VERSION
        );

        // JavaScript用設定を渡す
        wp_localize_script( 'intelligent-checker-editor', 'intelligentCheckerConfig', array(
            // 機能の有効/無効
            'altCheckerEnabled'       => (bool) $settings['alt_checker_enabled'],
            'nakedUrlEnabled'         => (bool) $settings['naked_url_enabled'],
            'titleCheckerEnabled'     => (bool) $settings['title_checker_enabled'],
            'longParagraphEnabled'        => (bool) $settings['long_paragraph_enabled'],
            'headingStructureEnabled'     => (bool) $settings['heading_structure_enabled'],
            'slugCheckerEnabled'          => (bool) $settings['slug_checker_enabled'],
            'duplicateKeywordEnabled'     => (bool) $settings['duplicate_keyword_enabled'],
            'duplicateKeywords'           => $this->text_to_array( $settings['duplicate_keywords'] ),
            'featuredImageCheckerEnabled' => (bool) $settings['featured_image_checker_enabled'],
            'forbiddenKeywordEnabled'     => (bool) $settings['forbidden_keyword_enabled'],
            'forbiddenKeywords'           => $this->text_to_array( $settings['forbidden_keywords'] ),
            'cautionKeywordEnabled'       => (bool) $settings['caution_keyword_enabled'],
            'cautionKeywords'             => $this->text_to_array( $settings['caution_keywords'] ),
            'longParagraphThreshold'      => (int) $settings['long_paragraph_threshold'],
            'longParagraphExcludeClasses' => $this->text_to_array( $settings['long_paragraph_exclude_classes'] ),
            // タイトルチェック設定
            'keywords'  => array(
                'required'    => $this->text_to_array( $settings['required_kw'] ),
                'recommended' => $this->text_to_array( $settings['recommended_kw'] ),
            ),
            'charLimit' => array(
                'min' => (int) $settings['char_min'],
                'max' => (int) $settings['char_max'],
            ),
            'checklistItems' => $this->text_to_array( $settings['checklist'] ),
            // ローカライズ文字列
            'l10n' => array(
                // ALT Checker
                'altAlertTitle'    => __( '件の画像にALT属性（代替テキスト）が設定されていません', 'intelligent-checker' ),
                'altAlertDesc'     => __( 'アクセシビリティ向上のため、すべての画像に代替テキストを設定してください', 'intelligent-checker' ),
                'altBadgeText'     => __( 'ALT未設定', 'intelligent-checker' ),
                'altCheckImages'   => __( '画像を確認', 'intelligent-checker' ),
                'altPanelTitle'    => __( '画像ALTチェック', 'intelligent-checker' ),
                'altAllSet'        => __( 'すべての画像にALTが設定されています', 'intelligent-checker' ),
                'altImageLabel'    => __( '画像', 'intelligent-checker' ),
                'altStatusSet'     => __( '設定済み', 'intelligent-checker' ),
                'altStatusMissing' => __( '未設定', 'intelligent-checker' ),
                // Naked URL Alert
                'nakedUrlMessage'  => __( 'URLが直書きでリンクされている箇所があります。適切なアンカーテキストに変更することを検討してください。', 'intelligent-checker' ),
                'nakedUrlDetail'   => __( '該当箇所', 'intelligent-checker' ),
                // Long Paragraph Checker
                'longParagraphAlertTitle' => __( '件の段落が長すぎます', 'intelligent-checker' ),
                'longParagraphAlertDesc'  => __( '視認性向上のため、適切な箇所で改行を追加してください', 'intelligent-checker' ),
                'longParagraphCheck'      => __( '段落を確認', 'intelligent-checker' ),
                // Heading Structure Checker
                'headingPanelTitle' => __( '見出し構造', 'intelligent-checker' ),
                'copyH2Button'      => __( 'H2一覧をコピー', 'intelligent-checker' ),
                'noHeadings'        => __( '見出しがありません', 'intelligent-checker' ),
                'copySuccess'       => __( 'コピーしました', 'intelligent-checker' ),
                'copyError'         => __( 'コピーに失敗しました', 'intelligent-checker' ),
                'emptyHeading'      => __( '(空の見出し)', 'intelligent-checker' ),
                // Slug Checker
                'slugAlertTitle'       => __( 'スラッグに使用できない文字が含まれています', 'intelligent-checker' ),
                'slugAlertDesc'        => __( '英数字とハイフン（-）のみ使用できます', 'intelligent-checker' ),
                'slugInvalidChars'     => __( '無効な文字', 'intelligent-checker' ),
                'slugNumbersOnlyTitle' => __( 'スラッグが数字のみになっています', 'intelligent-checker' ),
                'slugNumbersOnlyDesc'  => __( 'スラッグには英字を含めてください', 'intelligent-checker' ),
                // Duplicate Keyword Checker
                'duplicateKeywordTitle' => __( 'タイトルに同じキーワードが複数回使用されています', 'intelligent-checker' ),
                'duplicateKeywordDesc'  => __( '同じキーワードを複数回使用するのは冗長です。1つに減らすことを検討してください。', 'intelligent-checker' ),
                'duplicateKeywordList'  => __( '重複キーワード', 'intelligent-checker' ),
                // Featured Image Checker
                'featuredImageTitle'    => __( 'アイキャッチ画像が設定されていません', 'intelligent-checker' ),
                'featuredImageDesc'     => __( '記事の見栄えを良くするため、アイキャッチ画像を設定してください', 'intelligent-checker' ),
                // Forbidden Keyword Checker
                'forbiddenKeywordTitle' => __( 'タイトルに使用できないキーワードが含まれています', 'intelligent-checker' ),
                'forbiddenKeywordDesc'  => __( '以下のキーワードはタイトルに使用できません。別の表現に変更してください。', 'intelligent-checker' ),
                'forbiddenKeywordList'  => __( '禁止キーワード', 'intelligent-checker' ),
                // Caution Keyword Checker
                'cautionKeywordTitle' => __( 'タイトルに要注意キーワードが含まれています', 'intelligent-checker' ),
                'cautionKeywordDesc'  => __( '以下のキーワードが含まれています。問題がないか確認してください。', 'intelligent-checker' ),
                'cautionKeywordList'  => __( '要注意キーワード', 'intelligent-checker' ),
            ),
        ) );
    }

    /**
     * 設定ページへのリンクを追加
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . admin_url( 'options-general.php?page=intelligent-checker-settings' ) . '">設定</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * 更新確認ボタンの処理
     */
    public function handle_check_update() {
        if ( ! isset( $_GET['ic_check_update'] ) || $_GET['ic_check_update'] !== '1' ) {
            return;
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ic_check_update' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // キャッシュをクリア
        delete_transient( self::CACHE_KEY );
        delete_site_transient( 'update_plugins' );

        // 設定ページにリダイレクト
        wp_redirect( admin_url( 'options-general.php?page=intelligent-checker-settings&update_checked=1' ) );
        exit;
    }

    /**
     * GitHub APIから最新リリース情報を取得
     *
     * @return object|false リリース情報またはfalse
     */
    private function get_github_release_info() {
        // キャッシュをチェック
        $cached = get_transient( self::CACHE_KEY );
        if ( $cached !== false ) {
            return $cached;
        }

        // GitHub APIリクエスト
        $response = wp_remote_get( self::GITHUB_API_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
        ) );

        // エラーチェック
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $release ) || ! isset( $release->tag_name ) ) {
            return false;
        }

        // バージョン番号を正規化（v1.0.1 -> 1.0.1）
        $version = ltrim( $release->tag_name, 'vV' );

        // 必要な情報を整形
        $release_info = (object) array(
            'version'      => $version,
            'download_url' => $release->zipball_url,
            'published_at' => $release->published_at,
            'body'         => isset( $release->body ) ? $release->body : '',
            'html_url'     => $release->html_url,
        );

        // キャッシュに保存
        set_transient( self::CACHE_KEY, $release_info, self::CACHE_EXPIRATION );

        return $release_info;
    }

    /**
     * WordPressの更新チェックに自プラグインの更新情報を注入
     *
     * @param object $transient 更新情報のトランジェント
     * @return object 更新後のトランジェント
     */
    public function check_for_plugin_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release_info = $this->get_github_release_info();

        if ( $release_info === false ) {
            return $transient;
        }

        // 現在のバージョンと比較
        $current_version = self::VERSION;
        $new_version = $release_info->version;

        if ( version_compare( $new_version, $current_version, '>' ) ) {
            $plugin_slug = plugin_basename( __FILE__ );

            $transient->response[ $plugin_slug ] = (object) array(
                'slug'        => dirname( $plugin_slug ),
                'plugin'      => $plugin_slug,
                'new_version' => $new_version,
                'url'         => 'https://github.com/' . self::GITHUB_USERNAME . '/' . self::GITHUB_REPO,
                'package'     => $release_info->download_url,
                'icons'       => array(),
                'banners'     => array(),
                'tested'      => '',
                'requires_php' => '7.4',
            );
        }

        return $transient;
    }

    /**
     * プラグイン詳細情報を提供（「詳細を表示」リンク用）
     *
     * @param false|object|array $result
     * @param string $action
     * @param object $args
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        $plugin_slug = dirname( plugin_basename( __FILE__ ) );

        if ( ! isset( $args->slug ) || $args->slug !== $plugin_slug ) {
            return $result;
        }

        $release_info = $this->get_github_release_info();

        if ( $release_info === false ) {
            return $result;
        }

        // Markdown -> HTML変換
        $changelog = $this->parse_markdown( $release_info->body );

        return (object) array(
            'name'              => 'Intelligent Checker',
            'slug'              => $plugin_slug,
            'version'           => $release_info->version,
            'author'            => '<a href="https://github.com/' . self::GITHUB_USERNAME . '">' . self::GITHUB_USERNAME . '</a>',
            'author_profile'    => 'https://github.com/' . self::GITHUB_USERNAME,
            'homepage'          => 'https://github.com/' . self::GITHUB_USERNAME . '/' . self::GITHUB_REPO,
            'short_description' => '投稿編集画面で画像ALT属性チェック、URL直書きアラート、タイトルセルフチェックを行う統合プラグイン',
            'sections'          => array(
                'description' => '投稿編集画面で画像ALT属性チェック、URL直書きアラート、タイトルセルフチェックを行う統合プラグイン',
                'changelog'   => $changelog,
            ),
            'download_link'     => $release_info->download_url,
            'last_updated'      => $release_info->published_at,
            'requires'          => '5.0',
            'tested'            => '6.7',
            'requires_php'      => '7.4',
        );
    }

    /**
     * GitHub MarkdownをHTMLに簡易変換
     *
     * @param string $markdown
     * @return string HTML
     */
    private function parse_markdown( $markdown ) {
        if ( empty( $markdown ) ) {
            return '<p>変更履歴の情報はありません。</p>';
        }

        // 基本的な変換
        $html = esc_html( $markdown );

        // 見出し
        $html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
        $html = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );
        $html = preg_replace( '/^# (.+)$/m', '<h2>$1</h2>', $html );

        // リスト項目
        $html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
        $html = preg_replace( '/^\* (.+)$/m', '<li>$1</li>', $html );

        // 連続する<li>を<ul>で囲む
        $html = preg_replace( '/(<li>.*<\/li>\n?)+/s', '<ul>$0</ul>', $html );

        // 改行を<br>に
        $html = nl2br( $html );

        return $html;
    }

    /**
     * 更新インストール後の処理
     * GitHubのzipballはディレクトリ名が異なるため修正
     *
     * @param bool $response
     * @param array $hook_extra
     * @param array $result
     * @return array
     */
    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        // このプラグインの更新かどうかチェック
        $plugin_slug = dirname( plugin_basename( __FILE__ ) );

        if ( ! isset( $hook_extra['plugin'] ) ) {
            return $result;
        }

        if ( dirname( $hook_extra['plugin'] ) !== $plugin_slug ) {
            return $result;
        }

        // 正しいディレクトリ名に変更
        $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;

        if ( $wp_filesystem->move( $result['destination'], $plugin_dir ) ) {
            $result['destination'] = $plugin_dir;
        }

        // プラグインを再有効化
        activate_plugin( $hook_extra['plugin'] );

        return $result;
    }

    /**
     * 作成者メタフィールドを登録
     */
    public function register_creator_meta() {
        register_post_meta( 'post', '_ic_creator', array(
            'show_in_rest'      => true,
            'single'            => true,
            'type'              => 'integer',
            'default'           => 0,
            'auth_callback'     => function() {
                return current_user_can( 'edit_posts' );
            },
            'sanitize_callback' => 'absint',
        ) );
    }

    /**
     * 新規投稿作成時に現在のユーザーを作成者として設定
     */
    public function set_default_creator( $post_id, $post, $update ) {
        // 更新時は何もしない
        if ( $update ) {
            return;
        }

        // 投稿タイプが post のみ
        if ( $post->post_type !== 'post' ) {
            return;
        }

        // 自動保存やリビジョンは無視
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // 既に作成者が設定されている場合は何もしない
        $existing = get_post_meta( $post_id, '_ic_creator', true );
        if ( ! empty( $existing ) ) {
            return;
        }

        // 現在のユーザーを作成者として設定
        $current_user_id = get_current_user_id();
        if ( $current_user_id ) {
            update_post_meta( $post_id, '_ic_creator', $current_user_id );
        }
    }

    /**
     * 作成者機能用のREST APIルートを登録
     */
    public function register_creator_rest_routes() {
        // ユーザー一覧取得エンドポイント（管理画面用）
        register_rest_route( 'intelligent-checker/v1', '/users', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_users_for_creator' ),
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        ) );
    }

    /**
     * 作成者選択用のユーザー一覧を取得
     */
    public function get_users_for_creator( $request ) {
        $users = get_users( array(
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => array( 'ID', 'display_name', 'user_login' ),
        ) );

        $result = array();
        foreach ( $users as $user ) {
            $result[] = array(
                'id'           => (int) $user->ID,
                'display_name' => $user->display_name,
                'user_login'   => $user->user_login,
            );
        }

        return rest_ensure_response( $result );
    }

    /**
     * 投稿一覧に作成者カラムを追加
     */
    public function add_creator_column( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            if ( $key === 'author' ) {
                $new_columns['ic_creator'] = '作成者';
            }
        }
        return $new_columns;
    }

    /**
     * 作成者カラムの内容を表示
     */
    public function render_creator_column( $column, $post_id ) {
        if ( $column !== 'ic_creator' ) {
            return;
        }

        $creator_id = get_post_meta( $post_id, '_ic_creator', true );
        if ( $creator_id ) {
            $user = get_user_by( 'ID', $creator_id );
            if ( $user ) {
                $filter_url = add_query_arg( 'ic_creator', $creator_id, admin_url( 'edit.php' ) );
                echo '<span data-creator-id="' . esc_attr( $creator_id ) . '"><a href="' . esc_url( $filter_url ) . '">' . esc_html( $user->display_name ) . '</a></span>';
            } else {
                echo '<span data-creator-id="0">—</span>';
            }
        } else {
            echo '<span data-creator-id="0">—</span>';
        }
    }

    /**
     * クイック編集に作成者フィールドを追加
     */
    public function add_creator_quick_edit( $column_name, $post_type ) {
        if ( $column_name !== 'ic_creator' || $post_type !== 'post' ) {
            return;
        }

        $users = get_users( array(
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ) );
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label class="inline-edit-group">
                    <span class="title">作成者</span>
                    <select name="ic_creator">
                        <option value="0">— 選択してください —</option>
                        <?php foreach ( $users as $user ) : ?>
                            <option value="<?php echo esc_attr( $user->ID ); ?>">
                                <?php echo esc_html( $user->display_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * クイック編集の保存処理
     */
    public function save_creator_quick_edit( $post_id, $post ) {
        // 自動保存は無視
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // 権限チェック
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // 投稿タイプチェック
        if ( $post->post_type !== 'post' ) {
            return;
        }

        // クイック編集からの保存かチェック
        if ( ! isset( $_POST['ic_creator'] ) ) {
            return;
        }

        $creator_id = absint( $_POST['ic_creator'] );
        update_post_meta( $post_id, '_ic_creator', $creator_id );
    }

    /**
     * 管理画面用スクリプトを読み込む
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( $hook !== 'edit.php' ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'post' ) {
            return;
        }

        wp_add_inline_script( 'inline-edit-post', $this->get_quick_edit_script() );
    }

    /**
     * クイック編集用のJavaScript
     */
    private function get_quick_edit_script() {
        return "
        (function($) {
            // クイック編集
            var originalInlineEdit = inlineEditPost.edit;
            inlineEditPost.edit = function(id) {
                originalInlineEdit.apply(this, arguments);

                var postId = 0;
                if (typeof(id) === 'object') {
                    postId = parseInt(this.getId(id));
                }

                if (postId > 0) {
                    var row = $('#post-' + postId);
                    var creatorId = row.find('.column-ic_creator span').data('creator-id') || 0;
                    var editRow = $('#edit-' + postId);
                    editRow.find('select[name=\"ic_creator\"]').val(creatorId);
                }
            };

            // 一括編集
            $(document).on('click', '#bulk_edit', function() {
                var bulkRow = $('#bulk-edit');
                var creatorVal = bulkRow.find('select[name=\"ic_creator_bulk\"]').val();

                if (creatorVal === '-1') {
                    return;
                }

                var postIds = [];
                bulkRow.find('#bulk-titles-list .button-link').each(function() {
                    postIds.push($(this).attr('id').replace('_', ''));
                });

                if (postIds.length === 0) {
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_bulk_creator',
                        post_ids: postIds,
                        ic_creator_bulk: creatorVal,
                        _inline_edit: $('#_inline_edit').val()
                    }
                });
            });
        })(jQuery);
        ";
    }

    /**
     * 投稿一覧に作成者フィルタードロップダウンを追加
     */
    public function add_creator_filter_dropdown( $post_type ) {
        if ( $post_type !== 'post' ) {
            return;
        }

        $users = get_users( array(
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ) );

        $selected = isset( $_GET['ic_creator'] ) ? absint( $_GET['ic_creator'] ) : 0;
        ?>
        <select name="ic_creator">
            <option value="">作成者で絞り込み</option>
            <?php foreach ( $users as $user ) : ?>
                <option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $selected, $user->ID ); ?>>
                    <?php echo esc_html( $user->display_name ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * 作成者でフィルタリング
     */
    public function filter_posts_by_creator( $query ) {
        global $pagenow;

        if ( ! is_admin() || $pagenow !== 'edit.php' || ! $query->is_main_query() ) {
            return;
        }

        if ( $query->get( 'post_type' ) !== 'post' ) {
            return;
        }

        if ( empty( $_GET['ic_creator'] ) ) {
            return;
        }

        $creator_id = absint( $_GET['ic_creator'] );
        if ( $creator_id > 0 ) {
            $query->set( 'meta_key', '_ic_creator' );
            $query->set( 'meta_value', $creator_id );
        }
    }

    /**
     * 一括編集に作成者フィールドを追加
     */
    public function add_creator_bulk_edit( $column_name, $post_type ) {
        if ( $column_name !== 'ic_creator' || $post_type !== 'post' ) {
            return;
        }

        $users = get_users( array(
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ) );
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label class="inline-edit-group">
                    <span class="title">作成者</span>
                    <select name="ic_creator_bulk">
                        <option value="-1">— 変更しない —</option>
                        <option value="0">— 未設定にする —</option>
                        <?php foreach ( $users as $user ) : ?>
                            <option value="<?php echo esc_attr( $user->ID ); ?>">
                                <?php echo esc_html( $user->display_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * 一括編集の保存処理（Ajax）
     */
    public function save_bulk_creator() {
        check_ajax_referer( 'inlineeditnonce', '_inline_edit' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( -1 );
        }

        $post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', $_POST['post_ids'] ) : array();
        $creator_id = isset( $_POST['ic_creator_bulk'] ) ? intval( $_POST['ic_creator_bulk'] ) : -1;

        if ( empty( $post_ids ) || $creator_id === -1 ) {
            wp_die( -1 );
        }

        foreach ( $post_ids as $post_id ) {
            if ( current_user_can( 'edit_post', $post_id ) ) {
                update_post_meta( $post_id, '_ic_creator', $creator_id );
            }
        }

        wp_die( 1 );
    }
}

// プラグインを初期化
Intelligent_Checker::get_instance();

/**
 * プラグイン有効化時にデフォルト設定を保存
 */
function intelligent_checker_activate() {
    if ( ! get_option( 'intelligent_checker_settings' ) ) {
        $plugin = Intelligent_Checker::get_instance();
        update_option( 'intelligent_checker_settings', $plugin->get_defaults() );
    }
}
register_activation_hook( __FILE__, 'intelligent_checker_activate' );
