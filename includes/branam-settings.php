<?php
if (!defined('ABSPATH')) exit; // Запрет прямого доступа

class Branam_Settings_Page {
    public function __construct() {
        // Добавляем страницу настроек в меню "Маркетинг"
        add_action('admin_menu', [$this, 'add_settings_page']);
        // Регистрируем настройки
        add_action('admin_init', [$this, 'register_settings']);
        // Проверка на совпадение ролей
        add_action('admin_notices', [$this, 'check_duplicate_roles']);
    }

    // Генерация и сохранение ключа шифрования
    public static function generate_encryption_key() {
        if (!get_option('branam_encryption_key')) {
            $key = bin2hex(random_bytes(32)); // Генерация 256-битного ключа
            add_option('branam_encryption_key', $key);
        }
    }

    // Получение ключа шифрования
    public static function get_encryption_key() {
        $key = get_option('branam_encryption_key');
        if (!$key) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            wp_die(esc_html__('Ключ шифрования не найден. Пожалуйста, активируйте плагин заново.', 'brand-ambassador'));
        }
        return $key;
    }

    // Функция шифрования данных
    public static function encrypt_data($data) {
        $key = self::get_encryption_key(); // Получаем ключ шифрования
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc')); // Генерация IV
        $encrypted_data = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($encrypted_data . '::' . $iv); // Сохранение IV вместе с данными
    }

    // Функция дешифрования данных
   public static function decrypt_data($encrypted_data) {
    $key = self::get_encryption_key();
    $decoded = base64_decode($encrypted_data);
    $parts = explode('::', $decoded, 2);
    if (count($parts) !== 2) {
        return false; // Неправильный формат
    }
    list($encrypted, $iv) = $parts;
    if (empty($iv)) {
        return false; // Нет IV — дешифровать нельзя
    }
    return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
}

    /**
     * Добавляем страницу настроек в меню "Маркетинг"
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce-marketing', // Родительская страница (WooCommerce > Маркетинг)
            esc_html__('Настройки Амбассадора', 'brand-ambassador'), // Заголовок страницы
            esc_html__('Настройки Амбассадора', 'brand-ambassador'), // Название в меню
            'manage_options', // Требуемые права
            'branam-settings', // Слаг страницы
            [$this, 'render_settings_page'] // Callback для рендеринга страницы
        );
    }

    /**
     * Регистрируем настройки
     */
    public function register_settings() {
        register_setting('branam_settings', 'branam_blogger_role', [
            'sanitize_callback' => [$this, 'validate_role'],
        ]);
        register_setting('branam_settings', 'branam_expert_role', [
            'sanitize_callback' => [$this, 'validate_role'],
        ]);
        register_setting('branam_settings', 'branam_blogger_reward', [
            'sanitize_callback' => 'absint', // Санитизация для чисел
        ]);
        register_setting('branam_settings', 'branam_expert_reward', [
            'sanitize_callback' => 'absint', // Санитизация для чисел
        ]);
        register_setting('branam_settings', 'branam_delete_meta', [
            'sanitize_callback' => 'rest_sanitize_boolean', // Для чекбокса
        ]);
        register_setting('branam_settings', 'branam_email_subject', [
            'sanitize_callback' => 'sanitize_text_field', // Санитизация для текста
        ]);
        register_setting('branam_settings', 'branam_email_template', [
            'sanitize_callback' => [self::class, 'sanitize_email_template'],
        ]);
        register_setting('branam_settings', 'branam_email_font', [
            'sanitize_callback' => 'sanitize_text_field', // Санитизация для текста
        ]);
    }

    /**
     * Кастомная функция валидации для ролей
     */
    public function validate_role($role) {
        global $wp_roles;
        $roles = array_keys($wp_roles->roles); // Получаем все доступные роли
        return in_array($role, $roles, true) ? $role : ''; // Проверяем, есть ли роль в списке
    }

    /**
     * Кастомная функция санитизации шаблона письма
     */
    public static function sanitize_email_template($input) {
        return wp_kses_post($input); // Разрешает только безопасные HTML-теги
    }

    /**
     * Проверка на совпадение ролей и сброс к значениям по умолчанию
     */
    public function check_duplicate_roles() {
        // Получаем текущие значения ролей из настроек
        $blogger_role = get_option('branam_blogger_role');
        $expert_role = get_option('branam_expert_role');

        // Проверяем, совпадают ли роли
        if (!empty($blogger_role) && !empty($expert_role) && $blogger_role === $expert_role) {
            // Сбрасываем роли к значениям по умолчанию
            update_option('branam_blogger_role', 'customer'); // По умолчанию 'customer'
            update_option('branam_expert_role', 'subscriber'); // По умолчанию 'subscriber'

            // Выводим уведомление об ошибке и сбросе
            echo '<div class="notice notice-error">';
            echo '<p>' . esc_html__('Необходимо выбрать разные роли для Блогера и Эксперта! Роли были сброшены к значениям по умолчанию.', 'brand-ambassador') . '</p>';
            echo '</div>';
        }
    }

    /**
     * Рендеринг страницы настроек
     */
    public function render_settings_page() {
        global $wp_roles;
        $roles = $wp_roles->roles;
        $blogger_role = get_option('branam_blogger_role', 'customer');
        $expert_role = get_option('branam_expert_role', 'subscriber');
        $blogger_reward = get_option('branam_blogger_reward', 450);
        $expert_reward = get_option('branam_expert_reward', 600);
        $delete_meta = get_option('branam_delete_meta', 0);
        $email_subject = get_option('branam_email_subject', 'Ваш купон был использован!'); // Значение по умолчанию для темы письма
        $email_template = get_option('branam_email_template', 'Здравствуйте, [ambassador]! Ваш купон "[coupon]" был использован для заказа №[order_id].');
        $email_font = get_option('branam_email_font', 'Arial, sans-serif'); // Значение по умолчанию
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Настройки Амбассадора бренда', 'brand-ambassador'); ?></h1>
            <!-- ВАЖНО: Информация о HPOS -->
          <div style="margin-bottom:10px;">
           <strong style="color:#788c1c; font-size: 14px;">
            <?php esc_html_e('Внимание! Этот плагин работает только с WooCommerce High-Performance Order Storage (HPOS).', 'brand-ambassador'); ?>
            </strong>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields('branam_settings'); ?>
                <?php do_settings_sections('branam_settings'); ?>
                <table class="form-table">
                    <!-- Добавлено уведомление -->
                    <tr>
                        <td colspan="2">
                            <p style="color: #000;">
                            <?php
                                echo wp_kses_post(__('Шорткоды:<br>[branam_user_coupon_name] - Купон Амбассадора <br>[branam_user_related_orders] - Статистика заказов Амбассадора <br>[branam_user_total_orders] - Общая статистика Амбассадора <br>[branam_ambassador_bank_form] - Форма ввода банковской карты Амбассадора <br>[branam_ambassador_card_number] - Отобразить последние 4 цифры номера карты', 'brand-ambassador'));
                            ?>
                            </p>
                            <p style="color: #646970;">
                                <?php esc_html_e('Выберите разные роли для уровней Блогер и Эксперт для корректного расчёта выплат. (Cоздать новые роли можно с помощью плагина User Role Editor)', 'brand-ambassador'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Роль для Блогера', 'brand-ambassador'); ?></th>
                        <td>
                            <select name="branam_blogger_role">
                                <?php foreach ($roles as $role_key => $role): ?>
                                    <option value="<?php echo esc_attr($role_key); ?>" <?php selected($blogger_role, $role_key); ?>>
                                        <?php echo esc_html($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Роль для Эксперта', 'brand-ambassador'); ?></th>
                        <td>
                            <select name="branam_expert_role">
                                <?php foreach ($roles as $role_key => $role): ?>
                                    <option value="<?php echo esc_attr($role_key); ?>" <?php selected($expert_role, $role_key); ?>>
                                        <?php echo esc_html($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Выплата за заказ для Блогера (руб)', 'brand-ambassador'); ?></th>
                        <td>
                            <input type="number" name="branam_blogger_reward" value="<?php echo esc_attr($blogger_reward); ?>" min="0" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Выплата за заказ для Эксперта (руб)', 'brand-ambassador'); ?></th>
                        <td>
                            <input type="number" name="branam_expert_reward" value="<?php echo esc_attr($expert_reward); ?>" min="0" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Тема письма', 'brand-ambassador'); ?></th>
                        <td>
                            <input
                                type="text"
                                name="branam_email_subject"
                                value="<?php echo esc_attr($email_subject); ?>"
                                class="regular-text"
                            />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Текст письма амбассадору', 'brand-ambassador'); ?></th>
                        <td>
                            <?php
                            wp_editor(
                                $email_template,
                                'branam_email_template',
                                [
                                    'textarea_name' => 'branam_email_template',
                                    'textarea_rows' => 10,
                                    'media_buttons' => true,
                                ]
                            );
                            ?>
                            <p class="description"><?php esc_html_e('Используйте плейсхолдеры [ambassador] для имени амбассадора, [coupon] для купона и [order_id] для номера заказа.', 'brand-ambassador'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Шрифт письма', 'brand-ambassador'); ?></th>
                        <td>
                            <input
                                type="text"
                                name="branam_email_font"
                                value="<?php echo esc_attr($email_font); ?>"
                                class="regular-text"
                            />
                            <p class="description"><?php esc_html_e('Укажите шрифт для письма (например, Arial, sans-serif).', 'brand-ambassador'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Перед удалением плагина', 'brand-ambassador'); ?></th>
                        <td>
                            <input type="checkbox" name="branam_delete_meta" value="1" <?php checked(1, $delete_meta, true); ?> />
                            <label for="branam_delete_meta">
                                <?php esc_html_e('Удалить метаполя, которые создал плагин', 'brand-ambassador'); ?></label>
                                <ul>
                                    <li><strong>_branam_ambassador_user</strong>: Связь между купоном и пользователем (ID пользователя).</li>
                                    <li><strong>branam_only_first_order</strong>: Чекбокс в купоне, который действует только для первого заказа.</li>
                                    <li><strong>_branam_user_coupon</strong>: Связь между пользователем и купоном (ID купона).</li>
                                    <li><strong>branam_user_numbercartbank</strong>: Номер банковской карты пользователя.</li>
                                    <li><strong>branam_user_bankname</strong>: Название банка пользователя.</li>
                                    <li><strong>_branam_payout_status</strong>: Статус выплаты.</li>
                                </ul>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Удаление данных плагина
     */
    public static function delete_plugin_data() {
        if (get_option('branam_delete_meta') == 1) {
            global $wpdb;

            // Удаление метаполей через WP функции вместо прямых SQL-запросов
            $postmeta_keys = array('_branam_ambassador_user', 'branam_only_first_order', '_branam_payout_status');
            $usermeta_keys = array('_branam_user_coupon', 'branam_user_numbercartbank', 'branam_user_bankname');

            // Удаляем post meta для всех постов
            foreach ($postmeta_keys as $meta_key) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
                        $meta_key
                    )
                );
            }
            foreach ($usermeta_keys as $meta_key) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
                        $meta_key
                    )
                );
            }

            // Удаление опций
            delete_option('branam_delete_meta');
            delete_option('branam_blogger_role');
            delete_option('branam_expert_role');
            delete_option('branam_blogger_reward');
            delete_option('branam_expert_reward');
            delete_option('branam_email_subject');
            delete_option('branam_email_template');
            delete_option('branam_email_font');
            delete_option('branam_encryption_key'); // Удаление ключа шифрования
        }
    }
}

// Регистрация хуков активации и удаления — вне класса
register_activation_hook(plugin_dir_path(__DIR__) . 'brand-ambassador.php', ['Branam_Settings_Page', 'generate_encryption_key']);
register_uninstall_hook(plugin_dir_path(__DIR__) . 'brand-ambassador.php', ['Branam_Settings_Page', 'delete_plugin_data']);
