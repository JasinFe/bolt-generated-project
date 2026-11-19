/* KM Blog — interactions légères, sans dépendance. */
(function () {
	'use strict';

	var live = function (msg) {
		var el = document.querySelector('[data-kmb-live]');
		if (el) {
			el.textContent = '';
			setTimeout(function () { el.textContent = msg; }, 50);
		}
	};

	/* ---------- Copier le lien ---------- */
	var fallbackCopy = function (text) {
		var t = document.createElement('textarea');
		t.value = text;
		t.setAttribute('readonly', '');
		t.style.position = 'fixed';
		t.style.opacity = '0';
		document.body.appendChild(t);
		t.select();
		var ok = false;
		try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
		document.body.removeChild(t);
		return ok;
	};

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-kmb-copy]') : null;
		if (!btn) { return; }
		var url = btn.getAttribute('data-kmb-copy');
		var label = btn.querySelector('[data-kmb-copy-label]') || btn;
		if (!btn.hasAttribute('data-label')) { btn.setAttribute('data-label', label.textContent); }
		var original = btn.getAttribute('data-label');

		var feedback = function (ok) {
			label.textContent = ok ? 'Lien copié ✓' : 'Copie impossible';
			btn.classList.toggle('is-done', ok);
			live(ok ? 'Lien copié dans le presse-papiers' : 'La copie du lien a échoué');
			clearTimeout(btn._kmbTimer);
			btn._kmbTimer = setTimeout(function () {
				label.textContent = original;
				btn.classList.remove('is-done');
			}, 2200);
		};

		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(url).then(function () { feedback(true); }, function () { feedback(fallbackCopy(url)); });
		} else {
			feedback(fallbackCopy(url));
		}
	});

	/* ---------- Partage natif (mobile) ---------- */
	var nativeBtn = document.querySelector('[data-kmb-share]');
	if (nativeBtn && navigator.share) {
		nativeBtn.hidden = false;
		nativeBtn.addEventListener('click', function () {
			navigator.share({
				title: nativeBtn.getAttribute('data-title'),
				url: nativeBtn.getAttribute('data-url')
			}).catch(function () {});
		});
	}

	/* ---------- Sommaire automatique ---------- */
	var content = document.querySelector('.kmb-single .kmb-content');
	var toc = document.querySelector('.kmb-toc');
	if (content && toc) {
		var heads = content.querySelectorAll('h2');
		if (heads.length >= 3) {
			var list = toc.querySelector('.kmb-toc__list');
			var used = {};
			var slug = function (s) {
				var base = (s || '').toLowerCase()
					.normalize('NFD').replace(/[̀-ͯ]/g, '')
					.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'section';
				var id = base, n = 2;
				while (used[id] || document.getElementById(id)) { id = base + '-' + n++; }
				used[id] = true;
				return id;
			};
			Array.prototype.forEach.call(heads, function (h) {
				if (!h.id) { h.id = slug(h.textContent); }
				var li = document.createElement('li');
				var a = document.createElement('a');
				a.href = '#' + h.id;
				a.textContent = h.textContent.trim();
				li.appendChild(a);
				list.appendChild(li);
			});
			toc.hidden = false;

			if ('IntersectionObserver' in window) {
				var links = list.querySelectorAll('a');
				var io = new IntersectionObserver(function (entries) {
					entries.forEach(function (en) {
						if (en.isIntersecting) {
							Array.prototype.forEach.call(links, function (l) {
								var on = l.getAttribute('href') === '#' + en.target.id;
								l.classList.toggle('is-active', on);
								if (on) { l.setAttribute('aria-current', 'true'); } else { l.removeAttribute('aria-current'); }
							});
						}
					});
				}, { rootMargin: '0px 0px -70% 0px' });
				Array.prototype.forEach.call(heads, function (h) { io.observe(h); });
			}
		}
	}

	/* ---------- Barre de progression de lecture ---------- */
	var bar = document.querySelector('.kmb-progress__bar');
	var article = document.querySelector('.kmb-single .kmb-content');
	if (bar && article) {
		var ticking = false;
		var update = function () {
			ticking = false;
			var r = article.getBoundingClientRect();
			var total = r.height - window.innerHeight * 0.6;
			var p = total > 0 ? Math.min(1, Math.max(0, -r.top / total)) : 1;
			bar.style.transform = 'scaleX(' + p.toFixed(4) + ')';
		};
		var onScroll = function () {
			if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
		};
		window.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('resize', onScroll, { passive: true });
		update();
	}
})();
