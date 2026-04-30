<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"><title>Pins & Bans</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;margin:20px}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #e5e7eb;padding:8px;text-align:left}
    input[type=text],input[type=number]{padding:6px}
  </style>
</head>
<body>
  <h1>Pins & Bans (Tenant: {{ $tenant_id }})</h1>
  @if(session('ok'))<div>{{ session('ok') }}</div>@endif
  @if($errors->any())<div>{{ $errors->first() }}</div>@endif

  <h2>Add Pin</h2>
  <form method="post" action="{{ route('admin.pins.add',$tenant_id) }}">
    @csrf
    <input type="text" name="query" placeholder="Query" required>
    <input type="number" name="product_id" placeholder="Product ID" required>
    <input type="number" name="position" placeholder="Position" value="1">
    <button type="submit">Add Pin</button>
  </form>

  <table>
    <thead><tr><th>Query</th><th>Product</th><th>Position</th><th>Actions</th></tr></thead>
    <tbody>
      @foreach($pins as $p)
        <tr>
          <td>{{ $p->query }}</td><td>{{ $p->product_id }}</td><td>{{ $p->position }}</td>
          <td>
            <form method="post" action="{{ route('admin.pins.remove',[$tenant_id,$p->id]) }}" onsubmit="return confirm('Remove pin?')">
              @csrf @method('DELETE')
              <button type="submit">Remove</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <h2>Add Ban</h2>
  <form method="post" action="{{ route('admin.bans.add',$tenant_id) }}">
    @csrf
    <input type="text" name="query" placeholder="Query" required>
    <input type="number" name="product_id" placeholder="Product ID" required>
    <button type="submit">Add Ban</button>
  </form>

  <table>
    <thead><tr><th>Query</th><th>Product</th><th>Actions</th></tr></thead>
    <tbody>
      @foreach($bans as $b)
        <tr>
          <td>{{ $b->query }}</td><td>{{ $b->product_id }}</td>
          <td>
            <form method="post" action="{{ route('admin.bans.remove',[$tenant_id,$b->id]) }}" onsubmit="return confirm('Remove ban?')">
              @csrf @method('DELETE')
              <button type="submit">Remove</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <p><a href="{{ route('admin.tenants.index') }}">Back</a></p>
</body>
</html>


