jQuery(document).ready(function ($) {
	jQuery(document).on(
		'click',
		'.cashu-review-notice button.cashu-review-dismiss',
		function (e) {
			e.preventDefault();
			$.ajax({
				url: cashuNotifications.ajax_url,
				type: 'post',
				data: {
					action: 'cashu_notifications',
					nonce: cashuNotifications.nonce,
				},
				success: function (data) {
					jQuery('.cashu-review-notice').remove();
				},
			});
		},
	);
	jQuery(document).on(
		'click',
		'.cashu-review-notice button.cashu-review-dismiss-forever',
		function (e) {
			e.preventDefault();
			$.ajax({
				url: cashuNotifications.ajax_url,
				type: 'post',
				data: {
					action: 'cashu_notifications',
					nonce: cashuNotifications.nonce,
					dismiss_forever: true,
				},
				success: function (data) {
					jQuery('.cashu-review-notice').remove();
				},
			});
		},
	);
});
