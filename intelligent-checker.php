<?php
/**
 * Plugin Name: Intelligent Checker
 * Description: 投稿編集画面で画像ALT属性チェック、URL直書きアラート、タイトルセルフチェックを行う統合プラグイン
 * Version: 1.13.1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: intelligent-checker
 */

// 直接アクセス禁止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 定数定義
define( 'IC_VERSION', '1.13.1' );
define( 'IC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// クラスファイルの読み込み
require_once IC_PLUGIN_DIR . 'includes/class-settings.php';
require_once IC_PLUGIN_DIR . 'includes/class-github-updater.php';
require_once IC_PLUGIN_DIR . 'includes/class-creator.php';
require_once IC_PLUGIN_DIR . 'includes/class-error-checker.php';
require_once IC_PLUGIN_DIR . 'includes/class-post-list.php';

/**
 * Intelligent Checker メインクラス
 */
class Intelligent_Checker {

    const VERSION = '1.13.1';

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
        // 各クラスを初期化
        IC_Settings::get_instance();
        IC_GitHub_Updater::get_instance();
        IC_Creator::get_instance();
        IC_Error_Checker::get_instance();
        IC_Post_List::get_instance();

        // エディター用アセット
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
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

        $settings = IC_Settings::get_instance()->get_settings();

        // メインスクリプト
        wp_enqueue_script(
            'intelligent-checker-editor',
            IC_PLUGIN_URL . 'js/editor.js',
            array( 'wp-plugins', 'wp-data', 'wp-element', 'wp-components', 'wp-edit-post', 'wp-blocks', 'wp-dom-ready', 'wp-compose' ),
            self::VERSION,
            true
        );

        // メインスタイル
        wp_enqueue_style(
            'intelligent-checker-editor',
            IC_PLUGIN_URL . 'css/editor.css',
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
            'duplicateKeywords'           => IC_Settings::get_instance()->text_to_array( $settings['duplicate_keywords'] ),
            'featuredImageCheckerEnabled' => (bool) $settings['featured_image_checker_enabled'],
            'forbiddenKeywordEnabled'        => (bool) $settings['forbidden_keyword_enabled'],
            'forbiddenKeywords'              => IC_Settings::get_instance()->text_to_array( $settings['forbidden_keywords'] ),
            'forbiddenKeywordsHeading'       => IC_Settings::get_instance()->text_to_array( $settings['forbidden_keywords_heading'] ),
            'cautionKeywordEnabled'          => (bool) $settings['caution_keyword_enabled'],
            'cautionKeywords'                => IC_Settings::get_instance()->text_to_array( $settings['caution_keywords'] ),
            'cautionKeywordsHeading'         => IC_Settings::get_instance()->text_to_array( $settings['caution_keywords_heading'] ),
            'bannedPatternsEnabled'          => (bool) $settings['banned_patterns_enabled'],
            'bannedPatterns'                 => IC_Settings::get_instance()->text_to_array( $settings['banned_patterns'] ),
            'h2H3DirectEnabled'              => (bool) $settings['h2_h3_direct_enabled'],
            'duplicateHeadingEnabled'        => (bool) $settings['duplicate_heading_enabled'],
            'h2RequiredKeywordEnabled'       => (bool) $settings['h2_required_keyword_enabled'],
            'h2RequiredKeywords'             => IC_Settings::get_instance()->text_to_array( $settings['h2_required_keywords'] ),
            'longParagraphThreshold'      => (int) $settings['long_paragraph_threshold'],
            'longParagraphExcludeClasses' => IC_Settings::get_instance()->text_to_array( $settings['long_paragraph_exclude_classes'] ),
            // タイトルチェック設定
            'keywords'  => array(
                'required'    => IC_Settings::get_instance()->text_to_array( $settings['required_kw'] ),
                'recommended' => IC_Settings::get_instance()->text_to_array( $settings['recommended_kw'] ),
            ),
            'charLimit' => array(
                'min' => (int) $settings['char_min'],
                'max' => (int) $settings['char_max'],
            ),
            'checklistItems' => IC_Settings::get_instance()->text_to_array( $settings['checklist'] ),
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
                'nakedUrlTitle'    => __( 'URLが直書きでリンクされている箇所があります', 'intelligent-checker' ),
                'nakedUrlDesc'     => __( '意図せずURLになっていないかを確認してください', 'intelligent-checker' ),
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
                // Heading Forbidden Keyword Checker
                'headingForbiddenKeywordTitle' => __( 'H2見出しに禁止キーワードが含まれています', 'intelligent-checker' ),
                'headingForbiddenKeywordDesc'  => __( '以下の見出しに禁止キーワードが含まれています。別の表現に変更してください。', 'intelligent-checker' ),
                'headingForbiddenKeywordCheck' => __( '見出しを確認', 'intelligent-checker' ),
                // Heading Caution Keyword Checker
                'headingCautionKeywordTitle' => __( 'H2見出しに要注意キーワードが含まれています', 'intelligent-checker' ),
                'headingCautionKeywordDesc'  => __( '以下の見出しに要注意キーワードが含まれています。問題がないか確認してください。', 'intelligent-checker' ),
                'headingCautionKeywordCheck' => __( '見出しを確認', 'intelligent-checker' ),
                // Banned Patterns Checker
                'bannedPatternsTitle' => __( '投稿内に禁止文字・文言が含まれています', 'intelligent-checker' ),
                'bannedPatternsDesc'  => __( '以下の禁止文字・文言が検出されました。削除または修正してください。', 'intelligent-checker' ),
                'bannedPatternsCheck' => __( '該当箇所を確認', 'intelligent-checker' ),
                // H2 Direct H3 Checker
                'h2H3DirectTitle' => __( 'H2見出しの直下にH3見出しが続いています', 'intelligent-checker' ),
                'h2H3DirectDesc'  => __( 'H2見出しとH3見出しの間に本文（段落など）を入れることを検討してください。', 'intelligent-checker' ),
                'h2H3DirectCheck' => __( '該当箇所を確認', 'intelligent-checker' ),
                // Duplicate Heading Checker
                'duplicateHeadingTitle' => __( '同じ文言の見出しが複数あります', 'intelligent-checker' ),
                'duplicateHeadingDesc'  => __( '見出しの文言が重複しています。異なる表現に変更することを検討してください。', 'intelligent-checker' ),
                'duplicateHeadingCheck' => __( '該当箇所を確認', 'intelligent-checker' ),
                // H2 Required Keyword Checker
                'h2RequiredKeywordTitle' => __( 'タイトルのキーワードがH2見出しに含まれていません', 'intelligent-checker' ),
                'h2RequiredKeywordDesc'  => __( 'タイトルに含まれているキーワードはH2見出しにも入れることを検討してください。', 'intelligent-checker' ),
                'h2RequiredKeywordList'  => __( '不足キーワード', 'intelligent-checker' ),
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
    // クラスファイルを読み込む
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-settings.php';

    if ( ! get_option( 'intelligent_checker_settings' ) ) {
        $settings = IC_Settings::get_instance();
        update_option( 'intelligent_checker_settings', $settings->get_defaults() );
    }
}
register_activation_hook( __FILE__, 'intelligent_checker_activate' );
