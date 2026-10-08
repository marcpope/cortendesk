{{-- Possible-duplicate flag (issue #91). The tooltip names the twin; the link
     opens the detail page, which explains and offers the fixes. --}}
@if ($duplicates?->has($device->id))
    <a href="{{ route('devices.show', $device->id) }}" class="badge bg-warning-subtle text-warning {{ $class ?? '' }}"
       title="{{ $duplicates->summary($device->id) }}"><i class="ri-file-copy-2-line me-1"></i>Possible duplicate</a>
@endif
