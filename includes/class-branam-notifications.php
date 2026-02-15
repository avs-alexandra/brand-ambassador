<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Запрет прямого доступа

class Branam_Notifications {

    /**
     * Meta key, чтобы не отправлять письмо повторно по одному и тому же заказу.
     */
    private const ORDER_META_EMAIL_SENT = '_branam_ambassador_email_sent';

    /**
     * Конструктор для инициализации хуков и добавления функционала
     */
    public function __construct() {
        // Хук для отслеживания изменения статуса заказа
        add_action( 'woocommerce_order_status_changed', [ $this, 'send_email_on_completed_order' ], 10, 4 );
    }

    /**
     * Отправка письма Амбассадору бренда, когда заказ завершён
     *
     * @param int      $order_id    ID заказа
     * @param string   $old_status  Старый статус заказа (без "wc-")
     * @param string   $new_status  Новый статус заказа (без "wc-")
     * @param WC_Order $order       Экземпляр заказа WooCommerce
     */
    public function send_email_on_completed_order( $order_id, $old_status, $new_status, $order ) {

        // Нас интересует только переход в completed
        if ( $new_status !== 'completed' ) {
            return;
        }

        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }
        }

        // Защита от повторных писем (если статус completed ставили несколько раз)
        $already_sent = $order->get_meta( self::ORDER_META_EMAIL_SENT, true );
        if ( $already_sent ) {
            return;
        }

        // Получаем использованные купоны
        $used_coupons = $order->get_coupon_codes();
        if ( empty( $used_coupons ) ) {
            return;
        }

        $sent_any = false;

        foreach ( (array) $used_coupons as $coupon_code ) {
            $coupon_code = (string) $coupon_code;
            if ( $coupon_code === '' ) {
                continue;
            }

            // Быстро получаем ID купона по коду (без создания WC_Coupon)
            $coupon_id = wc_get_coupon_id_by_code( $coupon_code );
            if ( ! $coupon_id ) {
                continue;
            }

            // Проверяем, есть ли связанный Амбассадор
            $ambassador_id = (int) get_post_meta( $coupon_id, '_branam_ambassador_user', true );
            if ( ! $ambassador_id ) {
                continue;
            }

            // Получаем данные Амбассадора
            $ambassador = get_userdata( $ambassador_id );
            if ( ! $ambassador || empty( $ambassador->user_email ) ) {
                continue;
            }

            // Тема письма из настроек
            $email_subject = get_option( 'branam_email_subject', __( 'Ваш купон был использован!', 'brand-ambassador' ) );

            // Тело письма
            $email_body = $this->generate_email_content( $ambassador, $coupon_code, (int) $order_id );

            // Отправляем письмо
            $ok = $this->send_woocommerce_email( (string) $ambassador->user_email, (string) $email_subject, (string) $email_body );
            if ( $ok ) {
                $sent_any = true;
            }
        }

        /**
         * Если письмо хотя бы по одному амбассадорскому купону отправилось — ставим флаг.
         * Так мы избегаем дублей при повторном переходе в completed.
         */
        if ( $sent_any ) {
            $order->update_meta_data( self::ORDER_META_EMAIL_SENT, 1 );
            $order->save();
        }
    }

    /**
     * Генерация содержимого письма
     *
     * @param WP_User $ambassador Данные пользователя
     * @param string  $coupon_code Код купона
     * @param int     $order_id ID заказа
     * @return string Содержимое письма
     */
    private function generate_email_content( $ambassador, $coupon_code, $order_id ) {
        $email_template = get_option(
            'branam_email_template',
            'Здравствуйте, [ambassador]! Ваш купон "[coupon]" был использован для заказа №[order_id].'
        );
        $email_font = get_option( 'branam_email_font', 'Arial, sans-serif' );

        $email_body = strtr(
            (string) $email_template,
            [
                '[ambassador]' => esc_html( (string) $ambassador->display_name ),
                '[coupon]'     => esc_html( (string) $coupon_code ),
                '[order_id]'   => esc_html( (string) $order_id ),
            ]
        );

        return '<tr><td style="font-family: ' . esc_attr( (string) $email_font ) . '; padding: 40px; font-size: 15px;">' . wpautop( $email_body ) . '</td></tr>';
    }

    /**
     * Отправка письма через wp_mail с кастомным шаблоном.
     *
     * @param string $recipient Email получателя
     * @param string $subject   Тема письма
     * @param string $message   Сообщение письма (HTML)
     * @return bool             true если wp_mail вернул true
     */
    private function send_woocommerce_email( $recipient, $subject, $message ): bool {
        $header_template = plugin_dir_path( __FILE__ ) . '../templates/email_header.php';
        $footer_template = plugin_dir_path( __FILE__ ) . '../templates/email_footer.php';

        $email_font = get_option( 'branam_email_font', 'Arial, sans-serif' );

        // Header
        ob_start();
        if ( file_exists( $header_template ) ) {
            include $header_template;
        }
        $header = ob_get_clean();
        $header = strtr(
            (string) $header,
            [
                '{background_color}'            => esc_attr( get_option( 'woocommerce_email_background_color', '#f5f5f5' ) ),
                '{woocommerce_email_base_color}' => esc_attr( get_option( 'woocommerce_email_base_color', '#007cba' ) ),
                '{title}'                      => esc_html( (string) $subject ),
                '{font_family}'                => esc_attr( (string) $email_font ),
            ]
        );

        // Footer
        ob_start();
        if ( file_exists( $footer_template ) ) {
            include $footer_template;
        }
        $footer = ob_get_clean();
        $footer = strtr(
            (string) $footer,
            [
                '{text_color}'  => esc_attr( get_option( 'woocommerce_email_text_color', '#444444' ) ),
                '{description}' => wp_kses_post( (string) get_option( 'woocommerce_email_footer_text', __( 'Спасибо за использование нашего сервиса!', 'brand-ambassador' ) ) ),
                '{site_title}'  => esc_html( get_bloginfo( 'name' ) ),
                '{site_url}'    => esc_url( home_url() ),
                '{year}'        => esc_html( gmdate( 'Y' ) ),
                '{font_family}' => esc_attr( (string) $email_font ),
            ]
        );

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

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        return (bool) wp_mail( (string) $recipient, (string) $subject, (string) $wrapped_message, $headers );
    }
}
