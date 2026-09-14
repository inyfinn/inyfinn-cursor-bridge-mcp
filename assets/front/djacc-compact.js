/**
 * Compact DJ Accessibility popup: hide extras behind "Show more features".
 * Does not edit the plugin templates (updates would wipe them).
 */
(function () {
	var labels = window.inyfinnDjaccCompact || {
		more: 'Rozwiń więcej funkcji',
		less: 'Zwiń dodatkowe funkcje',
	};

	function setup(panel) {
		if (!panel || panel.getAttribute('data-inyfinn-djacc') === '1') {
			return;
		}
		var list = panel.querySelector('.djacc__list');
		if (!list) {
			return;
		}

		panel.classList.add('inyfinn-djacc--compact');
		panel.setAttribute('data-inyfinn-djacc', '1');

		var extras = [];
		Array.prototype.forEach.call(list.children, function (li) {
			if (
				li.classList.contains('djacc__item') &&
				!li.classList.contains('djacc__item--full') &&
				!li.classList.contains('inyfinn-djacc-more')
			) {
				extras.push(li);
			}
		});
		if (!extras.length) {
			return;
		}

		var wrap = document.createElement('li');
		wrap.className = 'djacc__item djacc__item--full inyfinn-djacc-more';

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'djacc__btn inyfinn-djacc-more__btn';
		btn.textContent = labels.more;
		btn.setAttribute('aria-expanded', 'false');

		btn.addEventListener('click', function () {
			var on = panel.classList.toggle('inyfinn-djacc--expanded');
			btn.setAttribute('aria-expanded', on ? 'true' : 'false');
			btn.textContent = on ? labels.less : labels.more;
		});

		wrap.appendChild(btn);

		var firstSlider = list.querySelector(':scope > li.djacc__item--full');
		if (firstSlider) {
			list.insertBefore(wrap, firstSlider);
		} else {
			list.appendChild(wrap);
		}
	}

	function run() {
		document.querySelectorAll('.djacc .djacc__panel').forEach(setup);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}

	var timer;
	if (window.MutationObserver) {
		new MutationObserver(function () {
			clearTimeout(timer);
			timer = setTimeout(run, 80);
		}).observe(document.documentElement, { childList: true, subtree: true });
	}
})();
