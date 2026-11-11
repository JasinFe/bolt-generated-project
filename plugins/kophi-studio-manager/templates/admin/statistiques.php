<?php if (!defined('ABSPATH')) exit;

// ── Calcul stats ─────────────────────────────────────────
$revenue_by_month  = [];
$sessions_by_month = [];
$services_count    = [];
$all_sessions = get_posts(['post_type'=>'kophi_session','posts_per_page'=>-1]);

foreach ($all_sessions as $s) {
    $date    = get_post_meta($s->ID, '_kophi_date_session', true);
    $montant = (float)get_post_meta($s->ID, '_kophi_montant', true);
    $type    = get_post_meta($s->ID, '_kophi_type_session', true) ?: 'voix';
    if (!$date) continue;
    $mois_key = date('Y-m', strtotime($date));
    $revenue_by_month[$mois_key]  = ($revenue_by_month[$mois_key]  ?? 0) + $montant;
    $sessions_by_month[$mois_key] = ($sessions_by_month[$mois_key] ?? 0) + 1;
    $services_count[$type]        = ($services_count[$type]        ?? 0) + 1;
}

ksort($revenue_by_month);
$last12 = [];
$now    = new DateTime();
for ($i = 11; $i >= 0; $i--) {
    $last12[] = (clone $now)->modify("-{$i} months")->format('Y-m');
}

$rev_labels = array_map(function($k) { return date('M Y', strtotime($k . '-01')); }, $last12);
$rev_data   = array_map(function($k) use ($revenue_by_month)  { return $revenue_by_month[$k]  ?? 0; }, $last12);
$sess_data  = array_map(function($k) use ($sessions_by_month) { return $sessions_by_month[$k] ?? 0; }, $last12);

$total_revenus  = array_sum($rev_data);
$total_sessions = array_sum($sess_data);
$nb_clients     = wp_count_posts('kophi_client')->publish ?? 0;
$nb_beats       = wp_count_posts('kophi_beat')->publish ?? 0;

$mois_courant  = date('Y-m');
$rev_mois      = $revenue_by_month[$mois_courant] ?? 0;
$sess_mois     = $sessions_by_month[$mois_courant] ?? 0;
$rev_prec      = $revenue_by_month[date('Y-m', strtotime('-1 month'))] ?? 0;
$growth        = $rev_prec > 0 ? round(($rev_mois - $rev_prec) / $rev_prec * 100) : 0;

$svc_labels = [
    'voix'         => 'Voix/Mix/Master',
    'faceB'        => 'Face B',
    'beats'        => 'Beat Custom',
    'consultation' => 'RDV',
    'mixmaster'    => 'Mix & Master',
    'mastering'    => 'Mastering',
    'interne'      => 'Interne Label',
];
arsort($services_count);

$svc_display = [];
foreach (array_keys($services_count) as $k) {
    $svc_display[] = isset($svc_labels[$k]) ? $svc_labels[$k] : $k;
}
?>
<div class="kophi-wrap"><div class="kophi-inner">

<div class="kophi-page-header">
  <div class="kophi-page-title">
    <div class="kophi-page-icon">📊</div>
    <h1>STATISTIQUES</h1>
  </div>
  <div class="kophi-btn-group">
    <select id="stat-periode" onchange="reloadStats()" style="font-size:11px;padding:7px 12px;background:var(--k-dark2);border:1px solid var(--k-border);border-radius:8px;color:var(--k-text)">
      <option value="mois">Ce mois</option>
      <option value="trimestre">Ce trimestre</option>
      <option value="annee">Cette année</option>
      <option value="tout">Tout le temps</option>
    </select>
  </div>
</div>

<!-- STATS CARDS -->
<div class="kophi-stats" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:24px">
  <div class="kophi-stat gold">
    <div class="kophi-stat-label">Revenus ce mois</div>
    <div class="kophi-stat-value"><?= number_format($rev_mois, 0, ',', ' ') ?> F</div>
    <div class="kophi-stat-sub"><?= $growth >= 0 ? '+' : '' ?><?= $growth ?>% vs mois préc.</div>
  </div>
  <div class="kophi-stat violet">
    <div class="kophi-stat-label">Sessions ce mois</div>
    <div class="kophi-stat-value"><?= $sess_mois ?></div>
  </div>
  <div class="kophi-stat green">
    <div class="kophi-stat-label">Total revenus (12 mois)</div>
    <div class="kophi-stat-value"><?= number_format($total_revenus, 0, ',', ' ') ?> F</div>
  </div>
  <div class="kophi-stat gold">
    <div class="kophi-stat-label">Total sessions (12 mois)</div>
    <div class="kophi-stat-value"><?= $total_sessions ?></div>
  </div>
  <div class="kophi-stat violet">
    <div class="kophi-stat-label">Clients enregistrés</div>
    <div class="kophi-stat-value"><?= $nb_clients ?></div>
  </div>
  <div class="kophi-stat gold">
    <div class="kophi-stat-label">Beats en vente</div>
    <div class="kophi-stat-value"><?= $nb_beats ?></div>
  </div>
</div>

<!-- CHARTS -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">
  <div class="kophi-card" style="grid-column:1/-1">
    <div class="kophi-card-title">📈 Revenus mensuels — F CFA</div>
    <canvas id="chart-revenus" height="80"></canvas>
  </div>
  <div class="kophi-card">
    <div class="kophi-card-title">📅 Sessions par mois</div>
    <canvas id="chart-sessions" height="140"></canvas>
  </div>
  <div class="kophi-card">
    <div class="kophi-card-title">🎙 Répartition par service</div>
    <canvas id="chart-services" height="140"></canvas>
  </div>
</div>

<!-- TABLEAU SERVICES -->
<?php if (!empty($services_count)): ?>
<div class="kophi-card">
  <div class="kophi-card-title">🎵 Détail par service</div>
  <table style="width:100%;border-collapse:collapse;font-size:12px">
    <thead><tr>
      <th style="text-align:left;padding:8px;border-bottom:1px solid var(--k-border);font-size:9px;letter-spacing:2px;color:var(--k-text3)">SERVICE</th>
      <th style="text-align:center;padding:8px;border-bottom:1px solid var(--k-border);font-size:9px;letter-spacing:2px;color:var(--k-text3)">SESSIONS</th>
      <th style="text-align:right;padding:8px;border-bottom:1px solid var(--k-border);font-size:9px;letter-spacing:2px;color:var(--k-text3)">% DU TOTAL</th>
    </tr></thead>
    <tbody>
    <?php $i = 0; foreach ($services_count as $svc => $cnt): $i++; ?>
    <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
      <td style="padding:8px;color:var(--k-text)"><?= esc_html(isset($svc_labels[$svc]) ? $svc_labels[$svc] : $svc) ?></td>
      <td style="padding:8px;text-align:center;font-weight:700;color:var(--k-gold)"><?= $cnt ?></td>
      <td style="padding:8px;text-align:right;color:var(--k-text3)"><?= $total_sessions > 0 ? round($cnt / $total_sessions * 100) : 0 ?>%</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

</div></div>

<script>
if (typeof Chart === 'undefined') {
  var s = document.createElement('script');
  s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js';
  s.onload = function() { initKophiCharts(); };
  document.head.appendChild(s);
} else {
  function initKophiCharts() { initKophiCharts(); });
}
</script>
<script>
function initKophiCharts() {
  var rev_labels = <?= json_encode($rev_labels) ?>;
  var rev_data   = <?= json_encode(array_values($rev_data)) ?>;
  var sess_data  = <?= json_encode(array_values($sess_data)) ?>;
  var svc_labels = <?= json_encode(array_values($svc_display)) ?>;
  var svc_data   = <?= json_encode(array_values($services_count)) ?>;

  var chartDefaults = {
    responsive: true,
    plugins: {
      legend: { labels: { color: '#C8C0DC', font: { size: 10 } } }
    },
    scales: {
      x: { ticks: { color: '#9E98B8', font: { size: 9 } }, grid: { color: 'rgba(255,255,255,.05)' } },
      y: { ticks: { color: '#9E98B8', font: { size: 9 } }, grid: { color: 'rgba(255,255,255,.05)' } }
    }
  };

  if (document.getElementById('chart-revenus')) {
    new Chart(document.getElementById('chart-revenus'), {
      type: 'bar',
      data: {
        labels: rev_labels,
        datasets: [{ label: 'Revenus (F CFA)', data: rev_data,
          backgroundColor: 'rgba(232,184,75,.35)', borderColor: '#E8B84B',
          borderWidth: 1, borderRadius: 4 }]
      },
      options: Object.assign({}, chartDefaults, {
        plugins: Object.assign({}, chartDefaults.plugins, {
          tooltip: { callbacks: { label: function(ctx) { return '  ' + ctx.parsed.y.toLocaleString('fr-FR') + ' F CFA'; } } }
        })
      })
    });
  }

  if (document.getElementById('chart-sessions')) {
    new Chart(document.getElementById('chart-sessions'), {
      type: 'line',
      data: {
        labels: rev_labels,
        datasets: [{ label: 'Sessions', data: sess_data,
          borderColor: '#8B3FD9', backgroundColor: 'rgba(139,63,217,.15)',
          tension: 0.4, fill: true, pointBackgroundColor: '#8B3FD9' }]
      },
      options: chartDefaults
    });
  }

  if (document.getElementById('chart-services') && svc_data.length > 0) {
    new Chart(document.getElementById('chart-services'), {
      type: 'doughnut',
      data: {
        labels: svc_labels,
        datasets: [{ data: svc_data,
          backgroundColor: ['#E8B84B','#8B3FD9','#00C87A','#FF4757','#FF8C42','#C084FC','#3B82F6'],
          borderColor: 'rgba(0,0,0,.3)', borderWidth: 2 }]
      },
      options: { responsive: true, plugins: { legend: { position: 'right', labels: { color: '#C8C0DC', font: { size: 10 } } } } }
    });
  } else if (document.getElementById('chart-services')) {
    document.getElementById('chart-services').parentElement.innerHTML += '<div style="text-align:center;padding:30px;color:var(--k-text3);font-size:12px">Aucune session enregistrée</div>';
  }
}
function reloadStats() { location.reload(); }
</script>
