// Draws every <canvas data-chart="{...}"> with Chart.js (loaded just before this file).
(function () {
  if (typeof Chart === 'undefined') { return; }
  document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
    var cfg;
    try { cfg = JSON.parse(canvas.getAttribute('data-chart')); } catch (e) { return; }
    var pie = cfg.type === 'doughnut';
    new Chart(canvas, {
      type: cfg.type || 'bar',
      data: { labels: cfg.labels, datasets: [{ label: cfg.label || '', data: cfg.values, backgroundColor: cfg.colors || '#12355b' }] },
      options: {
        responsive: true,
        plugins: { legend: { display: pie } },
        scales: pie ? {} : { y: { beginAtZero: true, ticks: { precision: 0 } } }
      }
    });
  });
})();
