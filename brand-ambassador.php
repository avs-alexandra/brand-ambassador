<?php
/**
 * Plugin Name: Brand Ambassador
 * Plugin URI: https://github.com/avs-alexandra/brand-ambassador
 * Description: Плагин для WooCommerce, который помогает управлять программой амбассадоров бренда с функционалом купонов и выплат.
 * Version: 1.0.1
 * Author: avs-alexandra
 * Author URI: https://github.com/avs-alexandra
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: brand-ambassador
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit; // Запрет прямого доступа

// Подключаем основной класс плагина
require_once plugin_dir_path(__FILE__) . 'includes/class-ambassador-coupon.php';

// Подключаем шорткоды и опцию в купоне только на первый заказ
require_once plugin_dir_path(__FILE__) . 'includes/shortcodes.php';

// Подключаем обработчик выплат
require_once plugin_dir_path(__FILE__) . 'includes/class-coupon-payouts-handler.php';

// Подключаем страницу для админки в WooCommerce
require_once plugin_dir_path(__FILE__) . 'includes/class-coupon-payouts-page.php';

// Подключаем страницу настроек
require_once plugin_dir_path(__FILE__) . 'includes/ambassador-settings.php';

// Подключаем класс уведомлений
require_once plugin_dir_path(__FILE__) . 'includes/class-ambassador-notifications.php';

// Инициализация плагина
function initialize_brand_ambassador() {
    // Логика выплат
    $payouts_handler = new CouponPayoutsHandler();
    add_action('admin_post_save_payout_status', [$payouts_handler, 'save_payout_status']);

    // Рендеринг админ-страницы
    $payouts_page = new CouponPayoutsPage();
    add_action('admin_menu', [$payouts_page, 'add_payouts_page']);

    // Основной функционал и настройки
    new AmbassadorCouponProgram();
    new AmbassadorSettingsPage(); // Страница настроек

    // Уведомления
    new AmbassadorNotifications(); // Уведомления для Амбассадоров
}
add_action('plugins_loaded', 'initialize_brand_ambassador');
