<?php
/*
Plugin Name: Brand Ambassador
Plugin URI: https://github.com/avs-alexandra/brand-ambassador
Description: Plugin for managing brand ambassadors and their rewards in WooCommerce. Requires WooCommerce High-Performance Order Storage (HPOS) to be enabled!
Version: 1.0.1
Author: avsalexandra
Author URI: https://github.com/avs-alexandra
Text Domain: brand-ambassador
Requires at least: 5.0
Requires PHP: 7.4
License: GPLv2 or later
*/

if (!defined('ABSPATH')) exit; // Запрет прямого доступа

// Подключаем основной функционал купонов амбассадора
require_once plugin_dir_path(__FILE__) . 'includes/class-branam-coupon-program.php';

// Подключаем страницу настроек
require_once plugin_dir_path(__FILE__) . 'includes/branam-settings.php';

// Подключаем шорткоды и опцию в купоне только на первый заказ
require_once plugin_dir_path(__FILE__) . 'includes/branam-shortcodes.php';

// Подключаем обработчик выплат
require_once plugin_dir_path(__FILE__) . 'includes/class-branam-payouts-handler.php';

// Подключаем страницу для админки в WooCommerce
require_once plugin_dir_path(__FILE__) . 'includes/class-branam-payouts-page.php';

// Подключаем класс уведомлений
require_once plugin_dir_path(__FILE__) . 'includes/class-branam-notifications.php';

// Инициализация плагина
function branam_initialize_brand_ambassador() {
    // Логика выплат
    $payouts_handler = new Branam_Payouts_Handler();
    add_action('admin_post_branam_save_payout_status', [$payouts_handler, 'save_payout_status']);

    // Рендеринг админ-страницы выплат
    $payouts_page = new Branam_Payouts_Page();
    $payouts_page->register_hooks();
    add_action('admin_menu', [$payouts_page, 'add_payouts_page']);

    // Основной функционал и настройки
    new Branam_Coupon_Program();
    new Branam_Settings_Page(); // Страница настроек

    // Уведомления
    new Branam_Notifications(); // Уведомления для Амбассадоров
}
add_action('plugins_loaded', 'branam_initialize_brand_ambassador');
// Кнопка "Настройки" 
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'branam_add_settings_link');
function branam_add_settings_link($links) {
    $settings_url = admin_url('admin.php?page=branam-settings');
    $settings_link = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Настройки', 'brand-ambassador') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
