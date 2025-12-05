<?php
/**
 * Plugin Name: Intelligent Checker
 * Plugin URI: https://example.com/intelligent-checker
 * Description: 投稿編集画面で画像ALT属性チェック、URL直書きアラート、タイトルセルフチェックを行う統合プラグイン
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
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

    const VERSION = '1.0.0';
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
