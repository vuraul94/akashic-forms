jQuery(document).ready(function($) {

    $('.akashic-form').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);
        const formId = form.data('form-id');
        const formData = new FormData(this);
        const submissionAction = form.data('submission-action');
        const submitButton = form.find('input[type="submit"][name="akashic_form_submit"]'); // Get the submit button
        const originalButtonText = submitButton.val(); // Store original text
        const submittingButtonText = submitButton.data('submitting-text') || 'Sending...'; // Get submitting text

        formData.append('form_id', formId); // Append form_id directly
        formData.append('submitted_at', new Date().toISOString()); // Append submitted_at directly

        $.ajax({
            url: akashicForms.rest_url + '/sync',
            type: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', akashicForms.nonce);
                submitButton.val(submittingButtonText).prop('disabled', true); 
            },
            data: formData, // Send the FormData object directly
            processData: false, // Don't process the data
            contentType: false, // Don't set content type,
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
                // Clear previous errors
                form.find('.akashic-field-error').remove();
                form.find('.akashic-error-field').removeClass('akashic-error-field');

                if (response.responseJSON && response.responseJSON.errors) {
                    const errors = response.responseJSON.errors;
                    for (const fieldName in errors) {
                        if (errors.hasOwnProperty(fieldName)) {
                            const errorMessage = errors[fieldName];
                            console.log('Processing error for field:', fieldName, 'Message:', errorMessage);
                            const fieldContainer = form.find('.field-container--' + fieldName);
                            if (fieldContainer.length) {
                                fieldContainer.addClass('akashic-error-field-container'); // Add class to container
                                fieldContainer.append('<p class="akashic-field-error">' + errorMessage + '</p>');
                            }
                        }
                    }
                } else {
                    alert('An unknown error occurred. Please try again.');
                }
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

    // Clear error message when file input changes
    $('.akashic-form').on('change', 'input[type="file"]', function() {
        const fieldContainer = $(this).closest('.field-container--' + $(this).attr('name'));
        if (fieldContainer.length) {
            fieldContainer.removeClass('akashic-error-field-container');
            fieldContainer.find('.akashic-field-error').remove();
        }
    });
});