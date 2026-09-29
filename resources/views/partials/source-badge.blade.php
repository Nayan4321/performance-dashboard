@php($src = $source ?? '')
@foreach(array_filter(array_map('trim', explode('+', $src))) as $part)
    <span class="badge fw-normal {{ ['Zenoti' => 'bg-primary-subtle text-primary', 'CallGear' => 'bg-info-subtle text-info'][$part] ?? 'bg-secondary-subtle text-secondary' }}" title="Data from {{ $part }}"><i class="bi {{ ['Zenoti' => 'bi-flower1', 'CallGear' => 'bi-telephone'][$part] ?? 'bi-pencil' }}"></i> {{ $part }}</span>
@endforeach
