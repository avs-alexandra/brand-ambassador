<?php
if (!defined('ABSPATH')) exit; // Запрет прямого доступа

class AmbassadorCouponProgram {
    public function __construct() {
        // Функционал амбассадора
        add_action('woocommerce_coupon_options', [$this, 'add_user_field_to_coupon']);
        add_action('woocommerce_coupon_options_save', [$this, 'save_user_field_to_coupon']);
        add_action('show_user_profile', [$this, 'show_user_coupon']);
        add_action('edit_user_profile', [$this, 'show_user_coupon']);

        // Подключение стилей и скриптов для Select2
        add_action('admin_enqueue_scripts', [$this, 'enqueue_select2_scripts']);

        // AJAX обработчики
        add_action('wp_ajax_search_users_by_email', [$this, 'search_users_by_email']);
        add_action('wp_ajax_unlink_user_from_coupon', [$this, 'unlink_user_from_coupon']);

        // Добавление нового столбца в таблицу купонов
        add_filter('manage_edit-shop_coupon_columns', [$this, 'add_user_column_to_coupon_table']);
        add_action('manage_shop_coupon_posts_custom_column', [$this, 'render_user_column_in_coupon_table'], 10, 2);

        // Отображение связанного пользователя рядом с купоном в заказе
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'display_associated_user_in_order']);

        //Добавить метаполя пользователю номер банковской карты и наименование банка   
        add_action('personal_options_update', [$this, 'save_user_meta_fields']);
        add_action('edit_user_profile_update', [$this, 'save_user_meta_fields']);

        //При удалении купона отвязывать Амбассадора
        add_action('before_delete_post', [$this, 'unlink_user_before_coupon_delete']);

        // Запретить пользователю применять свой купон
        add_filter('woocommerce_coupon_is_valid', [$this, 'restrict_user_from_using_own_coupon'], 10, 3);
    }

    /**
     * Добавление нового столбца "Амбассадор" в таблицу купонов
     */
    public function add_user_column_to_coupon_table($columns) {
        $columns['associated_user'] = esc_html__('Амбассадор', 'brand-ambassador');
        return $columns;
    }

    /**
     * Отображение данных в столбце "Амбассадор" в таблице купонов
     */
    public function render_user_column_in_coupon_table($column, $post_id) {
        if ($column === 'associated_user') {
            $user_id = get_post_meta($post_id, '_branam_ambassador_user', true);
            if ($user_id) {
                $user = get_userdata($user_id);
                if ($user) {
                    echo '<a href="' . esc_url(get_edit_user_link($user_id)) . '">' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</a>';
                } else {
                    echo esc_html__('Н/Д', 'brand-ambassador'); // Если пользователь не найден
                }
            }
            // Если $user_id пуст, то ничего не выводится
        }
    }

    /**
     * Добавление поля пользователя в настройках купона
     */
    public function add_user_field_to_coupon() {
        global $post;
        $ambassador_user_id = get_post_meta($post->ID, '_branam_ambassador_user', true);
        $user_email_display = '';

        if ($ambassador_user_id) {
            $user = get_userdata($ambassador_user_id);
            if ($user) {
                $user_email_display = $user->user_email . ' (' . $user->display_name . ')';
            }
        }
        ?>
        <div class="options_group">
            <p class="form-field">
                <label for="ambassador_user"><?php esc_html_e('Амбассадор (по email)', 'brand-ambassador'); ?></label>
                <select id="ambassador_user" name="ambassador_user" class="wc-user-search" style="width: 50%;" data-placeholder="<?php esc_attr_e('Начните вводить email', 'brand-ambassador'); ?>">
                    <?php if ($ambassador_user_id && $user_email_display): ?>
                        <option value="<?php echo esc_attr($ambassador_user_id); ?>" selected="selected">
                            <?php echo esc_html($user_email_display); ?>
                        </option>
                    <?php endif; ?>
                </select>
                <?php if ($ambassador_user_id): ?>
                    <p>
                        <?php echo wp_kses_post(
                         sprintf(
                            __('Амбассадор: <a href="%s" target="_blank">%s</a>', 'brand-ambassador'),
                            esc_url(get_edit_user_link($ambassador_user_id)),
                            esc_html($user_email_display)
                            )
                        ); ?>
                    </p>
                    <button type="button" class="button unlink-user-button" data-coupon-id="<?php echo esc_attr($post->ID); ?>">
                        <?php esc_html_e('Отвязать', 'brand-ambassador'); ?>
                    </button>
                <?php endif; ?>
            </p>
        </div>
        <script>
            jQuery(document).ready(function($) {
                // Инициализация Select2
                $('#ambassador_user').select2({
                    ajax: {
                        url: ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function(params) {
                            return {
                                action: 'search_users_by_email',
                                term: params.term,
                                nonce: '<?php echo wp_create_nonce('search_users_by_email'); ?>'
                            };
                        },
                        processResults: function(data) {
                            return {
                                results: data.results
                            };
                        },
                        cache: true
                    },
                    minimumInputLength: 2
                });

                // Кнопка "Отвязать пользователя"
                $('.unlink-user-button').on('click', function() {
                    var couponId = $(this).data('coupon-id');
                    $.post(ajaxurl, {
                        action: 'unlink_user_from_coupon',
                        coupon_id: couponId,
                        nonce: '<?php echo wp_create_nonce('unlink_user_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert('<?php esc_html_e('Пользователь отвязан от купона.', 'brand-ambassador'); ?>');
                            location.reload();
                        } else {
                            alert('<?php esc_html_e('Ошибка при отвязке пользователя.', 'brand-ambassador'); ?>');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Сохранение пользователя для купона
     */
    public function save_user_field_to_coupon($post_id) {
        if (isset($_POST['ambassador_user'])) {
            $new_user_id = sanitize_text_field($_POST['ambassador_user']);
            $old_user_id = get_post_meta($post_id, '_branam_ambassador_user', true);

            // Удаляем купон из старого пользователя
            if ($old_user_id && $old_user_id !== $new_user_id) {
                delete_user_meta($old_user_id, '_branam_user_coupon');
            }

            // Привязываем купон к новому пользователю
            update_post_meta($post_id, '_branam_ambassador_user', $new_user_id);

            if ($new_user_id) {
                update_user_meta($new_user_id, '_branam_user_coupon', $post_id);
            }
        }
    }

    /**
     * Отображение связанного купона и дополнительных метаполей в профиле пользователя
     */
    public function show_user_coupon($user) {
        // Получаем роли из настроек
        $blogger_role = get_option('branam_blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
        $expert_role = get_option('branam_expert_role', 'subscriber'); // Роль для экспертов (по умолчанию subscriber)

        // Проверяем, есть ли у пользователя нужные роли
        if (in_array($expert_role, (array) $user->roles) || in_array($blogger_role, (array) $user->roles)) {
            // Устанавливаем заголовок в зависимости от роли
            $title = '';
            if (in_array($expert_role, (array) $user->roles)) {
                $title = esc_html__('Программа амбассадор бренда для экспертов', 'brand-ambassador');
            } elseif (in_array($blogger_role, (array) $user->roles)) {
                $title = esc_html__('Программа амбассадор бренда для блогеров', 'brand-ambassador');
            }

            // Вывод заголовка
            echo '<h2>' . esc_html($title) . '</h2>';

            // Получаем связанный купон
            $coupon_id = get_user_meta($user->ID, '_branam_user_coupon', true);
            if ($coupon_id) {
                $coupon = get_post($coupon_id);
                if ($coupon) {
                    echo '<h4><strong>' . esc_html__('Купон:', 'brand-ambassador') . '</strong> ';
                    echo '<a href="' . esc_url(get_edit_post_link($coupon_id)) . '">' . esc_html($coupon->post_title) . '</a></h4>';
                }
            } else {
                echo '<p>' . esc_html__('Купон не добавлен', 'brand-ambassador') . '</p>';
            }

            // Метаполя для номера банковской карты и банка
            $encrypted_numbercartbank = get_user_meta($user->ID, 'branam_user_numbercartbank', true);
            $user_bankname = get_user_meta($user->ID, 'branam_user_bankname', true);

            // Расшифровка номера карты и отображение только последних 4 цифр
            $user_numbercartbank = !empty($encrypted_numbercartbank) ? AmbassadorSettingsPage::decrypt_data($encrypted_numbercartbank) : '';
            $masked_numbercartbank = !empty($user_numbercartbank) ? str_repeat('*', strlen($user_numbercartbank) - 4) . substr($user_numbercartbank, -4) : '';

            echo '<h3>' . esc_html__('Банковские реквизиты', 'brand-ambassador') . '</h3>';
            ?>
            <table class="form-table">
                <tr>
                    <th><label for="branam_user_numbercartbank"><?php esc_html_e('Номер банковской карты', 'brand-ambassador'); ?></label></th>
                    <td>
                        <input type="text" name="branam_user_numbercartbank" id="branam_user_numbercartbank" value="<?php echo esc_attr($masked_numbercartbank); ?>" class="regular-text" readonly />
                    </td>
                </tr>
                <tr>
                    <th><label for="branam_user_bankname"><?php esc_html_e('Наименование банка', 'brand-ambassador'); ?></label></th>
                    <td>
                        <input type="text" name="branam_user_bankname" id="branam_user_bankname" value="<?php echo esc_attr($user_bankname); ?>" class="regular-text" readonly />
                    </td>
                </tr>
            </table>
            <?php
        }
        // Ничего не делаем, если у пользователя нет нужной роли
    }

    /**
     * Сохранение метаполей для банковских реквизитов
     */
    public function save_user_meta_fields($user_id) {
        // Проверка nonce для профиля пользователя (если форма добавляет nonce)
        if (
            !isset($_POST['_wpnonce']) ||
            !check_admin_referer('update-user_' . $user_id)
        ) {
            // Можно использовать wp_die, но в профиле WordPress WordPress сам покажет ошибку nonce
            return;
        }

        // Проверка прав пользователя
        if ( ! current_user_can('edit_user', $user_id) ) {
            return;
        }

        // Получаем роли из настроек
        $blogger_role = get_option('branam_blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
        $expert_role = get_option('branam_expert_role', 'subscriber'); // Роль для экспертов (по умолчанию subscriber)

        // Проверяем, есть ли у пользователя нужные роли
        $user = get_userdata($user_id);
        if (in_array($expert_role, (array) $user->roles) || in_array($blogger_role, (array) $user->roles)) {
            // Сохраняем Номер банковской карты
            if (isset($_POST['branam_user_numbercartbank'])) {
                update_user_meta($user_id, 'branam_user_numbercartbank', sanitize_text_field($_POST['branam_user_numbercartbank']));
            }

            // Сохраняем Наименование банка
            if (isset($_POST['branam_user_bankname'])) {
                update_user_meta($user_id, 'branam_user_bankname', sanitize_text_field($_POST['branam_user_bankname']));
            }
        }
    }

    /**
     * Подключение стилей и скриптов для Select2
     */
    public function enqueue_select2_scripts($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        $post_type = get_post_type();
        if ($post_type !== 'shop_coupon') {
            return;
        }

        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js', ['jquery'], null, true);
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css');
    }

    /**
     * AJAX: Поиск пользователей по email с фильтром по ролям и исключением пользователей с уже связанным купоном
     */
    public function search_users_by_email() {
        check_ajax_referer('search_users_by_email', 'nonce');

        // Проверка прав пользователя — только для админа или менеджера магазина
        if ( ! current_user_can( 'edit_users' ) ) {
            wp_send_json_error(['message' => esc_html__('Недостаточно прав для выполнения действия.', 'brand-ambassador')]);
        }

        $term = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';

        if (empty($term)) {
            wp_send_json_error(['message' => esc_html__('Введите email для поиска.', 'brand-ambassador')]);
        }

        // Получаем роли из настроек
        $blogger_role = get_option('branam_blogger_role', 'customer'); // Роль для блогеров (по умолчанию customer)
        $expert_role = get_option('branam_expert_role', 'subscriber'); // Роль для экспертов (по умолчанию subscriber)

        // Указываем роли, которые нужно фильтровать
        $allowed_roles = [$blogger_role, $expert_role];

        // Поиск пользователей с указанными ролями и исключение пользователей с уже связанным купоном
        $users = get_users([
            'search'         => '*' . esc_attr($term) . '*',
            'search_columns' => ['user_email', 'display_name'],
            'number'         => 10,
            'role__in'       => $allowed_roles, // Фильтр по ролям из настроек
            'meta_query'     => [ // Исключить пользователей с метаполем _branam_user_coupon
                [
                    'key'     => '_branam_user_coupon',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        $results = [];
        foreach ($users as $user) {
            $results[] = [
                'id'   => $user->ID,
                'text' => $user->user_email . ' (' . $user->display_name . ')',
            ];
        }

        wp_send_json(['results' => $results]);
    }

    /**
     * AJAX: Отвязка пользователя от купона
     */
    public function unlink_user_from_coupon() {
        check_ajax_referer('unlink_user_nonce', 'nonce');

        $coupon_id = isset($_POST['coupon_id']) ? intval($_POST['coupon_id']) : 0;

        if (!$coupon_id || !current_user_can('edit_post', $coupon_id)) {
            wp_send_json_error();
        }

        $ambassador_user_id = get_post_meta($coupon_id, '_branam_ambassador_user', true);
        if ($ambassador_user_id) {
            delete_user_meta($ambassador_user_id, '_branam_user_coupon');
        }

        delete_post_meta($coupon_id, '_branam_ambassador_user');
        wp_send_json_success();
    }

    /**
     * Запретить пользователю применять купон, с которым он связан
     */
    public function restrict_user_from_using_own_coupon($valid, $coupon, $discount) {
        $current_user_id = get_current_user_id();

        // Если пользователь не авторизован, пропустить проверку
        if (!$current_user_id) {
            return $valid;
        }

        // Получить ID пользователя, связанного с купоном
        $associated_user_id = get_post_meta($coupon->get_id(), '_branam_ambassador_user', true);

        // Проверить, связан ли текущий пользователь с купоном
        if ($current_user_id == $associated_user_id) {
            wc_add_notice(
                esc_html__('Вы не можете применить собственный купон.', 'brand-ambassador'),
                'error'
            );
            return false; // Купон недействителен
        }

        return $valid;
    }

    /**
     * Отвязывает амбассадора от купона перед его удалением.
     */
    public function unlink_user_before_coupon_delete($post_id) {
        // Проверяем, является ли удаляемая запись купоном
        if (get_post_type($post_id) === 'shop_coupon') {
            // Получаем ID пользователя, связанного с купоном
            $ambassador_user_id = get_post_meta($post_id, '_branam_ambassador_user', true);

            if ($ambassador_user_id) {
                // Удаляем связь между пользователем и купоном
                delete_user_meta($ambassador_user_id, '_branam_user_coupon');
            }

            // Удаляем метаполе, связанное с купоном
            delete_post_meta($post_id, '_branam_ambassador_user');
        }
    }

    /**
     * Отображение связанного пользователя рядом с купоном в интерфейсе редактирования заказа
     */
    public function display_associated_user_in_order($order) {
        // Получаем все применённые купоны в заказе
        $used_coupons = $order->get_used_coupons();

        if (!empty($used_coupons)) {
            echo '<div class="used-coupons">';
            echo '<p>___</p>';
            echo '<h4>' . esc_html__('Программа амбассадор бренда:', 'brand-ambassador') . '</h4>';

            foreach ($used_coupons as $coupon_code) {
                // Получаем объект купона
                $coupon = new WC_Coupon($coupon_code);

                // Получаем ID пользователя, связанного с купоном
                $associated_user_id = get_post_meta($coupon->get_id(), '_branam_ambassador_user', true);

                if ($associated_user_id) {
                    $user = get_userdata($associated_user_id);

                    if ($user) {
                        echo '<p><strong>' . esc_html__('Купон:', 'brand-ambassador') . '</strong> ' . esc_html($coupon_code) . '</p>';
                        echo '<p><strong>' . esc_html__('Амбассадор:', 'brand-ambassador') . '</strong> ' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</p>';
                    } else {
                        echo '<p><strong>' . esc_html__('Купон:', 'brand-ambassador') . '</strong> ' . esc_html($coupon_code) . '</p>';
                        echo '<p><strong>' . esc_html__('Амбассадор:', 'brand-ambassador') . '</strong> ' . esc_html__('Н/Д', 'brand-ambassador') . '</p>';
                    }
                } else {
                    echo '<p><strong>' . esc_html__('Купон:', 'brand-ambassador') . '</strong> ' . esc_html($coupon_code) . '</p>';
                    echo '<p>' . esc_html__('Н/Д.', 'brand-ambassador') . '</p>';
                }
            }

            echo '</div>';
        }
    }
}
