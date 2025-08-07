jQuery(document).ready(function($) {
    
    $('.akashic-form').on('submit', function(e) {
    console.log('akashic-forms-public.js loaded');
        var form = $(this);
        var formId = form.data('form-id');
        var submissionAction = form.data('submission-action');

        if ('redirect' === submissionAction) {
            return;
        }

        e.preventDefault();

        var formData = new FormData(this);
        $.ajax({
            url: akashicForms.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    if ('message' === submissionAction) {
                        $('#akashic-form-container-' + formId + ' .akashic-form').hide();
                        $('#akashic-form-container-' + formId + ' .akashic-form-message').show();
                    } else if ('modal' === submissionAction) {
                        $('#akashic-form-modal-' + formId).show();
                    }
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
            }
        });
    });

    $('.akashic-form-modal-close').on('click', function() {
        $(this).closest('.akashic-form-modal').hide();
    });

    $(window).on('click', function(e) {
        if ($(e.target).hasClass('akashic-form-modal')) {
            $(e.target).hide();
        }
    });
});