<?php
if (!defined('ABSPATH')) exit; // Запрет прямого доступа

class CouponPayoutsHandler {
    /**
     * Обрабатывает сохранение статуса выплат и расчёт суммы выплат
     */
    public function save_payout_status() {
        // 1. Проверка nonce
        if (
            !isset($_POST['payout_status_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['payout_status_nonce'])),
                'save_payout_status'
            )
        ) {
            wp_die(esc_html__('Ошибка безопасности. Попробуйте снова.', 'brand-ambassador'));
        }

        // 2. Проверка прав пользователя
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Недостаточно прав для выполнения действия.', 'brand-ambassador'));
        }

        $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : ''; // Тип действия
        $selected_orders = isset($_POST['payout_status']) ? $_POST['payout_status'] : []; // Выбранные заказы
        $calculation_result = null; // Результат расчёта

        // Проверяем, выбраны ли заказы
        if (empty($selected_orders)) {
            wp_safe_redirect(add_query_arg(['message' => 'no_orders'], wp_get_referer()));
            exit;
        }

        // Проверяем, выбраны ли строки с разными статусами выплат
        $statuses = [];
        foreach ($selected_orders as $order_id => $value) {
            $current_status = get_post_meta($order_id, '_branam_payout_status', true);
            $statuses[] = $current_status;
        }

        // Удаляем дубликаты из массива статусов
        $unique_statuses = array_unique($statuses);

        // Если выбранные строки имеют разные статусы
        if (count($unique_statuses) > 1) {
            wp_safe_redirect(add_query_arg(['message' => 'mixed_statuses'], wp_get_referer()));
            exit;
        }

        // Обработка действий
        if ($action_type === 'calculate_sum') {
            // Расчёт суммы выплат
            $calculation_result = $this->calculate_payout_sum($selected_orders);

            // Сохраняем результат расчёта и выбранные заказы во временные данные
            set_transient('branam_coupon_payout_calculation_result', $calculation_result, 30); // Результат расчёта
            set_transient('branam_coupon_payout_selected_orders', $selected_orders, 30); // Выбранные заказы
            set_transient('branam_show_action_buttons', true, 30); // Флаг для отображения кнопок
        } elseif (!empty($selected_orders)) {
            // Обработка статуса выплат
            foreach ($selected_orders as $order_id => $status) {
                if ($action_type === 'mark_paid') {
                    update_post_meta($order_id, '_branam_payout_status', 'paid'); // Устанавливаем статус "Выплачено"
                } elseif ($action_type === 'mark_unpaid') {
                    update_post_meta($order_id, '_branam_payout_status', 'unpaid'); // Устанавливаем статус "Не выплачено"
                }
            }
        }

        // Перенаправление обратно на страницу выплат с сохранением фильтров
        $redirect_url = admin_url('admin.php?page=coupon-payouts');
        if (!empty($_POST['filters'])) {
            $redirect_url .= '&' . http_build_query(array_map('sanitize_text_field', wp_unslash($_POST['filters'])));
        }
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Логика для расчёта суммы выплат
     */
    private function calculate_payout_sum($selected_orders) {
        if (empty($selected_orders)) {
            return ['error' => esc_html__('Выберите хотя бы одну строку для расчёта.', 'brand-ambassador')];
        }

        // Получаем текущие настройки для ролей и выплат
        $blogger_role = get_option('branam_blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
        $expert_role = get_option('branam_expert_role', 'subscriber'); // Роль для экспертов (по умолчанию subscriber)
        $blogger_reward = get_option('branam_blogger_reward', 450); // Выплата для блогеров (по умолчанию 450)
        $expert_reward = get_option('branam_expert_reward', 600); // Выплата для экспертов (по умолчанию 600)

        $ambassadors = [];
        foreach ($selected_orders as $order_id => $value) {
            $order = wc_get_order($order_id);
            if (!$order) continue;

            $coupon_codes = $order->get_coupon_codes();
            foreach ($coupon_codes as $coupon_code) {
                $coupon = new WC_Coupon($coupon_code);
                $associated_user_id = get_post_meta($coupon->get_id(), '_branam_ambassador_user', true);
                if (!$associated_user_id) continue;

                $user = get_userdata($associated_user_id);
                if (!$user) continue;

                // Логика определения уровня пользователя
                $role_label = 'Неизвестная роль';
                $reward = 0;

                if (in_array($expert_role, $user->roles)) {
                    $role_label = 'Эксперт';
                    $reward = $expert_reward;
                } elseif (in_array($blogger_role, $user->roles)) {
                    $role_label = 'Блогер';
                    $reward = $blogger_reward;
                }

                // Если уровень не определён, пропускаем
                if ($reward === 0) {
                    continue;
                }

                if (!isset($ambassadors[$associated_user_id])) {
                    $ambassadors[$associated_user_id] = [
                        'user' => $user,
                        'reward' => $reward,
                        'orders' => 0,
                        'level' => $role_label,
                    ];
                }
                $ambassadors[$associated_user_id]['orders']++;
            }
        }

        if (count($ambassadors) > 1) {
            return [
                'error' => esc_html__('Выбрано несколько Амбассадоров, пожалуйста, измените выбор.', 'brand-ambassador'),
            ];
        }

        $ambassador = reset($ambassadors);
        $user = $ambassador['user'];
        $reward = $ambassador['reward'];
        $orders_count = $ambassador['orders'];
        $sum = $orders_count * $reward;
        $user_level = $ambassador['level'];

        // Расшифровка номера карты
        $encrypted_card_number = get_user_meta($user->ID, 'branam_user_numbercartbank', true);
        $decrypted_card_number = !empty($encrypted_card_number) ? AmbassadorSettingsPage::decrypt_data($encrypted_card_number) : esc_html__('Не указан', 'brand-ambassador');

        return [
            'message' => sprintf(
                __('Общая сумма выплаты за %1$s %2$d для %3$s (%4$s): %5$d*%6$dруб = %7$dруб<br>Уровень: %8$s<br>№ карты: %9$s<br>Банк: %10$s', 'brand-ambassador'),
                esc_html(date_i18n('F')),
                esc_html(date('Y')),
                esc_html($user->display_name),
                esc_html($user->user_email),
                esc_html($orders_count),
                esc_html($reward),
                esc_html($sum),
                esc_html($user_level),
                esc_html($decrypted_card_number),
                esc_html(get_user_meta($user->ID, 'branam_user_bankname', true))
            ),
        ];
    }
}
