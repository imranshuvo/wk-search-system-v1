<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Zero Results</title>
<style>body{font-family:system-ui;margin:20px} table{border-collapse:collapse;width:100%} th,td{border:1px solid #e5e7eb;padding:8px;text-align:left}</style>
</head><body>
<h1>Zero-Result Queries (Tenant: {{ $tenant_id }})</h1>
<table><thead><tr><th>Query</th><th>Count</th><th>Last</th></tr></thead><tbody>
@foreach($rows as $r)
<tr><td>{{ $r->query }}</td><td>{{ $r->count }}</td><td>{{ $r->last_searched }}</td></tr>
@endforeach
</tbody></table>
<p><a href="{{ route('admin.tenants.index') }}">Back</a></p>
</body></html>


