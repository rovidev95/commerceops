(function ($) {
	'use strict';

	function showMessage(text, type) {
		var $el = $('#co-license-message');
		$el.text(text).removeClass('is-error is-success').addClass(type === 'error' ? 'is-error' : 'is-success');
	}

	$('#co-activate-license').on('click', function () {
		var key = $('#co-license-key').val();
		var nonce = $(this).data('nonce');
		$.post(ajaxurl, {
			action: 'commerceops_activate_license',
			license_key: key,
			nonce: nonce
		}).done(function (resp) {
			if (resp.success) {
				showMessage(resp.data.message || commerceOpsAdmin.i18n.activated, 'success');
				window.location.reload();
			} else {
				showMessage((resp.data && resp.data.message) || commerceOpsAdmin.i18n.error, 'error');
			}
		}).fail(function () {
			showMessage(commerceOpsAdmin.i18n.error, 'error');
		});
	});

	$('#co-deactivate-license').on('click', function () {
		var nonce = $(this).data('nonce');
		$.post(ajaxurl, {
			action: 'commerceops_deactivate_license',
			nonce: nonce
		}).done(function (resp) {
			if (resp.success) {
				window.location.reload();
			} else {
				showMessage((resp.data && resp.data.message) || commerceOpsAdmin.i18n.error, 'error');
			}
		});
	});
})(jQuery);
