/**
 * Lessclicks Admin Scripts
 *
 * @package Lessclicks
 */

(function ($) {
	'use strict';

	$(
		function () {
			$( '.lessclicks-review-notice' ).on(
				'click',
				'.notice-dismiss',
				function (event) {
					event.preventDefault();

					var $notice = $( this ).closest( '.lessclicks-review-notice' );

					$notice.fadeOut(
						200,
						function () {
							$notice.remove();
						}
					);

					if (typeof lessclicksAdminData === 'undefined' || ! lessclicksAdminData.ajaxUrl) {
						return;
					}

					$.post(
						lessclicksAdminData.ajaxUrl,
						{
							action: 'lessclicks_review_snooze',
							nonce: lessclicksAdminData.nonce
						}
					);
				}
			);
		}
	);
})( jQuery );
