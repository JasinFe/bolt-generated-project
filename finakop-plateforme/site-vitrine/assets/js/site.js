/* FinaKop ERP — site vitrine : animations et formulaire. Aucune dépendance. */
(function () {
  'use strict';
  var doc = document.documentElement;
  doc.classList.add('js');
  var calme = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var WA = '2250503404389';

  document.addEventListener('DOMContentLoaded', function () {
    var an = $('#annee'); if (an) an.textContent = new Date().getFullYear();

    /* En-tête, progression, retour en haut */
    var entete = $('#entete'), prog = $('#progres'), haut = $('#haut-btn');
    function auDefilement() {
      var y = window.scrollY, h = doc.scrollHeight - innerHeight;
      entete.classList.toggle('colle', y > 20);
      prog.style.transform = 'scaleX(' + (h > 0 ? y / h : 0) + ')';
      haut.classList.toggle('on', y > 900);
    }
    addEventListener('scroll', auDefilement, { passive: true }); auDefilement();
    haut.addEventListener('click', function () { scrollTo({ top: 0, behavior: calme ? 'auto' : 'smooth' }); });

    /* Menu mobile */
    var mb = $('#menuBtn');
    mb.addEventListener('click', function () {
      var o = entete.classList.toggle('menu-ouvert');
      mb.setAttribute('aria-expanded', o ? 'true' : 'false');
    });
    $$('#nav a').forEach(function (a) { a.addEventListener('click', function () { entete.classList.remove('menu-ouvert'); mb.setAttribute('aria-expanded', 'false'); }); });

    /* Bulle WhatsApp (une fois, après 7 s) */
    var bulle = $('#waBulle');
    setTimeout(function () { bulle.classList.add('on'); setTimeout(function () { bulle.classList.remove('on'); }, 6000); }, 7000);

    /* Compteurs */
    function fr(n) { return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
    function compter(el) {
      if (el.dataset.fait) return; el.dataset.fait = 1;
      var fin = +el.dataset.compte, suf = el.dataset.suffixe || '', d = 1600, t0 = null;
      if (calme) { el.textContent = fr(fin) + suf; return; }
      requestAnimationFrame(function pas(t) {
        if (!t0) t0 = t; var p = Math.min(1, (t - t0) / d), e = 1 - Math.pow(1 - p, 3);
        el.textContent = fr(fin * e) + suf; if (p < 1) requestAnimationFrame(pas);
      });
    }

    /* Apparition au défilement */
    var obs = 'IntersectionObserver' in window ? new IntersectionObserver(function (ents) {
      ents.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add('vu');
        $$('[data-compte]', e.target).forEach(compter);
        if (e.target.dataset.compte) compter(e.target);
        obs.unobserve(e.target);
      });
    }, { threshold: 0.18, rootMargin: '0px 0px -40px 0px' }) : null;
    $$('.rv, .chiffre strong').forEach(function (el) { obs ? obs.observe(el) : el.classList.add('vu'); });
    if (!obs) $$('[data-compte]').forEach(compter);
    $$('#ecranHeros [data-compte]').forEach(function (el) { setTimeout(function () { compter(el); }, 700); });

    /* Mots qui défilent */
    var rot = $$('#rotatif span'), ri = 0;
    if (rot.length && !calme) setInterval(function () {
      var a = rot[ri]; a.classList.remove('actif'); a.classList.add('sort');
      setTimeout(function () { a.classList.remove('sort'); }, 500);
      ri = (ri + 1) % rot.length; rot[ri].classList.add('actif');
    }, 2400);

    /* Notifications « en direct » du héros */
    var toasts = $('#toasts');
    var evts = [
      ['💰', 'Paiement Mobile Money reçu', '+ 45 000 F CFA · il y a 2 s'],
      ['🧾', 'Facture FA-2026-0413 certifiée', 'Facture normalisée · envoyée'],
      ['📦', 'Stock bas : Jus 50 cl', 'Commande fournisseur proposée'],
      ['📒', 'Écritures du jour générées', '42 écritures · journal des ventes'],
      ['👥', 'Paie d\'octobre validée', '24 bulletins · prêts à envoyer'],
      ['🏦', 'Relevé bancaire rapproché', '98 % des lignes lettrées']
    ], ti = 0;
    function toast() {
      var d = document.createElement('div'); var e = evts[ti++ % evts.length];
      d.className = 'toast'; d.innerHTML = '<span class="ic">' + e[0] + '</span><span><b></b><small></small></span>';
      d.querySelector('b').textContent = e[1]; d.querySelector('small').textContent = e[2];
      toasts.appendChild(d);
      setTimeout(function () { d.classList.add('sortie'); setTimeout(function () { d.remove(); }, 450); }, 4200);
    }
    if (toasts && !calme) { setTimeout(toast, 1600); setInterval(function () { if (!document.hidden) toast(); }, 2900); }

    /* Inclinaison 3D : écran du héros et cartes */
    var fin = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var eh = $('#ecranHeros');
    if (eh && fin && !calme) {
      var sc = eh.parentNode;
      sc.addEventListener('mousemove', function (ev) {
        var r = sc.getBoundingClientRect(), x = (ev.clientX - r.left) / r.width - .5, y = (ev.clientY - r.top) / r.height - .5;
        eh.style.animation = 'none';
        eh.style.transform = 'rotateY(' + (-14 + x * 16) + 'deg) rotateX(' + (7 - y * 12) + 'deg)';
      });
      sc.addEventListener('mouseleave', function () { eh.style.transform = ''; });
    }
    $$('[data-incline]').forEach(function (c) {
      c.addEventListener('mousemove', function (ev) {
        var r = c.getBoundingClientRect(), x = ev.clientX - r.left, y = ev.clientY - r.top;
        c.style.setProperty('--mx', x + 'px'); c.style.setProperty('--my', y + 'px');
        if (fin && !calme) c.style.transform = 'perspective(800px) rotateX(' + ((y / r.height - .5) * -8) + 'deg) rotateY(' + ((x / r.width - .5) * 10) + 'deg) translateY(-4px)';
      });
      c.addEventListener('mouseleave', function () { c.style.transform = ''; });
    });

    /* Bandes défilantes : contenu doublé pour une boucle sans à-coup */
    $$('[data-boucle]').forEach(function (b) {
      $$(':scope > span', b).forEach(function (s) { var c = s.cloneNode(true); c.setAttribute('aria-hidden', 'true'); b.appendChild(c); });
    });

    /* Démonstration par onglets */
    var demo = $('#demoTabs');
    if (demo) {
      var ongl = $$('.onglet', demo), vues = $$('.vue', demo), cur = 0, minu = null, DUREE = 7000;
      demo.style.setProperty('--duree', DUREE / 1000 + 's');
      function montrer(i, focus) {
        cur = (i + ongl.length) % ongl.length;
        ongl.forEach(function (o, k) {
          var on = k === cur; o.classList.toggle('actif', on); o.setAttribute('aria-selected', on ? 'true' : 'false'); o.tabIndex = on ? 0 : -1;
          var t = $('.temps', o); t.style.animation = 'none'; void t.offsetWidth; t.style.animation = '';
        });
        vues.forEach(function (v, k) {
          var on = k === cur; v.classList.toggle('actif', on);
          var ui = $('.ui', v); ui.classList.remove('anime'); if (on) { void ui.offsetWidth; ui.classList.add('anime'); }
        });
        if (focus) ongl[cur].focus();
        relancer();
      }
      function relancer() { clearTimeout(minu); if (!calme) minu = setTimeout(function () { if (!demo.classList.contains('pause')) montrer(cur + 1); else relancer(); }, DUREE); }
      ongl.forEach(function (o, k) {
        o.addEventListener('click', function () { montrer(k); });
        o.addEventListener('keydown', function (e) {
          if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); montrer(cur + 1, true); }
          if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { e.preventDefault(); montrer(cur - 1, true); }
        });
      });
      demo.addEventListener('mouseenter', function () { demo.classList.add('pause'); });
      demo.addEventListener('mouseleave', function () { demo.classList.remove('pause'); });
      var vuDemo = new IntersectionObserver(function (e) { if (e[0].isIntersecting) { montrer(cur); vuDemo.disconnect(); } }, { threshold: .3 });
      vuDemo.observe(demo);
    }

    /* Code 2FA qui change */
    var c2 = $('#code2fa');
    if (c2 && !calme) setInterval(function () {
      var n = ''; for (var i = 0; i < 6; i++) n += Math.floor(Math.random() * 10);
      c2.textContent = n.slice(0, 3) + ' ' + n.slice(3);
    }, 6000);

    /* Vidéos : bouton de lecture et chapitres */
    $$('.lire').forEach(function (b) {
      var v = document.getElementById(b.dataset.video);
      b.addEventListener('click', function () { b.hidden = true; v.play(); });
      v.addEventListener('play', function () { b.hidden = true; $$('video').forEach(function (o) { if (o !== v) o.pause(); }); });
    });
    $$('.chapitres').forEach(function (g) {
      var v = document.getElementById(g.dataset.cible);
      $$('button', g).forEach(function (b) {
        b.addEventListener('click', function () {
          var go = function () { v.currentTime = +b.dataset.t; v.play(); };
          if (v.readyState < 1) { v.preload = 'auto'; v.addEventListener('loadedmetadata', go, { once: true }); v.load(); } else go();
        });
      });
    });

    /* Visionneuse d'images */
    var vis = $('#visionneuse'), visImg = $('img', vis);
    $$('#galerieImgs figure').forEach(function (f) {
      f.tabIndex = 0; f.setAttribute('role', 'button');
      var ouvrir = function () { var i = $('img', f); visImg.src = i.src; visImg.alt = i.alt; vis.classList.add('ouverte'); $('.fermer', vis).focus(); };
      f.addEventListener('click', ouvrir);
      f.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); ouvrir(); } });
    });
    vis.addEventListener('click', function () { vis.classList.remove('ouverte'); });
    addEventListener('keydown', function (e) { if (e.key === 'Escape') vis.classList.remove('ouverte'); });

    /* Réseau animé du héros */
    var cv = $('#reseau');
    if (cv && cv.getContext && !calme) {
      var ctx = cv.getContext('2d'), pts = [], W, H, actif = true, dpr = Math.min(2, window.devicePixelRatio || 1);
      function taille() {
        W = cv.offsetWidth; H = cv.offsetHeight; cv.width = W * dpr; cv.height = H * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        var n = Math.min(70, Math.round(W * H / 16000)); pts = [];
        for (var i = 0; i < n; i++) pts.push({ x: Math.random() * W, y: Math.random() * H, vx: (Math.random() - .5) * .35, vy: (Math.random() - .5) * .35 });
      }
      taille(); addEventListener('resize', taille);
      new IntersectionObserver(function (e) { actif = e[0].isIntersecting; }).observe(cv);
      (function boucle() {
        if (actif && !document.hidden) {
          ctx.clearRect(0, 0, W, H);
          for (var i = 0; i < pts.length; i++) {
            var p = pts[i]; p.x += p.vx; p.y += p.vy;
            if (p.x < 0 || p.x > W) p.vx *= -1; if (p.y < 0 || p.y > H) p.vy *= -1;
            for (var j = i + 1; j < pts.length; j++) {
              var q = pts[j], dx = p.x - q.x, dy = p.y - q.y, d = dx * dx + dy * dy;
              if (d < 16000) { ctx.strokeStyle = 'rgba(120,150,255,' + (1 - d / 16000) * .35 + ')'; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y); ctx.stroke(); }
            }
            ctx.fillStyle = i % 5 ? 'rgba(160,185,255,.8)' : 'rgba(247,147,30,.95)';
            ctx.beginPath(); ctx.arc(p.x, p.y, i % 5 ? 1.6 : 2.4, 0, 6.29); ctx.fill();
          }
        }
        requestAnimationFrame(boucle);
      })();
    }

    /* ═══ Formulaire de devis ═══ */
    var f = $('#formDevis');
    if (!f) return;
    $('#f-t').value = Math.floor(Date.now() / 1000);
    var pas = $$('.pas', f), barres = $$('.etapes-form span', f), ip = 0, nav = $('#navForm'), msg = $('#msgForm');
    var prec = $('#prec'), suiv = $('#suiv'), usr = $('#f-users'), out = $('#o-users');
    nav.hidden = false;
    usr.addEventListener('input', function () { out.textContent = usr.value === '100' ? '100 +' : usr.value; });

    $$('[data-edition]').forEach(function (a) {
      a.addEventListener('click', function () { $('#f-ed').value = a.dataset.edition; $('#f-type').value = 'Devis'; });
    });

    function donnees() {
      var fd = new FormData(f), mods = fd.getAll('modules[]');
      return {
        nom: fd.get('nom') || '', entreprise: fd.get('entreprise') || '', email: fd.get('email') || '', tel: fd.get('telephone') || '',
        fonction: fd.get('fonction') || '', ville: fd.get('ville') || '', type: fd.get('type'), activite: fd.get('activite') || 'Non précisée',
        edition: fd.get('edition'), societes: fd.get('societes'), users: usr.value === '100' ? '100 et plus' : usr.value, modules: mods.length ? mods.join(', ') : 'À définir',
        delai: fd.get('delai'), reprise: fd.get('reprise'), message: fd.get('message') || '', pref: fd.get('preference')
      };
    }
    function resume() {
      var d = donnees(), r = $('#resume');
      r.hidden = false;
      r.innerHTML = '';
      [['Demande', d.type], ['Activité', d.activite], ['Édition', d.edition], ['Utilisateurs', d.users + ' · ' + d.societes + ' société(s)'], ['Modules', d.modules], ['Démarrage', d.delai]].forEach(function (l) {
        var p = document.createElement('div'), b = document.createElement('b'); b.textContent = l[0] + ' : '; p.appendChild(b); p.appendChild(document.createTextNode(l[1])); r.appendChild(p);
      });
    }
    function valider(i) {
      var ok = true;
      $$('input[required], select[required], textarea[required]', pas[i]).forEach(function (c) {
        if (!c.checkValidity()) { if (ok) c.reportValidity(); ok = false; }
      });
      return ok;
    }
    function aller(i) {
      ip = i;
      pas.forEach(function (p, k) { p.classList.toggle('actif', k === ip); });
      barres.forEach(function (b, k) { b.classList.toggle('fait', k <= ip); });
      prec.style.visibility = ip ? 'visible' : 'hidden';
      suiv.hidden = ip === pas.length - 1;
      if (ip === pas.length - 1) resume();
      msg.className = 'message-form'; msg.textContent = '';
    }
    prec.addEventListener('click', function () { aller(Math.max(0, ip - 1)); });
    suiv.addEventListener('click', function () { if (valider(ip)) aller(ip + 1); });
    aller(0);

    function texteWhatsapp() {
      var d = donnees();
      return 'Bonjour, je souhaite ' + (d.type === 'Devis' ? 'un devis' : d.type === 'Démonstration' ? 'une démonstration' : 'des informations') + ' pour FinaKop ERP.\n\n' +
        '• Nom : ' + d.nom + '\n• Entreprise : ' + d.entreprise + (d.fonction ? ' (' + d.fonction + ')' : '') + '\n• Téléphone : ' + d.tel + '\n• E-mail : ' + d.email +
        (d.ville ? '\n• Ville : ' + d.ville : '') + '\n• Activité : ' + d.activite + '\n• Édition : ' + d.edition + '\n• Utilisateurs : ' + d.users + ' · Sociétés : ' + d.societes +
        '\n• Modules : ' + d.modules + '\n• Démarrage : ' + d.delai + ' · Reprise : ' + d.reprise + (d.message ? '\n\n' + d.message : '');
    }
    $('#viaWhatsapp').addEventListener('click', function () {
      if (!valider(0)) { aller(0); return; }
      window.open('https://wa.me/' + WA + '?text=' + encodeURIComponent(texteWhatsapp()), '_blank', 'noopener');
    });

    f.addEventListener('submit', function (e) {
      e.preventDefault();
      for (var i = 0; i < pas.length; i++) { if (!valider(i)) { aller(i); return; } }
      var b = $('#envoyer'); b.disabled = true; b.textContent = 'Envoi en cours…';
      fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (r) {
          if (r && r.ok) { $('#succes').classList.add('on'); f.reset(); out.textContent = '5'; }
          else { throw new Error(r && r.message ? r.message : ''); }
        })
        .catch(function (err) {
          msg.className = 'message-form ko';
          msg.textContent = (err && err.message ? err.message + ' ' : 'L\'envoi n\'a pas abouti. ') + 'Vous pouvez aussi utiliser le bouton WhatsApp ou écrire à supports@finakoperp.com.';
        })
        .then(function () { b.disabled = false; b.textContent = 'Envoyer ma demande →'; });
    });
  });
})();
