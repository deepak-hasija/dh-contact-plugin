/**
 * DH Contact — Frontend form handler
 * Handles AJAX submission, validation feedback, success/error states
 */
(function () {
    'use strict';

    function dhcInit() {
        // Find the submit button — our form uses a <button type="submit"> with class dh-submit
        const submitBtn = document.querySelector('.dh-submit');
        if (!submitBtn) return;

        // Walk up to find the containing form-like div (our form has no <form> tag yet)
        // We'll gather inputs by name attribute instead
        submitBtn.addEventListener('click', function (e) {
            e.preventDefault();
            dhcSubmit(submitBtn);
        });

        // Also handle Enter key on inputs
        document.querySelectorAll('.dh-input, .dh-textarea, .dh-select').forEach(function (el) {
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && el.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    dhcSubmit(submitBtn);
                }
            });
        });
    }

    function dhcSubmit(btn) {
        // Gather all named fields
        const fields = document.querySelectorAll('[name]');
        const data   = new FormData();

        fields.forEach(function (f) {
            if (f.name && f.name !== '') {
                data.append(f.name, f.value || '');
            }
        });

        data.append('action', 'dh_contact_submit');
        data.append('nonce',  dhcAjax.nonce);

        // Client-side validation
        const name    = data.get('name')    || '';
        const email   = data.get('email')   || '';
        const service = data.get('service') || '';
        const message = data.get('message') || '';

        if (!name.trim()) {
            dhcShowError(btn, 'Please enter your name.');
            focusField('name');
            return;
        }
        if (!email.trim() || !dhcValidEmail(email)) {
            dhcShowError(btn, 'Please enter a valid email address.');
            focusField('email');
            return;
        }
        if (!service) {
            dhcShowError(btn, 'Please select the service you need.');
            focusField('service');
            return;
        }
        if (!message.trim()) {
            dhcShowError(btn, 'Please describe your project.');
            focusField('message');
            return;
        }

        // Loading state
        const originalText = btn.innerHTML;
        btn.innerHTML      = 'Sending\u2026';
        btn.disabled       = true;
        btn.style.opacity  = '0.75';
        dhcClearMessages(btn);

        fetch(dhcAjax.ajaxurl, {
            method: 'POST',
            body:   data,
            credentials: 'same-origin',
        })
        .then(function (res) { return res.json(); })
        .then(function (res) {
            if (res.success) {
                if (res.data && res.data.redirect) {
                    window.location.href = res.data.redirect;
                    return;
                }
                const msg = (res.data && res.data.message) || "Thank you \u2014 I'll be in touch within 24 hours.";
                dhcShowSuccess(btn, msg);
                dhcResetForm();
            } else {
                const errMsg = (res.data && res.data.message) || 'Something went wrong. Please try again.';
                dhcShowError(btn, errMsg);
                btn.innerHTML  = originalText;
                btn.disabled   = false;
                btn.style.opacity = '1';
            }
        })
        .catch(function () {
            dhcShowError(btn, 'Network error. Please check your connection and try again.');
            btn.innerHTML  = originalText;
            btn.disabled   = false;
            btn.style.opacity = '1';
        });
    }

    function dhcValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
    }

    function focusField(name) {
        const el = document.querySelector('[name="' + name + '"]');
        if (el) {
            el.focus();
            el.style.borderColor = '#EF4444';
            setTimeout(function () { el.style.borderColor = ''; }, 3000);
        }
    }

    function dhcShowSuccess(btn, message) {
        dhcClearMessages(btn);
        const el = document.createElement('div');
        el.className = 'dhc-success-msg';
        el.style.cssText = [
            'background:#F0FDFA',
            'border:1px solid rgba(13,148,136,0.3)',
            'border-radius:6px',
            'padding:14px 18px',
            'margin-top:14px',
            'font-size:14px',
            'color:#065F46',
            'line-height:1.6',
            "font-family:'Source Sans 3',sans-serif",
            'display:flex',
            'align-items:center',
            'gap:10px',
        ].join(';');
        el.innerHTML = '<span style="font-size:18px;">&#10003;</span><span>' + message + '</span>';
        btn.parentNode.insertBefore(el, btn.nextSibling);
        btn.innerHTML = 'Sent \u2713';
        btn.style.background = '#059669';
    }

    function dhcShowError(btn, message) {
        dhcClearMessages(btn);
        const el = document.createElement('div');
        el.className = 'dhc-error-msg';
        el.style.cssText = [
            'background:#FEF2F2',
            'border:1px solid rgba(239,68,68,0.3)',
            'border-radius:6px',
            'padding:14px 18px',
            'margin-top:14px',
            'font-size:14px',
            'color:#991B1B',
            "font-family:'Source Sans 3',sans-serif",
        ].join(';');
        el.textContent = message;
        btn.parentNode.insertBefore(el, btn.nextSibling);
    }

    function dhcClearMessages(btn) {
        const existing = btn.parentNode.querySelectorAll('.dhc-success-msg, .dhc-error-msg');
        existing.forEach(function (el) { el.remove(); });
    }

    function dhcResetForm() {
        document.querySelectorAll('.dh-input, .dh-textarea').forEach(function (el) {
            el.value = '';
        });
        document.querySelectorAll('.dh-select').forEach(function (el) {
            el.selectedIndex = 0;
        });
    }

    // Init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', dhcInit);
    } else {
        dhcInit();
    }
})();
