<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Не шорткод. Добавляем возможность купону только для первой покупки. Если первый заказ удалён/отменён/возвращён, то купон на первый новый заказ применится
 */
add_filter( 'woocommerce_coupon_is_valid', 'branam_coupon_only_first_order_is_valid', 10, 3 );
function branam_coupon_only_first_order_is_valid( $is_valid, $coupon, $discount ) {
    if ( ! $is_valid ) {
        return $is_valid;
    }

    if ( ! $coupon instanceof WC_Coupon ) {
        return $is_valid;
    }

    if ( get_post_meta( $coupon->get_id(), 'branam_only_first_order', true ) !== 'yes' ) {
        return $is_valid;
    }

    if ( ! is_user_logged_in() ) {
        throw new Exception( __( 'Купон действует только для авторизованных пользователей (первый заказ).', 'brand-ambassador' ) );
    }

    $user_id = get_current_user_id();
    $existing_orders = wc_get_orders(
        [
            'customer_id' => $user_id,
            'limit'       => 1,
            'return'      => 'ids',
            // Ищем любой заказ, кроме отменённого/failed
            'status'      => array_diff(
                array_keys( wc_get_order_statuses() ),
                [ 'wc-cancelled', 'wc-failed', 'wc-refunded' ]
            ),
        ]
    );
    if ( ! empty( $existing_orders ) ) {
        throw new Exception( __( 'Купон действует только на первый заказ.', 'brand-ambassador' ) );
    }
    return $is_valid;
}

/**
 * Добавляем галочку "Только для первого заказа" в настройки купона.
 */
add_action( 'woocommerce_coupon_options', 'branam_add_coupon_option_first_order_checkbox' );
function branam_add_coupon_option_first_order_checkbox() {
    wp_nonce_field( 'branam_save_coupon_option_first_order', 'branam_save_coupon_option_first_order_nonce' );

    woocommerce_wp_checkbox(
        [
            'id'          => 'branam_only_first_order',
            'label'       => __( 'Только для первого заказа', 'brand-ambassador' ),
            'description' => __( 'Применять купон только к первому заказу пользователя.', 'brand-ambassador' ),
        ]
    );
}

/**
 * Сохраняем значение галочки "Только для первого заказа".
 */
add_action( 'woocommerce_coupon_options_save', 'branam_save_coupon_option_first_order_checkbox' );
function branam_save_coupon_option_first_order_checkbox( $post_id ) {
    if (
        ! isset( $_POST['branam_save_coupon_option_first_order_nonce'] ) ||
        ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['branam_save_coupon_option_first_order_nonce'] ) ),
            'branam_save_coupon_option_first_order'
        )
    ) {
        return;
    }

    $only_first_order = isset( $_POST['branam_only_first_order'] ) ? 'yes' : 'no';
    update_post_meta( $post_id, 'branam_only_first_order', $only_first_order );
}

/**
 * Шорткод [branam_user_coupon_name] — наименование купона.
 */
function branam_get_user_coupon_name() {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return esc_html__( 'Пользователь не авторизован.', 'brand-ambassador' );
    }

    $coupon_id = get_user_meta( $user_id, '_branam_user_coupon', true );
    if ( ! $coupon_id ) {
        return esc_html__( 'Купон не найден.', 'brand-ambassador' );
    }

    $coupon = get_post( $coupon_id );
    if ( ! $coupon || $coupon->post_type !== 'shop_coupon' ) {
        return esc_html__( 'Купон не существует.', 'brand-ambassador' );
    }

    return esc_html( $coupon->post_title );
}
add_shortcode( 'branam_user_coupon_name', 'branam_get_user_coupon_name' );

/**
 * Вспомогательное: получить reward_per_order для текущего пользователя (или 0 если нет доступа).
 */
function branam_get_reward_per_order_for_user( WP_User $current_user ): int {
    $blogger_role   = get_option( 'branam_blogger_role', 'customer' );
    $expert_role    = get_option( 'branam_expert_role', 'subscriber' );
    $blogger_reward = (int) get_option( 'branam_blogger_reward', 450 );
    $expert_reward  = (int) get_option( 'branam_expert_reward', 600 );

    if ( in_array( $expert_role, (array) $current_user->roles, true ) ) {
        return $expert_reward;
    }

    if ( in_array( $blogger_role, (array) $current_user->roles, true ) ) {
        return $blogger_reward;
    }

    return 0;
}

/**
 * Вспомогательное: получить купон пользователя.
 * Возвращает массив [coupon_id, coupon_code_lower] либо [0, ''].
 */
function branam_get_user_coupon_data( int $user_id ): array {
    $coupon_id = (int) get_user_meta( $user_id, '_branam_user_coupon', true );
    if ( ! $coupon_id ) {
        return [ 0, '' ];
    }

    $coupon = get_post( $coupon_id );
    if ( ! $coupon || $coupon->post_type !== 'shop_coupon' ) {
        return [ 0, '' ];
    }

    return [ $coupon_id, strtolower( (string) $coupon->post_title ) ];
}

/**
 * Вспомогательное: границы месяца в UTC (WooCommerce в wc_order_stats.date_created_gmt).
 */
function branam_get_month_range_gmt( int $year, int $month ): array {
    $month = max( 1, min( 12, $month ) );

    $start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );

    $dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $start, new DateTimeZone( 'UTC' ) );
    if ( ! $dt ) {
        $end = sprintf( '%04d-%02d-31 23:59:59', $year, $month );
        return [ $start, $end ];
    }

    $dt->modify( 'first day of this month' );
    $start = $dt->format( 'Y-m-d 00:00:00' );

    $dt->modify( 'last day of this month' );
    $end = $dt->format( 'Y-m-d 23:59:59' );

    return [ $start, $end ];
}

/**
 * Вспомогательное: проверка существования lookup-таблиц (кеш в рамках одного запроса).
 */
function branam_hpos_lookup_tables_exist(): bool {
    static $result = null;
    if ( $result !== null ) {
        return (bool) $result;
    }

    global $wpdb;
    $table_coupon_lookup = $wpdb->prefix . 'wc_order_coupon_lookup';
    $table_order_stats   = $wpdb->prefix . 'wc_order_stats';

    $coupon_lookup_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_coupon_lookup ) ) === $table_coupon_lookup );
    $order_stats_exists   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_order_stats ) ) === $table_order_stats );

    $result = ( $coupon_lookup_exists && $order_stats_exists );
    return (bool) $result;
}

/**
 * Шорткод [branam_user_related_orders]
 * Оптимизация:
 * - per_page = 20
 * - кешируем результаты SQL (IDs + totals) на 30 минут по ключу (user/coupon/month/year/page)
 */
add_shortcode( 'branam_user_related_orders', function () {

    if ( ! is_user_logged_in() ) {
        return esc_html__( 'Вы должны быть авторизованы для просмотра ваших заказов.', 'brand-ambassador' );
    }

    $current_user     = wp_get_current_user();
    $reward_per_order = branam_get_reward_per_order_for_user( $current_user );
    if ( $reward_per_order <= 0 ) {
        return esc_html__( 'У вас нет доступа к статистике.', 'brand-ambassador' );
    }

    [ $coupon_id, $related_coupon_code ] = branam_get_user_coupon_data( (int) $current_user->ID );
    if ( ! $coupon_id ) {
        return esc_html__( 'У вас нет связанных купонов.', 'brand-ambassador' );
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $month = isset( $_GET['month'] ) ? absint( $_GET['month'] ) : (int) gmdate( 'm' );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $year  = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : (int) gmdate( 'Y' );

    if ( $month < 1 || $month > 12 ) {
        return esc_html__( 'Неверный месяц.', 'brand-ambassador' );
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $paged_completed = isset( $_GET['branam_page_completed'] ) ? max( 1, absint( $_GET['branam_page_completed'] ) ) : 1;

    $per_page         = 20;
    $offset_completed = ( $paged_completed - 1 ) * $per_page;

    if ( ! branam_hpos_lookup_tables_exist() ) {
        return '';
    }

    [ $start_gmt, $end_gmt ] = branam_get_month_range_gmt( $year, $month );

    // --- Кеш SQL (а не HTML), чтобы payout_status всегда был актуален ---
    $cache_key = 'branam_related_orders_sql_v2_'
        . (int) $current_user->ID . '_'
        . (int) $coupon_id . '_'
        . (int) $year . '_'
        . (int) $month . '_'
        . (int) $paged_completed . '_'
        . (int) $per_page;

    $cached = get_transient( $cache_key );

    if ( is_array( $cached )
        && isset(
            $cached['total_completed_with_coupon'],
            $cached['completed_order_ids']
        )
    ) {
        $total_completed_with_coupon = (int) $cached['total_completed_with_coupon'];
        $completed_order_ids         = (array) $cached['completed_order_ids'];
    } else {
        global $wpdb;
        $table_coupon_lookup = $wpdb->prefix . 'wc_order_coupon_lookup';
        $table_order_stats   = $wpdb->prefix . 'wc_order_stats';

        // Completed totals
        $total_completed_with_coupon = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT l.order_id)
                 FROM {$table_coupon_lookup} l
                 INNER JOIN {$table_order_stats} s ON s.order_id = l.order_id
                 WHERE l.coupon_id = %d
                   AND s.status = %s
                   AND s.date_created_gmt BETWEEN %s AND %s",
                $coupon_id,
                'wc-completed',
                $start_gmt,
                $end_gmt
            )
        );

        // Completed page IDs
        $completed_order_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT l.order_id
                 FROM {$table_coupon_lookup} l
                 INNER JOIN {$table_order_stats} s ON s.order_id = l.order_id
                 WHERE l.coupon_id = %d
                   AND s.status = %s
                   AND s.date_created_gmt BETWEEN %s AND %s
                 ORDER BY s.date_created_gmt DESC
                 LIMIT %d OFFSET %d",
                $coupon_id,
                'wc-completed',
                $start_gmt,
                $end_gmt,
                $per_page,
                $offset_completed
            )
        );

        set_transient(
            $cache_key,
            [
                'total_completed_with_coupon' => $total_completed_with_coupon,
                'completed_order_ids'         => array_map( 'intval', (array) $completed_order_ids ),
            ],
            30 * MINUTE_IN_SECONDS
        );
    }

    $build_pagination = function( int $total, int $per_page, int $current_page, string $page_param ): string {
        if ( $total <= $per_page ) {
            return '';
        }

        $total_pages = (int) ceil( $total / $per_page );
        $out = '<div class="branam-pagination">';

        for ( $p = 1; $p <= $total_pages; $p++ ) {
            $url = add_query_arg( $page_param, $p );
            $class = ( $p === $current_page ) ? ' class="branam-page branam-page-active"' : ' class="branam-page"';
            $out .= '<a' . $class . ' href="' . esc_url( $url ) . '">' . (int) $p . '</a> ';
        }

        $out .= '</div>';
        return $out;
    };

    ob_start();

    echo '<div class="branam-user-related-orders">';

    // Форма фильтра
    echo '<form method="get" class="branam-filter-form">';
    echo '<label for="month">' . esc_html__( 'Месяц:', 'brand-ambassador' ) . '</label>';
    echo '<select id="month" name="month" class="branam-filter-select">';
    for ( $m = 1; $m <= 12; $m++ ) {
        echo sprintf(
            '<option value="%d" %s>%s</option>',
            esc_attr( $m ),
            selected( $month, $m, false ),
            esc_html( date_i18n( 'F', mktime( 0, 0, 0, $m, 10 ) ) )
        );
    }
    echo '</select>';

    echo '<label for="year">' . esc_html__( 'Год:', 'brand-ambassador' ) . '</label>';
    echo '<select id="year" name="year" class="branam-filter-select">';
    for ( $y = (int) gmdate( 'Y' ) - 1; $y <= (int) gmdate( 'Y' ); $y++ ) {
        echo sprintf(
            '<option value="%d" %s>%d</option>',
            esc_attr( $y ),
            selected( $year, $y, false ),
            esc_html( $y )
        );
    }
    echo '</select>';

    echo '<button type="submit" class="branam-apply-buttons">' . esc_html__( 'Применить', 'brand-ambassador' ) . '</button>';
    echo '</form>';

    echo '<h3 class="branam-selected-month-year-title">' . esc_html(
        sprintf(
            __( 'Заказы со статусом выполнен* за %1$s %2$d:', 'brand-ambassador' ),
            date_i18n( 'F', mktime( 0, 0, 0, $month, 10 ) ),
            $year
        )
    ) . '</h3>';

    if ( $total_completed_with_coupon <= 0 ) {
        echo '<p>' . esc_html__( 'Нет выполненных заказов за выбранный период.', 'brand-ambassador' ) . '</p>';
    } else {
        echo '<ul>';

        foreach ( (array) $completed_order_ids as $order_id ) {
            $order_id = (int) $order_id;

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $payout_status = (string) $order->get_meta( '_branam_payout_status', true );
            $payout_label  = ( $payout_status === 'paid' )
                ? esc_html__( 'Вознаграждение выплачено', 'brand-ambassador' )
                : esc_html__( 'Нет выплаты', 'brand-ambassador' );

            echo '<li>' . esc_html(
                sprintf(
                    __( '№%1$d от %2$s c купоном: %3$s — %4$s', 'brand-ambassador' ),
                    $order_id,
                    $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '',
                    $related_coupon_code,
                    $payout_label
                )
            ) . '</li>';
        }

        echo '</ul>';

        echo $build_pagination( $total_completed_with_coupon, $per_page, $paged_completed, 'branam_page_completed' );

        $total_reward = $total_completed_with_coupon * $reward_per_order;

        echo '<p class="branam-payout">' . esc_html(
            sprintf(
                __( 'Выплата за %1$s %2$d составит %3$d * %4$dруб = %5$dруб', 'brand-ambassador' ),
                date_i18n( 'F', mktime( 0, 0, 0, $month, 10 ) ),
                $year,
                $total_completed_with_coupon,
                $reward_per_order,
                $total_reward
            )
        ) . '</p>';
    }

    echo '<p class="branam-reward-note">' . esc_html__( '*Вознаграждение начисляется только за выполненные заказы.', 'brand-ambassador' ) . '</p>';
    echo '</div>';

    return ob_get_clean();
} );

/**
 * Шорткод [branam_user_total_orders] — SQL + кеш.
 */
add_shortcode( 'branam_user_total_orders', function () {

    if ( ! is_user_logged_in() ) {
        return esc_html__( 'Вы должны быть авторизованы для просмотра информации.', 'brand-ambassador' );
    }

    $current_user = wp_get_current_user();
    $reward_per_order = branam_get_reward_per_order_for_user( $current_user );
    if ( $reward_per_order <= 0 ) {
        return esc_html__( 'У вас нет доступа к статистике.', 'brand-ambassador' );
    }

    [ $coupon_id, $related_coupon_code ] = branam_get_user_coupon_data( (int) $current_user->ID );
    if ( ! $coupon_id ) {
        return esc_html__( 'У вас нет личного купона.', 'brand-ambassador' );
    }

    if ( ! branam_hpos_lookup_tables_exist() ) {
        return '';
    }

    global $wpdb;
    $table_coupon_lookup = $wpdb->prefix . 'wc_order_coupon_lookup';
    $table_order_stats   = $wpdb->prefix . 'wc_order_stats';

    $cache_key = 'branam_total_orders_' . (int) $current_user->ID . '_' . (int) $coupon_id;
    $cached = get_transient( $cache_key );

    if ( is_array( $cached ) && isset( $cached['order_count'], $cached['total_reward'] ) ) {
        $order_count  = (int) $cached['order_count'];
        $total_reward = (int) $cached['total_reward'];
    } else {
        $order_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT l.order_id)
                 FROM {$table_coupon_lookup} l
                 INNER JOIN {$table_order_stats} s ON s.order_id = l.order_id
                 WHERE l.coupon_id = %d
                   AND s.status = %s",
                (int) $coupon_id,
                'wc-completed'
            )
        );

        $total_reward = $order_count * (int) $reward_per_order;

        set_transient(
            $cache_key,
            [
                'order_count'  => $order_count,
                'total_reward' => $total_reward,
                'coupon_code'  => $related_coupon_code,
            ],
            30 * MINUTE_IN_SECONDS
        );
    }

    ob_start();

    echo '<div class="branam-user-total-orders">';
    echo '<h3 class="branam-user-statistics-title">' . esc_html__( 'За весь период', 'brand-ambassador' ) . '</h3>';

    echo '<p>' . esc_html(
        sprintf(
            __( 'Всего заказов с вашим купоном: %d', 'brand-ambassador' ),
            $order_count
        )
    ) . '</p>';

    echo '<p>' . esc_html(
        sprintf(
            __( 'Общая сумма вознаграждения: %dруб', 'brand-ambassador' ),
            $total_reward
        )
    ) . '</p>';

    echo '</div>';

    return ob_get_clean();
} );

/**
 * Регистрируем шорткод [branam_ambassador_bank_form] для формы банковских данных
 */
add_shortcode( 'branam_ambassador_bank_form', 'branam_render_bank_data_form' );

function branam_render_bank_data_form() {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'Пожалуйста, войдите, чтобы заполнить банковские данные.', 'brand-ambassador' ) . '</p>';
    }

    $user_id = get_current_user_id();
    $encrypted_card_number = get_user_meta( $user_id, 'branam_user_numbercartbank', true );
    $bank_name = get_user_meta( $user_id, 'branam_user_bankname', true );

    $card_number = ! empty( $encrypted_card_number ) ? Branam_Settings_Page::decrypt_data( $encrypted_card_number ) : '';
    $masked_card_number = ! empty( $card_number ) ? str_repeat( '*', strlen( $card_number ) - 4 ) . substr( $card_number, -4 ) : '';

    ob_start();
    ?>
    <form method="post" action="">
        <?php wp_nonce_field( 'branam_save_bank_data', 'branam_bank_data_nonce' ); ?>
        <p>
            <label for="branam_card_number" class="branam-header-formbank"><?php esc_html_e( 'Номер банковской карты', 'brand-ambassador' ); ?></label><br>
            <input type="text" name="branam_card_number" id="branam_card_number" class="branam-input-bank" placeholder="0000 0000 0000 0000" value="<?php echo esc_attr( $masked_card_number ); ?>" maxlength="16" required />
        </p>
        <p>
            <label for="branam_bank_name" class="branam-header-formbank"><?php esc_html_e( 'Наименование банка', 'brand-ambassador' ); ?></label><br>
            <input type="text" name="branam_bank_name" id="branam_bank_name" class="branam-input-bank" placeholder="сбер" value="<?php echo esc_attr( $bank_name ); ?>" required />
        </p>
        <p>
            <button type="submit" name="branam_submit_bank_data" class="button branam-button-save"><?php esc_html_e( 'Сохранить', 'brand-ambassador' ); ?></button>
        </p>
        <?php if ( ! empty( $encrypted_card_number ) ) : ?>
            <p>
                <button type="submit" name="branam_delete_bank_data" class="button branam-deleted-bank"><?php esc_html_e( 'Удалить данные карты', 'brand-ambassador' ); ?></button>
            </p>
        <?php endif; ?>
    </form>
    <?php
    return ob_get_clean();
}

add_action( 'init', 'branam_process_bank_data_form' );
function branam_process_bank_data_form() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    $user_id = get_current_user_id();

    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        wp_die( esc_html__( 'Недостаточно прав для выполнения действия.', 'brand-ambassador' ) );
    }

    if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['branam_submit_bank_data'] ) ) {
        if (
            ! isset( $_POST['branam_bank_data_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['branam_bank_data_nonce'] ) ), 'branam_save_bank_data' )
        ) {
            wp_die( esc_html__( 'Ошибка безопасности. Попробуйте снова.', 'brand-ambassador' ) );
        }

        $card_number = isset( $_POST['branam_card_number'] ) ? sanitize_text_field( wp_unslash( $_POST['branam_card_number'] ) ) : '';
        $bank_name   = isset( $_POST['branam_bank_name'] ) ? sanitize_text_field( wp_unslash( $_POST['branam_bank_name'] ) ) : '';

        if ( ! preg_match( '/^\d{16}$/', $card_number ) ) {
            wp_die( esc_html__( 'Номер карты должен содержать 16 цифр.', 'brand-ambassador' ) );
        }

        $encrypted_card_number = Branam_Settings_Page::encrypt_data( $card_number );
        update_user_meta( $user_id, 'branam_user_numbercartbank', $encrypted_card_number );
        update_user_meta( $user_id, 'branam_user_bankname', $bank_name );

        wp_safe_redirect( add_query_arg( 'success', '1', wp_get_referer() ) );
        exit;
    }

    if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['branam_delete_bank_data'] ) ) {
        if (
            ! isset( $_POST['branam_bank_data_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['branam_bank_data_nonce'] ) ), 'branam_save_bank_data' )
        ) {
            wp_die( esc_html__( 'Ошибка безопасности. Попробуйте снова.', 'brand-ambassador' ) );
        }

        delete_user_meta( $user_id, 'branam_user_numbercartbank' );
        delete_user_meta( $user_id, 'branam_user_bankname' );

        wp_safe_redirect( add_query_arg( 'deleted', '1', wp_get_referer() ) );
        exit;
    }
}

/**
 * Шорткод [branam_ambassador_card_number] — последние 4 цифры карты.
 */
add_shortcode( 'branam_ambassador_card_number', 'branam_render_ambassador_card_number' );

function branam_render_ambassador_card_number() {
    if ( ! is_user_logged_in() ) {
        return '';
    }

    $user_id = get_current_user_id();
    $encrypted_card_number = get_user_meta( $user_id, 'branam_user_numbercartbank', true );

    if ( empty( $encrypted_card_number ) ) {
        return '';
    }

    $card_number = Branam_Settings_Page::decrypt_data( $encrypted_card_number );
    if ( empty( $card_number ) ) {
        return '';
    }

    $last_four_digits = substr( $card_number, -4 );

    return '<div class="branam-ambassador-card-number"><p>' . esc_html(
        sprintf(
            __( '**** **** **** %s', 'brand-ambassador' ),
            $last_four_digits
        )
    ) . '</p></div>';
}
