@component('mail::message')
@if ($language === 'rus')
# Подтверждение регистрации

Привет, {{ $name }}!

Ваш код подтверждения:

@component('mail::panel')
## {{ $code }}
@endcomponent

Введите этот код на странице регистрации для подтверждения вашего аккаунта.

@if ($expiresAt)
**Код действителен до:** {{ $expiresAt->format('H:i d.m.Y') }}
@endif

Если вы не регистрировались, просто проигнорируйте это письмо.

С уважением,  
Команда LiveCode

@else

# Registration Verification

Hello, {{ $name }}!

Your verification code is:

@component('mail::panel')
## {{ $code }}
@endcomponent

Enter this code on the registration page to confirm your account.

@if ($expiresAt)
**Code expires at:** {{ $expiresAt->format('H:i Y-m-d') }}
@endif

If you didn't register, please ignore this email.

Best regards,  
LiveCode Team

@endif
@endcomponent
