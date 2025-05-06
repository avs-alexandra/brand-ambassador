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
                    nonce: ambassadorUserData.nonce_search
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
            nonce: ambassadorUserData.nonce_unlink
        }, function(response) {
            if (response.success) {
                alert(ambassadorUserData.success_message);
                location.reload();
            } else {
                alert(ambassadorUserData.error_message);
            }
        });
    });
});
