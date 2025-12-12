<?php
/**
 * IC_Creator - 作成者機能クラス
 *
 * @package Intelligent_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 作成者機能管理クラス
 */
class IC_Creator {

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
