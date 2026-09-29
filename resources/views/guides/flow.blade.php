@extends('layouts.app')
@section('title', $guide[0])
@push('head')
<style>
    .flow-wrap { overflow: auto; max-height: calc(100vh - 230px); background: #fff; border-radius: .5rem; }
    .flow-wrap svg { display: block; margin: 0 auto; }
    .flow-legend span { display: inline-flex; align-items: center; gap: .35rem; margin-right: 1rem; font-size: .85rem; }
    .flow-legend i { width: 14px; height: 14px; border-radius: 3px; display: inline-block; border: 1px solid rgba(0,0,0,.15); }
    .flow-editor table td { vertical-align: middle; }
    .flow-editor textarea { min-height: 38px; resize: vertical; }
    .flow-editor .table-wrap { max-height: 420px; overflow: auto; }
    .flow-editor .swatch { width: 10px; height: 26px; border-radius: 3px; display: inline-block; }
</style>
@endpush
@section('content')
<div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h1 class="h4 mb-0"><i class="{{ $guide[1] }} me-1"></i>{{ $guide[0] }}</h1>
    <span class="badge text-bg-light">Call center guide</span>
    <div class="ms-auto d-flex gap-2">
        <div class="btn-group btn-group-sm" role="group" aria-label="Layout">
            <button type="button" class="btn btn-outline-secondary" data-dir="LR">Across</button>
            <button type="button" class="btn btn-outline-secondary" data-dir="TD">Top to bottom</button>
        </div>
        <div class="btn-group btn-group-sm" role="group" aria-label="Zoom">
            <button type="button" class="btn btn-outline-secondary" data-zoom="-1" title="Smaller">&minus;</button>
            <button type="button" class="btn btn-outline-secondary" data-zoom="0" title="Fit to the page">Fit</button>
            <button type="button" class="btn btn-outline-secondary" data-zoom="1" title="Bigger">+</button>
        </div>
        @if($canEdit)<button type="button" class="btn btn-sm btn-primary" id="editToggle"><i class="iconoir-page-edit"></i> Edit chart</button>@endif
    </div>
</div>
<p class="text-muted mb-2">{{ $guide[2] }}.</p>
<div class="flow-legend mb-2">
    <span><i style="background:#f9e79f"></i>Opening</span><span><i style="background:#d4e6f1"></i>Bookings &amp; enquiries</span>
    <span><i style="background:#a9dfbf"></i>No-show recovery</span><span><i style="background:#f5b7b1"></i>Complaints</span>
    <span><i style="background:#f1948a"></i>Call end</span><span class="text-muted">Dotted line: when it happens later</span>
</div>
@if($errors->any())<div class="alert alert-danger small">{{ $errors->first() }}</div>@endif
<div class="card"><div class="card-body p-2 flow-wrap"><div id="flow" class="text-muted p-3">Drawing the flow…</div></div></div>

@if($canEdit)
<div class="card mt-3 flow-editor d-none" id="flowEditor"><div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h2 class="h6 mb-0">Edit chart</h2>
        <span class="small text-muted">Changes show in the chart above as you type. Nothing is saved until you press Save.</span>
        <form method="post" action="{{ route('guides.flow.save', $slug) }}" class="ms-auto d-flex gap-2" id="flowSave">@csrf @method('PUT')
            <input type="hidden" name="chart" id="chartInput">
            <button type="button" class="btn btn-sm btn-light" id="flowCancel">Cancel</button>
            <button class="btn btn-sm btn-primary">Save chart</button>
        </form>
        @if($edited)
        <form method="post" action="{{ route('guides.flow.reset', $slug) }}" onsubmit="return confirm('Throw away all edits and go back to the original chart?')">@csrf @method('DELETE')
            <button class="btn btn-sm btn-outline-danger">Reset to original</button></form>
        @endif
    </div>
    <div class="row g-4">
        <div class="col-xl-6">
            <div class="d-flex align-items-center mb-2"><h3 class="h6 mb-0">Steps</h3>
                <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="addStep">+ Add step</button></div>
            <div class="table-wrap"><table class="table table-sm mb-0">
                <thead><tr><th></th><th>Text (Enter for a new line)</th><th>Colour</th><th>Shape</th><th></th></tr></thead>
                <tbody id="stepRows"></tbody>
            </table></div>
        </div>
        <div class="col-xl-6">
            <div class="d-flex align-items-center mb-2"><h3 class="h6 mb-0">Arrows</h3>
                <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="addLink">+ Add arrow</button></div>
            <div class="table-wrap"><table class="table table-sm mb-0">
                <thead><tr><th>From</th><th>To</th><th>Label on the arrow</th><th>Dotted</th><th></th></tr></thead>
                <tbody id="linkRows"></tbody>
            </table></div>
        </div>
    </div>
</div></div>
@endif
<script type="application/json" id="flowData">{!! json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
@push('scripts')
<script src="{{ asset('vendor/mermaid/mermaid.min.js') }}"></script>
<script>
(() => {
    const GROUPS = { intro: ['Opening', '#f9e79f', '#e2c044'], book: ['Bookings & enquiries', '#d4e6f1', '#9dbfd8'], noshow: ['No-show recovery', '#a9dfbf', '#52be80'],
        complaint: ['Complaints', '#f5b7b1', '#e57373'], endnode: ['Call end', '#f1948a', '#b03a2e'], dark: ['Start (dark)', '#2c3e50', '#2c3e50'] };
    const SHAPES = { box: ['Box', '["', '"]'], decision: ['Decision (diamond)', '{"', '"}'], rounded: ['Rounded', '(["', '"])'], start: ['Start / hexagon', '{' + '{"', '"}' + '}'] }; // split so Blade doesn't read it as an echo
    const saved = JSON.parse(document.getElementById('flowData').textContent);
    let chart = JSON.parse(JSON.stringify(saved));
    const box = document.getElementById('flow');
    let zoom = null, n = 0, timer = null;

    const esc = t => String(t ?? '').replace(/"/g, '#quot;').replace(/</g, '#lt;').replace(/>/g, '#gt;').replace(/\r?\n/g, '<br>');
    function toMermaid(c) {
        const lines = ['flowchart ' + (c.direction === 'TD' ? 'TD' : 'LR')];
        c.steps.forEach(s => { const [, open, close] = SHAPES[s.shape] || SHAPES.box; lines.push(`    ${s.id}${open}${esc(s.label) || ' '}${close}:::${GROUPS[s.group] ? s.group : 'book'}`); });
        c.links.forEach(l => {
            if (!c.steps.some(s => s.id === l.from) || !c.steps.some(s => s.id === l.to)) return;
            const lab = (l.label || '').trim();
            lines.push('    ' + l.from + (l.dotted ? (lab ? ` -. "${esc(lab)}" .-> ` : ' -.-> ') : (lab ? ` -- "${esc(lab)}" --> ` : ' --> ')) + l.to);
        });
        Object.entries(GROUPS).forEach(([k, [, fill, stroke]]) =>
            lines.push(`    classDef ${k} fill:${fill},stroke:${stroke}${k === 'endnode' ? ',stroke-width:2px' : ''},color:${k === 'dark' ? '#fff' : '#333'}`));
        return lines.join('\n');
    }

    mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: 'base',
        themeVariables: { fontFamily: 'inherit', fontSize: '15px', lineColor: '#6c757d', edgeLabelBackground: '#ffffff' },
        flowchart: { useMaxWidth: false, htmlLabels: true, curve: 'basis', nodeSpacing: 35, rankSpacing: 55 } });
    async function draw() {
        try {
            const { svg } = await mermaid.render('flowSvg' + (n++), toMermaid(chart));
            box.innerHTML = svg;
            applyZoom();
        } catch (e) { box.textContent = 'The flow could not be drawn: ' + (e.message || e); }
    }
    const redraw = () => { clearTimeout(timer); timer = setTimeout(draw, 350); };
    function applyZoom() {
        const svg = box.querySelector('svg');
        if (!svg) return;
        const w = svg.viewBox.baseVal.width, h = svg.viewBox.baseVal.height;
        const z = zoom ?? Math.max(0.6, Math.min(1, (box.parentElement.clientWidth - 24) / w));
        svg.style.width = (w * z) + 'px'; svg.style.height = (h * z) + 'px'; svg.style.maxWidth = 'none';
        document.querySelector('[data-zoom="0"]').textContent = zoom === null ? 'Fit' : Math.round(z * 100) + '%';
        box.dataset.zoom = z;
    }
    const markDir = () => document.querySelectorAll('[data-dir]').forEach(x => x.classList.toggle('active', x.dataset.dir === (chart.direction === 'TD' ? 'TD' : 'LR')));
    document.querySelectorAll('[data-dir]').forEach(b => b.addEventListener('click', () => { chart.direction = b.dataset.dir; zoom = null; markDir(); draw(); }));
    document.querySelectorAll('[data-zoom]').forEach(b => b.addEventListener('click', () => {
        const step = +b.dataset.zoom, current = +box.dataset.zoom || 1;
        zoom = step === 0 ? null : Math.min(2, Math.max(0.3, current + step * 0.15));
        applyZoom();
    }));
    markDir();
    draw();

    // ---------------------------------------------------------------- editor (guides.manage only)
    const editor = document.getElementById('flowEditor');
    if (!editor) return;
    const stepRows = document.getElementById('stepRows'), linkRows = document.getElementById('linkRows');
    const option = (value, label, selected) => { const o = document.createElement('option'); o.value = value; o.textContent = label; o.selected = selected; return o; };
    const select = (entries, value, onChange) => { const s = document.createElement('select'); s.className = 'form-select form-select-sm';
        entries.forEach(([v, l]) => s.append(option(v, l, v === value))); s.addEventListener('change', () => onChange(s.value)); return s; };
    const button = (text, cls, title, onClick) => { const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm ' + cls; b.textContent = text; b.title = title; b.addEventListener('click', onClick); return b; };
    const cell = (...kids) => { const td = document.createElement('td'); td.append(...kids); return td; };
    const stepName = s => (s.label || '(no text)').replace(/\s+/g, ' ').slice(0, 40);

    function renderSteps() {
        stepRows.innerHTML = '';
        chart.steps.forEach((s, i) => {
            const sw = document.createElement('span'); sw.className = 'swatch'; sw.style.background = (GROUPS[s.group] || GROUPS.book)[1];
            const text = document.createElement('textarea'); text.className = 'form-control form-control-sm'; text.rows = Math.max(1, s.label.split('\n').length); text.value = s.label;
            text.addEventListener('input', () => { s.label = text.value; redraw(); });
            text.addEventListener('change', renderLinks);
            const move = document.createElement('div'); move.className = 'btn-group';
            move.append(button('↑', 'btn-light', 'Move up in the list', () => { if (i > 0) { [chart.steps[i - 1], chart.steps[i]] = [chart.steps[i], chart.steps[i - 1]]; renderAll(); } }));
            const del = button('×', 'btn-outline-danger', 'Remove this step and its arrows', () => {
                const used = chart.links.filter(l => l.from === s.id || l.to === s.id).length;
                if (used && !confirm(`Remove "${stepName(s)}" and its ${used} arrow(s)?`)) return;
                chart.steps.splice(i, 1); chart.links = chart.links.filter(l => l.from !== s.id && l.to !== s.id); renderAll();
            });
            const tr = document.createElement('tr');
            tr.append(cell(sw), cell(text),
                cell(select(Object.entries(GROUPS).map(([k, v]) => [k, v[0]]), s.group, v => { s.group = v; sw.style.background = GROUPS[v][1]; redraw(); })),
                cell(select(Object.entries(SHAPES).map(([k, v]) => [k, v[0]]), s.shape, v => { s.shape = v; redraw(); })),
                cell(move, ' ', del));
            stepRows.append(tr);
        });
    }
    function renderLinks() {
        linkRows.innerHTML = '';
        const opts = chart.steps.map(s => [s.id, stepName(s)]);
        chart.links.forEach((l, i) => {
            const label = document.createElement('input'); label.className = 'form-control form-control-sm'; label.value = l.label || ''; label.maxLength = 100;
            label.addEventListener('input', () => { l.label = label.value; redraw(); });
            const dotted = document.createElement('input'); dotted.type = 'checkbox'; dotted.className = 'form-check-input'; dotted.checked = !!l.dotted;
            dotted.addEventListener('change', () => { l.dotted = dotted.checked; redraw(); });
            const tr = document.createElement('tr');
            tr.append(cell(select(opts, l.from, v => { l.from = v; redraw(); })), cell(select(opts, l.to, v => { l.to = v; redraw(); })),
                cell(label), cell(dotted), cell(button('×', 'btn-outline-danger', 'Remove this arrow', () => { chart.links.splice(i, 1); renderLinks(); redraw(); })));
            linkRows.append(tr);
        });
    }
    const renderAll = () => { renderSteps(); renderLinks(); redraw(); };
    document.getElementById('addStep').addEventListener('click', () => {
        let k = chart.steps.length + 1; while (chart.steps.some(s => s.id === 'step' + k)) k++;
        chart.steps.push({ id: 'step' + k, label: 'New step', shape: 'box', group: 'book' });
        renderAll();
        stepRows.lastElementChild?.querySelector('textarea')?.select();
    });
    document.getElementById('addLink').addEventListener('click', () => {
        if (chart.steps.length < 1) return;
        const last = chart.steps[chart.steps.length - 1];
        chart.links.push({ from: (chart.steps[chart.steps.length - 2] || last).id, to: last.id, label: '', dotted: false });
        renderLinks(); redraw();
        linkRows.lastElementChild?.scrollIntoView({ block: 'nearest' });
    });
    document.getElementById('editToggle').addEventListener('click', () => { editor.classList.remove('d-none'); renderAll(); editor.scrollIntoView({ behavior: 'smooth' }); });
    document.getElementById('flowCancel').addEventListener('click', () => { chart = JSON.parse(JSON.stringify(saved)); editor.classList.add('d-none'); markDir(); draw(); });
    document.getElementById('flowSave').addEventListener('submit', () => { document.getElementById('chartInput').value = JSON.stringify(chart); });
})();
</script>
@endpush
