<?php
if (!defined('ABSPATH')) exit; // Запрет прямого доступа

class AmbassadorNotifications {
    /**
     * Конструктор для инициализации хуков и добавления функционала
     */
    public function __construct() {
        // Хук для отслеживания изменения статуса заказа
        add_action('woocommerce_order_status_changed', [$this, 'send_email_on_completed_order'], 10, 4);
    }

    /**
     * Отправка письма Амбассадору бренда, когда заказ завершён
     *
     * @param int $order_id ID заказа
     * @param string $old_status Старый статус заказа
     * @param string $new_status Новый статус заказа
     * @param WC_Order $order Экземпляр заказа WooCommerce
     */
    public function send_email_on_completed_order($order_id, $old_status, $new_status, $order) {
        if ($new_status !== 'completed') {
            return;
        }

        // Получаем использованные купоны
        $used_coupons = $order->get_used_coupons();

        if (empty($used_coupons)) {
            return;
        }

        foreach ($used_coupons as $coupon_code) {
            // Получаем ID купона
            $coupon = new WC_Coupon($coupon_code);
            $coupon_id = $coupon->get_id();

            // Проверяем, есть ли связанный Амбассадор
            $ambassador_id = get_post_meta($coupon_id, '_branam_ambassador_user', true);
            if (!$ambassador_id) {
                continue;
            }

            // Получаем данные Амбассадора
            $ambassador = get_userdata($ambassador_id);
            if (!$ambassador || empty($ambassador->user_email)) {
                continue;
            }

            // Формируем тему письма из настроек
            $email_subject = get_option('branam_ambassador_email_subject', __('Ваш купон был использован!', 'brand-ambassador'));

            // Формируем текст письма
            $email_body = $this->generate_email_content($ambassador, $coupon_code, $order_id);

            // Отправляем письмо с использованием WooCommerce
            $this->send_woocommerce_email($ambassador->user_email, $email_subject, $email_body);
        }
    }

    /**
     * Генерация содержимого письма
     *
     * @param WP_User $ambassador Данные пользователя
     * @param string $coupon_code Код купона
     * @param int $order_id ID заказа
     * @return string Содержимое письма
     */
    private function generate_email_content($ambassador, $coupon_code, $order_id) {
        // Получаем текст письма и шрифт из настроек "Настройки Амбассадора"
        $email_template = get_option('branam_ambassador_email_template', 'Здравствуйте, [ambassador]! Ваш купон "[coupon]" был использован для заказа №[order_id].');
        $email_font = get_option('branam_ambassador_email_font', 'Arial, sans-serif');

        // Заменяем плейсхолдеры на реальные данные
        $email_body = strtr($email_template, [
            '[ambassador]' => esc_html($ambassador->display_name),
            '[coupon]' => esc_html($coupon_code),
            '[order_id]' => esc_html($order_id),
        ]);

        // Возвращаем текст письма с применением шрифта
        return '<tr><td style="font-family: ' . esc_attr($email_font) . '; padding: 40px; font-size: 15px;">' . wpautop($email_body) . '</td></tr>';
    }

    /**
     * Отправка письма через WooCommerce с кастомным шаблоном
     *
     * @param string $recipient Email получателя
     * @param string $subject Тема письма
     * @param string $message Сообщение письма
     */
    private function send_woocommerce_email($recipient, $subject, $message) {
        // Пути к шаблонам
        $header_template = plugin_dir_path(__FILE__) . '../templates/email_header.php';
        $footer_template = plugin_dir_path(__FILE__) . '../templates/email_footer.php';

        // Получаем шрифт из настроек "Настройки Амбассадора"
        $email_font = get_option('branam_ambassador_email_font', 'Arial, sans-serif');

        // Генерация шапки
        ob_start();
        if (file_exists($header_template)) {
            include $header_template;
        }
        $header = ob_get_clean();
        $header = strtr($header, [
            '{background_color}' => esc_attr(get_option("woocommerce_email_background_color", "#f5f5f5")),
            '{woocommerce_email_base_color}' => esc_attr(get_option("woocommerce_email_base_color", "#007cba")),
            '{title}' => esc_html($subject),
            '{font_family}' => esc_attr($email_font),
        ]);

        // Генерация подвала
        ob_start();
        if (file_exists($footer_template)) {
            include $footer_template;
        }
        $footer = ob_get_clean();
        $footer = strtr($footer, [
            '{text_color}'   => esc_attr(get_option("woocommerce_email_text_color", "#444444")),
            '{description}'  => wp_kses_post(get_option('woocommerce_email_footer_text', __("Спасибо за использование нашего сервиса!", "brand-ambassador"))),
            '{site_title}'   => esc_html(get_bloginfo('name')),
            '{site_url}'     => esc_url(home_url()),
            '{year}'         => esc_html(date('Y')),
            '{font_family}'  => esc_attr($email_font), // Применяем шрифт
        ]);

        // Объединяем шапку, тело и подвал
        $wrapped_message = '
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f5f5f5;">
                <tr>
                    <td align="center">
                        <table border="0" cellpadding="0" cellspacing="0" width="600" style="background-color:#ffffff;border:1px solid #dedede;border-radius:3px;">
                            ' . $header . '
                            ' . $message . '
                            ' . $footer . '
                        </table>
                    </td>
                </tr>
            </table>
        ';

        // Заголовки
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        // Отправляем письмо
        wp_mail($recipient, $subject, $wrapped_message, $headers);
    }
}
