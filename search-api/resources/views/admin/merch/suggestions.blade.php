<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"><title>Synonym Suggestions</title>
  <style>
    body{font-family:system-ui;margin:20px}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #e5e7eb;padding:8px;text-align:left}
    form{display:inline}
  </style>
</head>
<body>
  <h1>Synonym Suggestions (Tenant: {{ $tenant_id }})</h1>
  @if(session('ok'))<div>{{ session('ok') }}</div>@endif
  <table>
    <thead><tr><th>From</th><th>To</th><th>Score</th><th>Actions</th></tr></thead>
    <tbody>
      @foreach($rows as $r)
        <tr>
          <td>{{ $r->from_term }}</td>
          <td>{{ $r->to_term }}</td>
          <td>{{ $r->score }}</td>
          <td>
            <form method="post" action="{{ route('admin.suggestions.approve', [$tenant_id, $r->id]) }}">@csrf @method('PUT')<button type="submit">Approve</button></form>
            <form method="post" action="{{ route('admin.suggestions.reject', [$tenant_id, $r->id]) }}" onsubmit="return confirm('Reject?')">@csrf @method('PUT')<button type="submit">Reject</button></form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  <p><a href="{{ route('admin.tenants.index') }}">Back</a></p>
</body>
</html>


