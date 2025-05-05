<?php
if (!defined('ABSPATH')) exit; // Запрет прямого доступа

class CouponPayoutsAdminPage {
    public function __construct() {
        // Добавляем страницу в меню "Маркетинг"
        add_action('admin_menu', [$this, 'add_payouts_page']);
        // Обрабатываем сохранение статуса выплат
        add_action('admin_post_save_payout_status', [$this, 'save_payout_status']);
    }

    /**
     * Добавляет страницу "Выплаты по купонам" в меню "Маркетинг"
     */
    public function add_payouts_page() {
        add_submenu_page(
            'woocommerce-marketing', // Родительская страница (WooCommerce > Маркетинг)
            __('Выплаты по купонам', 'woocommerce'), // Заголовок страницы
            __('Выплаты по купонам', 'woocommerce'), // Название в меню
            'manage_woocommerce', // Разрешения
            'coupon-payouts', // Слаг страницы
            [$this, 'render_payouts_page'] // Callback для рендеринга страницы
        );
    }

    /**
     * Получает минимальный год, в котором есть заказы
     */
    private function get_minimum_order_year() {
        global $wpdb;

        $result = $wpdb->get_var("
            SELECT YEAR(MIN(post_date)) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'shop_order' 
              AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        ");

        return $result ? absint($result) : date('Y');
    }

 /**
 * Обрабатывает сохранение статуса выплат и расчёт суммы выплат
 */
public function save_payout_status() {
    $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : ''; // Тип действия
    $selected_orders = isset($_POST['payout_status']) ? $_POST['payout_status'] : []; // Выбранные заказы
    $calculation_result = null; // Результат расчёта

    if ($action_type === 'calculate_sum') {
        // Расчёт суммы выплат
        $calculation_result = $this->calculate_payout_sum($selected_orders);

        // Сохраняем результат расчёта и выбранные заказы во временные данные
        set_transient('coupon_payout_calculation_result', $calculation_result, 30); // Результат расчёта
        set_transient('coupon_payout_selected_orders', $selected_orders, 30); // Выбранные заказы
        set_transient('show_action_buttons', true, 30); // Флаг для отображения кнопок "Рассчитать Амбассадора" и "Отменить выплату"
    } elseif (!empty($selected_orders)) {
        // Обработка статуса выплат для выбранных заказов
        foreach ($selected_orders as $order_id => $status) {
            if ($action_type === 'mark_paid') {
                update_post_meta($order_id, '_payout_status', 'paid'); // Устанавливаем статус "Выплачено"
            } elseif ($action_type === 'mark_unpaid') {
                delete_post_meta($order_id, '_payout_status'); // Сбрасываем статус выплаты
            }
        }
    }

    // Перенаправление обратно на страницу выплат с сохранением фильтров
    $redirect_url = admin_url('admin.php?page=coupon-payouts');
    if (!empty($_POST['filters'])) {
        $redirect_url .= '&' . http_build_query($_POST['filters']);
    }
    wp_redirect($redirect_url); // Перенаправление
    exit; // Завершаем выполнение
}

    /**
     * Логика для расчёта суммы выплат
     */
    private function calculate_payout_sum($selected_orders) {
        if (empty($selected_orders)) {
            return [
                'error' => __('Выберите хотя бы одну строку для расчёта.', 'woocommerce'),
            ];
        }

        // Получаем текущие настройки для ролей и выплат
        $blogger_role = get_option('blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
        $expert_role = get_option('expert_role', 'expert'); // Роль для экспертов (по умолчанию expert)
        $blogger_reward = get_option('blogger_reward', 450); // Выплата для блогеров (по умолчанию 450)
        $expert_reward = get_option('expert_reward', 600); // Выплата для экспертов (по умолчанию 600)

        $ambassadors = [];
        foreach ($selected_orders as $order_id => $value) {
            $order = wc_get_order($order_id);
            if (!$order) continue;

            $coupon_codes = $order->get_coupon_codes();
            foreach ($coupon_codes as $coupon_code) {
                $coupon = new WC_Coupon($coupon_code);
                $associated_user_id = get_post_meta($coupon->get_id(), '_ambassador_user', true);
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
                'error' => __('Выбрано несколько Амбассадоров, пожалуйста, измените выбор.', 'woocommerce'),
            ];
        }

        $ambassador = reset($ambassadors);
        $user = $ambassador['user'];
        $reward = $ambassador['reward'];
        $orders_count = $ambassador['orders'];
        $sum = $orders_count * $reward;
        $user_level = $ambassador['level'];

        return [
            'message' => sprintf(
                // Уведомление об общей выплате
                __('Общая сумма выплаты за %s %d для %s (%s): %d*%dруб = %dруб<br>Уровень: %s<br>№ карты: %s<br>Банк: %s', 'woocommerce'),
                date_i18n('F'),
                date('Y'),
                $user->display_name,
                $user->user_email,
                $orders_count,
                $reward,
                $sum,
                $user_level,
                get_user_meta($user->ID, 'user_numbercartbank', true),
                get_user_meta($user->ID, 'user_bankname', true)
            ),
        ];
    }

    /**
     * Рендеринг страницы "Выплаты по купонам"
     */
public function render_payouts_page() {
    
    // Получаем результат расчёта из transient
    $calculation_result = get_transient('coupon_payout_calculation_result');
    delete_transient('coupon_payout_calculation_result'); // Удаляем transient, чтобы уведомление не отображалось повторно

    // Получаем выбранные заказы из transient
    $selected_orders = get_transient('coupon_payout_selected_orders');
    delete_transient('coupon_payout_selected_orders'); // Удаляем transient

    // Определяем, показывать ли кнопки "Рассчитать Амбассадора" и "Отменить выплату"
    $show_action_buttons = get_transient('show_action_buttons');
    if ($show_action_buttons) {
        delete_transient('show_action_buttons'); // Удаляем transient после отображения кнопок
    }

    // Получаем роли и размеры выплат из настроек
    $blogger_role = get_option('blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
    $expert_role = get_option('expert_role', 'customer'); // Роль для экспертов (по умолчанию customer)
    $blogger_reward = get_option('blogger_reward', 450); // Выплата для блогеров (по умолчанию 450)
    $expert_reward = get_option('expert_reward', 600); // Выплата для экспертов (по умолчанию 600)

    // Получаем параметры из GET-запроса
    $month = isset($_GET['m']) ? absint($_GET['m']) : 0; // 0 = Все месяцы
    $year = isset($_GET['y']) ? absint($_GET['y']) : date('Y');
    $user_filter = isset($_GET['user']) ? sanitize_text_field($_GET['user']) : '';
    $email_sort = isset($_GET['email_sort']) ? sanitize_text_field($_GET['email_sort']) : '';
    $level_filter = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';

    // Получаем минимальный год для фильтра
    $min_year = $this->get_minimum_order_year();

    // Проверяем, корректны ли значения года
    if ($year < $min_year || $year > date('Y')) {
        echo '<div class="notice notice-error"><p>' . __('Ошибка: Неверный год.', 'woocommerce') . '</p></div>';
        return;
    }

    // Определяем, показывать ли кнопки "Рассчитать Амбассадора" и "Отменить выплату"
    $show_action_buttons = get_transient('show_action_buttons');
    if ($show_action_buttons) {
        delete_transient('show_action_buttons'); // Удаляем transient, чтобы кнопки не отображались повторно без расчёта
    }

        // Параметры для WP_Query
        $args = [
            'post_type'      => 'shop_order',
            'post_status'    => 'wc-completed', // Только выполненные заказы
            'posts_per_page' => -1,            // Без ограничения на количество
            'date_query'     => [
                [
                    'year'  => $year,
                ],
            ],
        ];

        // Если выбран конкретный месяц, добавляем его в date_query
        if ($month > 0) {
            $args['date_query'][0]['month'] = $month;
        }

        // Получаем заказы через WP_Query
        $query = new WP_Query($args);
        $orders = [];

        // Собираем данные
        while ($query->have_posts()) {
            $query->the_post();
            $order = wc_get_order(get_the_ID());
            $coupon_codes = $order->get_coupon_codes();

            foreach ($coupon_codes as $coupon_code) {
                $coupon = new WC_Coupon($coupon_code);
                $associated_user_id = get_post_meta($coupon->get_id(), '_ambassador_user', true);

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
                    $payout_status = get_post_meta($order->get_id(), '_payout_status', true);

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
             <h1 class="wp-heading-inline"><?php _e('Выплаты по купонам', 'woocommerce'); ?></h1>
        <?php if ($calculation_result): ?>
            <div class="notice <?php echo isset($calculation_result['error']) ? 'notice-error' : 'notice-success'; ?> is-dismissible">
                <p><?php echo isset($calculation_result['error']) ? esc_html($calculation_result['error']) : wp_kses_post($calculation_result['message']); ?></p>
            </div>
        <?php endif; ?>

            <!-- Форма фильтрации -->
            <form method="get" class="filter-form">
                <input type="hidden" name="page" value="coupon-payouts">

                <label for="month"><?php _e('Месяц:', 'woocommerce'); ?></label>
                <select id="month" name="m">
                    <option value="0" <?php selected($month, 0); ?>><?php _e('Все месяцы', 'woocommerce'); ?></option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo esc_attr($m); ?>" <?php selected($month, $m); ?>>
                            <?php echo esc_html(date_i18n('F', mktime(0, 0, 0, $m, 10))); ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label for="year"><?php _e('Год:', 'woocommerce'); ?></label>
                <select id="year" name="y">
                    <?php for ($y = $min_year; $y <= date('Y'); $y++): ?>
                        <option value="<?php echo esc_attr($y); ?>" <?php selected($year, $y); ?>>
                            <?php echo esc_html($y); ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label for="user"><?php _e('Пользователь:', 'woocommerce'); ?></label>
                <input type="text" id="user" name="user" value="<?php echo esc_attr($user_filter); ?>" placeholder="<?php _e('Имя, Email или ID', 'woocommerce'); ?>" />

                <label for="email_sort"><?php _e('Сортировка email:', 'woocommerce'); ?></label>
                <select id="email_sort" name="email_sort">
                    <option value=""><?php _e('Не сортировать', 'woocommerce'); ?></option>
                    <option value="asc" <?php selected($email_sort, 'asc'); ?>><?php _e('A-Z', 'woocommerce'); ?></option>
                </select>

                <label for="level"><?php _e('Уровень:', 'woocommerce'); ?></label>
                <select id="level" name="level">
                    <option value=""><?php _e('Все уровни', 'woocommerce'); ?></option>
                    <option value="Эксперт" <?php selected($level_filter, 'Эксперт'); ?>><?php _e('Эксперт', 'woocommerce'); ?></option>
                    <option value="Блогер" <?php selected($level_filter, 'Блогер'); ?>><?php _e('Блогер', 'woocommerce'); ?></option>
                </select>

                <button type="submit" class="button"><?php _e('Применить', 'woocommerce'); ?></button>
            </form>

           <!-- Проверяем, есть ли заказы -->
<?php if (empty($orders)): ?>
    <p style="margin-top: 20px; font-size: 16px; color: #555;">
        <?php echo sprintf(__('Нет заказов за %s %d.', 'woocommerce'), $month > 0 ? date_i18n('F', mktime(0, 0, 0, $month, 10)) : __('все месяцы', 'woocommerce'), $year); ?>
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
                    <th><?php _e('Номер заказа', 'woocommerce'); ?></th>
                    <th><?php _e('Дата заказа', 'woocommerce'); ?></th>
                    <th><?php _e('Промокод', 'woocommerce'); ?></th>
                    <th><?php _e('Амбассадор', 'woocommerce'); ?></th>
                    <th><?php _e('Уровень', 'woocommerce'); ?></th>
                    <th><?php _e('Размер выплаты', 'woocommerce'); ?></th>
                    <th><?php _e('Статус выплаты', 'woocommerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" name="payout_status[<?php echo esc_attr($order['order_id']); ?>]" value="1" <?php echo (isset($selected_orders[$order['order_id']]) ? 'checked' : ''); ?> /></td>
                        <td><?php echo esc_html($order['order_id']); ?></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($order['date']))); ?></td>
                        <td><a href="<?php echo $order['coupon_edit_url']; ?>" target="_blank"><?php echo esc_html($order['coupon_code']); ?></a></td>
                        <td><?php echo $order['user_display']; ?></td>
                        <td><?php echo esc_html($order['role']); ?></td>
                        <td><?php echo esc_html($order['reward']); ?> руб.</td>
                        <td style="background-color: <?php echo $order['payout_status'] ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $order['payout_status'] ? '#155724' : '#721c24'; ?>;">
                            <?php echo $order['payout_status'] ? __('Выплачена', 'woocommerce') : __('Нет оплаты', 'woocommerce'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Кнопки -->
       <button type="submit" name="action_type" value="calculate_sum" class="button button-secondary" 
    style="background-color: #ffc107; border-color: #ffc107; color: #000;">
    <?php _e('Рассчитать выплату', 'woocommerce'); ?>
</button>

       <!-- Условные кнопки -->
<?php if (get_transient('show_action_buttons')): ?>
    <button type="submit" name="action_type" value="mark_paid" 
        class="button button-primary" style="background-color: #28a745; border-color: #28a745;">
        <?php _e('Рассчитать Амбассадора', 'woocommerce'); ?>
    </button>
    <button type="submit" name="action_type" value="mark_unpaid" 
        class="button button-secondary" style="background-color: #dc3545; border-color: #dc3545; color: #fff;">
        <?php _e('Отменить выплату', 'woocommerce'); ?>
    </button>
    <?php 
        // Удаляем transient, чтобы кнопки не отображались повторно без нового расчёта
        delete_transient('show_action_buttons'); 
    ?>
<?php endif; ?>
</form>
<?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const calculateButton = document.querySelector('button[name="action_type"][value="calculate_sum"]');
        const markPaidButton = document.querySelector('button[name="action_type"][value="mark_paid"]');
        const markUnpaidButton = document.querySelector('button[name="action_type"][value="mark_unpaid"]');

        if (markPaidButton && markUnpaidButton) {
            markPaidButton.style.display = 'none';
            markUnpaidButton.style.display = 'none';
        }

        calculateButton.addEventListener('click', function (event) {
            event.preventDefault(); // Предотвращаем отправку формы
            if (markPaidButton) markPaidButton.style.display = 'inline-block';
            if (markUnpaidButton) markUnpaidButton.style.display = 'inline-block';
        });
    });
</script>

<?php

        // Сбрасываем WP_Query
        wp_reset_postdata();
    }
}
