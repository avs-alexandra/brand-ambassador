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
            if (!wc_has_notice(__('Купон действует только на первый заказ.', 'brand-ambassador'), 'error')) {
                wc_add_notice(__('Купон действует только на первый заказ.', 'brand-ambassador'), 'error');
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
        'label' => __('Только для первого заказа', 'brand-ambassador'),
        'description' => __('Применять купон только к первому заказу пользователя.', 'brand-ambassador'),
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
        return __('Пользователь не авторизован.', 'brand-ambassador');
    }

    // Получить ID связанного купона из метаполя _user_coupon
    $coupon_id = get_user_meta($user_id, '_user_coupon', true);
    if (!$coupon_id) {
        return __('Купон не найден.', 'brand-ambassador');
    }

    // Получить объект купона
    $coupon = get_post($coupon_id);
    if (!$coupon || $coupon->post_type !== 'shop_coupon') {
        return __('Купон не существует.', 'brand-ambassador');
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
        return __('Вы должны быть авторизованы для просмотра ваших заказов.', 'brand-ambassador');
    }

    // Получаем ID текущего пользователя
    $current_user_id = $current_user->ID;

    // Получаем роли и размеры выплат из настроек
    $blogger_role = get_option('blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
    $expert_role = get_option('expert_role', 'subscriber'); // Роль для экспертов (по умолчанию subscriber)
    $blogger_reward = get_option('blogger_reward', 450); // Выплата для блогеров (по умолчанию 450)
    $expert_reward = get_option('expert_reward', 600); // Выплата для экспертов (по умолчанию 600)

    // Проверяем роли пользователя и устанавливаем вознаграждение
    $reward_per_order = 0; // По умолчанию
    if (in_array($expert_role, (array) $current_user->roles, true)) {
        $reward_per_order = $expert_reward; // Выплата для роли "Эксперт"
    } elseif (in_array($blogger_role, (array) $current_user->roles, true)) {
        $reward_per_order = $blogger_reward; // Выплата для роли "Блогер"
    } else {
        return __('У вас нет доступа к статистике.', 'brand-ambassador');
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
        return __('У вас нет связанных купонов.', 'brand-ambassador');
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
        return __('Неверный месяц.', 'brand-ambassador');
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
    echo '<label for="month">' . __('Месяц:', 'brand-ambassador') . '</label>';
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

    echo '<label for="year">' . __('Год:', 'brand-ambassador') . '</label>';
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

    echo '<button type="submit" class="apply-buttons">' . __('Применить', 'brand-ambassador') . '</button>';
    echo '</form>';

    // Заголовок с выбранным месяцем и годом
    echo '<h3 class="selected-month-year-title">' . sprintf(__('Заказы со статусом выполнен* за %s %d:', 'brand-ambassador'), date_i18n('F', mktime(0, 0, 0, $month, 10)), $year) . '</h3>';

    if (empty($orders_completed)) {
        // Если заказов нет
        echo '<p>' . __('Нет выполненных заказов за выбранный период.', 'brand-ambassador') . '</p>';
    } else {
        // Если заказы найдены
        $order_count = 0;
        echo '<ul>';

        foreach ($orders_completed as $order_post) {
            $order = wc_get_order($order_post->ID);
            $used_coupons = $order->get_coupon_codes(); // Получаем применённые купоны
            $payout_status = get_post_meta($order->get_id(), '_payout_status', true); // Получаем статус выплаты
            $payout_label = $payout_status === 'paid' ? __('Вознаграждение выплачено', 'brand-ambassador') : __('Нет выплаты', 'brand-ambassador');
            
            foreach ($used_coupons as $coupon_code) {
                if (in_array(strtolower($coupon_code), $related_coupons, true)) {
                    // Если купон связан с текущим пользователем, добавляем заказ в вывод
                    $order_count++;
                    echo '<li>';
                    echo sprintf(
                    __('№%d от %s c купоном: %s — %s', 'brand-ambassador'),
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
            echo '<p>' . __('Нет выполненных заказов за выбранный период.', 'brand-ambassador') . '</p>';
        } else {
            // Расчёт выплаты
            $total_reward = $order_count * $reward_per_order;

            // Вывод информации о выплате
            echo '<p class="payout">' . sprintf(
                __('Выплата за %s %d составит %d * %dруб = %dруб', 'brand-ambassador'),
                date_i18n('F', mktime(0, 0, 0, $month, 10)),
                $year,
                $order_count,
                $reward_per_order,
                $total_reward
            ) . '</p>';
        }
    }

    // Заголовок для заказов с другими статусами
    echo '<p class="other-statuses-title">' . __('Посмотреть заказы в статусе: обработка, доставка, отменён, выполнен', 'brand-ambassador') . '</p>';

    if (empty($orders_other_statuses)) {
        // Если заказов с другими статусами нет
        echo '<p class="other-statuses-none">' . __('Нет заказов с другими статусами за выбранный период.', 'brand-ambassador') . '</p>';
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
                        __('№%d от %s, Статус: %s', 'brand-ambassador'),
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
    echo '<p class="reward-note">' . __('*Вознаграждение начисляется только за выполненные заказы.', 'brand-ambassador') . '</p>';

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
        return __('Вы должны быть авторизованы для просмотра информации.', 'brand-ambassador');
    }

    // Получаем ID текущего пользователя
    $current_user_id = $current_user->ID;

    // Получаем роли и размеры выплат из настроек
    $blogger_role = get_option('blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
    $expert_role = get_option('expert_role', 'subscriber'); // Роль для экспертов (по умолчанию subscriber)
    $blogger_reward = get_option('blogger_reward', 450); // Выплата для блогеров (по умолчанию 450)
    $expert_reward = get_option('expert_reward', 600); // Выплата для экспертов (по умолчанию 600)

    // Проверяем роли пользователя и устанавливаем вознаграждение
    $reward_per_order = 0; // По умолчанию, если роль не указана
    if (in_array($expert_role, (array) $current_user->roles, true)) {
        $reward_per_order = $expert_reward; // Выплата для роли "Эксперт"
    } elseif (in_array($blogger_role, (array) $current_user->roles, true)) {
        $reward_per_order = $blogger_reward; // Выплата для роли "Блогер"
    } else {
        return __('У вас нет доступа к статистике.', 'brand-ambassador');
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
        return __('У вас нет личного купона.', 'brand-ambassador');
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
    echo '<h3 class="user-statistics-title">'. __('За весь период', 'brand-ambassador') . '</h3>';
    echo '<p>' . sprintf(__('Всего заказов с вашим купоном: %d', 'brand-ambassador'), $order_count) . '</p>';
    echo '<p>' . sprintf(__('Общая сумма вознаграждения: %dруб', 'brand-ambassador'), $total_reward) . '</p>';
    echo '</div>';

    return ob_get_clean();
});



/**
 * Регистрируем шорткод [ambassador_bank_form] для формы банковских данных
 */
add_shortcode('ambassador_bank_form', 'render_bank_data_form');

function render_bank_data_form() {
    if (!is_user_logged_in()) {
        return '<p>' . __('Пожалуйста, войдите, чтобы заполнить банковские данные.', 'brand-ambassador') . '</p>';
    }

    $user_id = get_current_user_id();
    $encrypted_card_number = get_user_meta($user_id, 'user_numbercartbank', true);
    $bank_name = get_user_meta($user_id, 'user_bankname', true);

    $card_number = !empty($encrypted_card_number) ? AmbassadorSettingsPage::decrypt_data($encrypted_card_number) : '';
    $masked_card_number = !empty($card_number) ? str_repeat('*', strlen($card_number) - 4) . substr($card_number, -4) : '';

    ob_start();
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('save_bank_data', 'bank_data_nonce'); ?>
        <p>
            <label for="card_number" class="header-formbank"><?php _e('Номер банковской карты', 'brand-ambassador'); ?></label><br>
            <input type="text" name="card_number" id="card_number" class="input-bank" placeholder="0000 0000 0000 0000" value="<?php echo esc_attr($masked_card_number); ?>" maxlength="16" required />
        </p>
        <p>
            <label for="bank_name" class="header-formbank"><?php _e('Наименование банка', 'brand-ambassador'); ?></label><br>
            <input type="text" name="bank_name" id="bank_name" class="input-bank" placeholder="сбер" value="<?php echo esc_attr($bank_name); ?>" required />
        </p>
        <p>
            <button type="submit" name="submit_bank_data" class="button button-save"><?php _e('Сохранить', 'brand-ambassador'); ?></button>
        </p>
    </form>
    <?php
    return ob_get_clean();
}

add_action('init', 'process_bank_data_form');
function process_bank_data_form() {
    if (!is_user_logged_in() || !isset($_POST['submit_bank_data'])) {
        return;
    }

    if (!isset($_POST['bank_data_nonce']) || !wp_verify_nonce($_POST['bank_data_nonce'], 'save_bank_data')) {
        wp_die(__('Ошибка безопасности. Попробуйте снова.', 'brand-ambassador'));
    }

    $user_id = get_current_user_id();
    $card_number = sanitize_text_field($_POST['card_number']);
    $bank_name = sanitize_text_field($_POST['bank_name']);

    if (!preg_match('/^\d{16}$/', $card_number)) {
        wp_die(__('Номер карты должен содержать 16 цифр.', 'brand-ambassador'));
    }

    // Используем статический вызов функции encrypt_data
    $encrypted_card_number = AmbassadorSettingsPage::encrypt_data($card_number);
    update_user_meta($user_id, 'user_numbercartbank', $encrypted_card_number);
    update_user_meta($user_id, 'user_bankname', $bank_name);

    wp_redirect(add_query_arg('success', '1', wp_get_referer()));
    exit;
}
