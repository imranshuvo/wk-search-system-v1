<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"><title>Create Tenant</title>
  <style>
    :root{--bg:#f8fafc;--card:#ffffff;--text:#0f172a;--muted:#475569;--border:#e2e8f0;--primary:#111827;--success:#16a34a}
    *{box-sizing:border-box}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;margin:0;background:var(--bg);color:var(--text)}
    .container{max-width:640px;margin:40px auto;padding:24px}
    .card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:24px}
    .title{font-size:22px;font-weight:700;margin-bottom:8px}
    .subtitle{color:var(--muted);font-size:13px;margin-bottom:20px}
    .form-row{margin-bottom:16px}
    label{display:block;font-size:13px;font-weight:500;color:var(--text);margin-bottom:6px}
    input,select{width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;background:#fff;font-size:14px}
    input:focus,select:focus{outline:none;border-color:var(--primary)}
    .footer{display:flex;gap:12px;justify-content:flex-end;margin-top:24px;padding-top:16px;border-top:1px solid var(--border)}
    .btn{display:inline-flex;align-items:center;gap:6px;background:var(--primary);color:#fff;border:1px solid #0b1220;border-radius:8px;padding:10px 16px;text-decoration:none;cursor:pointer;font-size:14px;font-weight:500}
    .btn.secondary{background:#fff;color:var(--primary);border:1px solid var(--border)}
    .error{margin-top:16px;padding:12px;border:1px solid #fecaca;background:#fef2f2;border-radius:8px;color:#b91c1c;font-size:13px}
    .note{font-size:12px;color:var(--muted);margin-top:4px}
  </style>
</head>
<body>
  <div class="container">
    <div class="card">
      <div class="title">Create New Tenant</div>
      <div class="subtitle">Tenant ID will be auto-generated</div>
      <form method="post" action="{{ route('admin.tenants.store') }}">
        @csrf
        <div class="form-row">
          <label>Site Name</label>
          <input name="site_name" required value="{{ old('site_name') }}" placeholder="e.g., Sporty Fit">
          <div class="note">The name of the website or store</div>
        </div>
        <div class="form-row">
          <label>Site URL</label>
          <input name="site_url" type="url" required value="{{ old('site_url','https://') }}" placeholder="https://example.com">
          <div class="note">Full URL including https://</div>
        </div>
        <div class="form-row">
          <label>Status</label>
          <select name="status">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>
        <div class="footer">
          <a class="btn secondary" href="{{ route('admin.tenants.index') }}">Cancel</a>
          <button class="btn" type="submit">Create Tenant</button>
        </div>
      </form>
      @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
      @endif
    </div>
  </div>
</body>
</html>


