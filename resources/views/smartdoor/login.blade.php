<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk — Smart Door Cloud</title>
<link rel="stylesheet" href="{{ asset('smartdoor/style.css') }}">
</head>
<body>
<div class="login">
  <form method="POST" action="{{ url('/login') }}" class="login-card">
    @csrf
    <div class="brand big"><span class="logo"></span>Smart Door Cloud</div>
    <p class="muted">Masuk sebagai pengelola untuk memantau dan mengendalikan pintu.</p>
    <label>Email<input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
    <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
    <label style="flex-direction:row;align-items:center;gap:8px;font-weight:500"><input type="checkbox" name="remember" value="1"> Ingat saya</label>
    <button class="btn primary" type="submit">Masuk</button>
    @error('email')<p class="err">{{ $message }}</p>@enderror
  </form>
</div>
</body>
</html>
