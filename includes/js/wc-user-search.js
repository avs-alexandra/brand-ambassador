jQuery(function ($) {
    $('#ambassador_user').select2({
        ajax: {
            url: userSearchData.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    action: 'search_users_by_email',
                    term: params.term, // Текст из поля поиска
                    nonce: userSearchData.nonce
                };
            },
            processResults: function (data) {
                return {
                    results: data.results || []
                };
            },
            cache: true
        },
        minimumInputLength: 2,
        placeholder: 'Начните вводить email',
        allowClear: true
    });
});
