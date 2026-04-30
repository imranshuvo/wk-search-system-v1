<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"><title>Synonyms</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;margin:20px}
    textarea{width:100%;min-height:240px;padding:10px;border:1px solid #e5e7eb;border-radius:8px}
    button,a{display:inline-block;background:#111827;color:#fff;border:none;border-radius:6px;padding:8px 12px;text-decoration:none;margin-top:12px}
  </style>
</head>
<body>
  <h1>Synonyms (Tenant: {{ $tenant_id }})</h1>
  <form method="post" action="{{ route('admin.synonyms.update',$tenant_id) }}">
    @csrf
    @method('PUT')
    <p>JSON array or object, e.g. [{"from":["tee","t-shirt"],"to":"tshirt"}]</p>
    <textarea name="synonym_json">{{ old('synonym_json', json_encode($data, JSON_PRETTY_PRINT)) }}</textarea>
    <div>
      <button type="submit">Save</button>
      <a href="{{ route('admin.tenants.index') }}">Back</a>
    </div>
    @if(session('ok'))<div>{{ session('ok') }}</div>@endif
    @if($errors->any())<div>{{ $errors->first() }}</div>@endif
  </form>
</body>
</html>


