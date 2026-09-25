/**
 * Aroseglink Services Limited - Universal Form Submission Handler
 * Connects all website forms to info@aroseglinkserviceslimited.com
 * Supports native mailer.php with FormSubmit fallback for 100% reliable delivery.
 */

(function ($) {
    'use strict';

    var TARGET_EMAIL = 'info@aroseglinkserviceslimited.com';
    var FORMSUBMIT_URL = 'https://formsubmit.co/ajax/' + TARGET_EMAIL;

    // Helper to show styled message
    function showMessage($container, type, text) {
        $container.empty();
        var alertClass = (type === 'success') ? 'alert-success' : 'alert-danger';
        var icon = (type === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation';
        var bg = (type === 'success') ? '#e8f5e9' : '#ffebee';
        var color = (type === 'success') ? '#2e7d32' : '#c62828';
        var border = (type === 'success') ? '#c8e6c9' : '#ffcdd2';

        var $alert = $(
            '<div style="background:' + bg + '; color:' + color + '; border: 1px solid ' + border + '; padding: 12px 16px; border-radius: 8px; margin-top: 15px; font-size: 14px; line-height: 1.5; display: flex; align-items: center; gap: 10px;">' +
            '<i class="fa-solid ' + icon + '" style="font-size: 18px; flex-shrink: 0;"></i>' +
            '<div>' + text + '</div>' +
            '</div>'
        );
        $container.html($alert);
    }

    function handleFormSubmit(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"], button.rts-btn').first();
        var originalBtnText = $btn.html();

        // Locate or create message feedback element
        var $msgContainer = $form.find('.form-messages');
        if (!$msgContainer.length) {
            $msgContainer = $('#form-messages');
        }
        if (!$msgContainer.length) {
            $msgContainer = $('<div class="form-messages mt--15"></div>');
            $form.append($msgContainer);
        }

        // Serialize fields into object and FormData
        var formArray = $form.serializeArray();
        var payload = {};
        $.each(formArray, function () {
            payload[this.name] = this.value;
        });

        // Ensure recipient & subject defaults
        if (!payload.form_type) {
            payload.form_type = $form.attr('id') || 'General Website Inquiry';
        }
        if (!payload._subject) {
            payload._subject = 'New Submission: ' + payload.form_type + ' - Aroseglink Website';
        }
        payload._template = 'table';
        payload._captcha = 'false';

        // UI state: sending
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin" style="margin-right: 8px;"></i> Sending...');
        $msgContainer.empty();

        // Primary: Try mailer.php
        $.ajax({
            type: 'POST',
            url: 'mailer.php',
            data: JSON.stringify(payload),
            contentType: 'application/json; charset=utf-8',
            dataType: 'json',
            timeout: 6000
        })
        .done(function (response) {
            $btn.prop('disabled', false).html(originalBtnText);
            showMessage($msgContainer, 'success', response.message || 'Thank you! Your message has been sent successfully. We will get back to you shortly.');
            $form[0].reset();
        })
        .fail(function () {
            // Fallback: Post to FormSubmit API directly
            $.ajax({
                type: 'POST',
                url: FORMSUBMIT_URL,
                data: JSON.stringify(payload),
                contentType: 'application/json; charset=utf-8',
                dataType: 'json',
                timeout: 8000
            })
            .done(function () {
                $btn.prop('disabled', false).html(originalBtnText);
                showMessage($msgContainer, 'success', 'Thank you! Your submission has been received successfully. Our team will contact you shortly.');
                $form[0].reset();
            })
            .fail(function () {
                $btn.prop('disabled', false).html(originalBtnText);
                showMessage($msgContainer, 'error', 'Unable to send message right now. Please email us directly at <a href="mailto:' + TARGET_EMAIL + '" style="color: inherit; text-decoration: underline; font-weight: 600;">' + TARGET_EMAIL + '</a> or call +234 814 969 9767.');
            });
        });
    }

    $(document).ready(function () {
        // Contact page form
        $(document).on('submit', '#contact-form', handleFormSubmit);

        // Home page consultation/quote form
        $(document).on('submit', '#appointment-form', handleFormSubmit);

        // Study abroad student consultation form
        $(document).on('submit', '#student-consultation-form', handleFormSubmit);

        // Newsletter subscription forms
        $(document).on('submit', '.newsletter-form', handleFormSubmit);
    });

})(jQuery);
