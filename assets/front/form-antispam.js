/**
 * Stamp Elementor forms with the time the page was opened (not when the form appeared).
 * Submits faster than the server threshold are treated as spam.
 */
(function () {
	var KEY = 'inyfinn_form_ts';
	var opened =
		window.performance && performance.timeOrigin
			? Math.round(performance.timeOrigin)
			: Date.now();

	function stamp() {
		document.querySelectorAll('form.elementor-form').forEach(function (form) {
			var input = form.querySelector('input[name="' + KEY + '"]');
			if (!input) {
				input = document.createElement('input');
				input.type = 'hidden';
				input.name = KEY;
				form.appendChild(input);
			}
			input.value = String(opened);
		});
	}

	var timer;
	function schedule() {
		clearTimeout(timer);
		timer = setTimeout(stamp, 50);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', stamp);
	} else {
		stamp();
	}
	document.addEventListener('elementor/popup/show', stamp);
	if (window.jQuery) {
		window.jQuery(document).on('elementor/popup/show', stamp);
	}
	if (window.MutationObserver) {
		new MutationObserver(schedule).observe(document.documentElement, {
			childList: true,
			subtree: true,
		});
	}
})();
