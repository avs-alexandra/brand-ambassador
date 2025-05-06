<?php
if (!defined('ABSPATH')) exit; // Запрет прямого доступа

class AmbassadorSettingsPage {
    public function __construct() {
        // Добавляем страницу настроек в меню "Маркетинг"
        add_action('admin_menu', [$this, 'add_settings_page']);
        // Регистрируем настройки
        add_action('admin_init', [$this, 'register_settings']);
        // Регистрируем хук для удаления данных при удалении плагина
        register_uninstall_hook(__FILE__, [__CLASS__, 'delete_plugin_data']);
    }

    /**
     * Добавляем страницу настроек в меню "Маркетинг"
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce-marketing', // Родительская страница (WooCommerce > Маркетинг)
            __('Настройки Амбассадора', 'woocommerce'), // Заголовок страницы
            __('Настройки Амбассадора', 'woocommerce'), // Название в меню
            'manage_options', // Требуемые права
            'ambassador-settings', // Слаг страницы
            [$this, 'render_settings_page'] // Callback для рендеринга страницы
        );
    }

    /**
     * Регистрируем настройки
     */
    public function register_settings() {
        register_setting('ambassador_settings', 'blogger_role');
        register_setting('ambassador_settings', 'expert_role');
        register_setting('ambassador_settings', 'blogger_reward');
        register_setting('ambassador_settings', 'expert_reward');
        register_setting('ambassador_settings', 'ambassador_delete_meta');
    }

    /**
     * Рендеринг страницы настроек
     */
    public function render_settings_page() {
        global $wp_roles;
        $roles = $wp_roles->roles;
        $blogger_role = get_option('blogger_role', 'customer');
        $expert_role = get_option('expert_role', 'customer');
        $blogger_reward = get_option('blogger_reward', 450);
        $expert_reward = get_option('expert_reward', 600);
        $delete_meta = get_option('ambassador_delete_meta', 0);
        ?>
        <div class="wrap">
            <h1><?php _e('Настройки Амбассадора бренда', 'woocommerce'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('ambassador_settings'); ?>
                <?php do_settings_sections('ambassador_settings'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('Роль для Блогера', 'woocommerce'); ?></th>
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
                        <th scope="row"><?php _e('Роль для Эксперта', 'woocommerce'); ?></th>
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
                        <th scope="row"><?php _e('Выплата за заказ для Блогера (руб)', 'woocommerce'); ?></th>
                        <td>
                            <input type="number" name="blogger_reward" value="<?php echo esc_attr($blogger_reward); ?>" min="0" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Выплата за заказ для Эксперта (руб)', 'woocommerce'); ?></th>
                        <td>
                            <input type="number" name="expert_reward" value="<?php echo esc_attr($expert_reward); ?>" min="0" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Перед удалением плагина', 'woocommerce'); ?></th>
                        <td>
                            <input type="checkbox" name="ambassador_delete_meta" value="1" <?php checked(1, $delete_meta, true); ?> />
                            <label for="ambassador_delete_meta">
                                <?php _e('Удалить метаполя, которые создал плагин', 'woocommerce'); ?>
                                <ul>
                                    <li><strong>_ambassador_user</strong>: Связь между купоном и пользователем (ID пользователя).</li>
                                    <li><strong>only_first_order</strong>: Чекбокс в купоне, который действует только для первого заказа.</li>
                                    <li><strong>_user_coupon</strong>: Связь между пользователем и купоном (ID купона).</li>
                                    <li><strong>user_numbercartbank</strong>: Номер банковской карты пользователя.</li>
                                    <li><strong>user_bankname</strong>: Название банка пользователя.</li>
                                    <li><strong>_payout_status</strong>: Статус выплаты.</li>
                                </ul>
                            </p>
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
    }
  }
}
