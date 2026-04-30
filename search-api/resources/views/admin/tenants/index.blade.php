<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"><title>Tenants</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;margin:20px}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #e5e7eb;padding:8px;text-align:left}
    a.button,button{display:inline-block;background:#111827;color:#fff;border:none;border-radius:6px;padding:8px 12px;text-decoration:none}
    .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
  </style>
</head>
<body>
  <div class="top">
    <h1>Tenants</h1>
    <div>
      <a class="button" href="{{ route('admin.tenants.create') }}">Create Tenant</a>
      <a class="button" href="{{ route('admin.logout') }}">Logout</a>
    </div>
  </div>
  @if(session('ok'))<div>{{ session('ok') }}</div>@endif
  <table>
    <thead><tr><th>Tenant ID</th><th>Name</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      @foreach($sites as $s)
        <tr>
          <td>{{ $s->tenant_id }}</td>
          <td>{{ $s->site_name }}</td>
          <td>{{ $s->status }}</td>
          <td>
            <a class="button" href="{{ route('admin.tenants.edit',$s->tenant_id) }}">Edit</a>
            <form method="post" action="{{ route('admin.tenants.destroy',$s->tenant_id) }}" style="display:inline" onsubmit="return confirm('Delete?')">
              @csrf @method('DELETE')
              <button type="submit">Delete</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>


