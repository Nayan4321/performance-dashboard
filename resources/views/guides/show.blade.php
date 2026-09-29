@extends('layouts.app')
@section('title', $title)
@push('head')
<style>
    .guide-notes { columns: 3 320px; column-gap: 1.25rem; }
    .sticky-note { break-inside: avoid; margin: 0 0 1.25rem; padding: 1.4rem 1.25rem 1.1rem; border-radius: 4px 4px 14px 4px;
        box-shadow: 0 6px 14px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.06); position: relative; color: #3a3a2c; }
    .sticky-note::before { content: ""; position: absolute; top: -9px; left: 50%; width: 70px; height: 18px; transform: translateX(-50%) rotate(-2deg);
        background: rgba(255,255,255,.55); box-shadow: 0 1px 2px rgba(0,0,0,.08); }
    .sticky-note:nth-child(5n+1) { background: #fff6b8; transform: rotate(-.6deg); }
    .sticky-note:nth-child(5n+2) { background: #d9f5e3; transform: rotate(.5deg); }
    .sticky-note:nth-child(5n+3) { background: #ffe0e6; transform: rotate(-.3deg); }
    .sticky-note:nth-child(5n+4) { background: #dcecff; transform: rotate(.6deg); }
    .sticky-note:nth-child(5n+5) { background: #f1e4ff; transform: rotate(-.4deg); }
    .sticky-note h2 { font-size: 1.05rem; font-weight: 700; margin-bottom: .6rem; color: #2b2b20; }
    .sticky-note p, .sticky-note li { font-size: .9rem; line-height: 1.45; }
    .sticky-note ul, .sticky-note ol { padding-left: 1.1rem; margin-bottom: .5rem; }
    .sticky-note p:last-child, .sticky-note ul:last-child, .sticky-note ol:last-child { margin-bottom: 0; }
    .sticky-note blockquote { border-left: 3px solid rgba(0,0,0,.25); padding: .15rem 0 .15rem .6rem; margin: .4rem 0 .6rem; font-style: italic; }
    .sticky-note table { width: 100%; font-size: .85rem; margin-bottom: .5rem; }
    .sticky-note td, .sticky-note th { border-bottom: 1px dashed rgba(0,0,0,.18); padding: .2rem .3rem; vertical-align: top; }
    .note-important { background: #c62828; color: #fff; border-radius: 6px; padding: .45rem .6rem; margin-bottom: .6rem; font-size: .85rem; font-weight: 600; }
    .note-important i { margin-right: .3rem; }
    .note-tools { position: absolute; top: .35rem; right: .5rem; display: flex; gap: .45rem; opacity: .35; }
    .sticky-note:hover .note-tools { opacity: 1; }
    .note-tools form { margin: 0; }
    .note-tools .btn { color: #3a3a2c; line-height: 1; }
    [data-bs-theme="dark"] .sticky-note { filter: brightness(.92); }
</style>
@endpush
@section('content')
<div class="d-flex align-items-center mb-2"><h1 class="h4 mb-0"><i class="{{ $guide[1] }} me-1"></i>{{ $title }}</h1>
    <span class="badge text-bg-light ms-2">Call center guide</span>
    @if($canEdit)<button type="button" class="btn btn-sm btn-primary ms-auto" data-note-edit data-action="{{ route('guides.notes.store', $slug) }}" data-method="POST"><i class="iconoir-plus-circle"></i> Add note</button>@endif</div>
@if($intro)<div class="text-muted mb-4">{!! $intro !!}</div>@else<p class="text-muted mb-4">{{ $guide[2] }}</p>@endif
@if($notes->isEmpty())<p class="text-muted">No notes yet.</p>@endif
<div class="guide-notes">
    @foreach($notes as $note)
        <div class="sticky-note">
            @if($canEdit)
                <div class="note-tools">
                    <form method="post" action="{{ route('guides.notes.move', $note) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-link btn-sm p-0" title="Move earlier" @disabled($loop->first)>&uarr;</button></form>
                    <form method="post" action="{{ route('guides.notes.move', $note) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-link btn-sm p-0" title="Move later" @disabled($loop->last)>&darr;</button></form>
                    <button type="button" class="btn btn-link btn-sm p-0" title="Edit" data-note-edit data-action="{{ route('guides.notes.update', $note) }}" data-method="PUT"
                        data-title="{{ $note->title }}" data-important="{{ $note->important }}" data-body="{{ $note->body }}"><i class="iconoir-page-edit"></i></button>
                    <form method="post" action="{{ route('guides.notes.destroy', $note) }}" onsubmit="return confirm('Remove this note?')">@csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 text-danger" title="Remove">&times;</button></form>
                </div>
            @endif
            @foreach($note->importantLines() as $imp)<div class="note-important"><i class="iconoir-warning-triangle"></i>{{ $imp }}</div>@endforeach
            <h2>{{ $note->title }}</h2>
            {!! $note->html() !!}
        </div>
    @endforeach
</div>

@if($canEdit)
<div class="modal fade" id="noteModal" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" class="modal-content" id="noteForm">@csrf
    <input type="hidden" name="_method" value="POST" id="noteMethod">
    <div class="modal-header"><h5 class="modal-title" id="noteModalTitle">Note</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <label class="form-label">Title</label>
        <input name="title" id="noteTitle" class="form-control mb-3" maxlength="200" required>
        <label class="form-label">Important (red banner, one per line, optional)</label>
        <textarea name="important" id="noteImportant" class="form-control mb-3" rows="2" maxlength="2000"></textarea>
        <label class="form-label">Text</label>
        <textarea name="body" id="noteBody" class="form-control font-monospace" rows="12" maxlength="20000"></textarea>
        <div class="form-text">Start a line with "- " for a bullet or "1. " for a numbered step. **Bold** makes text bold, and a line starting with "&gt; " shows as a quote.</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save note</button></div>
</form></div></div>
@push('scripts')
<script>
document.querySelectorAll('[data-note-edit]').forEach(btn => btn.addEventListener('click', () => {
    const d = btn.dataset, adding = d.method === 'POST';
    document.getElementById('noteForm').action = d.action;
    document.getElementById('noteMethod').value = d.method;
    document.getElementById('noteModalTitle').textContent = adding ? 'Add note' : 'Edit note';
    document.getElementById('noteTitle').value = adding ? '' : (d.title || '');
    document.getElementById('noteImportant').value = adding ? '' : (d.important || '');
    document.getElementById('noteBody').value = adding ? '' : (d.body || '');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('noteModal')).show();
}));
</script>
@endpush
@endif
@endsection
