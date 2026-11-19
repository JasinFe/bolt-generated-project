(function () {
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-kmb-copy]');
		if (!btn) { return; }
		var url = btn.getAttribute('data-kmb-copy');
		var label = btn.textContent;
		var done = function () {
			btn.textContent = 'Lien copié ✓';
			setTimeout(function () { btn.textContent = label; }, 2000);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(url).then(done);
		} else {
			var t = document.createElement('textarea');
			t.value = url;
			document.body.appendChild(t);
			t.select();
			try { document.execCommand('copy'); done(); } catch (err) {}
			document.body.removeChild(t);
		}
	});
})();
