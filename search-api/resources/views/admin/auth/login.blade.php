<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sign in · WK Search Admin</title>
  <style>
    html,body{height:100%}
    body{margin:0;background:#fff;color:#111827;display:flex;align-items:center;justify-content:center}
    .wrap{width:100%;max-width:440px;padding:24px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:32px}
    .title{font-size:22px;font-weight:700;margin:0 0 8px;text-align:center}
    .muted{color:#6b7280;text-align:center;margin-bottom:20px}
    label{display:block;font-size:13px;margin-bottom:12px}
    input{display:block;width:100%;margin-top:6px;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;font:inherit;background:#fff;color:#111827}
    button{display:block;width:100%;background:#4f46e5;color:#fff;border:none;border-radius:8px;padding:10px 12px;font-weight:600;cursor:pointer}
    button:hover{background:#4338ca}
    .error{margin-top:8px;color:#b91c1c;font-size:13px}
  </style>
  </head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="title">Sign in</div>
      <div class="muted">Enter your admin credentials</div>
      <form method="post" action="{{ route('admin.login.post') }}">
        @csrf
        <label>Email
          <input name="email" type="email" value="{{ old('email') }}" required />
        </label>
        <label>Password
          <input name="password" type="password" required />
        </label>
        <button type="submit">Continue</button>
        @if ($errors->any())
          <div class="error">{{ $errors->first() }}</div>
        @endif
      </form>
    </div>
  </div>
</body>
</html>


