<?php
if (!defined('ABSPATH')) exit; // Запрет прямого доступа

class CouponPayoutsPage {
    /**
     * Добавляет страницу выплат
     */
    public function add_payouts_page() {
        add_submenu_page(
            'woocommerce-marketing', // Родительская страница
            __('Выплаты по купонам', 'brand-ambassador'), // Заголовок страницы
            __('Выплаты по купонам', 'brand-ambassador'), // Название в меню
            'manage_woocommerce', // Разрешения
            'coupon-payouts', // Слаг страницы
            [$this, 'render_payouts_page'] // Callback для рендеринга страницы
        );
    }

    /**
     * Получает минимальный год, в котором есть заказы (HPOS-ONLY: через WC_Order_Query)
     */
    private function get_minimum_order_year() {
        $args = [
            'status' => ['wc-completed', 'wc-processing', 'wc-on-hold'],
            'limit'  => 1,
            'orderby' => 'date',
            'order'   => 'ASC',
            'return'  => 'ids',
        ];
        $query = new WC_Order_Query($args);
        $order_ids = $query->get_orders();
        if (empty($order_ids)) {
            return gmdate('Y');
        }
        $order = wc_get_order($order_ids[0]);
        if (!$order) {
            return gmdate('Y');
        }
        $date_created = $order->get_date_created();
        return $date_created ? (int)$date_created->format('Y') : gmdate('Y');
    }

    /**
     * Рендеринг страницы выплат
     */
    public function render_payouts_page() {
        // Проверяем доступ через фильтр
        $has_access = apply_filters('coupon_payouts_page_access', current_user_can('manage_woocommerce'));

        if (!$has_access) {
            wp_die(__('У вас недостаточно прав для доступа к этой странице.', 'brand-ambassador'));
        }
        // Редирект, если параметры m и y отсутствуют
        if (!isset($_GET['m']) || !isset($_GET['y'])) {
            wp_safe_redirect(add_query_arg(['m' => 0, 'y' => gmdate('Y')], admin_url('admin.php?page=coupon-payouts')));
            exit;
        }
        // Получаем результат расчёта из transient
        $calculation_result = get_transient('branam_coupon_payout_calculation_result');
        delete_transient('branam_coupon_payout_calculation_result'); // Удаляем transient, чтобы уведомление не отображалось повторно

        // Получаем выбранные заказы из transient
        $selected_orders = get_transient('branam_coupon_payout_selected_orders');
        delete_transient('branam_coupon_payout_selected_orders'); // Удаляем transient

        // Проверяем флаг для отображения кнопок
        $show_action_buttons = get_transient('branam_show_action_buttons');
        if ($show_action_buttons) {
            delete_transient('branam_show_action_buttons'); // Удаляем transient после отображения кнопок
        }

        // Получаем роли и размеры выплат из настроек
        $blogger_role = get_option('branam_blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
        $expert_role = get_option('branam_expert_role', 'subscriber'); // Роль для экспертов (по умолчанию subscriber)
        $blogger_reward = get_option('branam_blogger_reward', 450); // Выплата для блогеров (по умолчанию 450)
        $expert_reward = get_option('branam_expert_reward', 600); // Выплата для экспертов (по умолчанию 600)

        // Получаем параметры из GET-запроса
        $month = isset($_GET['m']) ? absint($_GET['m']) : 0; // 0 = Все месяцы
        $year = isset($_GET['y']) ? absint($_GET['y']) : gmdate('Y');
        $user_filter = isset($_GET['user']) ? sanitize_text_field($_GET['user']) : '';
        $email_sort = isset($_GET['email_sort']) ? sanitize_text_field($_GET['email_sort']) : '';
        $level_filter = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';

        // Получаем минимальный год для фильтра
        $min_year = $this->get_minimum_order_year();

        // Проверяем, корректны ли значения года
        if ($year < $min_year || $year > gmdate('Y')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Ошибка: Неверный год.', 'brand-ambassador') . '</p></div>';
            return;
        }

        // Формируем аргументы для выборки заказов через HPOS (WC_Order_Query)
        $date_query = [];
        if ($year) {
            $date_query['after'] = $year . '-01-01 00:00:00';
            $date_query['before'] = $year . '-12-31 23:59:59';
        }
        if ($month > 0) {
            $date_query['after'] = $year . '-' . sprintf('%02d', $month) . '-01 00:00:00';
            $date_query['before'] = $year . '-' . sprintf('%02d', $month) . '-31 23:59:59';
        }

        $args = [
            'status' => 'wc-completed',
            'limit'  => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'ids',
        ];
        if (!empty($date_query)) {
            $args['date_created'] = $date_query['after'] . '...' . $date_query['before'];
        }

        // Получаем заказы через WC_Order_Query (HPOS!)
        $query = new WC_Order_Query($args);
        $order_ids = $query->get_orders();
        $orders = [];

        // Собираем данные
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) continue;
            $coupon_codes = $order->get_coupon_codes();

            foreach ($coupon_codes as $coupon_code) {
                $coupon = new WC_Coupon($coupon_code);
                $associated_user_id = get_post_meta($coupon->get_id(), '_branam_ambassador_user', true);

                if (!empty($associated_user_id)) {
                    $user = get_userdata($associated_user_id);

                    if ($user_filter) {
                        $match = false;
                        if (strpos($user->user_login, $user_filter) !== false ||
                            strpos($user->user_email, $user_filter) !== false ||
                            strpos($user->display_name, $user_filter) !== false ||
                            $user->ID == $user_filter) {
                            $match = true;
                        }

                        if (!$match) {
                            continue;
                        }
                    }

                    // Определяем роль пользователя
                    $role_label = 'Неизвестная роль';
                    $reward = 0;
                    if (in_array($expert_role, $user->roles)) {
                        $role_label = 'Эксперт';
                        $reward = $expert_reward;
                    } elseif (in_array($blogger_role, $user->roles)) {
                        $role_label = 'Блогер';
                        $reward = $blogger_reward;
                    }

                    // Применяем фильтр по уровню
                    if ($level_filter && $role_label !== $level_filter) {
                        continue;
                    }

                    // Получаем статус выплаты
                    $payout_status = get_post_meta($order->get_id(), '_branam_payout_status', true);

                    $orders[] = [
                        'order_id' => $order->get_id(),
                        'date' => $order->get_date_created(),
                        'coupon_code' => $coupon_code,
                        'user_email' => $user->user_email,
                        'user_display' => sprintf(
                            '<a href="%s" target="_blank">%s (%s)</a>',
                            esc_url(admin_url('user-edit.php?user_id=' . $associated_user_id)),
                            esc_html($user->display_name),
                            esc_html($user->user_email)
                        ),
                        'coupon_edit_url' => esc_url(admin_url('post.php?post=' . $coupon->get_id() . '&action=edit')),
                        'role' => $role_label,
                        'reward' => $reward,
                        'payout_status' => $payout_status === 'paid', // true, если выплата уже сделана
                    ];
                }
            }
        }

        // Применяем сортировку по email (Амбассадор), если указано
        if ($email_sort === 'asc') {
            usort($orders, function ($a, $b) {
                return strcmp($a['user_email'], $b['user_email']);
            });
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Выплаты по купонам', 'brand-ambassador'); ?></h1>
            <?php if (isset($_GET['message']) && $_GET['message'] === 'mixed_statuses'): ?>
            <div class="notice notice-error is-dismissible">
                <p><?php esc_html_e('Выбраны строки с разными статусами выплат. Пожалуйста, измените выбор.', 'brand-ambassador'); ?></p>
            </div>
        <?php endif; ?>
            <?php if ($calculation_result): ?>
                <div class="notice <?php echo isset($calculation_result['error']) ? 'notice-error' : 'notice-success'; ?> is-dismissible">
                    <p><?php echo isset($calculation_result['error']) ? esc_html($calculation_result['error']) : wp_kses_post($calculation_result['message']); ?></p>
                </div>
            <?php endif; ?>

            <!-- Форма фильтрации -->
            <form method="get" class="filter-form">
                <input type="hidden" name="page" value="coupon-payouts">

                <label for="month"><?php esc_html_e('Месяц:', 'brand-ambassador'); ?></label>
                <select id="month" name="m">
                    <option value="0"><?php esc_html_e('Все месяцы', 'brand-ambassador'); ?></option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo esc_attr($m); ?>" <?php selected($month, $m); ?>>
                            <?php echo esc_html(date_i18n('F', mktime(0, 0, 0, $m, 10))); ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label for="year"><?php esc_html_e('Год:', 'brand-ambassador'); ?></label>
                <select id="year" name="y">
                    <?php for ($y = $min_year; $y <= gmdate('Y'); $y++): ?>
                        <option value="<?php echo esc_attr($y); ?>" <?php selected($year, $y); ?>>
                            <?php echo esc_html($y); ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label for="user"><?php esc_html_e('Пользователь:', 'brand-ambassador'); ?></label>
                <input type="text" id="user" name="user" value="<?php echo esc_attr($user_filter); ?>" placeholder="<?php esc_html_e('Имя, Email или ID', 'brand-ambassador'); ?>" />

                <label for="email_sort"><?php esc_html_e('Сортировка email:', 'brand-ambassador'); ?></label>
                <select id="email_sort" name="email_sort">
                    <option value=""><?php esc_html_e('Не сортировать', 'brand-ambassador'); ?></option>
                    <option value="asc" <?php selected($email_sort, 'asc'); ?>><?php esc_html_e('A-Z', 'brand-ambassador'); ?></option>
                </select>

                <label for="level"><?php esc_html_e('Уровень:', 'brand-ambassador'); ?></label>
                <select id="level" name="level">
                    <option value=""><?php esc_html_e('Все уровни', 'brand-ambassador'); ?></option>
                    <option value="Эксперт" <?php selected($level_filter, 'Эксперт'); ?>><?php esc_html_e('Эксперт', 'brand-ambassador'); ?></option>
                    <option value="Блогер" <?php selected($level_filter, 'Блогер'); ?>><?php esc_html_e('Блогер', 'brand-ambassador'); ?></option>
                </select>

                <button type="submit" class="button"><?php esc_html_e('Применить', 'brand-ambassador'); ?></button>
            </form>
            
            <!-- Проверяем, есть ли заказы -->
            <?php if (empty($orders)): ?>
                <p style="margin-top: 20px; font-size: 16px; color: #555;">
                    <?php echo esc_html(sprintf(__('Нет заказов за %1$s %2$d.', 'brand-ambassador'), $month > 0 ? date_i18n('F', mktime(0, 0, 0, $month, 10)) : esc_html__('все месяцы', 'brand-ambassador'), $year)); ?>
                </p>
            <?php else: ?>
            
            <!-- Таблица -->
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="save_payout_status">
                    <input type="hidden" name="filters[m]" value="<?php echo esc_attr($month); ?>">
                    <input type="hidden" name="filters[y]" value="<?php echo esc_attr($year); ?>">
                    <input type="hidden" name="filters[user]" value="<?php echo esc_attr($user_filter); ?>">
                    <input type="hidden" name="filters[email_sort]" value="<?php echo esc_attr($email_sort); ?>">
                    <input type="hidden" name="filters[level]" value="<?php echo esc_attr($level_filter); ?>">
                    <?php wp_nonce_field('save_payout_status', 'payout_status_nonce'); ?>
                     <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all"></th>
                                <th><?php esc_html_e('Номер заказа', 'brand-ambassador'); ?></th>
                                <th><?php esc_html_e('Дата заказа', 'brand-ambassador'); ?></th>
                                <th><?php esc_html_e('Промокод', 'brand-ambassador'); ?></th>
                                <th><?php esc_html_e('Амбассадор', 'brand-ambassador'); ?></th>
                                <th><?php esc_html_e('Уровень', 'brand-ambassador'); ?></th>
                                <th><?php esc_html_e('Размер выплаты', 'brand-ambassador'); ?></th>
                                <th><?php esc_html_e('Статус выплаты', 'brand-ambassador'); ?></th>
                            </tr>
                        </thead>
                       <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr style="background-color: <?php echo $order['payout_status'] ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $order['payout_status'] ? '#155724' : '#721c24'; ?>;">
                                    <td>
                                        <input type="checkbox" class="row-checkbox" name="payout_status[<?php echo esc_attr($order['order_id']); ?>]" value="1" <?php echo isset($selected_orders[$order['order_id']]) ? 'checked' : ''; ?>>
                                    </td>
                                    <td><?php echo esc_html($order['order_id']); ?></td>
                                    <td><?php echo esc_html($order['date'] ? $order['date']->date_i18n(get_option('date_format')) : ''); ?></td>
                                    <td><a href="<?php echo $order['coupon_edit_url']; ?>" target="_blank"><?php echo esc_html($order['coupon_code']); ?></a></td>
                                    <td><?php echo $order['user_display']; ?></td>
                                    <td><?php echo esc_html($order['role']); ?></td>
                                    <td><?php echo esc_html($order['reward']); ?> руб.</td>
                                    <td>
                                        <?php echo $order['payout_status'] ? esc_html__('Выплачена', 'brand-ambassador') : esc_html__('Не выплатили', 'brand-ambassador'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <button type="submit" name="action_type" value="calculate_sum" class="button button-secondary" style="background-color: #ffc107; border-color: #ffc107; color: #000;">
                        <?php esc_html_e('Рассчитать выплату', 'brand-ambassador'); ?>
                    </button>

                    <?php if ($show_action_buttons): ?>
                        <button type="submit" name="action_type" value="mark_paid" class="button button-primary" style="background-color: #28a745; border-color: #28a745;">
                            <?php esc_html_e('Рассчитать Амбассадора', 'brand-ambassador'); ?>
                        </button>
                        <button type="submit" name="action_type" value="mark_unpaid" class="button button-secondary" style="background-color: #dc3545; border-color: #dc3545; color: #fff;">
                            <?php esc_html_e('Отменить выплату', 'brand-ambassador'); ?>
                        </button>
                        
                        <button type="button" class="button button-secondary" style="background-color: #6c757d; border-color: #6c757d; color: #fff;" onclick="window.location.reload();">
                        <?php esc_html_e('Отменить выбор', 'brand-ambassador'); ?>
                    </button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const selectAllCheckbox = document.getElementById('select-all');
                if (selectAllCheckbox) {
                    selectAllCheckbox.addEventListener('change', function () {
                        const isChecked = this.checked;
                        document.querySelectorAll('.row-checkbox').forEach(function (checkbox) {
                            checkbox.checked = isChecked;
                        });
                    });
                }
            });
        </script>
        <?php
    }
}
