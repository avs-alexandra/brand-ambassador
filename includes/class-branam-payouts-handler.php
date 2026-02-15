<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Запрет прямого доступа

class Branam_Payouts_Handler {

    /**
     * Обрабатывает сохранение статуса выплат и расчёт суммы выплат
     */
    public function save_payout_status() {
        // 1) Nonce
        if (
            ! isset( $_POST['branam_payout_status_nonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['branam_payout_status_nonce'] ) ),
                'branam_save_payout_status'
            )
        ) {
            wp_die( esc_html__( 'Ошибка безопасности. Попробуйте снова.', 'brand-ambassador' ) );
        }

        // 2) Capability
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Недостаточно прав для выполнения действия.', 'brand-ambassador' ) );
        }

        $action_type = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';

        // payout_status[order_id] => 1
        $selected_orders = [];
        $raw_post = $_POST; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $raw_payout_status = array_key_exists( 'payout_status', $raw_post ) ? wp_unslash( $raw_post['payout_status'] ) : [];

        if ( is_array( $raw_payout_status ) ) {
            foreach ( $raw_payout_status as $order_id => $value ) {
                $safe_order_id = absint( $order_id );
                if ( $safe_order_id > 0 ) {
                    $selected_orders[ $safe_order_id ] = sanitize_text_field( (string) $value );
                }
            }
        }

        if ( empty( $selected_orders ) ) {
            wp_safe_redirect( add_query_arg( [ 'message' => 'no_orders' ], wp_get_referer() ) );
            exit;
        }

        // Проверяем, выбраны ли строки с разными статусами выплат (HPOS meta)
        $statuses = [];
        foreach ( array_keys( $selected_orders ) as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }
            $statuses[] = (string) $order->get_meta( '_branam_payout_status', true );
        }
        $unique_statuses = array_unique( $statuses );

        if ( count( $unique_statuses ) > 1 ) {
            wp_safe_redirect( add_query_arg( [ 'message' => 'mixed_statuses' ], wp_get_referer() ) );
            exit;
        }

        if ( $action_type === 'calculate_sum' ) {

            $calculation_result = $this->calculate_payout_sum( array_keys( $selected_orders ) );

            set_transient( 'branam_coupon_payout_calculation_result', $calculation_result, 30 );
            set_transient( 'branam_coupon_payout_selected_orders', $selected_orders, 30 );
            set_transient( 'branam_show_action_buttons', true, 30 );

        } else {

            foreach ( array_keys( $selected_orders ) as $order_id ) {
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                    continue;
                }

                if ( $action_type === 'mark_paid' ) {
                    $order->update_meta_data( '_branam_payout_status', 'paid' );
                    $order->save();
                } elseif ( $action_type === 'mark_unpaid' ) {
                    $order->update_meta_data( '_branam_payout_status', 'unpaid' );
                    $order->save();
                }
            }
        }

        // Redirect back with filters
        $redirect_url = admin_url( 'admin.php?page=branam-coupon-payouts' );
        if ( ! empty( $_POST['filters'] ) && is_array( $_POST['filters'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
            $filters = wp_unslash( $_POST['filters'] );
            $filters = array_map( 'sanitize_text_field', $filters );
            $redirect_url .= '&' . http_build_query( $filters );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Расчёт суммы выплат (HPOS meta + минимум загрузок).
     *
     * @param int[] $order_ids
     */
    private function calculate_payout_sum( array $order_ids ): array {
        if ( empty( $order_ids ) ) {
            return [ 'error' => esc_html__( 'Выберите хотя бы одну строку для расчёта.', 'brand-ambassador' ) ];
        }

        $blogger_role   = get_option( 'branam_blogger_role', 'customer' );
        $expert_role    = get_option( 'branam_expert_role', 'subscriber' );
        $blogger_reward = (int) get_option( 'branam_blogger_reward', 450 );
        $expert_reward  = (int) get_option( 'branam_expert_reward', 600 );

        $ambassadors = [];

        foreach ( $order_ids as $order_id ) {
            $order_id = (int) $order_id;

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $coupon_codes = $order->get_coupon_codes();
            foreach ( (array) $coupon_codes as $coupon_code ) {
                $coupon = new WC_Coupon( (string) $coupon_code );
                $coupon_id = (int) $coupon->get_id();
                if ( ! $coupon_id ) {
                    continue;
                }

                $associated_user_id = (int) get_post_meta( $coupon_id, '_branam_ambassador_user', true );
                if ( ! $associated_user_id ) {
                    continue;
                }

                $user = get_userdata( $associated_user_id );
                if ( ! $user ) {
                    continue;
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

                if ( $reward === 0 ) {
                    continue;
                }

                if ( ! isset( $ambassadors[ $associated_user_id ] ) ) {
                    $ambassadors[ $associated_user_id ] = [
                        'user'   => $user,
                        'reward' => $reward,
                        'orders' => 0,
                        'level'  => $role_label,
                    ];
                }

                $ambassadors[ $associated_user_id ]['orders']++;
            }
        }

        if ( count( $ambassadors ) > 1 ) {
            return [
                'error' => esc_html__( 'Выбрано несколько Амбассадоров, пожалуйста, измените выбор.', 'brand-ambassador' ),
            ];
        }

        $ambassador = reset( $ambassadors );
        if ( ! is_array( $ambassador ) || empty( $ambassador['user'] ) ) {
            return [
                'error' => esc_html__( 'Не удалось определить Амбассадора по выбранным заказам.', 'brand-ambassador' ),
            ];
        }

        /** @var WP_User $user */
        $user = $ambassador['user'];
        $reward = (int) $ambassador['reward'];
        $orders_count = (int) $ambassador['orders'];
        $sum = $orders_count * $reward;
        $user_level = (string) $ambassador['level'];

        $encrypted_card_number = get_user_meta( $user->ID, 'branam_user_numbercartbank', true );
        $decrypted_card_number = ! empty( $encrypted_card_number )
            ? Branam_Settings_Page::decrypt_data( $encrypted_card_number )
            : esc_html__( 'Не указан', 'brand-ambassador' );

        $bank = (string) get_user_meta( $user->ID, 'branam_user_bankname', true );

        return [
            'message' => sprintf(
                /* translators: 1: месяц, 2: год, 3: имя, 4: email, 5: кол-во заказов, 6: сумма за заказ, 7: сумма итого, 8: уровень, 9: номер карты, 10: банк */
                __( 'Общая сумма выплаты за %1$s %2$d для %3$s (%4$s): %5$d*%6$dруб = %7$dруб<br>Уровень: %8$s<br>№ карты: %9$s<br>Банк: %10$s', 'brand-ambassador' ),
                esc_html( date_i18n( 'F' ) ),
                esc_html( gmdate( 'Y' ) ),
                esc_html( $user->display_name ),
                esc_html( $user->user_email ),
                (int) $orders_count,
                (int) $reward,
                (int) $sum,
                esc_html( $user_level ),
                esc_html( $decrypted_card_number ),
                esc_html( $bank )
            ),
        ];
    }
}
