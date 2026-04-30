<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Performance</title>
<style>body{font-family:system-ui;margin:20px} table{border-collapse:collapse;width:100%} th,td{border:1px solid #e5e7eb;padding:8px;text-align:left}</style>
</head><body>
<h1>Search Performance (Tenant: {{ $tenant_id }})</h1>
<table><thead><tr><th>Date</th><th>Hour</th><th>Event</th><th>Sum(ms)</th></tr></thead><tbody>
@foreach($rows as $r)
<tr><td>{{ $r->date }}</td><td>{{ $r->hour }}</td><td>{{ $r->event_type }}</td><td>{{ $r->count }}</td></tr>
@endforeach
</tbody></table>
<p><a href="{{ route('admin.tenants.index') }}">Back</a></p>
</body></html>


