jQuery(document).ready(function($) {

    // Localized strings injected by WordPress.
    const i18n = (window.akashicForms && akashicForms.i18n) ? akashicForms.i18n : {};

    // Minimal sprintf replacement: substitutes the first %s / %d placeholder.
    function formatString(template, value) {
        return String(template || '').replace(/%[sd]/, value);
    }

    // Helper function to escape special characters in CSS selectors
    function escapeSelector(name) {
        return name.replace(/([[\]])/g, '\\$1');
    }

    $('.akashic-form').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);
        const submitButton = form.find('input[type="submit"][name="akashic_form_submit"]');
        const originalButtonText = submitButton.val();
        const submittingButtonText = submitButton.data('submitting-text') || 'Sending...';

        // Remember the real button text so it can be restored once the request completes.
        submitButton.data('original-text', originalButtonText);

        // Clear previous errors
        form.find('.akashic-field-error').remove();
        form.find('.akashic-error-field-container').removeClass('akashic-error-field-container');

        // Client-side validation
        let hasErrors = false;

        // Required fields validation (text, email, number, select)
        form.find('input[data-required="1"], select[data-required="1"], textarea[data-required="1"]').each(function() {
            const input = $(this);
            const rawName = input.attr('name') || '';
            const fieldName = rawName.replace('[]', '');
            const label = input.data('label') || i18n.thisField;
            let isEmpty = false;

            if (input.attr('type') === 'file') {
                isEmpty = !input[0].files || input[0].files.length === 0;
            } else if (input.is('select')) {
                isEmpty = !input.val() || input.val() === fieldName;
            } else {
                isEmpty = !input.val() || input.val().trim() === '';
            }

            if (isEmpty) {
                hasErrors = true;
                const fieldContainer = form.find('.field-container--' + escapeSelector(fieldName));
                if (fieldContainer.length && !fieldContainer.find('.akashic-field-error').length) {
                    fieldContainer.addClass('akashic-error-field-container');
                    fieldContainer.append('<p class="akashic-field-error">' + formatString(i18n.required, label) + '</p>');
                }
            }
        });

        // File upload required validation (by ID)
        form.find('.field-container.file').each(function() {
            const container = $(this);
            const input = container.find('input[type="file"]');
            if (input.length && input.data('required') == 1) {
                if (!input[0].files || input[0].files.length === 0) {
                    hasErrors = true;
                    const label = input.data('label') || i18n.file;
                    if (!container.find('.akashic-field-error').length) {
                        container.addClass('akashic-error-field-container');
                        container.append('<p class="akashic-field-error">' + formatString(i18n.required, label) + '</p>');
                    }
                }
            }
        });

        // Email validation
        form.find('input[type="email"]').each(function() {
            const input = $(this);
            const value = input.val();
            const fieldName = input.attr('name');
            const validationMessage = input.data('validation-message') || i18n.invalidEmail;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (value && !emailRegex.test(value)) {
                hasErrors = true;
                const fieldContainer = form.find('.field-container--' + escapeSelector(fieldName));
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
            const validationMessage = input.data('validation-message') || i18n.invalidFormat;

            if (pattern && value) {
                const regex = new RegExp('^' + pattern + '$');
                if (!regex.test(value)) {
                    hasErrors = true;
                    const fieldContainer = form.find('.field-container--' + escapeSelector(fieldName));
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
                    alert(i18n.securityFailed);
                    submitButton.val(originalButtonText).prop('disabled', false);
                }
            },
            error: function() {
                alert(i18n.prepareError);
                submitButton.val(originalButtonText).prop('disabled', false);
            }
        });
    });

    function submitFormWithNonce(form, nonce) {
        const formId = form.data('form-id');
        const formData = new FormData(form[0]);
        const submissionAction = form.data('submission-action');
        const submitButton = form.find('input[type="submit"][name="akashic_form_submit"]');
        const originalButtonText = submitButton.data('original-text') || submitButton.val();

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
                            const fieldContainer = form.find('.field-container--' + escapeSelector(fieldName));
                            if (fieldContainer.length) {
                                fieldContainer.addClass('akashic-error-field-container');
                                fieldContainer.append('<p class="akashic-field-error">' + errorMessage + '</p>');
                            }
                        }
                    }
                } else if (response.status === 429) {
                    // Rate limit reached for this IP.
                    alert(i18n.rateLimited);
                } else {
                    // If the error is the nonce one, provide a more helpful message
                    if (response.responseJSON && response.responseJSON.code === 'rest_cookie_invalid_nonce') {
                        alert(i18n.sessionExpired);
                    } else {
                        alert(i18n.unknownError);
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
        const fieldName = ($(this).attr('name') || '').replace('[]', '');
        const fieldContainer = $(this).closest('.field-container--' + escapeSelector(fieldName));
        if (fieldContainer.length) {
            fieldContainer.removeClass('akashic-error-field-container');
            fieldContainer.find('.akashic-field-error').remove();
        }
    });

    // File uploader: one delegated set of handlers for every file field.
    function updateUploaderDisplay(input, files) {
        const wrapper = $(input).closest('.sardimar-uploader-wrapper');
        const fieldName = wrapper.data('field-name');
        let display = fieldName ? wrapper.find('#display-' + escapeSelector(String(fieldName))) : $();

        if (!display.length) {
            display = wrapper.find('.sub-text');
        }

        if (!display.length || !files) {
            return;
        }

        if (files.length === 1) {
            display.text(formatString(i18n.fileSelected, files[0].name));
        } else if (files.length > 1) {
            display.text(formatString(i18n.filesSelected, files.length));
        }

        display.css({ 'color': '#004a99', 'font-weight': 'bold' });
    }

    $(document).on('change', '.sardimar-uploader-wrapper .real-input', function() {
        if (this.files) {
            updateUploaderDisplay(this, this.files);
        }
    });

    $(document).on('dragover dragleave drop', '.sardimar-uploader-wrapper .drop-zone', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const zone = $(this);

        if ('dragover' === e.type) {
            zone.addClass('drag-over');
        } else {
            zone.removeClass('drag-over');
        }

        if ('drop' === e.type) {
            const input = zone.find('.real-input')[0];
            const dataTransfer = e.originalEvent ? e.originalEvent.dataTransfer : null;
            const droppedFiles = dataTransfer ? dataTransfer.files : null;

            if (input && droppedFiles && droppedFiles.length > 0) {
                input.files = droppedFiles;
                updateUploaderDisplay(input, droppedFiles);
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    });
});
