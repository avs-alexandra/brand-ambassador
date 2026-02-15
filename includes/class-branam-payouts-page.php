<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Запрет прямого доступа

class Branam_Payouts_Page {

    /**
     * Добавляет страницу выплат
     */
    public function add_payouts_page() {
        add_submenu_page(
            'woocommerce-marketing',
            esc_html__( 'Выплаты по купонам', 'brand-ambassador' ),
            esc_html__( 'Выплаты по купонам', 'brand-ambassador' ),
            'manage_woocommerce',
            'branam-coupon-payouts',
            [ $this, 'render_payouts_page' ]
        );
    }

    /**
     * Минимальный год, в котором есть заказы.
     * Оптимизировано: wc_order_stats (один запрос).
     */
    private function get_minimum_order_year(): int {
        global $wpdb;
        $table_order_stats = $wpdb->prefix . 'wc_order_stats';

        $exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_order_stats ) ) === $table_order_stats );
        if ( ! $exists ) {
            return (int) gmdate( 'Y' );
        }

        $min_date = $wpdb->get_var( "SELECT MIN(date_created_gmt) FROM {$table_order_stats}" );
        if ( empty( $min_date ) ) {
            return (int) gmdate( 'Y' );
        }

        $ts = strtotime( (string) $min_date );
        if ( ! $ts ) {
            return (int) gmdate( 'Y' );
        }

        return (int) gmdate( 'Y', $ts );
    }

    /**
     * Рендеринг страницы выплат (оптимизировано для больших баз):
     * - lookup-таблицы вместо перебора заказов
     * - пагинация
     * - минимум вызовов wc_get_order / WC_Coupon
     */
    public function render_payouts_page() {

        $has_access = apply_filters( 'branam_coupon_payouts_page_access', current_user_can( 'manage_woocommerce' ) );
        if ( ! $has_access ) {
            wp_die( esc_html__( 'У вас недостаточно прав для доступа к этой странице.', 'brand-ambassador' ) );
        }

        // Редирект, если параметры m и y отсутствуют
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['m'] ) || ! isset( $_GET['y'] ) ) {
            wp_safe_redirect(
                add_query_arg(
                    [ 'm' => 0, 'y' => gmdate( 'Y' ) ],
                    admin_url( 'admin.php?page=branam-coupon-payouts' )
                )
            );
            exit;
        }

        // Transients для UI
        $calculation_result = get_transient( 'branam_coupon_payout_calculation_result' );
        delete_transient( 'branam_coupon_payout_calculation_result' );

        $selected_orders = get_transient( 'branam_coupon_payout_selected_orders' );
        delete_transient( 'branam_coupon_payout_selected_orders' );

        $show_action_buttons = get_transient( 'branam_show_action_buttons' );
        if ( $show_action_buttons ) {
            delete_transient( 'branam_show_action_buttons' );
        }

        // Настройки ролей/выплат
        $blogger_role   = get_option( 'branam_blogger_role', 'customer' );
        $expert_role    = get_option( 'branam_expert_role', 'subscriber' );
        $blogger_reward = (int) get_option( 'branam_blogger_reward', 450 );
        $expert_reward  = (int) get_option( 'branam_expert_reward', 600 );

        // GET параметры
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $month = isset( $_GET['m'] ) ? absint( $_GET['m'] ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $year = isset( $_GET['y'] ) ? absint( $_GET['y'] ) : (int) gmdate( 'Y' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $user_filter = isset( $_GET['user'] ) ? sanitize_text_field( wp_unslash( $_GET['user'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $email_sort = isset( $_GET['email_sort'] ) ? sanitize_text_field( wp_unslash( $_GET['email_sort'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $level_filter = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';

        // Пагинация
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 50;
        $offset = ( $paged - 1 ) * $per_page;

        $min_year = $this->get_minimum_order_year();
        if ( $year < $min_year || $year > (int) gmdate( 'Y' ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Ошибка: Неверный год.', 'brand-ambassador' ) . '</p></div>';
            return;
        }

        global $wpdb;
        $table_coupon_lookup = $wpdb->prefix . 'wc_order_coupon_lookup';
        $table_order_stats   = $wpdb->prefix . 'wc_order_stats';

        $coupon_lookup_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_coupon_lookup ) ) === $table_coupon_lookup );
        $order_stats_exists   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_order_stats ) ) === $table_order_stats );

        if ( ! $coupon_lookup_exists || ! $order_stats_exists ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Таблицы WooCommerce (wc_order_stats / wc_order_coupon_lookup) не найдены. Проверьте, что включён HPOS и выполнена миграция.', 'brand-ambassador' ) . '</p></div>';
            return;
        }

        // Дата-диапазон (GMT)
        $start_gmt = sprintf( '%04d-01-01 00:00:00', $year );
        $end_gmt   = sprintf( '%04d-12-31 23:59:59', $year );

        if ( $month > 0 && $month <= 12 ) {
            $start_gmt = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
            $dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $start_gmt, new DateTimeZone( 'UTC' ) );
            if ( $dt ) {
                $dt->modify( 'last day of this month' );
                $end_gmt = $dt->format( 'Y-m-d 23:59:59' );
            } else {
                $end_gmt = sprintf( '%04d-%02d-31 23:59:59', $year, $month );
            }
        }

        $coupon_meta_key = '_branam_ambassador_user';

        // Подзапрос: купоны, у которых есть ambassador_user
        $coupon_ids_with_ambassador_sql = $wpdb->prepare(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             WHERE pm.meta_key = %s
               AND pm.meta_value <> ''",
            $coupon_meta_key
        );

        // Total rows for pagination
        $total_rows = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM (
                    SELECT l.order_id, l.coupon_id
                    FROM {$table_coupon_lookup} l
                    INNER JOIN {$table_order_stats} s ON s.order_id = l.order_id
                    WHERE s.status = %s
                      AND s.date_created_gmt BETWEEN %s AND %s
                      AND l.coupon_id IN ({$coupon_ids_with_ambassador_sql})
                    GROUP BY l.order_id, l.coupon_id
                 ) x",
                'wc-completed',
                $start_gmt,
                $end_gmt
            )
        );

        // Page rows
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.order_id, l.coupon_id, s.date_created_gmt
                 FROM {$table_coupon_lookup} l
                 INNER JOIN {$table_order_stats} s ON s.order_id = l.order_id
                 WHERE s.status = %s
                   AND s.date_created_gmt BETWEEN %s AND %s
                   AND l.coupon_id IN ({$coupon_ids_with_ambassador_sql})
                 GROUP BY l.order_id, l.coupon_id
                 ORDER BY s.date_created_gmt DESC
                 LIMIT %d OFFSET %d",
                'wc-completed',
                $start_gmt,
                $end_gmt,
                $per_page,
                $offset
            )
        );

        // Collect ids
        $coupon_ids = [];
        $order_ids  = [];
        foreach ( (array) $rows as $r ) {
            $coupon_ids[] = (int) $r->coupon_id;
            $order_ids[]  = (int) $r->order_id;
        }
        $coupon_ids = array_values( array_unique( $coupon_ids ) );
        $order_ids  = array_values( array_unique( $order_ids ) );

        // coupon_id -> coupon_code (post_title)
        $coupon_code_by_id = [];
        if ( ! empty( $coupon_ids ) ) {
            $in = implode( ',', array_fill( 0, count( $coupon_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $coupon_posts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_title
                     FROM {$wpdb->posts}
                     WHERE post_type = 'shop_coupon'
                       AND ID IN ($in)",
                    $coupon_ids
                )
            );
            foreach ( (array) $coupon_posts as $cp ) {
                $coupon_code_by_id[ (int) $cp->ID ] = (string) $cp->post_title;
            }
        }

        // coupon_id -> ambassador_user_id
        $ambassador_by_coupon_id = [];
        if ( ! empty( $coupon_ids ) ) {
            $in = implode( ',', array_fill( 0, count( $coupon_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $coupon_meta = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_value
                     FROM {$wpdb->postmeta}
                     WHERE meta_key = %s
                       AND post_id IN ($in)",
                    array_merge( [ $coupon_meta_key ], $coupon_ids )
                )
            );
            foreach ( (array) $coupon_meta as $cm ) {
                $ambassador_by_coupon_id[ (int) $cm->post_id ] = (int) $cm->meta_value;
            }
        }

        // payout_status по заказам: на HPOS правильнее читать из order meta (wc_get_order()->get_meta()).
        // Но массово через SQL мы это не достанем без знания таблицы meta в конкретной версии WooCommerce.
        // Поэтому делаем компромисс: читаем meta из объектов заказов только для текущей страницы (<=50).
        $payout_status_by_order_id = [];
        foreach ( $order_ids as $oid ) {
            $order = wc_get_order( $oid );
            if ( ! $order ) {
                continue;
            }
            $payout_status_by_order_id[ (int) $oid ] = (string) $order->get_meta( '_branam_payout_status', true );
        }

        // Users cache in-memory
        $user_cache = [];

        $orders = [];
        foreach ( (array) $rows as $r ) {
            $order_id  = (int) $r->order_id;
            $coupon_id = (int) $r->coupon_id;

            $associated_user_id = $ambassador_by_coupon_id[ $coupon_id ] ?? 0;
            if ( ! $associated_user_id ) {
                continue;
            }

            if ( ! isset( $user_cache[ $associated_user_id ] ) ) {
                $user_cache[ $associated_user_id ] = get_userdata( $associated_user_id );
            }
            $user = $user_cache[ $associated_user_id ];
            if ( ! $user ) {
                continue;
            }

            if ( $user_filter ) {
                $needle = (string) $user_filter;
                $match =
                    ( strpos( $user->user_login, $needle ) !== false ) ||
                    ( strpos( $user->user_email, $needle ) !== false ) ||
                    ( strpos( $user->display_name, $needle ) !== false ) ||
                    ( (string) $user->ID === $needle );

                if ( ! $match ) {
                    continue;
                }
            }

            $role_label = 'Неизвестная роль';
            $reward = 0;

            if ( in_array( $expert_role, (array) $user->roles, true ) ) {
                $role_label = 'Эксперт';
                $reward = $expert_reward;
            } elseif ( in_array( $blogger_role, (array) $user->roles, true ) ) {
                $role_label = 'Блогер';
                $reward = $blogger_reward;
            }

            if ( $level_filter && $role_label !== $level_filter ) {
                continue;
            }

            $coupon_code = $coupon_code_by_id[ $coupon_id ] ?? '';
            if ( $coupon_code === '' ) {
                continue;
            }

            $payout_status_val = $payout_status_by_order_id[ $order_id ] ?? '';
            $paid = ( $payout_status_val === 'paid' );

            $orders[] = [
                'order_id' => $order_id,
                'date_gmt' => (string) $r->date_created_gmt,
                'coupon_id' => $coupon_id,
                'coupon_code' => $coupon_code,
                'user_email' => (string) $user->user_email,
                'user_display' => sprintf(
                    '<a href="%s" target="_blank">%s (%s)</a>',
                    esc_url( admin_url( 'user-edit.php?user_id=' . $associated_user_id ) ),
                    esc_html( $user->display_name ),
                    esc_html( $user->user_email )
                ),
                'coupon_edit_url' => esc_url( admin_url( 'post.php?post=' . $coupon_id . '&action=edit' ) ),
                'role' => $role_label,
                'reward' => $reward,
                'payout_status' => $paid,
            ];
        }

        if ( $email_sort === 'asc' ) {
            usort(
                $orders,
                static function ( $a, $b ) {
                    return strcmp( $a['user_email'], $b['user_email'] );
                }
            );
        }

        $total_pages = $total_rows > 0 ? (int) ceil( $total_rows / $per_page ) : 1;

        $build_admin_pagination = static function ( int $total_pages, int $current_page ): string {
            if ( $total_pages <= 1 ) {
                return '';
            }

            $out = '<div style="margin: 12px 0;">';
            for ( $p = 1; $p <= $total_pages; $p++ ) {
                $url = add_query_arg( 'paged', $p );
                $style = $p === $current_page ? 'font-weight:700; text-decoration:underline;' : '';
                $out .= '<a href="' . esc_url( $url ) . '" style="margin-right:8px;' . esc_attr( $style ) . '">' . (int) $p . '</a>';
            }
            $out .= '</div>';

            return $out;
        };

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Выплаты по купонам', 'brand-ambassador' ); ?></h1>

            <?php if ( isset( $_GET['message'] ) && $_GET['message'] === 'mixed_statuses' ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e( 'Выбраны строки с разными статусами выплат. Пожалуйста, измените выбор.', 'brand-ambassador' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( $calculation_result ) : ?>
                <div class="notice <?php echo isset( $calculation_result['error'] ) ? 'notice-error' : 'notice-success'; ?> is-dismissible">
                    <p><?php echo isset( $calculation_result['error'] ) ? esc_html( $calculation_result['error'] ) : wp_kses_post( $calculation_result['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <form method="get" class="filter-form">
                <input type="hidden" name="page" value="branam-coupon-payouts">

                <label for="month"><?php esc_html_e( 'Месяц:', 'brand-ambassador' ); ?></label>
                <select id="month" name="m">
                    <option value="0"><?php esc_html_e( 'Все месяцы', 'brand-ambassador' ); ?></option>
                    <?php for ( $m = 1; $m <= 12; $m++ ) : ?>
                        <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $month, $m ); ?>>
                            <?php echo esc_html( date_i18n( 'F', mktime( 0, 0, 0, $m, 10 ) ) ); ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label for="year"><?php esc_html_e( 'Год:', 'brand-ambassador' ); ?></label>
                <select id="year" name="y">
                    <?php for ( $y = (int) $min_year; $y <= (int) gmdate( 'Y' ); $y++ ) : ?>
                        <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $year, $y ); ?>>
                            <?php echo esc_html( $y ); ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label for="user"><?php esc_html_e( 'Пользователь:', 'brand-ambassador' ); ?></label>
                <input type="text" id="user" name="user" value="<?php echo esc_attr( $user_filter ); ?>" placeholder="<?php esc_html_e( 'Имя, Email или ID', 'brand-ambassador' ); ?>" />

                <label for="email_sort"><?php esc_html_e( 'Сортировка email:', 'brand-ambassador' ); ?></label>
                <select id="email_sort" name="email_sort">
                    <option value=""><?php esc_html_e( 'Не сортировать', 'brand-ambassador' ); ?></option>
                    <option value="asc" <?php selected( $email_sort, 'asc' ); ?>><?php esc_html_e( 'A-Z', 'brand-ambassador' ); ?></option>
                </select>

                <label for="level"><?php esc_html_e( 'Уровень:', 'brand-ambassador' ); ?></label>
                <select id="level" name="level">
                    <option value=""><?php esc_html_e( 'Все уровни', 'brand-ambassador' ); ?></option>
                    <option value="Эксперт" <?php selected( $level_filter, 'Эксперт' ); ?>><?php esc_html_e( 'Эксперт', 'brand-ambassador' ); ?></option>
                    <option value="Блогер" <?php selected( $level_filter, 'Блогер' ); ?>><?php esc_html_e( 'Блогер', 'brand-ambassador' ); ?></option>
                </select>

                <button type="submit" class="button"><?php esc_html_e( 'Применить', 'brand-ambassador' ); ?></button>
            </form>

            <?php echo wp_kses_post( $build_admin_pagination( $total_pages, $paged ) ); ?>

            <?php if ( empty( $orders ) ) : ?>
                <p style="margin-top: 20px; font-size: 16px; color: #555;">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: месяц, 2: год */
                            __( 'Нет заказов за %1$s %2$d.', 'brand-ambassador' ),
                            $month > 0 ? date_i18n( 'F', mktime( 0, 0, 0, $month, 10 ) ) : esc_html__( 'все месяцы', 'brand-ambassador' ),
                            $year
                        )
                    );
                    ?>
                </p>
            <?php else : ?>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="branam_save_payout_status">
                    <input type="hidden" name="filters[m]" value="<?php echo esc_attr( $month ); ?>">
                    <input type="hidden" name="filters[y]" value="<?php echo esc_attr( $year ); ?>">
                    <input type="hidden" name="filters[user]" value="<?php echo esc_attr( $user_filter ); ?>">
                    <input type="hidden" name="filters[email_sort]" value="<?php echo esc_attr( $email_sort ); ?>">
                    <input type="hidden" name="filters[level]" value="<?php echo esc_attr( $level_filter ); ?>">
                    <?php wp_nonce_field( 'branam_save_payout_status', 'branam_payout_status_nonce' ); ?>

                    <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all"></th>
                                <th><?php esc_html_e( 'Номер заказа', 'brand-ambassador' ); ?></th>
                                <th><?php esc_html_e( 'Дата заказа', 'brand-ambassador' ); ?></th>
                                <th><?php esc_html_e( 'Промокод', 'brand-ambassador' ); ?></th>
                                <th><?php esc_html_e( 'Амбассадор', 'brand-ambassador' ); ?></th>
                                <th><?php esc_html_e( 'Уровень', 'brand-ambassador' ); ?></th>
                                <th><?php esc_html_e( 'Размер выплаты', 'brand-ambassador' ); ?></th>
                                <th><?php esc_html_e( 'Статус выплаты', 'brand-ambassador' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $orders as $order ) : ?>
                                <tr style="background-color: <?php echo $order['payout_status'] ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $order['payout_status'] ? '#155724' : '#721c24'; ?>;">
                                    <td>
                                        <input type="checkbox" class="row-checkbox" name="payout_status[<?php echo esc_attr( $order['order_id'] ); ?>]" value="1" <?php echo ( is_array( $selected_orders ) && isset( $selected_orders[ $order['order_id'] ] ) ) ? 'checked' : ''; ?>>
                                    </td>
                                    <td><?php echo esc_html( $order['order_id'] ); ?></td>
                                    <td><?php echo esc_html( $order['date_gmt'] ? date_i18n( get_option( 'date_format' ), strtotime( $order['date_gmt'] ) ) : '' ); ?></td>
                                    <td><a href="<?php echo esc_url( $order['coupon_edit_url'] ); ?>" target="_blank"><?php echo esc_html( $order['coupon_code'] ); ?></a></td>
                                    <td><?php echo wp_kses_post( $order['user_display'] ); ?></td>
                                    <td><?php echo esc_html( $order['role'] ); ?></td>
                                    <td><?php echo esc_html( $order['reward'] ); ?> руб.</td>
                                    <td><?php echo $order['payout_status'] ? esc_html__( 'Выплачена', 'brand-ambassador' ) : esc_html__( 'Не выплатили', 'brand-ambassador' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <button type="submit" name="action_type" value="calculate_sum" class="button button-secondary" style="background-color: #ffc107; border-color: #ffc107; color: #000;">
                        <?php esc_html_e( 'Рассчитать выплату', 'brand-ambassador' ); ?>
                    </button>

                    <?php if ( $show_action_buttons ) : ?>
                        <button type="submit" name="action_type" value="mark_paid" class="button button-primary" style="background-color: #28a745; border-color: #28a745;">
                            <?php esc_html_e( 'Рассчитать Амбассадора', 'brand-ambassador' ); ?>
                        </button>
                        <button type="submit" name="action_type" value="mark_unpaid" class="button button-secondary" style="background-color: #dc3545; border-color: #dc3545; color: #fff;">
                            <?php esc_html_e( 'Отменить выплату', 'brand-ambassador' ); ?>
                        </button>
                        <button type="button" class="button button-secondary" style="background-color: #6c757d; border-color: #6c757d; color: #fff;" onclick="window.location.reload();" id="cancel-selection">
                            <?php esc_html_e( 'Отменить выбор', 'brand-ambassador' ); ?>
                        </button>
                    <?php endif; ?>
                </form>

            <?php endif; ?>

            <?php echo wp_kses_post( $build_admin_pagination( $total_pages, $paged ) ); ?>

        </div>
        <?php
    }

    /**
     * Подключение JS для чекбоксов на странице выплат
     */
    public function enqueue_payouts_page_scripts( $hook ) {
        $page_hooks = [
            'woocommerce-marketing_page_branam-coupon-payouts',
            '%d0%bc%d0%b0%d1%80%d0%ba%d0%b5%d1%82%d0%b8%d0%bd%d0%b3_page_branam-coupon-payouts',
        ];
        if ( ! in_array( $hook, $page_hooks, true ) ) {
            return;
        }

        wp_enqueue_script(
            'branam-coupon-payouts-js',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/branam-coupon-payouts.js',
            [],
            '1.0.1',
            true
        );
    }

    /**
     * Регистрируем хуки для enqueue скриптов
     */
    public function register_hooks() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_payouts_page_scripts' ] );
    }
}
