/* Dashboard renderer: fetches each widget's data with the current filters and
 * re-polls on an interval so numbers stay live without opening Zenoti. */
(function () {
    const cfg = window.DASHBOARD;
    const css = getComputedStyle(document.documentElement);
    const SERIES = [1, 2, 3, 4, 5, 6, 7, 8].map(i => css.getPropertyValue('--series-' + i).trim());
    const GRID = css.getPropertyValue('--grid').trim();
    const INK = css.getPropertyValue('--text-secondary').trim();
    const charts = {};
    const state = { branch_id: '', tag_id: '', employee_id: '', from: '', to: '' };

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = INK;

    const fmt = (v, money) => {
        const n = Number(v || 0);
        if (money === 'pct') return n.toLocaleString(undefined, { maximumFractionDigits: 1 }) + '%';
        const s = n.toLocaleString(undefined, { maximumFractionDigits: money ? 0 : 2 });
        return money ? cfg.currency + s : s;
    };
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /* Pie / doughnut: at most 7 slices + "Other" so hues are never cycled. */
    function foldOther(labels, values) {
        if (labels.length <= 8) return { labels, values };
        const l = labels.slice(0, 7), v = values.slice(0, 7);
        l.push('Other'); v.push(values.slice(7).reduce((a, b) => a + b, 0));
        return { labels: l, values: v };
    }

    /* ---------- rizz-style elements (ApexCharts) ---------- */
    const APEX = ['stat', 'sparkline', 'radial', 'area', 'column', 'hbar', 'donut', 'progress', 'branch_table', 'employee_table', 'agent_table', 'agent_bars', 'agent_tags', 'group_target', 'tier_bars'];
    const theme = () => document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    const colorOf = name => css.getPropertyValue('--bs-' + name).trim() || SERIES[0];
    const apexBase = (money) => ({
        chart: { toolbar: { show: false }, fontFamily: 'inherit', background: 'transparent', animations: { enabled: true, speed: 400 } },
        theme: { mode: theme() },
        dataLabels: { enabled: false },
        grid: { strokeDashArray: 3, borderColor: GRID, xaxis: { lines: { show: false } }, yaxis: { lines: { show: true } } },
        tooltip: { theme: theme(), y: { formatter: v => fmt(v, money) } },
        legend: { show: false },
    });

    function trendHtml(data) {
        if (data.change === null || data.change === undefined) {
            return data.previous !== undefined ? '<span class="text-muted">No earlier data ' + esc(data.compare_label || '') + '</span>' : '';
        }
        const up = data.change >= 0;
        return '<span class="text-' + (up ? 'success' : 'danger') + ' stat-trend fw-semibold"><i class="bi bi-arrow-' + (up ? 'up' : 'down') + '-short"></i>' +
            Math.abs(data.change).toLocaleString(undefined, { maximumFractionDigits: 1 }) + '%</span> ' + esc(data.compare_label || '');
    }

    function renderApex(card, body, id, type, data, money) {
        const color = card.dataset.color || 'primary', accent = colorOf(color);
        const title = esc(card.dataset.title), icon = esc(card.dataset.icon || 'graph-up');

        if (type === 'stat') {
            body.innerHTML = '<div class="row d-flex justify-content-center border-dashed-bottom pb-3">' +
                '<div class="col-9"><p class="text-dark mb-0 fw-semibold fs-14 text-truncate">' + title + '</p>' +
                '<h3 class="mt-2 mb-0 fw-bold stat-value" title="' + esc(fmt(data.value, money)) + '">' + fmt(data.value, money) + '</h3></div>' +
                '<div class="col-3 align-self-center"><div class="d-flex justify-content-center align-items-center thumb-xl stat-icon bg-' + color + '-subtle rounded-circle mx-auto">' +
                '<i class="bi bi-' + icon + ' align-self-center mb-0 text-' + color + '"></i></div></div></div>' +
                '<p class="mb-0 text-truncate text-muted mt-3">' + (trendHtml(data) || esc(card.dataset.range)) + '</p>' +
                (card.dataset.source ? '<small class="text-muted d-block mt-1" style="font-size:11px">Source: ' + esc(card.dataset.source) + '</small>' : '');
            return;
        }

        if (type === 'sparkline') {
            if (charts[id]) { charts[id].destroy(); delete charts[id]; }
            body.innerHTML = '<div class="border-dashed-bottom pb-3 mb-3"><div class="row d-flex justify-content-between">' +
                '<div class="col-auto"><div class="d-flex justify-content-center align-items-center thumb-xl stat-icon border border-' + color + ' rounded-circle">' +
                '<i class="bi bi-' + icon + ' align-self-center mb-0 text-' + color + '"></i></div><h5 class="mt-2 mb-0 fs-14">' + title + '</h5></div>' +
                '<div class="col align-self-center"><div class="spark-box float-end"></div></div></div></div>' +
                '<h2 class="fs-22 mt-0 mb-1 fw-bold kpi-value">' + fmt(data.value, money) + '</h2>' +
                '<p class="mb-0 text-truncate text-muted">' + (trendHtml(data) || esc(card.dataset.range)) + '</p>';
            const vals = (data.values && data.values.length > 1) ? data.values : [0, data.value || 0];
            charts[id] = new ApexCharts(body.querySelector('.spark-box'), {
                series: [{ name: card.dataset.title, data: vals }],
                chart: { type: 'line', width: 120, height: 40, sparkline: { enabled: true },
                    dropShadow: { enabled: true, top: 4, left: 0, blur: 2, color: 'rgba(132, 145, 183, 0.3)', opacity: .35 } },
                colors: [accent], stroke: { curve: 'smooth', width: 3, lineCap: 'round' },
                tooltip: { theme: theme(), fixed: { enabled: false }, x: { show: false }, y: { title: { formatter: () => '' }, formatter: v => fmt(v, money) }, marker: { show: false } },
            });
            charts[id].render();
            return;
        }

        if (type === 'agent_bars') {
            if (charts[id]) { charts[id].destroy(); delete charts[id]; }
            const labels = data.labels || [], values = data.values || [], pcts = data.percents || [], target = Number(data.target || 0);
            const c = data.counts || {};
            const summary = '<div class="d-flex flex-wrap gap-2 small mb-2">' +
                '<span class="badge bg-success-subtle text-success">High ' + (c.High || 0) + '</span>' +
                '<span class="badge bg-warning-subtle text-warning">On track ' + (c['On track'] || 0) + '</span>' +
                '<span class="badge bg-danger-subtle text-danger">Low ' + (c.Low || 0) + '</span>' +
                '<span class="text-muted ms-auto">' + (data.summary ? esc(data.summary) : 'Target ' + fmt(target) + ' ' + esc(data.unit || '') + ' · ' + (data.days || 1) + ' day(s)') + '</span></div>';
            const cash = data.money === true, lead = data.lead || [];
            if (!labels.length) {
                body.innerHTML = summary + '<div class="text-muted small py-4 text-center">No agents yet. Tag employees on the Employees page, and calls appear once CallGear syncs.</div>';
                return;
            }
            const tone = p => p >= 100 ? colorOf('success') : p >= 80 ? colorOf('warning') : colorOf('danger');
            body.innerHTML = summary + '<div class="apex-box" style="cursor:pointer"></div>';
            const o = apexBase(false);
            Object.assign(o, {
                series: [{ name: data.unit || card.dataset.title, data: values }],
                chart: Object.assign(o.chart, { type: 'bar', height: Math.max(220, labels.length * 32 + 60),
                    events: { dataPointSelection: (e, ctx, cfgp) => drill(card, cfgp.dataPointIndex) } }),
                colors: [({ dataPointIndex }) => lead[dataPointIndex] ? (colorOf('secondary') || '#888') : tone(pcts[dataPointIndex] || 0)],
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '65%' } },
                dataLabels: { enabled: true, formatter: (v, o2) => fmt(v, cash) + (lead[o2.dataPointIndex] ? '' : ' (' + fmt(pcts[o2.dataPointIndex], 'pct') + ')'), style: { fontSize: '11px', fontWeight: 500 } },
                tooltip: { theme: theme(), y: { formatter: v => fmt(v, cash) } },
                xaxis: { categories: labels, tickAmount: 4, min: 0, max: Math.ceil(Math.max(target * 1.15, ...values.map(Number))) || undefined, labels: { formatter: v => cash ? fmt(Math.round(v / 1000)) + 'k' : fmt(Math.round(v)) } },
                annotations: target ? { xaxis: [{ x: target, borderColor: colorOf('dark') || '#555', strokeDashArray: 4,
                    label: { text: 'Target ' + fmt(target, cash), orientation: 'horizontal', style: { background: 'transparent', color: INK, fontSize: '11px' } } }] } : {},
                grid: { strokeDashArray: 3, borderColor: GRID, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
                states: { active: { filter: { type: 'none' } } },
            });
            charts[id] = new ApexCharts(body.querySelector('.apex-box'), o);
            charts[id].render();
            return;
        }

        if (type === 'tier_bars') {
            if (charts[id]) { charts[id].destroy(); delete charts[id]; }
            const aed = v => fmt(Math.round(v)) + ' AED';
            const rows = data.rows || [], shares = data.shares || [], n = data.agents || 0;
            const tones = ['warning', 'info', 'primary', 'success'];
            const toneOf = rate => { const i = shares.findIndex(s => s[1] === rate); return i < 0 ? 'danger' : tones[Math.min(tones.length - 1, i + Math.max(0, tones.length - shares.length))]; };
            let html = '<div class="d-flex flex-wrap gap-2 small mb-3"><span class="text-muted">Each group tier split equally between ' + n + ' agents:</span>' +
                shares.map(s => '<span class="badge bg-light text-dark border fw-normal"><b>' + s[1] + '%</b> from ' + aed(s[0]) + ' each <span class="text-muted">(' + fmt(s[2] / 1000) + 'k ÷ ' + n + ') = ' + aed(s[0] * s[1] / 100) + '</span></span>').join('') + '</div>';
            if (!rows.length) {
                body.innerHTML = html + '<div class="text-muted small py-4 text-center">No agents yet. Tag employees "Callgear" on the Employees page.</div>';
                return;
            }
            const top = shares.length ? shares[shares.length - 1][0] : 0;
            const scale = top * 1.15 || Math.max(...rows.map(r => Number(r.revenue)), 1), at = v => Math.min(100, v / scale * 100);
            rows.forEach((r, i) => {
                const tone = toneOf(r.rate);
                html += '<div class="row g-2 align-items-center mb-2 tier-row" data-i="' + i + '" style="cursor:pointer">' +
                    '<div class="col-md-2 small fw-medium text-truncate" title="' + esc(r.employee) + '">' + esc(r.employee) + '</div>' +
                    '<div class="col-md-6"><div class="position-relative" style="height:18px"><div class="progress h-100"><div class="progress-bar bg-' + tone + '" style="width:' + at(r.revenue) + '%"></div></div>' +
                    shares.map(s => '<div class="position-absolute top-0 h-100" title="' + s[1] + '% from ' + aed(s[0]) + '" style="left:' + at(s[0]) + '%;border-left:2px dashed ' + (r.revenue >= s[0] ? colorOf('success') : INK) + '"></div>').join('') +
                    '</div></div>' +
                    '<div class="col-md-4 small"><b>' + aed(r.revenue) + '</b> · ' + (r.rate ? '<span class="text-' + tone + ' fw-semibold">' + r.rate + '% = ' + aed(r.commission) + '</span>' : '<span class="text-danger">no tier yet</span>') +
                    (r.next ? ' · <span class="text-muted">' + aed(r.to_next) + ' to ' + r.next_rate + '%</span>' : ' · <span class="text-success">top tier</span>') + '</div></div>';
            });
            html += '<div class="row g-2"><div class="col-md-2"></div><div class="col-md-6 position-relative small text-muted" style="height:16px">' +
                shares.map(s => '<span class="position-absolute" style="left:' + at(s[0]) + '%;transform:translateX(-50%)">' + s[1] + '%</span>').join('') + '</div></div>';
            body.innerHTML = html;
            body.querySelectorAll('.tier-row').forEach(el => el.addEventListener('click', () => drill(card, Number(el.dataset.i))));
            return;
        }

        if (type === 'group_target') {
            if (charts[id]) { charts[id].destroy(); delete charts[id]; }
            const aed = v => fmt(Math.round(v)) + ' AED';
            const tiers = data.tiers || [], total = Number(data.total || 0), top = tiers.length ? tiers[tiers.length - 1][0] : 0;
            const scale = Math.max(top * 1.05, total) || 1, at = v => Math.min(100, v / scale * 100);
            const box = (label, value, sub, tone) => '<div class="col-6 col-lg-3"><div class="border rounded p-2 h-100"><div class="text-muted small">' + label + '</div>' +
                '<div class="fs-18 fw-bold' + (tone ? ' text-' + tone : '') + '">' + value + '</div><div class="small text-muted">' + sub + '</div></div></div>';
            const reached = data.tier !== null && data.tier !== undefined;
            let html = '<div class="row g-2 mb-3">' +
                box('Achieved by the group', aed(total), (data.agents || 0) + ' agents · ' + esc(card.dataset.range || ''), '') +
                box('Commission now', reached ? aed(data.commission) : '0 AED', reached ? 'For the group: ' + data.rate + '% of ' + aed(total) : 'First tier at ' + aed(tiers.length ? tiers[0][0] : 0), reached ? 'success' : 'danger') +
                box('Next tier', data.next ? aed(data.next) : 'Top tier reached', data.next ? 'Pays ' + data.next_rate + '% = ' + aed(data.next * data.next_rate / 100) + ' at that point' : 'Highest rate ' + data.rate + '%', 'primary') +
                box(data.next ? 'Still to achieve' : 'Above top tier', data.next ? aed(data.to_next) : aed(total - top),
                    data.projected !== null && data.projected !== undefined ? 'At this pace: ' + aed(data.projected) + ' by month end (' + data.projected_rate + '%)' : '', data.next ? 'warning' : 'success') +
                '</div>';
            html += '<div class="position-relative mb-5 mx-1" style="height:22px"><div class="progress h-100" role="progressbar" aria-valuenow="' + Math.round(at(total)) + '" aria-valuemin="0" aria-valuemax="100">' +
                '<div class="progress-bar bg-' + (reached ? 'success' : 'warning') + '" style="width:' + at(total) + '%">' + (at(total) > 12 ? aed(total) : '') + '</div></div>';
            tiers.forEach(t => {
                const ok = total >= t[0];
                html += '<div class="position-absolute top-0 h-100" style="left:' + at(t[0]) + '%;border-left:2px dashed ' + (ok ? colorOf('success') : INK) + '"></div>' +
                    '<div class="position-absolute small text-center" style="left:' + at(t[0]) + '%;top:26px;transform:translateX(-50%);white-space:nowrap;line-height:1.2">' +
                    '<span class="fw-semibold' + (ok ? ' text-success' : '') + '">' + (ok ? '✓ ' : '') + t[1] + '% of ' + fmt(t[0] / 1000) + 'k</span><br><span class="text-muted">= ' + aed(t[0] * t[1] / 100) + '</span></div>';
            });
            html += '</div><div class="small text-muted">Running total this period · dashed lines are the tiers (' + tiers.map(t => fmt(t[0] / 1000) + 'k = ' + t[1] + '%').join(', ') + ')</div><div class="apex-box"></div>';
            body.innerHTML = html;
            const o = apexBase(false);
            const vals = data.values || [];
            Object.assign(o, {
                series: [{ name: 'Group revenue so far', data: vals }],
                chart: Object.assign(o.chart, { type: 'area', height: 260 }),
                colors: [colorOf('primary')],
                stroke: { curve: 'smooth', width: 2 },
                fill: { type: 'gradient', gradient: { opacityFrom: .35, opacityTo: .05 } },
                xaxis: { categories: (data.labels || []).map(d => d.slice(8, 10) + '/' + d.slice(5, 7)), tickAmount: 10, labels: { rotate: 0 } },
                yaxis: { min: 0, max: Math.ceil(Math.max(top * 1.05, ...vals.filter(v => v !== null).map(Number), 1)), labels: { formatter: v => fmt(Math.round(v / 1000)) + 'k' } },
                annotations: { yaxis: tiers.map(t => ({ y: t[0], borderColor: total >= t[0] ? colorOf('success') : (colorOf('secondary') || '#888'), strokeDashArray: 4 })) },
            });
            charts[id] = new ApexCharts(body.querySelector('.apex-box'), o);
            charts[id].render();
            return;
        }

        if (type === 'agent_tags') {
            if (charts[id]) { charts[id].destroy(); delete charts[id]; }
            const labels = data.labels || [], series = data.series || [];
            if (!labels.length || !series.length) {
                body.innerHTML = '<div class="text-muted small py-4 text-center">No tagged calls yet. When agents tag calls in CallGear (e.g. "Outgoing new sale"), the results show here.</div>';
                return;
            }
            body.innerHTML = '<div class="apex-box"></div>';
            const o = apexBase(false);
            Object.assign(o, {
                series,
                chart: Object.assign(o.chart, { type: 'bar', stacked: true, height: Math.max(220, labels.length * 34 + 90) }),
                colors: SERIES,
                plotOptions: { bar: { horizontal: true, borderRadius: 3, barHeight: '65%' } },
                dataLabels: { enabled: true, formatter: v => v ? fmt(v) : '', style: { fontSize: '10px' } },
                xaxis: { categories: labels, labels: { formatter: v => fmt(Math.round(v)) } },
                legend: { show: true, position: 'top', horizontalAlign: 'left' },
                grid: { strokeDashArray: 3, borderColor: GRID, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
            });
            charts[id] = new ApexCharts(body.querySelector('.apex-box'), o);
            charts[id].render();
            return;
        }

        if (type === 'branch_table' || type === 'employee_table' || type === 'agent_table') {
            const cols = data.columns || [];
            const sort = card._sort || [data.sort || (cols[1] || [])[0], -1];
            const rows = (data.rows || []).slice().sort((a, b) => {
                const x = a[sort[0]], y = b[sort[0]];
                if (typeof x === 'string' || typeof y === 'string') return String(x ?? '').localeCompare(String(y ?? '')) * sort[1];
                return ((x ?? -1e18) - (y ?? -1e18)) * sort[1];
            });
            const STATUS = { High: 'success', 'On track': 'warning', Low: 'danger' };
            const cell = (v, f, k) => v === null || v === undefined || v === '' ? '<span class="text-muted">—</span>' : k === 'status' && STATUS[v] ? '<span class="badge bg-' + STATUS[v] + '-subtle text-' + STATUS[v] + '">' + esc(v) + '</span>' : f === 's' ? esc(v) : f === 'm' ? fmt(v, true) : f === 'p' ? fmt(v, 'pct') : f === 'x' ? '× ' + fmt(v, false) : fmt(v, false);
            const tone = (k, r) => (k === 'calls_pct' || k === 'talk_pct') ? ' fw-semibold ' + (r[k] >= 100 ? 'text-success' : r[k] >= 80 ? 'text-warning' : 'text-danger') : (k === 'achievement' || k === 'gap' || k === 'variance') && r.achievement !== null && r.achievement !== undefined
                ? ' fw-semibold ' + (r.achievement >= 100 ? 'text-success' : r.achievement >= 80 ? 'text-warning' : 'text-danger') : '';
            body.innerHTML = (data.note ? '<div class="small mb-2 fw-medium">' + esc(data.note) + '</div>' : '') + '<div class="d-flex justify-content-end mb-2 no-print"><button class="btn btn-sm btn-light export-csv"><i class="bi bi-download"></i> Excel (CSV)</button></div>' +
                '<div class="table-responsive" style="max-height:520px"><table class="table table-sm table-hover mb-0"><thead class="sticky-top bg-body"><tr>' +
                cols.map(([k, l, f]) => '<th role="button" data-k="' + k + '" class="text-nowrap ' + (f === 's' ? '' : 'text-end') + '">' + l + (sort[0] === k ? (sort[1] > 0 ? ' ▲' : ' ▼') : '') + '</th>').join('') +
                '</tr></thead><tbody>' + (rows.length ? rows.map(r => '<tr data-row-key="' + esc(r.key ?? '') + '">' + cols.map(([k, , f]) => '<td class="' + (f === 's' ? (k === cols[0][0] ? 'fw-medium' : '') : 'text-end text-nowrap') + tone(k, r) + '">' + cell(r[k], f, k) + '</td>').join('') + '</tr>').join('')
                    : '<tr><td colspan="' + cols.length + '" class="text-center text-muted py-4">' + (type === 'agent_table' ? 'No agents yet. Tag employees on the Employees page, and calls appear once CallGear syncs.' : 'No data for these filters.') + '</td></tr>') + '</tbody></table></div>';
            body.querySelectorAll('th[data-k]').forEach(th => th.addEventListener('click', () => {
                const col = cols.find(c => c[0] === th.dataset.k);
                card._sort = [th.dataset.k, sort[0] === th.dataset.k ? -sort[1] : (col && col[2] === 's' ? 1 : -1)];
                renderApex(card, body, id, type, data, money);
            }));
            body.querySelectorAll('tr[data-row-key]').forEach(tr => {
                tr.style.cursor = 'pointer';
                tr.addEventListener('click', () => openRecords(card, { field: type === 'branch_table' ? 'branch' : 'employee', key: tr.dataset.rowKey },
                    tr.firstElementChild.textContent));
            });
            body.querySelector('.export-csv').addEventListener('click', () => {
                const q = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
                const csv = [cols.map(c => q(c[1])).join(',')].concat(rows.map(r => cols.map(c => q(r[c[0]])).join(','))).join('\r\n');
                const a = document.createElement('a');
                a.href = URL.createObjectURL(new Blob(['\ufeff' + csv], { type: 'text/csv' }));
                a.download = (card.dataset.title || 'table') + '.csv';
                a.click();
            });
            return;
        }

        if (type === 'radial') {
            if (charts[id]) { charts[id].destroy(); delete charts[id]; }
            const pct = data.percent ?? Math.min(100, Number(data.value || 0));
            body.innerHTML = '<div class="radial-box mx-auto" style="max-width:320px"></div><hr class="hr-dashed border-secondary w-25 mt-0 mx-auto">' +
                '<div class="text-center"><h4 class="mb-1">' + fmt(data.value, money) + '</h4><p class="text-muted mb-0">' +
                (data.target ? 'of ' + fmt(data.target, money) + ' target' : 'Set a target on this widget to see progress') + '</p></div>';
            charts[id] = new ApexCharts(body.querySelector('.radial-box'), {
                series: [Math.round(Math.min(pct, 100) * 10) / 10],
                chart: { height: 260, type: 'radialBar', offsetY: -10, sparkline: { enabled: true } },
                plotOptions: { radialBar: { startAngle: -90, endAngle: 90, hollow: { size: '75%', position: 'front' },
                    track: { background: 'rgba(42, 118, 244, .18)', strokeWidth: '80%', opacity: .5, margin: 5 },
                    dataLabels: { name: { show: false }, value: { offsetY: -2, fontSize: '22px', fontWeight: 600, formatter: () => (pct ?? 0).toLocaleString(undefined, { maximumFractionDigits: 1 }) + '%' } } } },
                stroke: { lineCap: 'round' }, colors: [accent], grid: { padding: { top: -10 } },
            });
            charts[id].render();
            return;
        }

        if (!data.labels || !data.labels.length) {
            if (charts[id]) { charts[id].destroy(); delete charts[id]; }
            body.innerHTML = '<div class="text-muted small py-4 text-center">No data for these filters.</div>';
            return;
        }

        if (type === 'progress') {
            const total = data.values.reduce((a, b) => a + Number(b || 0), 0) || 1;
            body.innerHTML = '<div class="progress-list" style="max-height:320px;overflow:auto">' + data.labels.slice(0, 10).map((l, i) => {
                const pct = Math.round(Number(data.values[i] || 0) / total * 1000) / 10;
                return '<div class="item" data-drill="' + i + '"><div class="d-flex justify-content-between align-items-center mb-1">' +
                    '<span class="fw-medium text-truncate me-2">' + esc(l) + '</span><span class="text-muted text-nowrap">' + fmt(data.values[i], money) + ' · ' + pct + '%</span></div>' +
                    '<div class="progress"><div class="progress-bar bg-' + color + '" style="width:' + pct + '%"></div></div></div>';
            }).join('') + '</div>';
            return;
        }

        let labels = data.labels, values = data.values;
        if (type === 'donut') ({ labels, values } = foldOther(labels, values));

        const o = apexBase(money);
        if (type === 'area') {
            Object.assign(o, {
                series: [{ name: card.dataset.title, data: values }],
                chart: Object.assign(o.chart, { type: 'area', height: 280,
                    dropShadow: { enabled: true, top: 12, left: 0, blur: 2, color: 'rgba(132, 145, 183, 0.3)', opacity: .35 } }),
                colors: [accent], stroke: { curve: 'smooth', width: 3, lineCap: 'round' },
                fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: .35, opacityTo: .02, stops: [0, 95] } },
                xaxis: { categories: labels, axisBorder: { show: false }, axisTicks: { show: false }, labels: { rotate: 0, hideOverlappingLabels: true } },
                yaxis: { labels: { formatter: v => fmt(v, money) } },
            });
        } else if (type === 'column') {
            const max = Math.max(...values);
            Object.assign(o, {
                series: [{ name: card.dataset.title, data: values }],
                chart: Object.assign(o.chart, { type: 'bar', height: 280,
                    dropShadow: { enabled: true, top: 0, left: 5, bottom: 5, blur: 5, color: '#45404a2e', opacity: .35 } }),
                colors: values.map(v => v === max ? accent : '#95a0c5'),
                plotOptions: { bar: { borderRadius: 6, columnWidth: values.length > 15 ? '60%' : '35%', distributed: true, dataLabels: { position: 'top' } } },
                dataLabels: { enabled: values.length <= 12, offsetY: -20, style: { fontSize: '11px', colors: ['#8997bd'] }, formatter: v => fmt(v, money) },
                grid: { strokeDashArray: 2.5, borderColor: GRID, xaxis: { lines: { show: false } } },
                xaxis: { categories: labels, axisBorder: { show: false }, axisTicks: { show: false }, labels: { rotate: -45, hideOverlappingLabels: true, trim: true } },
                yaxis: { labels: { formatter: v => fmt(v, money) } },
            });
        } else if (type === 'hbar') {
            Object.assign(o, {
                series: [{ name: card.dataset.title, data: values }],
                chart: Object.assign(o.chart, { type: 'bar', height: Math.max(220, labels.length * 34 + 40) }),
                colors: [accent],
                plotOptions: { bar: { horizontal: true, borderRadius: 5, barHeight: '65%' } },
                dataLabels: { enabled: true, formatter: v => fmt(v, money), style: { fontSize: '11px', fontWeight: 500 } },
                xaxis: { categories: labels, tickAmount: 4, labels: { formatter: v => fmt(v, money) } },
                grid: { strokeDashArray: 3, borderColor: GRID, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
            });
        } else { // donut
            Object.assign(o, {
                series: values.map(Number), labels,
                chart: Object.assign(o.chart, { type: 'donut', height: 290 }),
                plotOptions: { pie: { donut: { size: '78%', labels: { show: true, total: { show: true, label: 'Total', formatter: w => fmt(w.globals.seriesTotals.reduce((a, b) => a + b, 0), money) } } } } },
                stroke: { show: true, width: 2, colors: ['transparent'] },
                colors: SERIES.slice(0, values.length),
                legend: { show: true, position: 'bottom', horizontalAlign: 'center', fontSize: '13px' },
            });
        }

        if (charts[id] && charts[id].w) {
            if (type === 'donut') charts[id].updateOptions({ series: o.series, labels: o.labels, colors: o.colors }, false, false);
            else charts[id].updateOptions({ series: o.series, xaxis: o.xaxis, colors: o.colors }, false, false);
            return;
        }
        o.chart.events = { dataPointSelection: (e, ctx, cfgp) => drill(card, cfgp.dataPointIndex) };
        o.states = { active: { filter: { type: 'none' } } };
        body.innerHTML = '<div class="apex-box" style="cursor:pointer"></div>';
        charts[id] = new ApexCharts(body.querySelector('.apex-box'), o);
        charts[id].render();
    }

    function render(card, data) {
        card._data = data;
        const body = card.querySelector('.widget-body');
        // Revenue and commission widgets draw with the agent chart and table.
        const id = card.dataset.widget, type = ['agent_revenue', 'agent_commission', 'agent_group_target', 'agent_tier_revenue'].includes(card.dataset.type) && data.type ? data.type : card.dataset.type, money = card.dataset.money === '1' ? true : (card.dataset.money === 'pct' ? 'pct' : false);
        if (data.error) { body.innerHTML = '<div class="text-danger small">' + esc(data.error) + '</div>'; return; }
        if (APEX.includes(type)) {
            if (typeof ApexCharts === 'undefined') { body.innerHTML = '<div class="text-danger small">Chart library failed to load.</div>'; return; }
            renderApex(card, body, id, type, data, money);
            return;
        }

        if (type === 'kpi') {
            body.innerHTML = '<div class="kpi-value">' + fmt(data.value, money) + '</div>';
            return;
        }
        if (!data.labels || !data.labels.length) {
            if (charts[id]) { charts[id].destroy(); delete charts[id]; }
            body.innerHTML = '<div class="text-muted small py-4 text-center">No data for these filters.</div>';
            return;
        }
        if (type === 'table') {
            body.innerHTML = '<div class="table-responsive" style="max-height:300px"><table class="table table-sm mb-0"><tbody>' +
                data.labels.map((l, i) => '<tr data-drill="' + i + '"><td>' + esc(l) + '</td><td class="text-end fw-semibold">' + fmt(data.values[i], money) + '</td></tr>').join('') +
                '</tbody></table></div>';
            return;
        }
        if (type === 'funnel') {
            const max = Math.max(...data.values, 1), first = data.values[0] || 1;
            body.innerHTML = data.labels.map((l, i) => {
                const pct = Math.max(2, (data.values[i] / max) * 100);
                const conv = i === 0 ? '' : ' <span class="text-muted">(' + Math.round(data.values[i] / first * 100) + '%)</span>';
                return '<div class="funnel-row" data-drill="' + i + '" title="' + esc(l) + ': ' + fmt(data.values[i], money) + '"><div class="funnel-label">' + esc(l) + '</div>' +
                    '<div class="funnel-track"><div class="funnel-bar" style="width:' + pct + '%"></div></div>' +
                    '<div class="funnel-value">' + fmt(data.values[i], money) + conv + '</div></div>';
            }).join('');
            return;
        }

        let labels = data.labels, values = data.values;
        const round = type === 'pie' || type === 'doughnut';
        if (round) ({ labels, values } = foldOther(labels, values));

        const dataset = round ? {
            data: values, backgroundColor: SERIES.slice(0, values.length), borderColor: '#fff', borderWidth: 2,
        } : {
            data: values, label: card.dataset.title,
            backgroundColor: SERIES[0], borderColor: SERIES[0],
            borderRadius: 4, maxBarThickness: 28, borderWidth: type === 'line' ? 2 : 0,
            pointRadius: values.length > 40 ? 0 : 3, pointHoverRadius: 5, tension: 0, fill: false,
        };

        if (charts[id]) {
            charts[id].data.labels = labels;
            charts[id].data.datasets[0].data = values;
            if (round) charts[id].data.datasets[0].backgroundColor = SERIES.slice(0, values.length);
            charts[id].update('none');
            return;
        }
        body.innerHTML = '<div class="chart-box"><canvas></canvas></div>';
        charts[id] = new Chart(body.querySelector('canvas'), {
            type: type,
            data: { labels, datasets: [dataset] },
            options: {
                onClick: (e, els) => { if (els.length) drill(card, els[0].index); },
                onHover: (e, els) => { e.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
                maintainAspectRatio: false,
                interaction: { mode: round ? 'nearest' : 'index', intersect: false },
                plugins: {
                    legend: { display: round, position: 'right', labels: { boxWidth: 10 } },
                    tooltip: { callbacks: { label: c => ' ' + (round ? c.label + ': ' : '') + fmt(c.parsed.y ?? c.parsed, money) } },
                },
                scales: round ? {} : {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: labels.length > 12 } },
                    y: { beginAtZero: true, grid: { color: GRID }, border: { display: false }, ticks: { callback: v => fmt(v, money) } },
                },
            },
        });
    }

    /* ---------- click-through: the rows behind a number, bar or slice ---------- */
    const SINGLE = ['kpi', 'stat', 'sparkline', 'radial'];
    const qsState = () => Object.entries(state).filter(([, v]) => v);
    function drill(card, index) {
        const d = card._data || {};
        if (!d.keys || d.keys[index] === undefined) return;
        const key = d.keys[index];
        openRecords(card, Object.assign({ key: key === null ? '' : key }, d.field ? { field: d.field } : {}), d.labels[index]);
    }
    let panel;
    function openRecords(card, extra, label) {
        const params = new URLSearchParams(qsState());
        Object.entries(extra || {}).forEach(([k, v]) => params.set(k, v ?? ''));
        const url = card.dataset.records + '?' + params.toString();
        document.getElementById('recordsTitle').textContent = card.dataset.title + (label ? ': ' + label : '');
        document.getElementById('recordsSub').textContent = 'Loading…';
        document.getElementById('recordsCsv').href = url + '&format=csv';
        const bodyEl = document.getElementById('recordsBody');
        bodyEl.innerHTML = '<div class="p-4 text-muted">Loading…</div>';
        panel = panel || new bootstrap.Offcanvas(document.getElementById('recordsPanel'));
        panel.show();
        fetch(url, { headers: { Accept: 'application/json' } }).then(r => r.json()).then(res => {
            if (res.error) { bodyEl.innerHTML = '<div class="p-4 text-danger">' + esc(res.error) + '</div>'; document.getElementById('recordsSub').textContent = ''; return; }
            document.getElementById('recordsSub').textContent = (card.dataset.source ? 'From ' + card.dataset.source + ' · ' : '') + res.total.toLocaleString() + ' record' + (res.total === 1 ? '' : 's') + (res.note ? ' · ' + res.note : '') +
                (res.total > res.shown ? ' · showing latest ' + res.shown.toLocaleString() + ' (Excel has the same limit)' : '');
            const cell = (v, f) => v === null || v === undefined ? '<span class="text-muted">—</span>' : f === 'm' ? fmt(v, true) : f === 'n' ? fmt(v, false) : f === 'd' ? esc(String(v).slice(0, 16).replace('T', ' ')) : esc(v);
            bodyEl.innerHTML = res.rows.length ? '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr>' +
                res.columns.map(([l, f]) => '<th class="text-nowrap ' + (f === 'm' || f === 'n' ? 'text-end' : '') + '">' + esc(l) + '</th>').join('') + '</tr></thead><tbody>' +
                res.rows.map((r, i) => '<tr>' + r.map((v, j) => {
                    const f = res.columns[j][1];
                    let html = cell(v, f);
                    if (res.columns[j][0] === 'Guest' && res.guest_links[i] && v) html = '<a href="' + esc(res.guest_links[i]) + '" target="_blank" rel="noopener">' + html + '</a>';
                    return '<td class="' + (f === 'm' || f === 'n' ? 'text-end text-nowrap' : '') + '">' + html + '</td>';
                }).join('') + '</tr>').join('') + '</tbody></table></div>'
                : '<div class="p-4 text-muted">No records for these filters.</div>';
        }).catch(() => { bodyEl.innerHTML = '<div class="p-4 text-danger">Could not load records.</div>'; });
    }
    document.addEventListener('click', e => {
        const row = e.target.closest('[data-drill]');
        const card = e.target.closest('[data-widget]');
        if (!card) return;
        if (row) { drill(card, Number(row.dataset.drill)); return; }
        if (SINGLE.includes(card.dataset.type) && e.target.closest('.widget-body')) openRecords(card, {}, '');
    });
    document.querySelectorAll('[data-widget]').forEach(c => { if (SINGLE.includes(c.dataset.type)) c.classList.add('clickable'); });
    document.getElementById('recordsPdf')?.addEventListener('click', () => {
        const w = window.open('', '_blank');
        if (!w) return;
        w.document.write('<html><head><title>' + esc(document.getElementById('recordsTitle').textContent) + '</title><style>body{font-family:sans-serif;font-size:11px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:4px;text-align:left}</style></head><body><h3>' +
            esc(document.getElementById('recordsTitle').textContent) + '</h3><p>' + esc(document.getElementById('recordsSub').textContent) + '</p>' + document.getElementById('recordsBody').innerHTML + '</body></html>');
        w.document.close(); w.focus(); setTimeout(() => w.print(), 300);
    });

    /* ---------- dashboard export ---------- */
    document.getElementById('exportPdf')?.addEventListener('click', e => { e.preventDefault(); window.print(); });
    document.getElementById('exportExcel')?.addEventListener('click', e => {
        e.preventDefault();
        const q = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
        const lines = [[q(cfg.name), q('Exported ' + new Date().toLocaleString())].join(',')];
        document.querySelectorAll('[data-widget]').forEach(card => {
            const d = card._data; if (!d) return;
            lines.push('', q(card.dataset.title));
            if (d.columns && d.rows) {
                lines.push(d.columns.map(c => q(c[1])).join(','));
                d.rows.forEach(r => lines.push(d.columns.map(c => q(r[c[0]])).join(',')));
            } else if (d.labels) {
                d.labels.forEach((l, i) => lines.push([q(l), q(d.values[i])].join(',')));
            } else {
                lines.push(['Value', q(d.value)].concat(d.change !== undefined && d.change !== null ? ['Change %', q(d.change)] : []).concat(d.target ? ['Target', q(d.target)] : []).join(','));
            }
        });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv' }));
        a.download = (cfg.name || 'dashboard') + '.csv';
        a.click();
    });

    function loadAll() {
        const qs = new URLSearchParams(Object.entries(state).filter(([, v]) => v)).toString();
        document.querySelectorAll('[data-widget]').forEach(card => {
            fetch(card.dataset.url + (qs ? '?' + qs : ''), { headers: { Accept: 'application/json' } })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(d => render(card, d))
                .catch(() => { card.querySelector('.widget-body').innerHTML = '<div class="text-danger small">Could not load data.</div>'; });
        });
        document.getElementById('updatedAt').textContent = 'Updated ' + new Date().toLocaleTimeString();
    }

    function loadEmployees() {
        const sel = document.getElementById('employeeFilter');
        if (!sel) return;
        const q = new URLSearchParams(Object.entries({ branch_id: state.branch_id, tag_id: state.tag_id }).filter(([, v]) => v)).toString();
        fetch(cfg.employeesUrl + (q ? '?' + q : ''), { headers: { Accept: 'application/json' } })
            .then(r => r.json())
            .then(list => {
                sel.innerHTML = '<option value="">All employees</option>' + list.map(e => '<option value="' + e.id + '">' + esc(e.name) + '</option>').join('');
                if (list.some(e => String(e.id) === state.employee_id)) sel.value = state.employee_id; else state.employee_id = '';
            });
    }

    document.querySelectorAll('#branchTabs [data-branch]').forEach(a => a.addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('#branchTabs .nav-link').forEach(x => x.classList.remove('active'));
        a.classList.add('active');
        state.branch_id = a.dataset.branch;
        state.employee_id = '';
        loadEmployees();
        loadAll();
    }));
    document.getElementById('tagFilter')?.addEventListener('change', e => { state.tag_id = e.target.value; state.employee_id = ''; loadEmployees(); loadAll(); });
    document.getElementById('employeeFilter')?.addEventListener('change', e => { state.employee_id = e.target.value; loadAll(); });

    const iso = d => d.toISOString().slice(0, 10);
    document.getElementById('rangeFilter').addEventListener('change', e => {
        const v = e.target.value, now = new Date();
        document.getElementById('customRange').classList.toggle('d-none', v !== 'custom');
        if (v === 'custom') return;
        if (!v) { state.from = state.to = ''; }
        else if (v === 'today') { state.from = state.to = iso(now); }
        else if (v === 'month') { state.from = iso(new Date(now.getFullYear(), now.getMonth(), 1)); state.to = iso(now); }
        else { state.from = iso(new Date(now - v * 864e5)); state.to = iso(now); }
        loadAll();
    });
    ['fromDate', 'toDate'].forEach(idName => document.getElementById(idName).addEventListener('change', () => {
        state.from = document.getElementById('fromDate').value;
        state.to = document.getElementById('toDate').value;
        loadAll();
    }));
    document.getElementById('refreshBtn').addEventListener('click', loadAll);

    // Rebuild charts in the new colours when light/dark mode is switched.
    document.addEventListener('themechange', () => {
        Object.keys(charts).forEach(k => { charts[k].destroy(); delete charts[k]; });
        loadAll();
    });

    loadEmployees();
    loadAll();
    if (cfg.refreshSeconds > 0) setInterval(() => { if (!document.hidden) loadAll(); }, cfg.refreshSeconds * 1000);
})();
