<?php
if (!defined('ABSPATH')) exit; // Запрет прямого доступа

class AmbassadorSettingsPage {
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
        if (!get_option('brand_ambassador_encryption_key')) {
            $key = bin2hex(random_bytes(32)); // Генерация 256-битного ключа
            add_option('brand_ambassador_encryption_key', $key);
        }
    }

    // Получение ключа шифрования
    public static function get_encryption_key() {
        $key = get_option('brand_ambassador_encryption_key');
        if (!$key) {
            wp_die(__('Ключ шифрования не найден. Пожалуйста, активируйте плагин заново.', 'brand-ambassador'));
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
        list($encrypted_data, $iv) = explode('::', base64_decode($encrypted_data), 2);
        return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
    }

    /**
     * Добавляем страницу настроек в меню "Маркетинг"
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce-marketing', // Родительская страница (WooCommerce > Маркетинг)
            __('Настройки Амбассадора', 'brand-ambassador'), // Заголовок страницы
            __('Настройки Амбассадора', 'brand-ambassador'), // Название в меню
            'manage_options', // Требуемые права
            'ambassador-settings', // Слаг страницы
            [$this, 'render_settings_page'] // Callback для рендеринга страницы
        );
    }

    /**
     * Регистрируем настройки
     */
    public function register_settings() {
        register_setting('ambassador_settings', 'blogger_role', [
            'sanitize_callback' => [$this, 'validate_role'],
        ]);
        register_setting('ambassador_settings', 'expert_role', [
            'sanitize_callback' => [$this, 'validate_role'],
        ]);
        register_setting('ambassador_settings', 'blogger_reward', [
            'sanitize_callback' => 'absint', // Санитизация для чисел
        ]);
        register_setting('ambassador_settings', 'expert_reward', [
            'sanitize_callback' => 'absint', // Санитизация для чисел
        ]);
        register_setting('ambassador_settings', 'ambassador_delete_meta', [
            'sanitize_callback' => 'rest_sanitize_boolean', // Для чекбокса
        ]);
        register_setting('ambassador_settings', 'ambassador_email_subject', [
            'sanitize_callback' => 'sanitize_text_field', // Санитизация для текста
        ]);
        register_setting('ambassador_settings', 'ambassador_email_template', [
            'sanitize_callback' => [self::class, 'sanitize_email_template'],
        ]);
        register_setting('ambassador_settings', 'ambassador_email_font', [
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
        $blogger_role = get_option('blogger_role');
        $expert_role = get_option('expert_role');

        // Проверяем, совпадают ли роли
        if (!empty($blogger_role) && !empty($expert_role) && $blogger_role === $expert_role) {
            // Сбрасываем роли к значениям по умолчанию
            update_option('blogger_role', 'customer'); // По умолчанию 'customer'
            update_option('expert_role', 'subscriber'); // По умолчанию 'subscriber'

            // Выводим уведомление об ошибке и сбросе
            echo '<div class="notice notice-error">';
            echo '<p>' . __('Необходимо выбрать разные роли для Блогера и Эксперта! Роли были сброшены к значениям по умолчанию.', 'brand-ambassador') . '</p>';
            echo '</div>';
        }
    }

    /**
     * Рендеринг страницы настроек
     */
    public function render_settings_page() {
        global $wp_roles;
        $roles = $wp_roles->roles;
        $blogger_role = get_option('blogger_role', 'customer');
        $expert_role = get_option('expert_role', 'subscriber');
        $blogger_reward = get_option('blogger_reward', 450);
        $expert_reward = get_option('expert_reward', 600);
        $delete_meta = get_option('ambassador_delete_meta', 0);
        $email_subject = get_option('ambassador_email_subject', 'Ваш купон был использован!'); // Значение по умолчанию для темы письма
        $email_template = get_option('ambassador_email_template', 'Здравствуйте, [ambassador]! Ваш купон "[coupon]" был использован для заказа №[order_id].');
        $email_font = get_option('ambassador_email_font', 'Arial, sans-serif'); // Значение по умолчанию
        ?>
        <div class="wrap">
            <h1><?php _e('Настройки Амбассадора бренда', 'brand-ambassador'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('ambassador_settings'); ?>
                <?php do_settings_sections('ambassador_settings'); ?>
                <table class="form-table">
                    <!-- Добавлено уведомление -->
                    <tr>
                        <p style="color: #000;">
                            <?php _e('Шорткоды:<br>[user_coupon_name] - Купон Амбассадора <br>[user_related_orders] - Статистика заказов Амбассадора <br>[user_total_orders] - Общая статистика Амбассадора <br>[ambassador_bank_form] - Форма ввода банковской карты Амбассадора <br>[ambassador_card_number] - Отобразить последние 4 цифры номера карты', 'brand-ambassador'); ?>
                        </p>
                        <p style="color: #646970;">
                            <?php _e('Выберите разные роли для уровней Блогер и Эксперт для корректного расчёта выплат. (Cоздать новые роли можно с помощью плагина User Role Editor)', 'brand-ambassador'); ?>
                        </p>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Роль для Блогера', 'brand-ambassador'); ?></th>
                        <td>
                            <select name="blogger_role">
                                <?php foreach ($roles as $role_key => $role): ?>
                                    <option value="<?php echo esc_attr($role_key); ?>" <?php selected($blogger_role, $role_key); ?>>
                                        <?php echo esc_html($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Роль для Эксперта', 'brand-ambassador'); ?></th>
                        <td>
                            <select name="expert_role">
                                <?php foreach ($roles as $role_key => $role): ?>
                                    <option value="<?php echo esc_attr($role_key); ?>" <?php selected($expert_role, $role_key); ?>>
                                        <?php echo esc_html($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Выплата за заказ для Блогера (руб)', 'brand-ambassador'); ?></th>
                        <td>
                            <input type="number" name="blogger_reward" value="<?php echo esc_attr($blogger_reward); ?>" min="0" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Выплата за заказ для Эксперта (руб)', 'brand-ambassador'); ?></th>
                        <td>
                            <input type="number" name="expert_reward" value="<?php echo esc_attr($expert_reward); ?>" min="0" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Тема письма', 'brand-ambassador'); ?></th>
                        <td>
                            <input
                                type="text"
                                name="ambassador_email_subject"
                                value="<?php echo esc_attr($email_subject); ?>"
                                class="regular-text"
                            />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Текст письма амбассадору', 'brand-ambassador'); ?></th>
                        <td>
                            <?php
                            wp_editor(
                                $email_template,
                                'ambassador_email_template',
                                [
                                    'textarea_name' => 'ambassador_email_template',
                                    'textarea_rows' => 10,
                                    'media_buttons' => true,
                                ]
                            );
                            ?>
                            <p class="description"><?php _e('Используйте плейсхолдеры [ambassador] для имени амбассадора, [coupon] для купона и [order_id] для номера заказа.', 'brand-ambassador'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Шрифт письма', 'brand-ambassador'); ?></th>
                        <td>
                            <input
                                type="text"
                                name="ambassador_email_font"
                                value="<?php echo esc_attr($email_font); ?>"
                                class="regular-text"
                            />
                            <p class="description"><?php _e('Укажите шрифт для письма (например, Arial, sans-serif).', 'brand-ambassador'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Перед удалением плагина', 'brand-ambassador'); ?></th>
                        <td>
                            <input type="checkbox" name="ambassador_delete_meta" value="1" <?php checked(1, $delete_meta, true); ?> />
                            <label for="ambassador_delete_meta">
                                <?php _e('Удалить метаполя, которые создал плагин', 'brand-ambassador'); ?>
                                <ul>
                                    <li><strong>_ambassador_user</strong>: Связь между купоном и пользователем (ID пользователя).</li>
                                    <li><strong>only_first_order</strong>: Чекбокс в купоне, который действует только для первого заказа.</li>
                                    <li><strong>_user_coupon</strong>: Связь между пользователем и купоном (ID купона).</li>
                                    <li><strong>user_numbercartbank</strong>: Номер банковской карты пользователя.</li>
                                    <li><strong>user_bankname</strong>: Название банка пользователя.</li>
                                    <li><strong>_payout_status</strong>: Статус выплаты.</li>
                                </ul>
                            </label>
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
        if (get_option('ambassador_delete_meta') == 1) {
            global $wpdb;

            // Удаление метаполей
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_ambassador_user', 'only_first_order', '_payout_status')");
            $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('_user_coupon', 'user_numbercartbank', 'user_bankname')");

            // Удаление опций
            delete_option('ambassador_delete_meta');
            delete_option('blogger_role');
            delete_option('expert_role');
            delete_option('blogger_reward');
            delete_option('expert_reward');
            delete_option('ambassador_email_subject');
            delete_option('ambassador_email_template');
            delete_option('ambassador_email_font');
            delete_option('brand_ambassador_encryption_key'); // Удаление ключа шифрования
        }
    }
}
// Регистрация хуков активации и удаления — вне класса
register_activation_hook(plugin_dir_path(__DIR__) . 'brand-ambassador.php', ['AmbassadorSettingsPage', 'generate_encryption_key']);
register_uninstall_hook(plugin_dir_path(__DIR__) . 'brand-ambassador.php', ['AmbassadorSettingsPage', 'delete_plugin_data']);
