<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Не шорткод. Добавляем возможность купону только для первой покупки
 */
add_filter('woocommerce_coupon_get_discount_amount', 'branam_apply_coupon_only_first_order_with_removal', 10, 5);
function branam_apply_coupon_only_first_order_with_removal($discount, $discounting_amount, $cart_item, $single, $coupon) {
    // Проверяем, активирован ли флаг "Только для первого заказа"
    if (get_post_meta($coupon->get_id(), 'branam_only_first_order', true) === 'yes') {
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
add_action('woocommerce_coupon_options', 'branam_add_coupon_option_first_order_checkbox');
function branam_add_coupon_option_first_order_checkbox() {
    // Добавляем nonce для сохранения чекбокса
    wp_nonce_field('branam_save_coupon_option_first_order', 'branam_save_coupon_option_first_order_nonce');
    woocommerce_wp_checkbox([
        'id' => 'branam_only_first_order',
        'label' => __('Только для первого заказа', 'brand-ambassador'),
        'description' => __('Применять купон только к первому заказу пользователя.', 'brand-ambassador'),
    ]);
}
// Сохраняем значение галочки "Только для первого заказа".
add_action('woocommerce_coupon_options_save', 'branam_save_coupon_option_first_order_checkbox');
function branam_save_coupon_option_first_order_checkbox($post_id) {
    // NONCE CHECK (WPCS: WordPress.Security.NonceVerification.Missing)
    if (
        ! isset( $_POST['branam_save_coupon_option_first_order_nonce'] ) ||
        ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['branam_save_coupon_option_first_order_nonce'] ) ), 'branam_save_coupon_option_first_order' )
    ) {
        return;
    }
    $only_first_order = isset($_POST['branam_only_first_order']) ? 'yes' : 'no';
    update_post_meta($post_id, 'branam_only_first_order', $only_first_order);
}

/**
 * Шорткод [user_coupon_name] наименование купона в личный кабинет амбассадора бренда.
 */
function branam_get_user_coupon_name() {
    $user_id = get_current_user_id(); // Получить ID текущего пользователя
    if (!$user_id) {
        return esc_html__('Пользователь не авторизован.', 'brand-ambassador');
    }

    // Получить ID связанного купона из метаполя _branam_user_coupon
    $coupon_id = get_user_meta($user_id, '_branam_user_coupon', true);
    if (!$coupon_id) {
        return esc_html__('Купон не найден.', 'brand-ambassador');
    }

    // Получить объект купона
    $coupon = get_post($coupon_id);
    if (!$coupon || $coupon->post_type !== 'shop_coupon') {
        return esc_html__('Купон не существует.', 'brand-ambassador');
    }

    // Вернуть название купона
    return esc_html($coupon->post_title);
}
add_shortcode('user_coupon_name', 'branam_get_user_coupon_name');

/**
 * Шорткод [user_related_orders] для вывода заказов в личном кабинете амбассадора бренда.
 */
add_shortcode('user_related_orders', function () {
    // Получаем текущего пользователя
    $current_user = wp_get_current_user();

    // Проверяем, авторизован ли пользователь
    if (!$current_user || $current_user->ID === 0) {
        return esc_html__('Вы должны быть авторизованы для просмотра ваших заказов.', 'brand-ambassador');
    }

    // Получаем ID текущего пользователя
    $current_user_id = $current_user->ID;

    // Получаем роли и размеры выплат из настроек
    $blogger_role = get_option('branam_blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
    $expert_role = get_option('branam_expert_role', 'subscriber'); // Роль для экспертов (по умолчанию subscriber)
    $blogger_reward = get_option('branam_blogger_reward', 450); // Выплата для блогеров (по умолчанию 450)
    $expert_reward = get_option('branam_expert_reward', 600); // Выплата для экспертов (по умолчанию 600)

    // Проверяем роли пользователя и устанавливаем вознаграждение
    $reward_per_order = 0; // По умолчанию
    if (in_array($expert_role, (array) $current_user->roles, true)) {
        $reward_per_order = $expert_reward; // Выплата для роли "Эксперт"
    } elseif (in_array($blogger_role, (array) $current_user->roles, true)) {
        $reward_per_order = $blogger_reward; // Выплата для роли "Блогер"
    } else {
        return esc_html__('У вас нет доступа к статистике.', 'brand-ambassador');
    }

    // Получаем id купона из user_meta (БЫСТРО!)
    $coupon_id = get_user_meta($current_user_id, '_branam_user_coupon', true);
    if (!$coupon_id) {
        return esc_html__('У вас нет связанных купонов.', 'brand-ambassador');
    }
    $coupon = get_post($coupon_id);
    if (!$coupon || $coupon->post_type !== 'shop_coupon') {
        return esc_html__('Купон не существует.', 'brand-ambassador');
    }
    $related_coupon_code = strtolower($coupon->post_title);

    // Получение параметров месяца и года из $_GET
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $month = isset($_GET['month']) ? absint($_GET['month']) : gmdate('m');
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $year = isset($_GET['year']) ? absint($_GET['year']) : gmdate('Y');

    // Проверка диапазона месяца
    if ($month < 1 || $month > 12) {
        return esc_html__('Неверный месяц.', 'brand-ambassador');
    }

    // Поиск заказов через WC_Order_Query (HPOS ONLY)
    $args_orders_completed = [
        'status'    => 'wc-completed',
        'limit'     => -1,
        'date_created' => $year . '-' . sprintf('%02d', $month) . '-01 00:00:00...' . $year . '-' . sprintf('%02d', $month) . '-31 23:59:59',
        'return'    => 'ids',
    ];
    $orders_completed_ids = (new WC_Order_Query($args_orders_completed))->get_orders();

    $args_orders_other_statuses = [
        'status'    => ['wc-delivery', 'wc-completed', 'wc-processing', 'wc-cancelled'],
        'limit'     => -1,
        'date_created' => $year . '-' . sprintf('%02d', $month) . '-01 00:00:00...' . $year . '-' . sprintf('%02d', $month) . '-31 23:59:59',
        'return'    => 'ids',
    ];
    $orders_other_statuses_ids = (new WC_Order_Query($args_orders_other_statuses))->get_orders();

    // Формируем вывод
    ob_start();

    echo '<div class="user-related-orders">';

    // Форма для выбора месяца и года
    echo '<form method="get" class="filter-form">';
    echo '<label for="month">' . esc_html__('Месяц:', 'brand-ambassador') . '</label>';
    echo '<select id="month" name="month" class="filter-select">';
    for ($m = 1; $m <= 12; $m++) {
        echo sprintf(
            '<option value="%d" %s>%s</option>',
            esc_attr($m),
            selected($month, $m, false),
            esc_html(date_i18n('F', mktime(0, 0, 0, $m, 10)))
        );
    }
    echo '</select>';

    echo '<label for="year">' . esc_html__('Год:', 'brand-ambassador') . '</label>';
    echo '<select id="year" name="year" class="filter-select">'; 
    for ($y = gmdate('Y') - 1; $y <= gmdate('Y'); $y++) {
        echo sprintf(
            '<option value="%d" %s>%d</option>',
            esc_attr($y),
            selected($year, $y, false),
            esc_html($y)
        );
    }
    echo '</select>';

    echo '<button type="submit" class="apply-buttons">' . esc_html__('Применить', 'brand-ambassador') . '</button>';
    echo '</form>';

    /* translators: %1$s: месяц, %2$d: год */
    echo '<h3 class="selected-month-year-title">' . esc_html(
        sprintf(
            /* translators: %1$s: месяц, %2$d: год */
            __('Заказы со статусом выполнен* за %1$s %2$d:', 'brand-ambassador'),
            esc_html(date_i18n('F', mktime(0, 0, 0, $month, 10))),
            esc_html($year)
        )
    ) . '</h3>';

    if (empty($orders_completed_ids)) {
        // Если заказов нет
        echo '<p>' . esc_html__('Нет выполненных заказов за выбранный период.', 'brand-ambassador') . '</p>';
    } else {
        // Если заказы найдены
        $order_count = 0;
        echo '<ul>';

        foreach ($orders_completed_ids as $order_id) {
            $order = wc_get_order($order_id);
            $used_coupons = $order->get_coupon_codes(); // Получаем применённые купоны
            $payout_status = get_post_meta($order->get_id(), '_branam_payout_status', true); // Получаем статус выплаты
            /* translators: %1$d: номер заказа, %2$s: дата, %3$s: купон, %4$s: статус выплаты */
            $payout_label = $payout_status === 'paid' ? esc_html__('Вознаграждение выплачено', 'brand-ambassador') : esc_html__('Нет выплаты', 'brand-ambassador');
            
            foreach ($used_coupons as $coupon_code) {
    if (strtolower($coupon_code) === $related_coupon_code) {
        $order_count++;
        echo '<li>';
echo esc_html(
    sprintf(
        // translators: %1$d: номер заказа, %2$s: дата, %3$s: купон, %4$s: статус выплаты
        __('№%1$d от %2$s c купоном: %3$s — %4$s', 'brand-ambassador'),
        (int) $order->get_id(),
        $order->get_date_created()->date_i18n(get_option('date_format')),
        $coupon_code,
        $payout_label
    )
);
echo '</li>';
    }
}
        }

        echo '</ul>';

        // Если не было заказов с применёнными купонами
        if ($order_count === 0) {
            echo '<p>' . esc_html__('Нет выполненных заказов за выбранный период.', 'brand-ambassador') . '</p>';
        } else {
            // Расчёт выплаты
            $total_reward = $order_count * $reward_per_order;

            /* translators: %1$s: месяц, %2$d: год, %3$d: кол-во заказов, %4$d: сумма за заказ, %5$d: итоговая сумма */
            echo '<p class="payout">' . esc_html(
                sprintf(
                    /* translators: %1$s: месяц, %2$d: год, %3$d: кол-во заказов, %4$d: сумма за заказ, %5$d: итоговая сумма */
                    __('Выплата за %1$s %2$d составит %3$d * %4$dруб = %5$dруб', 'brand-ambassador'),
                    date_i18n('F', mktime(0, 0, 0, $month, 10)),
                    $year,
                    $order_count,
                    $reward_per_order,
                    $total_reward
                )
            ) . '</p>';
        }
    }

    // Заголовок для заказов с другими статусами
    echo '<p class="other-statuses-title">' . esc_html__('Посмотреть заказы в статусе: обработка, доставка, отменён, выполнен', 'brand-ambassador') . '</p>';

    if (empty($orders_other_statuses_ids)) {
        // Если заказов с другими статусами нет
        echo '<p class="other-statuses-none">' . esc_html__('Нет заказов с другими статусами за выбранный период.', 'brand-ambassador') . '</p>';
    } else {
        // Если заказы с другими статусами найдены
        echo '<ul class="other-statuses-list">';

        foreach ($orders_other_statuses_ids as $order_id) {
            $order = wc_get_order($order_id);
            $used_coupons = $order->get_coupon_codes(); // Получаем применённые купоны

            foreach ($used_coupons as $coupon_code) {
                if (strtolower($coupon_code) === $related_coupon_code) {
                    echo '<li>';
                    echo esc_html(
                        sprintf(
                            // translators: %1$d: номер заказа, %2$s: дата, %3$s: статус
                            __('№%1$d от %2$s, Статус: %3$s', 'brand-ambassador'),
                            (int) $order->get_id(),
                            $order->get_date_created()->date_i18n(get_option('date_format')),
                            wc_get_order_status_name($order->get_status())
                        )
                    );
                    echo '</li>';
                }
            }
        }

        echo '</ul>';
    }
    // Добавляем финальную строчку с классом
    echo '<p class="reward-note">' . esc_html__('*Вознаграждение начисляется только за выполненные заказы.', 'brand-ambassador') . '</p>';

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
        return esc_html__('Вы должны быть авторизованы для просмотра информации.', 'brand-ambassador');
    }

    // Получаем ID текущего пользователя
    $current_user_id = $current_user->ID;

    // Получаем роли и размеры выплат из настроек
    $blogger_role = get_option('branam_blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
    $expert_role = get_option('branam_expert_role', 'subscriber'); // Роль для экспертов (по умолчанию subscriber)
    $blogger_reward = get_option('branam_blogger_reward', 450); // Выплата для блогеров (по умолчанию 450)
    $expert_reward = get_option('branam_expert_reward', 600); // Выплата для экспертов (по умолчанию 600)

    // Проверяем роли пользователя и устанавливаем вознаграждение
    $reward_per_order = 0; // По умолчанию, если роль не указана
    if (in_array($expert_role, (array) $current_user->roles, true)) {
        $reward_per_order = $expert_reward; // Выплата для роли "Эксперт"
    } elseif (in_array($blogger_role, (array) $current_user->roles, true)) {
        $reward_per_order = $blogger_reward; // Выплата для роли "Блогер"
    } else {
        return esc_html__('У вас нет доступа к статистике.', 'brand-ambassador');
    }

    // Получаем id купона из user_meta (БЫСТРО!)
    $coupon_id = get_user_meta($current_user_id, '_branam_user_coupon', true);
    if (!$coupon_id) {
        return esc_html__('У вас нет личного купона.', 'brand-ambassador');
    }
    $coupon = get_post($coupon_id);
    if (!$coupon || $coupon->post_type !== 'shop_coupon') {
        return esc_html__('Купон не существует.', 'brand-ambassador');
    }
    $related_coupon_code = strtolower($coupon->post_title);

    // Поиск всех выполненных заказов через WC_Order_Query (HPOS ONLY)
    $args_orders = [
        'status'    => 'wc-completed',
        'limit'     => -1,
        'return'    => 'ids',
    ];
    $order_ids = (new WC_Order_Query($args_orders))->get_orders();

    // Подсчёт общего количества заказов и общей комиссии
    $order_count = 0;
    $total_reward = 0;

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        $used_coupons = $order->get_coupon_codes(); // Получаем применённые купоны

        foreach ($used_coupons as $coupon_code) {
            if (strtolower($coupon_code) === $related_coupon_code) {
                // Если купон связан с текущим пользователем, увеличиваем счётчики
                $order_count++;
                $total_reward += $reward_per_order;
            }
        }
    }

    // Формируем вывод
    ob_start();

    echo '<div class="user-total-orders">';
    echo '<h3 class="user-statistics-title">'. esc_html__('За весь период', 'brand-ambassador') . '</h3>';
    /* translators: %d: число заказов */
    echo '<p>' . esc_html(
        sprintf(
            /* translators: %d: число заказов */
            __('Всего заказов с вашим купоном: %d', 'brand-ambassador'),
            $order_count
        )
    ) . '</p>';
    /* translators: %d: сумма вознаграждения */
    echo '<p>' . esc_html(
        sprintf(
            /* translators: %d: сумма вознаграждения */
            __('Общая сумма вознаграждения: %dруб', 'brand-ambassador'),
            $total_reward
        )
    ) . '</p>';
    echo '</div>';
    return ob_get_clean();
});

/**
 * Регистрируем шорткод [ambassador_bank_form] для формы банковских данных
 */
add_shortcode('ambassador_bank_form', 'branam_render_bank_data_form');

function branam_render_bank_data_form() {
    if (!is_user_logged_in()) {
        return '<p>' . esc_html__('Пожалуйста, войдите, чтобы заполнить банковские данные.', 'brand-ambassador') . '</p>';
    }

    $user_id = get_current_user_id();
    $encrypted_card_number = get_user_meta($user_id, 'branam_user_numbercartbank', true);
    $bank_name = get_user_meta($user_id, 'branam_user_bankname', true);

    $card_number = !empty($encrypted_card_number) ? AmbassadorSettingsPage::decrypt_data($encrypted_card_number) : '';
    $masked_card_number = !empty($card_number) ? str_repeat('*', strlen($card_number) - 4) . substr($card_number, -4) : '';

    ob_start();
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('save_bank_data', 'bank_data_nonce'); ?>
        <p>
            <label for="card_number" class="header-formbank"><?php esc_html_e('Номер банковской карты', 'brand-ambassador'); ?></label><br>
            <input type="text" name="card_number" id="card_number" class="input-bank" placeholder="0000 0000 0000 0000" value="<?php echo esc_attr($masked_card_number); ?>" maxlength="16" required />
        </p>
        <p>
            <label for="bank_name" class="header-formbank"><?php esc_html_e('Наименование банка', 'brand-ambassador'); ?></label><br>
            <input type="text" name="bank_name" id="bank_name" class="input-bank" placeholder="сбер" value="<?php echo esc_attr($bank_name); ?>" required />
        </p>
        <p>
            <button type="submit" name="submit_bank_data" class="button button-save"><?php esc_html_e('Сохранить', 'brand-ambassador'); ?></button>
        </p>
        <?php if (!empty($encrypted_card_number)) : ?>
            <p>
                <button type="submit" name="delete_bank_data" class="button deleted-bank"><?php esc_html_e('Удалить данные карты', 'brand-ambassador'); ?></button>
            </p>
        <?php endif; ?>
    </form>
    <?php
    return ob_get_clean();
}

add_action('init', 'branam_process_bank_data_form');
function branam_process_bank_data_form() {
    if (!is_user_logged_in()) {
        return;
    }

    $user_id = get_current_user_id();

    // Проверка прав пользователя
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        wp_die(esc_html__('Недостаточно прав для выполнения действия.', 'brand-ambassador'));
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_bank_data'])) {
        if (
            !isset($_POST['bank_data_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bank_data_nonce'])), 'save_bank_data')
        ) {
            wp_die(esc_html__('Ошибка безопасности. Попробуйте снова.', 'brand-ambassador'));
        }

        $card_number = '';
        $bank_name = '';
        if (isset($_POST['card_number'])) {
            $card_number = sanitize_text_field(wp_unslash($_POST['card_number']));
        }
        if (isset($_POST['bank_name'])) {
            $bank_name = sanitize_text_field(wp_unslash($_POST['bank_name']));
        }

        if (!preg_match('/^\d{16}$/', $card_number)) {
            wp_die(esc_html__('Номер карты должен содержать 16 цифр.', 'brand-ambassador'));
        }

        // Используем статический вызов функции encrypt_data
        $encrypted_card_number = AmbassadorSettingsPage::encrypt_data($card_number);
        update_user_meta($user_id, 'branam_user_numbercartbank', $encrypted_card_number);
        update_user_meta($user_id, 'branam_user_bankname', $bank_name);

        wp_safe_redirect(add_query_arg('success', '1', wp_get_referer()));
        exit;
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_bank_data'])) {
        if (
            !isset($_POST['bank_data_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bank_data_nonce'])), 'save_bank_data')
        ) {
            wp_die(esc_html__('Ошибка безопасности. Попробуйте снова.', 'brand-ambassador'));
        }

        // Удаляем мета-данные пользователя
        delete_user_meta($user_id, 'branam_user_numbercartbank');
        delete_user_meta($user_id, 'branam_user_bankname');

        wp_safe_redirect(add_query_arg('deleted', '1', wp_get_referer()));
        exit;
    }
}

/**
 * Шорткод [ambassador_card_number] для вывода последних 4 цифр банковской карты.
 */
add_shortcode('ambassador_card_number', 'branam_render_ambassador_card_number');

function branam_render_ambassador_card_number() {
    if (!is_user_logged_in()) {
        return ''; // Если пользователь не авторизован, ничего не выводим
    }

    $user_id = get_current_user_id();
    $encrypted_card_number = get_user_meta($user_id, 'branam_user_numbercartbank', true);

    if (empty($encrypted_card_number)) {
        return ''; // Если карта не добавлена, ничего не выводим
    }

    // Расшифровываем номер карты
    $card_number = AmbassadorSettingsPage::decrypt_data($encrypted_card_number);

    if (empty($card_number)) {
        return ''; // Если номер карты пустой после расшифровки, ничего не выводим
    }

    // Получаем последние 4 цифры карты
    $last_four_digits = substr($card_number, -4);

    /* translators: %s: последние 4 цифры карты */
    return '<div class="ambassador-card-number"><p>' . esc_html(sprintf(
        /* translators: %s: последние 4 цифры карты */
        __('**** **** **** %s', 'brand-ambassador'),
        $last_four_digits
    )) . '</p></div>';
}
