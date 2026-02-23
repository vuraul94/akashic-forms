jQuery(document).ready(function($) {

    $('.akashic-form').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);
        const submitButton = form.find('input[type="submit"][name="akashic_form_submit"]');
        const originalButtonText = submitButton.val();
        const submittingButtonText = submitButton.data('submitting-text') || 'Sending...';

        // Clear previous errors
        form.find('.akashic-field-error').remove();
        form.find('.akashic-error-field-container').removeClass('akashic-error-field-container');

        // Client-side validation
        let hasErrors = false;

        // Email validation
        form.find('input[type="email"]').each(function() {
            const input = $(this);
            const value = input.val();
            const fieldName = input.attr('name');
            const validationMessage = input.data('validation-message') || 'Please enter a valid email address.';
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (value && !emailRegex.test(value)) {
                hasErrors = true;
                const fieldContainer = form.find('.field-container--' + fieldName);
                if (fieldContainer.length) {
                    fieldContainer.addClass('akashic-error-field-container');
                    fieldContainer.append('<p class="akashic-field-error">' + validationMessage + '</p>');
                }
            }
        });

        // Pattern validation
        form.find('input[data-pattern], textarea[data-pattern]').each(function() {
            const input = $(this);
            const pattern = input.data('pattern');
            const value = input.val();
            const fieldName = input.attr('name');
            const validationMessage = input.data('validation-message') || 'Invalid format.';

            if (pattern && value) {
                const regex = new RegExp('^' + pattern + '$');
                if (!regex.test(value)) {
                    hasErrors = true;
                    const fieldContainer = form.find('.field-container--' + fieldName);
                    if (fieldContainer.length) {
                        fieldContainer.addClass('akashic-error-field-container');
                        fieldContainer.append('<p class="akashic-field-error">' + validationMessage + '</p>');
                    }
                }
            }
        });

        if (hasErrors) {
            return;
        }

        submitButton.val(submittingButtonText).prop('disabled', true);

        // First, get a fresh nonce to avoid caching issues
        $.ajax({
            url: akashicForms.ajax_url,
            type: 'GET',
            data: {
                action: 'akashic_forms_get_nonce'
            },
            success: function(response) {
                if (response.success && response.data.nonce) {
                    // Now that we have a fresh nonce, submit the form
                    submitFormWithNonce(form, response.data.nonce);
                } else {
                    alert('Could not verify security. Please reload the page and try again.');
                    submitButton.val(originalButtonText).prop('disabled', false);
                }
            },
            error: function() {
                alert('An error occurred while preparing the form. Please reload the page and try again.');
                submitButton.val(originalButtonText).prop('disabled', false);
            }
        });
    });

    function submitFormWithNonce(form, nonce) {
        const formId = form.data('form-id');
        const formData = new FormData(form[0]);
        const submissionAction = form.data('submission-action');
        const submitButton = form.find('input[type="submit"][name="akashic_form_submit"]');
        const originalButtonText = submitButton.data('original-text') || 'Submit';

        formData.append('form_id', formId);
        formData.append('submitted_at', new Date().toISOString());

        $.ajax({
            url: akashicForms.rest_url + '/sync',
            type: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if ('message' === submissionAction) {
                    $('#akashic-form-container-' + formId + ' .akashic-form').hide();
                    $('#form-title').hide();
                    $('#form-subtitle').hide();
                    $('#akashic-form-container-' + formId + ' .akashic-form-message').show();
                } else if ('modal' === submissionAction) {
                    $('#akashic-form-modal-' + formId).show();
                } else if ('redirect' === submissionAction) {
                    window.location.href = form.data('redirect-url');
                }
            },
            error: function(response) {
                form.find('.akashic-field-error').remove();
                form.find('.akashic-error-field-container').removeClass('akashic-error-field-container');

                if (response.responseJSON && response.responseJSON.errors) {
                    const errors = response.responseJSON.errors;
                    for (const fieldName in errors) {
                        if (errors.hasOwnProperty(fieldName)) {
                            const errorMessage = errors[fieldName];
                            const fieldContainer = form.find('.field-container--' + fieldName);
                            if (fieldContainer.length) {
                                fieldContainer.addClass('akashic-error-field-container');
                                fieldContainer.append('<p class="akashic-field-error">' + errorMessage + '</p>');
                            }
                        }
                    }
                } else {
                    // If the error is the nonce one, provide a more helpful message
                    if (response.responseJSON && response.responseJSON.code === 'rest_cookie_invalid_nonce') {
                        alert('Your session has expired. Please reload the page and try again.');
                    } else {
                        alert('An unknown error occurred. Please try again.');
                    }
                }
            },
            complete: function() {
                submitButton.val(originalButtonText).prop('disabled', false);
            }
        });
    }

    // Handle Help Modals
    $('.akashic-help-button').on('click', function(e) {
        e.preventDefault();
        var modalId = $(this).data('modal-id');
        $('#' + modalId).show();
    });

    // Combined close handler for all modals
    $('.akashic-form-modal-close, .akashic-help-modal-close').on('click', function() {
        $(this).closest('.akashic-form-modal, .akashic-help-modal').hide();
    });

    // Combined window click handler for all modals
    $(window).on('click', function(e) {
        if ($(e.target).hasClass('akashic-form-modal') || $(e.target).hasClass('akashic-help-modal')) {
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
