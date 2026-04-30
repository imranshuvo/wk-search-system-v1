<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"><title>Edit Tenant</title>
  <style>
    :root{--bg:#f8fafc;--card:#ffffff;--text:#0f172a;--muted:#475569;--border:#e2e8f0;--primary:#111827;--danger:#b91c1c}
    *{box-sizing:border-box}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;margin:0;background:var(--bg);color:var(--text)}
    .container{max-width:1080px;margin:0 auto;padding:24px}
    .header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
    .title{font-size:22px;font-weight:700}
    .subtitle{color:var(--muted);font-size:13px;margin-top:4px}
    .actions{display:flex;gap:8px;flex-wrap:wrap}
    .btn{display:inline-flex;align-items:center;gap:6px;background:var(--primary);color:#fff;border:1px solid #0b1220;border-radius:8px;padding:8px 12px;text-decoration:none;cursor:pointer}
    .btn.secondary{background:#fff;color:var(--primary);border:1px solid var(--border)}
    .btn.danger{background:var(--danger);border-color:#7f1d1d}
    .grid{display:grid;grid-template-columns:1.2fr .8fr;gap:16px}
    .card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px}
    .card h3{margin:0 0 12px 0;font-size:15px}
    .form-row{margin-bottom:12px}
    label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
    input,select{width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;background:#fff}
    .readonly input{background:#f1f5f9;color:#334155}
    .stack{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .stack-3{grid-template-columns:repeat(3,1fr)}
    .footer{display:flex;align-items:center;justify-content:space-between;margin-top:12px}
    .note{font-size:12px;color:var(--muted)}
    .inline{display:inline}
    .inline + .inline{margin-left:8px}
    .alert{margin-top:12px;padding:10px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:8px;color:#166534}
    .error{margin-top:12px;padding:10px;border:1px solid #fecaca;background:#fef2f2;border-radius:8px;color:#7f1d1d}
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div>
        <div class="title">Tenant • {{ $site->site_name ?: $site->tenant_id }}</div>
        <div class="subtitle">Edit settings and manage sync for <strong>{{ $site->tenant_id }}</strong></div>
      </div>
      <div class="actions">
        <a class="btn secondary" href="{{ route('admin.tenants.index') }}">Back</a>
        <form class="inline" method="post" action="{{ route('admin.tenants.sync',$site->tenant_id) }}" onsubmit="return confirm('Trigger delta sync now?')">
          @csrf
          <input type="hidden" name="full" value="0" />
          <button class="btn" type="submit">Sync Now (Delta)</button>
        </form>
        <form class="inline" method="post" action="{{ route('admin.tenants.sync',$site->tenant_id) }}" onsubmit="return confirm('Trigger FULL feed sync now? This may take time.')">
          @csrf
          <input type="hidden" name="full" value="1" />
          <button class="btn" type="submit">Sync Now (Full)</button>
        </form>
        <form class="inline" method="post" action="{{ route('admin.tenants.regen',$site->tenant_id) }}" onsubmit="return confirm('Regenerate API key?')">
          @csrf
          <button class="btn danger" type="submit">Regenerate API Key</button>
        </form>
      </div>
    </div>

    <div class="grid">
      <div class="card">
        <h3>Profile</h3>
        <form method="post" action="{{ route('admin.tenants.update',$site->tenant_id) }}">
          @csrf
          @method('PUT')
          <div class="form-row">
            <label>Tenant ID</label>
            <input value="{{ $site->tenant_id }}" readonly />
          </div>
          <div class="stack">
            <div class="form-row">
              <label>Site Name</label>
              <input name="site_name" required value="{{ old('site_name',$site->site_name) }}">
            </div>
            <div class="form-row">
              <label>Status</label>
              <select name="status">
                <option value="active" {{ $site->status==='active'?'selected':'' }}>active</option>
                <option value="inactive" {{ $site->status==='inactive'?'selected':'' }}>inactive</option>
                <option value="suspended" {{ $site->status==='suspended'?'selected':'' }}>suspended</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <label>Site URL</label>
            <input name="site_url" type="url" required value="{{ old('site_url',$site->site_url) }}">
          </div>
          <div class="form-row">
            <label>Feed File URL (products.json or full.json)</label>
            <?php $settings = $site->settings ?? []; ?>
            <input name="feed_file_url" type="url" placeholder="https://example.com/wp-content/uploads/wk-search/{tenant}/products.json" value="{{ old('feed_file_url', $settings['feed_file_url'] ?? '') }}">
          </div>
          <div class="form-row">
            <label>Popular Searches URL (popular_searches.json)</label>
            <input name="popular_url" type="url" placeholder="https://example.com/wp-content/uploads/wk-search/{tenant}/popular_searches.json" value="{{ old('popular_url', $settings['popular_url'] ?? '') }}">
          </div>
          <div class="form-row">
            <label>Top Categories URL (top_categories.json)</label>
            <input name="top_categories_url" type="url" placeholder="https://example.com/wp-content/uploads/wk-search/{tenant}/top_categories.json" value="{{ old('top_categories_url', $settings['top_categories_url'] ?? '') }}">
          </div>
          <div class="footer">
            <span class="note">Changes take effect immediately after save.</span>
            <button class="btn" type="submit">Save</button>
          </div>
        </form>
      </div>

      <div class="card readonly">
        <h3>Keys & Stats</h3>
        <div class="form-row">
          <label>API Key</label>
          <input value="{{ $site->api_key }}" readonly />
        </div>
        <div class="stack">
          <div class="form-row">
            <label>Total Products</label>
            <input value="{{ $site->total_products ?? 0 }}" readonly />
          </div>
          <div class="form-row">
            <label>Feed Frequency</label>
            <input value="{{ $site->feed_frequency ?? 'daily 03:00' }}" readonly />
          </div>
        </div>
        <div class="stack">
          <div class="form-row">
            <label>Last Feed At</label>
            <input value="{{ $site->last_feed_at ?? '' }}" readonly />
          </div>
          <div class="form-row">
            <label>Last Sync At</label>
            <input value="{{ $site->last_sync_at ?? '' }}" readonly />
          </div>
        </div>
        <div class="stack-3">
          <div class="form-row">
            <label>Created At</label>
            <input value="{{ $site->created_at ?? '' }}" readonly />
          </div>
          <div class="form-row">
            <label>Updated At</label>
            <input value="{{ $site->updated_at ?? '' }}" readonly />
          </div>
          <div class="form-row">
            <label>Tenant</label>
            <input value="{{ $site->tenant_id }}" readonly />
          </div>
        </div>
        <form method="post" action="{{ route('admin.tenants.upload',$site->tenant_id) }}" enctype="multipart/form-data" style="margin-top:12px;border:1px dashed var(--border);padding:10px;border-radius:8px" onsubmit="return confirm('Upload and import a full feed JSON?')">
          @csrf
          <label>Upload full.json</label>
          <input type="file" name="feed_file" accept="application/json,.json,text/plain" />
          <div class="footer" style="margin-top:8px">
            <span class="note">Use for manual imports in staging or local.</span>
            <button class="btn secondary" type="submit">Upload & Import</button>
          </div>
        </form>
        <div class="footer" style="margin-top:12px">
          <form class="inline" method="post" action="{{ route('admin.tenants.syncPopular',$site->tenant_id) }}" onsubmit="return confirm('Sync popular searches from URL?')">
            @csrf
            <button class="btn" type="submit">Sync Popular (URL)</button>
          </form>
          <form class="inline" method="post" action="{{ route('admin.tenants.syncTopCats',$site->tenant_id) }}" onsubmit="return confirm('Sync top categories from URL?')">
            @csrf
            <button class="btn" type="submit">Sync Top Categories (URL)</button>
          </form>
        </div>
      </div>
    </div>

    @if(session('ok'))<div class="alert">{{ session('ok') }}</div>@endif
    @if ($errors->any())
      <div class="error">{{ $errors->first() }}</div>
    @endif
  </div>
</body>
</html>


