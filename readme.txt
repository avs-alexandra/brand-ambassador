=== Brand Ambassador ===
Contributors: avsalexandra
Tags: woocommerce, coupons, ambassador, affiliate
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Плагин для WooCommerce, который помогает управлять программой амбассадоров бренда с функционалом купонов и выплат.

== Description ==
Brand Ambassador — лёгкий, но функциональный плагин для программы Амбассадор бренда для Woocommerce.
Суть: компания приглашает к сотрудничеству Амбассадоров бренда и предоставляет личный купон.
Например, за первый заказ клиента от 2500 руб с применением купона (клиент при этом получает скидку 10%), Амбассадор получает 500 руб выплаты (вознаграждение).

В плагине можно сделать 2 уровня выплат (можете использовать только один уровень)
Например, Программа Амбассадоры бренда для Блогеров - 450 руб
Программа Амбассадор бренда для Экспертов - 600 руб
(название программы видны только в админке, на сайте можете придумать любые названия)

Вы можете изменить сумму выплат. Ведь ничто так не мотивирует людей рекламировать, как выплаты живыми деньгами!

== Installation ==
1. Скачайте плагин.
2. Загрузите папку с плагином в директорию `/wp-content/plugins/`.
3. Активируйте плагин через меню "Плагины" в админке WordPress.

== Frequently Asked Questions ==
= Какие шорткоды доступны? =
- `[branam_user_coupon_name]` - Купон Амбассадора
- `[branam_user_related_orders]` - Статистика заказов Амбассадора
- `[branam_user_total_orders]` - Общая статистика Амбассадора
- `[branam_ambassador_bank_form]` - Форма ввода банковской карты Амбассадора
- `[branam_ambassador_card_number]` - Отобразить последние 4 цифры номера карты

= Можно ли использовать плагин без WooCommerce? =
Нет, плагин предназначен только для сайтов WooCommerce. Обязательно включите в настройках Woocommerce HPOS (High-Performance Order Storage)

= Пример css кода для шорткода [branam_user_related_orders] =
```css
selector .branam-apply-buttons{background-color:#61C6CC;margin-top:10px;}
selector .branam-apply-buttons:hover{background-color:#5AB9BE;}
selector .branam-filter-select{border:2px solid #61C6CC;border-radius:10px;padding:8px 10px;width:230px;margin-top:2px;}
selector .branam-selected-month-year-title{font-weight:bold;margin-top:30px;margin-bottom:8px;}
selector .branam-payout{margin-top:16px;padding:10px 18px;background:#E5D4EF;border-radius:8px;font-size:15px;width:fit-content;}
.branam-user-related-orders ul{list-style-type:none;padding:0;margin:0px 0px;}
.branam-other-statuses-title{margin-top:20px;color:#989898;margin-bottom:8px;font-size:15px;}
.branam-other-statuses-list{color:#989898;font-size:14px;}
.branam-other-statuses-none{color:#989898;font-size:15px;margin-bottom:8px;}
.branam-user-related-orders ul li {
    border-bottom: 2px dotted #bbbbbb; 
    padding-top:4px;}
.branam-user-related-orders ul li:last-child {
    border-bottom: none;}
.branam-reward-note {font-size: 14px; color: #555;margin-top: 20px;}
```
= Почему не могу прикрепить пользователя к купону? =
Пользователь должен иметь соответствующую роль, которую вы указали в настройках Амбассадора.

= Почему на странице выплат не отображается заказ с применением купона амбассадора? =
Заказ должен быть в статусе выполнен.

= Нужно что-то вносить в политику конфиденциальности? =
Да! Добавьте в раздел "Обработка и защита персональных данных"
"Все номера банковских карт шифруются с использованием алгоритма AES-256-CBC, который является одним из самых надёжных стандартов шифрования."

= Как платить налоги согласно закону РФ с выплат Амбассадору? =
Если у вас ООО или ИП, то для того, чтобы переводить физ.л. нужно заключить договор ГПХ (оплатить НДФЛ, страх. взносы).
Либо попросите Амбассадора открыть самозанятость (скачать приложение Мой налог) и тогда перевод с р/с будет самозанятому, а самозанятый платит 6% налога.
Если Вы переводите со своей личной карты на карту амбассадора, то это будет неофициальной выплатой и при большом количество переводов у налоговой могут возникнуть вопросы.
Обсудите этот вопрос с вашим бухгалтером, также в законе есть понятие комиссионный доход.

= Как ограничить доступ к странице выплат? =
Ограничьте доступ к странице выплат для лучшей безопасности персональных данных. 
Добавьте сниппет-код:

```php
// доступ к странице выплат по купонам
add_filter('branam_coupon_payouts_page_access', function($has_access) {
    return current_user_can('administrator') || current_user_can('shop_manager');
});
```

== Changelog ==
= 1.0.0 =
* Первая версия плагина.
* Добавлены шорткоды и логика управления купонами.
* Реализована страница выплат в админке.

== Upgrade Notice ==
= 1.0.0 =
* Обновите до последней версии для полного функционала.

== Compatibility ==
Плагин протестирован и поддерживает PHP версии 7.4, 8.0, 8.1, 8.2, 8.3

== Screenshots ==
1. Настройки плагина
2. Привязать пользователя к купону
3. На странице пользователя отображение его купона и реквизитов карты
4. Шорткод ввода банк. карты в личном кабинете пользователя
5. Шоткоды статистики выплат в личном кабинете пользователя
6. Изменение статусы выплат

== License ==
Этот плагин распространяется под лицензией GPLv2 или более поздней версии.
