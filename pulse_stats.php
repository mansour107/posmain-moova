<?php include('includes/header.php'); ?>
<?php include('includes/navbar.php'); ?>
<?php include('includes/sidebar.php'); ?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0"><i class="fas fa-chart-line text-primary ml-2"></i> Pulse — الإحصائيات</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-left m-0 bg-transparent p-0">
            <li class="breadcrumb-item"><a href="dashboard.php">الرئيسية</a></li>
            <li class="breadcrumb-item"><a href="pulse.php">Pulse</a></li>
            <li class="breadcrumb-item active">الإحصائيات</li>
          </ol>
        </div>
      </div>
    </div>
  </section>

  <section class="content">
    <div class="container-fluid">

      <!-- ═══════════ Period Filter ═══════════ -->
      <div class="card shadow-sm mb-4" style="border-radius: 12px; overflow: hidden;">
        <div class="card-body py-3">
          <div class="d-flex flex-wrap align-items-center" style="gap: 8px;">
            <span class="font-weight-bold ml-2"><i class="fas fa-filter"></i> الفترة:</span>
            <button class="btn btn-sm period-btn active" data-period="today" style="border-radius: 20px;">اليوم</button>
            <button class="btn btn-sm period-btn" data-period="week" style="border-radius: 20px;">الأسبوع</button>
            <button class="btn btn-sm period-btn" data-period="month" style="border-radius: 20px;">الشهر</button>
            <button class="btn btn-sm period-btn" data-period="all" style="border-radius: 20px;">الكل</button>
            <span class="mx-2 text-muted">|</span>
            <input type="date" id="dateFrom" class="form-control form-control-sm" style="width:150px; border-radius:20px;">
            <span class="text-muted">إلى</span>
            <input type="date" id="dateTo" class="form-control form-control-sm" style="width:150px; border-radius:20px;">
            <button class="btn btn-sm btn-primary period-btn" data-period="custom" style="border-radius: 20px;"><i class="fas fa-search"></i> بحث</button>
          </div>
        </div>
      </div>

      <!-- ═══════════ Summary Cards ═══════════ -->
      <div class="row mb-4" id="summaryCards">
        <div class="col-lg-3 col-6">
          <div class="small-box" style="background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 14px; overflow: hidden;">
            <div class="inner text-white p-3">
              <h3 id="stat_total" style="font-size: 2.2rem; font-weight: 800;">0</h3>
              <p style="font-weight: 600;">إجمالي التقييمات</p>
            </div>
            <div class="icon" style="position:absolute;top:15px;left:15px;font-size:3rem;opacity:0.2;color:#fff;"><i class="fas fa-bolt"></i></div>
          </div>
        </div>
        <div class="col-lg-3 col-6">
          <div class="small-box" style="background: linear-gradient(135deg, #10b981, #059669); border-radius: 14px; overflow: hidden;">
            <div class="inner text-white p-3">
              <h3 id="stat_positive" style="font-size: 2.2rem; font-weight: 800;">0</h3>
              <p style="font-weight: 600;">إيجابي <i class="fas fa-smile"></i></p>
            </div>
            <div class="icon" style="position:absolute;top:15px;left:15px;font-size:3rem;opacity:0.2;color:#fff;"><i class="fas fa-thumbs-up"></i></div>
          </div>
        </div>
        <div class="col-lg-3 col-6">
          <div class="small-box" style="background: linear-gradient(135deg, #ef4444, #dc2626); border-radius: 14px; overflow: hidden;">
            <div class="inner text-white p-3">
              <h3 id="stat_negative" style="font-size: 2.2rem; font-weight: 800;">0</h3>
              <p style="font-weight: 600;">سلبي <i class="fas fa-frown"></i></p>
            </div>
            <div class="icon" style="position:absolute;top:15px;left:15px;font-size:3rem;opacity:0.2;color:#fff;"><i class="fas fa-thumbs-down"></i></div>
          </div>
        </div>
        <div class="col-lg-3 col-6">
          <div class="small-box" style="background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 14px; overflow: hidden;">
            <div class="inner text-white p-3">
              <h3 id="stat_avg_rating" style="font-size: 2.2rem; font-weight: 800;">0</h3>
              <p style="font-weight: 600;">متوسط التقييم <i class="fas fa-star"></i></p>
            </div>
            <div class="icon" style="position:absolute;top:15px;left:15px;font-size:3rem;opacity:0.2;color:#fff;"><i class="fas fa-star"></i></div>
          </div>
        </div>
      </div>

      <div class="row">
        <!-- ═══════════ Leaderboard ═══════════ -->
        <div class="col-lg-7 mb-4">
          <div class="card shadow-sm h-100" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header" style="background: linear-gradient(135deg, #fef3c7, #fffbeb); border-bottom: none;">
              <h3 class="card-title font-weight-bold" style="color: #92400e;">
                <i class="fas fa-trophy text-warning ml-2"></i> لوح الشرف — Leaderboard
              </h3>
            </div>
            <div class="card-body p-0" id="leaderboardBody">
              <div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
            </div>
          </div>
        </div>

        <!-- ═══════════ Charts ═══════════ -->
        <div class="col-lg-5 mb-4">
          <div class="card shadow-sm mb-4" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header" style="background: #f8fafc;">
              <h3 class="card-title font-weight-bold"><i class="fas fa-chart-line text-primary ml-2"></i> التقييمات اليومية</h3>
            </div>
            <div class="card-body">
              <canvas id="dailyChart" height="200"></canvas>
            </div>
          </div>
          <div class="card shadow-sm" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header" style="background: #f8fafc;">
              <h3 class="card-title font-weight-bold"><i class="fas fa-chart-pie text-info ml-2"></i> أكثر الأنواع استخداماً</h3>
            </div>
            <div class="card-body">
              <canvas id="typesChart" height="200"></canvas>
            </div>
          </div>
        </div>
      </div>

    </div>
  </section>
</div>

<style>
  .period-btn { background: #f1f5f9; color: #475569; border: 2px solid transparent; font-weight: 600; transition: all 0.3s; }
  .period-btn.active, .period-btn:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; }
  .leader-row { display: flex; align-items: center; padding: 14px 20px; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; }
  .leader-row:hover { background: #fefce8; }
  .leader-rank { font-size: 1.4rem; font-weight: 800; min-width: 45px; text-align: center; }
  .leader-rank.gold { color: #f59e0b; }
  .leader-rank.silver { color: #9ca3af; }
  .leader-rank.bronze { color: #b45309; }
  .leader-name { font-weight: 700; font-size: 1rem; flex: 1; }
  .leader-bar { width: 200px; height: 10px; background: #f1f5f9; border-radius: 10px; overflow: hidden; margin: 0 15px; }
  .leader-bar-fill { height: 100%; border-radius: 10px; transition: width 0.8s ease; }
  .leader-stats { display: flex; gap: 15px; font-weight: 600; font-size: 0.9rem; min-width: 200px; justify-content: flex-end; }
  .leader-avg { background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: 700; }
  @keyframes countUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
  .small-box { animation: countUp 0.5s ease-out; }
</style>

<?php include('includes/footer.php'); ?>

<script>
$(function() {
    var dailyChartInstance = null;
    var typesChartInstance = null;

    // ─── Period filter ───
    $('.period-btn').on('click', function() {
        $('.period-btn').removeClass('active');
        $(this).addClass('active');
        loadStats($(this).data('period'));
    });

    function loadStats(period) {
        var params = { action: 'get_stats', period: period };
        if (period === 'custom') {
            params.from = $('#dateFrom').val();
            params.to = $('#dateTo').val();
        }

        $.getJSON('ajax/pulse_ajax.php', params, function(data) {
            // Summary
            var s = data.summary || {};
            animateCounter('#stat_total', s.total || 0);
            animateCounter('#stat_positive', s.positive_count || 0);
            animateCounter('#stat_negative', s.negative_count || 0);
            $('#stat_avg_rating').text((s.avg_rating || 0) + '/10');

            // Leaderboard
            renderLeaderboard(data.leaderboard || []);

            // Charts
            renderDailyChart(data.chart || []);
            renderTypesChart(data.topTypes || []);
        });
    }

    function animateCounter(selector, target) {
        var $el = $(selector);
        var current = parseInt($el.text()) || 0;
        $({val: current}).animate({val: target}, {
            duration: 600,
            step: function() { $el.text(Math.floor(this.val)); },
            complete: function() { $el.text(target); }
        });
    }

    function renderLeaderboard(data) {
        if (!data.length) {
            $('#leaderboardBody').html('<div class="text-center py-5 text-muted"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>لا توجد بيانات في هذه الفترة</div>');
            return;
        }

        var maxPts = Math.max.apply(null, data.map(function(d){ return Math.abs(d.net_pts); })) || 1;
        var medals = ['🥇','🥈','🥉'];
        var html = '';

        data.forEach(function(emp, i) {
            var rankClass = i === 0 ? 'gold' : i === 1 ? 'silver' : i === 2 ? 'bronze' : '';
            var rankLabel = i < 3 ? medals[i] : (i+1);
            var barPct = Math.min(100, Math.max(5, (Math.abs(emp.net_pts) / maxPts) * 100));
            var barColor = emp.net_pts >= 0 ? 'linear-gradient(90deg, #10b981, #34d399)' : 'linear-gradient(90deg, #ef4444, #f87171)';

            html += '<div class="leader-row">';
            html += '<div class="leader-rank '+rankClass+'">'+rankLabel+'</div>';
            html += '<div class="leader-name">'+emp.name+'</div>';
            html += '<div class="leader-bar"><div class="leader-bar-fill" style="width:'+barPct+'%; background:'+barColor+'"></div></div>';
            html += '<div class="leader-stats">';
            html += '<span style="color:#10b981">+'+emp.positive_pts+'</span>';
            html += '<span style="color:#ef4444">'+emp.negative_pts+'</span>';
            html += '<strong style="color:'+(emp.net_pts>=0?'#10b981':'#ef4444')+'">= '+emp.net_pts+'</strong>';
            html += '<span class="leader-avg"><i class="fas fa-star" style="font-size:0.7rem"></i> '+(emp.avg_rating||0)+'</span>';
            html += '</div>';
            html += '</div>';
        });

        $('#leaderboardBody').html(html);
    }

    function renderDailyChart(data) {
        var ctx = document.getElementById('dailyChart').getContext('2d');
        if (dailyChartInstance) dailyChartInstance.destroy();

        dailyChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(function(d){ return d.day; }),
                datasets: [
                    {
                        label: 'إيجابي',
                        data: data.map(function(d){ return d.pos; }),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16,185,129,0.1)',
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#10b981',
                        borderWidth: 2
                    },
                    {
                        label: 'سلبي',
                        data: data.map(function(d){ return d.neg; }),
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239,68,68,0.1)',
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#ef4444',
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    function renderTypesChart(data) {
        var ctx = document.getElementById('typesChart').getContext('2d');
        if (typesChartInstance) typesChartInstance.destroy();

        var colors = data.map(function(d){ return d.category === 'positive' ? '#10b981' : '#ef4444'; });

        typesChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.map(function(d){ return d.name; }),
                datasets: [{
                    data: data.map(function(d){ return d.cnt; }),
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 11 } } }
                }
            }
        });
    }

    // Initial load
    loadStats('today');
});
</script>
