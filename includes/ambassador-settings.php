<?php
if (!defined('ABSPATH')) exit; // Запрет прямого доступа

class AmbassadorSettingsPage {
    public function __construct() {
        // Добавляем страницу настроек в меню "Маркетинг"
        add_action('admin_menu', [$this, 'add_settings_page']);
        // Регистрируем настройки
        add_action('admin_init', [$this, 'register_settings']);
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
        // Регистрируем опцию для роли Блогера
        register_setting('ambassador_settings', 'blogger_role');
        // Регистрируем опцию для роли Эксперта
        register_setting('ambassador_settings', 'expert_role');
        // Регистрируем опцию для выплат
        register_setting('ambassador_settings', 'blogger_reward');
        register_setting('ambassador_settings', 'expert_reward');
    }

    /**
     * Рендеринг страницы настроек
     */
    public function render_settings_page() {
        // Получаем список всех доступных ролей
        global $wp_roles;
        $roles = $wp_roles->roles;
        $blogger_role = get_option('blogger_role', 'customer');
        $expert_role = get_option('expert_role', 'customer');
        $blogger_reward = get_option('blogger_reward', 450);
        $expert_reward = get_option('expert_reward', 600);
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
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
