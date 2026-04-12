<!DOCTYPE html>
<html lang="{{ $language === 'rus' ? 'ru' : 'en' }}">
<head>
  <meta charset="UTF-8" />
  <title>{{ $language === 'rus' ? 'Код подтверждения регистрации' : 'Registration Verification Code' }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #111827;">
  @if ($language === 'rus')
    <h1 style="margin: 0 0 16px;">Подтверждение регистрации</h1>
    <p>Привет, {{ $name }}!</p>
    <p>Ваш код подтверждения:</p>
    <p style="font-size: 24px; font-weight: 700; letter-spacing: 2px;">{{ $code }}</p>
    <p>Введите этот код на странице регистрации для подтверждения аккаунта.</p>
    @if ($expiresAt)
      <p><strong>Код действителен до:</strong> {{ $expiresAt->format('H:i d.m.Y') }}</p>
    @endif
    <p>Если вы не регистрировались, просто проигнорируйте это письмо.</p>
    <p>С уважением,<br />Команда LiveCode</p>
  @else
    <h1 style="margin: 0 0 16px;">Registration Verification</h1>
    <p>Hello, {{ $name }}!</p>
    <p>Your verification code is:</p>
    <p style="font-size: 24px; font-weight: 700; letter-spacing: 2px;">{{ $code }}</p>
    <p>Enter this code on the registration page to confirm your account.</p>
    @if ($expiresAt)
      <p><strong>Code expires at:</strong> {{ $expiresAt->format('H:i Y-m-d') }}</p>
    @endif
    <p>If you didn't register, please ignore this email.</p>
    <p>Best regards,<br />LiveCode Team</p>
  @endif
</body>
</html>
