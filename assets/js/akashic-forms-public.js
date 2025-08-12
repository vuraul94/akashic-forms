jQuery(document).ready(function($) {

    $('.akashic-form').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);
        const formId = form.data('form-id');
        const formData = new FormData(this);
        const submissionAction = form.data('submission-action');
        const submitButton = form.find('input[type="submit"][name="akashic_form_submit"]'); // Get the submit button
        const originalButtonText = submitButton.val(); // Store original text
        const submittingButtonText = submitButton.data('submitting-text') || 'Submitting...'; // Get submitting text

        let data = {};
        for (let [key, value] of formData.entries()) {
            data[key] = value;
        }
        data['submitted_at'] = new Date().toISOString();

        $.ajax({
            url: akashicForms.rest_url + '/sync',
            type: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', akashicForms.nonce);
                submitButton.val(submittingButtonText).prop('disabled', true); 
            },
            data: {
                form_id: formId,
                form_data: data
            },
            success: function(response) {
                
                if ('message' === submissionAction) {
                    $('#akashic-form-container-' + formId + ' .akashic-form').hide();
                    $('#akashic-form-container-' + formId + ' .akashic-form-message').show();
                } else if ('modal' === submissionAction) {
                    $('#akashic-form-modal-' + formId).show();
                } else if ('redirect' === submissionAction) {
                    window.location.href = form.attr('action');
                }
            },
            error: function(response) {
                alert('An error occurred. Please try again.');
            },
            complete: function() { 
                submitButton.val(originalButtonText).prop('disabled', false); 
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