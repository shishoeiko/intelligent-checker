<?php
/**
 * IC_Post_List - 投稿一覧エラーカラムクラス
 *
 * @package Intelligent_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 投稿一覧エラーカラム管理クラス
 */
class IC_Post_List {

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
        // 投稿一覧エラーカラム
        add_filter( 'manage_posts_columns', array( $this, 'add_error_column' ), 20 );
        add_action( 'manage_posts_custom_column', array( $this, 'render_error_column' ), 10, 2 );
        add_filter( 'manage_edit-post_sortable_columns', array( $this, 'add_error_sortable_column' ) );
        add_action( 'pre_get_posts', array( $this, 'sort_by_error_count' ) );
        add_action( 'save_post', array( $this, 'update_error_count_meta' ), 20 );
        add_action( 'admin_init', array( $this, 'handle_recalculate_errors' ) );
    }

    /**
     * 投稿一覧にエラーカラムを追加
     */
    public function add_error_column( $columns ) {
        $settings = IC_Settings::get_instance()->get_settings();
        if ( ! $settings['post_list_error_column_enabled'] ) {
            return $columns;
        }

        $new_columns = array();
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            if ( $key === 'title' ) {
                $new_columns['ic_errors'] = 'エラー';
            }
        }
        return $new_columns;
    }

    /**
     * エラーカラムの内容を表示
     */
    public function render_error_column( $column, $post_id ) {
        if ( $column !== 'ic_errors' ) {
            return;
        }

        // キャッシュされたエラー数を取得
        $cached_total = get_post_meta( $post_id, '_ic_error_count', true );

        // メタデータがない場合は計算して保存
        if ( $cached_total === '' ) {
            $errors = IC_Error_Checker::get_instance()->get_post_errors( $post_id );
            $total = array_sum( $errors );
            update_post_meta( $post_id, '_ic_error_count', $total );
        } else {
            $total = (int) $cached_total;
        }

        if ( $total === 0 ) {
            echo '<span style="color: #999;">—</span>';
        } else {
            echo '<span style="color: #dc2626; font-weight: 600;">' . esc_html( $total ) . '</span>';
        }
    }

    /**
     * エラーカラムをソート可能にする
     */
    public function add_error_sortable_column( $columns ) {
        $settings = IC_Settings::get_instance()->get_settings();
        if ( ! $settings['post_list_error_column_enabled'] ) {
            return $columns;
        }

        $columns['ic_errors'] = 'ic_errors';
        return $columns;
    }

    /**
     * エラー数でソート
     */
    public function sort_by_error_count( $query ) {
        global $pagenow;

        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        if ( $pagenow !== 'edit.php' ) {
            return;
        }

        if ( $query->get( 'orderby' ) !== 'ic_errors' ) {
            return;
        }

        // メタデータがない投稿も含めてソートするためにmeta_queryを使用
        $query->set( 'meta_query', array(
            'relation' => 'OR',
            'error_count_exists' => array(
                'key'     => '_ic_error_count',
                'compare' => 'EXISTS',
                'type'    => 'NUMERIC',
            ),
            'error_count_not_exists' => array(
                'key'     => '_ic_error_count',
                'compare' => 'NOT EXISTS',
            ),
        ) );
        $query->set( 'orderby', 'error_count_exists' );
    }

    /**
     * 投稿保存時にエラー数をメタデータに保存
     */
    public function update_error_count_meta( $post_id ) {
        // 自動保存やリビジョンは無視
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // 投稿タイプが post のみ
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'post' ) {
            return;
        }

        $errors = IC_Error_Checker::get_instance()->get_post_errors( $post_id );
        $total = array_sum( $errors );

        update_post_meta( $post_id, '_ic_error_count', $total );
    }

    /**
     * エラー数の一括再計算を処理
     */
    public function handle_recalculate_errors() {
        if ( ! isset( $_GET['ic_recalculate_errors'] ) || $_GET['ic_recalculate_errors'] !== '1' ) {
            return;
        }

        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'ic_recalculate_errors' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $posts = get_posts( array(
            'post_type'      => 'post',
            'posts_per_page' => -1,
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'fields'         => 'ids',
        ) );

        $count = 0;
        foreach ( $posts as $post_id ) {
            $errors = IC_Error_Checker::get_instance()->get_post_errors( $post_id );
            $total = array_sum( $errors );
            update_post_meta( $post_id, '_ic_error_count', $total );
            $count++;
        }

        // リダイレクト
        wp_redirect( add_query_arg( array(
            'page' => 'intelligent-checker-settings',
            'ic_recalculated' => $count,
        ), admin_url( 'options-general.php' ) ) );
        exit;
    }
}
