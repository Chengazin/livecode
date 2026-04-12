# Регистрация с кодом подтверждения на почту

## Общие сведения

Процесс регистрации был обновлен для добавления дополнительного уровня безопасности через проверку электронной почты. Новый процесс состоит из двух этапов:

1. **Шаг 1 (registerInitiate)**: Пользователь вводит данные и получает код подтверждения на почту
2. **Шаг 2 (registerVerify)**: Пользователь введит код из письма для завершения регистрации

## API Endpoints

### 1. Инициирование регистрации

**POST** `/api/auth/register/initiate`

Первый шаг регистрации. Пользователь отправляет свои данные и получает код подтверждения на почту.

#### Запрос

```json
{
  "name": "Иван Петров",
  "email": "user@example.com",
  "password": "SecurePassword123!",
  "language": "rus"
}
```

#### Параметры

- `name` (строка, обязательно): Имя пользователя (макс 255 символов)
- `email` (строка, обязательно): Email адрес (должен быть уникальным)
- `password` (строка, обязательно): Пароль (минимум 8 символов)
- `language` (строка, опционально): Язык (rus или eng, по умолчанию rus)

#### Ответ успешного запроса (202 Accepted)

```json
{
  "message": "Verification code has been sent to your email",
  "registration_verification_id": 123,
  "email": "user@example.com",
  "expires_in_minutes": 30
}
```

#### Ошибки

- `422 Unprocessable Entity`: Валидация не прошла (например, email уже занят)
- `500 Internal Server Error`: Ошибка сервера

### 2. Проверка кода и завершение регистрации

**POST** `/api/auth/register/verify`

Второй шаг регистрации. Пользователь отправляет код из письма для завершения регистрации.

#### Запрос

```json
{
  "registration_verification_id": 123,
  "verification_code": "123456",
  "device_name": "My Device"
}
```

#### Параметры

- `registration_verification_id` (число, обязательно): ID сессии регистрации (получен на шаге 1)
- `verification_code` (строка, обязательно): 6-значный код из письма
- `device_name` (строка, опционально): Название устройства для токена

#### Ответ успешного запроса (201 Created)

```json
{
  "token_type": "Bearer",
  "access_token": "1|AbCdEfGhIjKlMnOpQrStUvWxYz...",
  "user": {
    "user_id": 42,
    "name": "Иван Петров",
    "email": "user@example.com",
    "language": "rus",
    "status": "active",
    "is_admin": false
  }
}
```

#### Ошибки

- `422 Unprocessable Entity`: Неверный код подтверждения
- `429 Too Many Requests`: Слишком много неудачных попыток (макс 3)
- `404 Not Found`: Сессия регистрации не найдена или истекла
- `500 Internal Server Error`: Ошибка сервера

### 3. Отправить код повторно

**POST** `/api/auth/register/resend-code`

Отправить новый код подтверждения на почту (если первый был потерян или истек).

#### Запрос

```json
{
  "registration_verification_id": 123
}
```

#### Параметры

- `registration_verification_id` (число, обязательно): ID сессии регистрации

#### Ответ успешного запроса (200 OK)

```json
{
  "message": "Verification code has been resent to your email",
  "expires_in_minutes": 30
}
```

#### Ошибки

- `400 Bad Request`: Код истек или уже верифицирован
- `404 Not Found`: Сессия не найдена
- `500 Internal Server Error`: Ошибка сервера

## Безопасность

### Защита от атак

1. **Rate Limiting**: 
   - 10 запросов на инициирование регистрации в минуту на IP
   - 10 запросов на проверку кода в минуту на IP
   - 3 попытки ввода неверного кода (потом требуется повторная отправка)

2. **Истечение кодов**:
   - Код действителен 30 минут
   - После истечения требуется получить новый код

3. **Хеширование паролей**:
   - Пароли хешируются с bcrypt (12 раундов)
   - Каждый хеш имеет собственную соль
   - Хеш создается перед отправкой кода, но пароль не сохраняется до подтверждения

4. **Логирование**:
   - Все попытки регистрации логируются
   - Неудачные попытки проверки кода логируются

## Frontend (Vue.js) пример

```javascript
// Шаг 1: Инициирование регистрации
async function initiateRegistration() {
  try {
    const response = await fetch('/api/auth/register/initiate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: formData.name,
        email: formData.email,
        password: formData.password,
        language: formData.language
      })
    });
    
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message);
    }
    
    const data = await response.json();
    // Сохрани registration_verification_id для следующего шага
    sessionStorage.setItem('registration_verification_id', 
                          data.registration_verification_id);
    // Показать форму ввода кода
    showVerificationCodeForm();
    
  } catch (error) {
    console.error('Registration initiate failed:', error);
  }
}

// Шаг 2: Проверка кода
async function verifyCode() {
  try {
    const verificationId = sessionStorage.getItem('registration_verification_id');
    
    const response = await fetch('/api/auth/register/verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        registration_verification_id: parseInt(verificationId),
        verification_code: userEnteredCode,
        device_name: getDeviceName()
      })
    });
    
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message);
    }
    
    const data = await response.json();
    // Сохрани токен и перенаправь в приложение
    localStorage.setItem('auth_token', data.access_token);
    router.push('/projects');
    
  } catch (error) {
    console.error('Verification failed:', error);
  }
}

// Повторная отправка кода
async function resendCode() {
  try {
    const verificationId = sessionStorage.getItem('registration_verification_id');
    
    const response = await fetch('/api/auth/register/resend-code', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        registration_verification_id: parseInt(verificationId)
      })
    });
    
    if (response.ok) {
      showMessage('Код отправлен на почту');
    }
  } catch (error) {
    console.error('Resend failed:', error);
  }
}
```

## Конфигурация

### Переменные окружения

```env
# Email configuration for sending verification codes
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@livecode.local
MAIL_FROM_NAME="LiveCode"

# Queue configuration (for sending emails asynchronously)
QUEUE_CONNECTION=database  # or redis, sync, etc.
```

### Время истечения кода

Время истечения кода настраивается в методе `createForRegistration()` модели `RegistrationVerification`:

```php
RegistrationVerification::createForRegistration(
    email: $email,
    name: $name,
    passwordHash: Hash::make($password),
    language: $language,
    expirationMinutes: 30  // <- Меняй здесь
);
```

## Тестирование

```bash
# Запустить все тесты регистрации
php artisan test tests/Feature/RegistrationVerificationTest.php

# Запустить конкретный тест
php artisan test tests/Feature/RegistrationVerificationTest.php --filter=test_register_initiate
```

## Будущие улучшения

- [ ] отправка SMS кодов для дополнительной безопасности
- [ ] Биометрическая верификация
- [ ] Двухфакторная аутентификация (2FA)
- [ ] Интеграция с социальными сетями для быстрой регистрации
- [ ] Капча для защиты от ботов (Cloudflare Turnstile или reCAPTCHA)
