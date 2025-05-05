<?php

/**
 * Не шорткод. Добавляем возможность купону только для первой покупки
 */
add_filter('woocommerce_coupon_get_discount_amount', 'apply_coupon_only_first_order_with_removal', 10, 5);
function apply_coupon_only_first_order_with_removal($discount, $discounting_amount, $cart_item, $single, $coupon) {
    // Проверяем, активирован ли флаг "Только для первого заказа"
    if (get_post_meta($coupon->get_id(), 'only_first_order', true) === 'yes') {
        $user_orders = wc_get_orders([
            'customer_id' => get_current_user_id(),
            'limit' => 1, // Проверяем только первый заказ
        ]);
        // Если у пользователя уже есть заказы, удаляем купон и показываем уведомление
        if (!empty($user_orders)) {
            // Удаляем купон из корзины
            WC()->cart->remove_coupon($coupon->get_code());

            // Выводим предупреждение для пользователя
            if (!wc_has_notice(__('Купон действует только на первый заказ.', 'woocommerce'), 'error')) {
                wc_add_notice(__('Купон действует только на первый заказ.', 'woocommerce'), 'error');
            }

            return $discount; // Возвращаем текущую скидку, так как купон будет удалён
        }
    }
    return $discount;
}
// Добавляем галочку "Только для первого заказа" в настройки купона.
add_action('woocommerce_coupon_options', 'add_coupon_option_first_order_checkbox');
function add_coupon_option_first_order_checkbox() {
    woocommerce_wp_checkbox([
        'id' => 'only_first_order',
        'label' => __('Только для первого заказа', 'woocommerce'),
        'description' => __('Применять купон только к первому заказу пользователя.', 'woocommerce'),
    ]);
}
//Сохраняем значение галочки "Только для первого заказа".
add_action('woocommerce_coupon_options_save', 'save_coupon_option_first_order_checkbox');
function save_coupon_option_first_order_checkbox($post_id) {
    $only_first_order = isset($_POST['only_first_order']) ? 'yes' : 'no';
    update_post_meta($post_id, 'only_first_order', $only_first_order);
}



/**
 * Шорткод [user_coupon_name] наименование купона в личный кабинет амбассадора бренда.
 */
function get_user_coupon_name() {
    $user_id = get_current_user_id(); // Получить ID текущего пользователя
    if (!$user_id) {
        return __('Пользователь не авторизован.', 'woocommerce');
    }

    // Получить ID связанного купона из метаполя _user_coupon
    $coupon_id = get_user_meta($user_id, '_user_coupon', true);
    if (!$coupon_id) {
        return __('Купон не найден.', 'woocommerce');
    }

    // Получить объект купона
    $coupon = get_post($coupon_id);
    if (!$coupon || $coupon->post_type !== 'shop_coupon') {
        return __('Купон не существует.', 'woocommerce');
    }

    // Вернуть название купона
    return esc_html($coupon->post_title);
}

// Регистрация шорткода для вывода названия купона
add_shortcode('user_coupon_name', 'get_user_coupon_name');



/**
 * Шорткод [user_related_orders] для вывода заказов в личном кабинете амбассадора бренда.
 */
add_shortcode('user_related_orders', function () {
    // Получаем текущего пользователя
    $current_user = wp_get_current_user();

    // Проверяем, авторизован ли пользователь
    if (!$current_user || $current_user->ID === 0) {
        return __('Вы должны быть авторизованы для просмотра ваших заказов.', 'woocommerce');
    }

    // Получаем ID текущего пользователя
    $current_user_id = $current_user->ID;

    // Получаем роли и размеры выплат из настроек
    $blogger_role = get_option('blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
    $expert_role = get_option('expert_role', 'customer'); // Роль для экспертов (по умолчанию customer)
    $blogger_reward = get_option('blogger_reward', 450); // Выплата для блогеров (по умолчанию 450)
    $expert_reward = get_option('expert_reward', 600); // Выплата для экспертов (по умолчанию 600)

    // Проверяем роли пользователя и устанавливаем вознаграждение
    $reward_per_order = 0; // По умолчанию
    if (in_array($expert_role, (array) $current_user->roles, true)) {
        $reward_per_order = $expert_reward; // Выплата для роли "Эксперт"
    } elseif (in_array($blogger_role, (array) $current_user->roles, true)) {
        $reward_per_order = $blogger_reward; // Выплата для роли "Блогер"
    } else {
        return __('У вас нет доступа к статистике.', 'woocommerce');
    }

    // Ищем купоны, связанные с текущим пользователем
    $args_coupons = [
        'post_type'      => 'shop_coupon',
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'   => '_ambassador_user',
                'value' => $current_user_id, // Связка с текущим пользователем
            ],
        ],
    ];

    $coupons = get_posts($args_coupons);
    if (empty($coupons)) {
        return __('У вас нет связанных купонов.', 'woocommerce');
    }

    // Составляем массив кодов купонов, связанных с пользователем
    $related_coupons = [];
    foreach ($coupons as $coupon) {
        $related_coupons[] = strtolower($coupon->post_title); // Приводим к нижнему регистру для сопоставления
    }

    // Получение параметров месяца и года из $_GET
    $month = isset($_GET['month']) ? absint($_GET['month']) : date('m');
    $year = isset($_GET['year']) ? absint($_GET['year']) : date('Y');

    // Проверка диапазона месяца
    if ($month < 1 || $month > 12) {
        return __('Неверный месяц.', 'woocommerce');
    }

    // Поиск заказов со статусом "выполнен" и применёнными купонами
    $args_orders_completed = [
        'post_type'      => 'shop_order',
        'post_status'    => 'wc-completed', // Только выполненные заказы
        'posts_per_page' => -1,
        'date_query'     => [
            [
                'year'  => $year,
                'month' => $month,
            ],
        ],
    ];

    $orders_completed = get_posts($args_orders_completed);

    // Поиск заказов со статусами ['wc-delivery', 'wc-completed', 'wc-processing', 'wc-cancelled']
    $args_orders_other_statuses = [
        'post_type'      => 'shop_order',
        'post_status'    => ['wc-delivery', 'wc-completed', 'wc-processing', 'wc-cancelled'], // Дополнительные статусы
        'posts_per_page' => -1,
        'date_query'     => [
            [
                'year'  => $year,
                'month' => $month,
            ],
        ],
    ];

    $orders_other_statuses = get_posts($args_orders_other_statuses);

    // Формируем вывод
    ob_start();

    echo '<div class="user-related-orders">';

    // Форма для выбора месяца и года
    echo '<form method="get" class="filter-form">';
    echo '<label for="month">' . __('Месяц:', 'woocommerce') . '</label>';
    echo '<select id="month" name="month" class="filter-select">';
    for ($m = 1; $m <= 12; $m++) {
        echo sprintf(
            '<option value="%d" %s>%s</option>',
            $m,
            selected($month, $m, false),
            date_i18n('F', mktime(0, 0, 0, $m, 10))
        );
    }
    echo '</select>';

    echo '<label for="year">' . __('Год:', 'woocommerce') . '</label>';
    echo '<select id="year" name="year" class="filter-select">'; 
    for ($y = date('Y') - 1; $y <= date('Y'); $y++) {
        echo sprintf(
            '<option value="%d" %s>%d</option>',
            $y,
            selected($year, $y, false),
            $y
        );
    }
    echo '</select>';

    echo '<button type="submit" class="apply-buttons">' . __('Применить', 'woocommerce') . '</button>';
    echo '</form>';

    // Заголовок с выбранным месяцем и годом
    echo '<h3 class="selected-month-year-title">' . sprintf(__('Заказы со статусом выполнен* за %s %d:', 'woocommerce'), date_i18n('F', mktime(0, 0, 0, $month, 10)), $year) . '</h3>';

    if (empty($orders_completed)) {
        // Если заказов нет
        echo '<p>' . __('Нет выполненных заказов за выбранный период.', 'woocommerce') . '</p>';
    } else {
        // Если заказы найдены
        $order_count = 0;
        echo '<ul>';

        foreach ($orders_completed as $order_post) {
            $order = wc_get_order($order_post->ID);
            $used_coupons = $order->get_coupon_codes(); // Получаем применённые купоны
            $payout_status = get_post_meta($order->get_id(), '_payout_status', true); // Получаем статус выплаты
            $payout_label = $payout_status === 'paid' ? __('Вознаграждение Вам выплачено', 'woocommerce') : __('Вознаграждение Вам ещё не выплачено', 'woocommerce');
            
            foreach ($used_coupons as $coupon_code) {
                if (in_array(strtolower($coupon_code), $related_coupons, true)) {
                    // Если купон связан с текущим пользователем, добавляем заказ в вывод
                    $order_count++;
                    echo '<li>';
                    echo sprintf(
                    __('№%d от %s c купоном: %s — %s', 'woocommerce'),
                    $order->get_id(),
                    date_i18n(get_option('date_format'), strtotime($order->get_date_created())),
                    $coupon_code,
                    $payout_label
                    );
                    echo '</li>';
                }
            }
        }

        echo '</ul>';

        // Если не было заказов с применёнными купонами
        if ($order_count === 0) {
            echo '<p>' . __('Нет выполненных заказов за выбранный период.', 'woocommerce') . '</p>';
        } else {
            // Расчёт выплаты
            $total_reward = $order_count * $reward_per_order;

            // Вывод информации о выплате
            echo '<p class="payout">' . sprintf(
                __('Выплата за %s %d составит %d * %dруб = %dруб', 'woocommerce'),
                date_i18n('F', mktime(0, 0, 0, $month, 10)),
                $year,
                $order_count,
                $reward_per_order,
                $total_reward
            ) . '</p>';
        }
    }

    // Заголовок для заказов с другими статусами
    echo '<p class="other-statuses-title">' . __('Посмотреть заказы в статусе: обработка, доставка, отменён, выполнен', 'woocommerce') . '</p>';

    if (empty($orders_other_statuses)) {
        // Если заказов с другими статусами нет
        echo '<p class="other-statuses-none">' . __('Нет заказов с другими статусами за выбранный период.', 'woocommerce') . '</p>';
    } else {
        // Если заказы с другими статусами найдены
        echo '<ul class="other-statuses-list">';

        foreach ($orders_other_statuses as $order_post) {
            $order = wc_get_order($order_post->ID);
            $used_coupons = $order->get_coupon_codes(); // Получаем применённые купоны

            foreach ($used_coupons as $coupon_code) {
                if (in_array(strtolower($coupon_code), $related_coupons, true)) {
                    echo '<li>';
                    echo sprintf(
                        __('№%d от %s, Статус: %s', 'woocommerce'),
                        $order->get_id(),
                        date_i18n(get_option('date_format'), strtotime($order->get_date_created())),
                        wc_get_order_status_name($order->get_status()) // Получаем статус заказа
                    );
                    echo '</li>';
                }
            }
        }

        echo '</ul>';
    }
    // Добавляем финальную строчку с классом
    echo '<p class="reward-note">' . __('*Вознаграждение начисляется только за выполненные заказы.', 'woocommerce') . '</p>';

    echo '</div>';

    return ob_get_clean();
});



/**
 * Шорткод [user_total_orders] для вывода общего количества заказов и общей суммы комиссии за всё время.
 */
add_shortcode('user_total_orders', function () {
    // Получаем текущего пользователя
    $current_user = wp_get_current_user();

    // Проверяем, авторизован ли пользователь
    if (!$current_user || $current_user->ID === 0) {
        return __('Вы должны быть авторизованы для просмотра информации.', 'woocommerce');
    }

    // Получаем ID текущего пользователя
    $current_user_id = $current_user->ID;

    // Получаем роли и размеры выплат из настроек
    $blogger_role = get_option('blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
    $expert_role = get_option('expert_role', 'customer'); // Роль для экспертов (по умолчанию customer)
    $blogger_reward = get_option('blogger_reward', 450); // Выплата для блогеров (по умолчанию 450)
    $expert_reward = get_option('expert_reward', 600); // Выплата для экспертов (по умолчанию 600)

    // Проверяем роли пользователя и устанавливаем вознаграждение
    $reward_per_order = 0; // По умолчанию, если роль не указана
    if (in_array($expert_role, (array) $current_user->roles, true)) {
        $reward_per_order = $expert_reward; // Выплата для роли "Эксперт"
    } elseif (in_array($blogger_role, (array) $current_user->roles, true)) {
        $reward_per_order = $blogger_reward; // Выплата для роли "Блогер"
    } else {
        return __('У вас нет доступа к статистике.', 'woocommerce');
    }

    // Ищем купоны, связанные с текущим пользователем
    $args_coupons = [
        'post_type'      => 'shop_coupon',
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'   => '_ambassador_user',
                'value' => $current_user_id, // Связка с текущим пользователем
            ],
        ],
    ];

    $coupons = get_posts($args_coupons);
    if (empty($coupons)) {
        return __('У вас нет личного купона.', 'woocommerce');
    }

    // Составляем массив кодов купонов, связанных с пользователем
    $related_coupons = [];
    foreach ($coupons as $coupon) {
        $related_coupons[] = strtolower($coupon->post_title); // Приводим к нижнему регистру для сопоставления
    }

    // Поиск всех выполненных заказов со статусом "выполнен" и применёнными купонами
    $args_orders = [
        'post_type'      => 'shop_order',
        'post_status'    => 'wc-completed', // Только выполненные заказы
        'posts_per_page' => -1,
    ];

    $orders = get_posts($args_orders);

    // Подсчёт общего количества заказов и общей комиссии
    $order_count = 0;
    $total_reward = 0;

    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        $used_coupons = $order->get_coupon_codes(); // Получаем применённые купоны

        foreach ($used_coupons as $coupon_code) {
            if (in_array(strtolower($coupon_code), $related_coupons, true)) {
                // Если купон связан с текущим пользователем, увеличиваем счётчики
                $order_count++;
                $total_reward += $reward_per_order;
            }
        }
    }

    // Формируем вывод
    ob_start();

    echo '<div class="user-total-orders">';
    echo '<h3 class="user-statistics-title">'. __('За весь период', 'woocommerce') . '</h3>';
    echo '<p>' . sprintf(__('Всего заказов с вашим купоном: %d', 'woocommerce'), $order_count) . '</p>';
    echo '<p>' . sprintf(__('Общая сумма вознаграждения: %dруб', 'woocommerce'), $total_reward) . '</p>';
    echo '</div>';

    return ob_get_clean();
});
