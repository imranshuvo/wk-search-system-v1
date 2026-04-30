<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"><title>Redirects</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;margin:20px}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #e5e7eb;padding:8px;text-align:left}
    input[type=text]{width:100%;padding:6px}
  </style>
</head>
<body>
  <h1>Redirects (Tenant: {{ $tenant_id }})</h1>
  <form method="post" action="{{ route('admin.redirects.store',$tenant_id) }}">
    @csrf
    <input type="text" name="query" placeholder="Query" required>
    <input type="text" name="url" placeholder="URL" required>
    <label><input type="checkbox" name="active" checked> Active</label>
    <button type="submit">Add</button>
  </form>
  @if(session('ok'))<div>{{ session('ok') }}</div>@endif
  @if($errors->any())<div>{{ $errors->first() }}</div>@endif

  <table>
    <thead><tr><th>Query</th><th>URL</th><th>Active</th><th>Actions</th></tr></thead>
    <tbody>
      @foreach($rows as $r)
        <tr>
          <td>{{ $r->query }}</td>
          <td>
            <form method="post" action="{{ route('admin.redirects.update',[$tenant_id,$r->id]) }}">
              @csrf @method('PUT')
              <input type="text" name="url" value="{{ $r->url }}">
              <label><input type="checkbox" name="active" {{ $r->active ? 'checked' : '' }}> Active</label>
              <button type="submit">Save</button>
            </form>
          </td>
          <td>{{ $r->active ? 'Yes' : 'No' }}</td>
          <td>
            <form method="post" action="{{ route('admin.redirects.destroy',[$tenant_id,$r->id]) }}" onsubmit="return confirm('Delete?')">
              @csrf @method('DELETE')
              <button type="submit">Delete</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  <p><a href="{{ route('admin.tenants.index') }}">Back</a></p>
</body>
</html>


