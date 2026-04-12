# Капча для регистрации

## Введение

Для защиты от автоматизированной регистрации и DDoS атак, приложение поддерживает интеграцию с сервисами капчи.

Поддерживаемые сервисы:
- **Cloudflare Turnstile** (рекомендуется) - бесплатно, простая интеграция
- **Google reCAPTCHA v3** - бесплатно, требует Google аккаунта

## Cloudflare Turnstile (рекомендуется)

### Почему Turnstile?

- ✅ Бесплатно
- ✅ Больше нет Google Analytics
- ✅ Более удобно для пользователя
- ✅ Работает лучше с GDPR
- ✅ Идеален для Timeweb хостинга
- ✅ Не требует Google аккаунта

### Интеграция

#### 1. Получить ключи

Переходим на https://dash.cloudflare.com/?to=/:account/turnstile

1. Переходим в раздел "Turnstile"
2. Нажимаем "Create a site"
3. Заполняем параметры:
   - **Friendly name**: "LiveCode Registration"
   - **Domains**: example.com (добавляем домены где будет использоваться)
   - **Mode**: Managed Challenge (рекомендуется)

4. Получаем Site Key и Secret Key

#### 2. Конфигурация сервера

```env
# .env файл
CAPTCHA_PROVIDER=cloudflare-turnstile
TURNSTILE_ENABLED=true
TURNSTILE_SITE_KEY=xxxxxxxxxxxxxxxxxxxxx
TURNSTILE_SECRET_KEY=xxxxxxxxxxxxxxxxxxxxx
```

#### 3. Интеграция во фронтенде

```vue
<!-- RegisterPage.vue -->
<template>
  <div class="page auth-page">
    <section class="card form-card">
      <h1>{{ t("register.title") }}</h1>

      <form class="form-grid" @submit.prevent="handleSubmit">
        <!-- Другие поля формы -->
        
        <!-- Cloudflare Turnstile -->
        <div v-if="captchaConfig.enabled" class="field">
          <div 
            ref="captchaContainer"
            :data-sitekey="captchaConfig.site_key"
            class="cf-turnstile"
            data-theme="light"
            data-size="normal"
          />
        </div>

        <button class="btn" type="submit" :disabled="loading">
          {{ loading ? t("register.submitting") : t("register.submit") }}
        </button>
      </form>
    </section>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { request } from "../services/api";

const { t } = useI18n();
const loading = ref(false);
const captchaContainer = ref(null);
const captchaConfig = reactive({
  enabled: false,
  provider: "",
  site_key: "",
});

const form = reactive({
  name: "",
  email: "",
  password: "",
  language: "rus",
});

// Получить конфиг капчи при загрузке страницы
onMounted(async () => {
  try {
    const response = await request({
      method: "GET",
      path: "/auth/captcha-config",
      auth: false,
    });
    
    if (response.data) {
      captchaConfig.enabled = response.data.enabled;
      captchaConfig.provider = response.data.provider;
      captchaConfig.site_key = response.data.site_key;

      // Загрузить скрипт Turnstile если включена
      if (captchaConfig.enabled && captchaConfig.provider === "cloudflare-turnstile") {
        loadTurnstileScript();
      }
    }
  } catch (error) {
    console.error("Failed to load captcha config:", error);
  }
});

function loadTurnstileScript() {
  if (document.querySelector('script[src*="turnstile"]')) {
    return; // Скрипт уже загружен
  }
  
  const script = document.createElement("script");
  script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js";
  script.async = true;
  script.defer = true;
  document.head.appendChild(script);
}

async function handleSubmit() {
  loading.value = true;
  errorMessage.value = "";

  try {
    let captchaToken = "";
    
    // Получить токен Turnstile если включена
    if (captchaConfig.enabled && window.turnstile) {
      captchaToken = window.turnstile.getResponse();
      if (!captchaToken) {
        throw new Error(t("register.captchaRequired"));
      }
    }

    // Инициировать регистрацию
    const response = await request({
      method: "POST",
      path: "/api/auth/register/initiate",
      auth: false,
      body: {
        name: form.name,
        email: form.email,
        password: form.password,
        language: form.language || "rus",
        captcha_token: captchaToken,
      },
    });

    // Сохрани ID для следующего шага
    sessionStorage.setItem('registration_verification_id', 
                          response.data.registration_verification_id);
    
    // Показать форму ввода кода
    showVerificationCodeForm(response.data.email);
    
  } catch (error) {
    errorMessage.value = resolveErrorMessage(error);
    
    // Сбросить капчу при ошибке (если включена)
    if (captchaConfig.enabled && window.turnstile) {
      window.turnstile.reset();
    }
  } finally {
    loading.value = false;
  }
}

// ... остальной код
</script>
```

#### 4. Загрузить скрипт в HTML

```html
<!-- index.html -->
<!DOCTYPE html>
<html>
  <head>
    <!-- ... другое содержимое ... -->
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" defer></script>
  </head>
  <body>
    <div id="app"></div>
  </body>
</html>
```

## Google reCAPTCHA v3

### Интеграция

#### 1. Получить ключи

Переходим на https://www.google.com/recaptcha/admin

1. Нажимаем "+" для создания нового сайта
2. Заполняем параметры:
   - **Label**: "LiveCode Registration"
   - **Type**: reCAPTCHA v3
   - **Domains**: example.com
3. Принимаем условия и нажимаем "Create"
4. Получаем Site Key и Secret Key

#### 2. Конфигурация сервера

```env
# .env файл
CAPTCHA_PROVIDER=recaptcha
RECAPTCHA_ENABLED=true
RECAPTCHA_SITE_KEY=xxxxxxxxxxxxxxxxxxxxx
RECAPTCHA_SECRET_KEY=xxxxxxxxxxxxxxxxxxxxx
RECAPTCHA_THRESHOLD=0.5  # Score от 0 до 1 (0.5-0.7 рекомендуется)
```

#### 3. Интеграция во фронтенде (аналогично Turnstile)

```javascript
// Загрузить скрипт
onMounted(() => {
  const script = document.createElement("script");
  script.src = "https://www.google.com/recaptcha/api.js";
  script.async = true;
  script.defer = true;
  document.head.appendChild(script);
});

// Получить токен перед отправкой формы
async function handleSubmit() {
  let captchaToken = "";
  
  if (captchaConfig.enabled && window.grecaptcha) {
    captchaToken = await window.grecaptcha.execute(captchaConfig.site_key);
  }
  
  // ... отправить форму с captchaToken
}
```

## Отключить капчу

Если капча не нужна (в разработке или тестировании):

```env
# .env файл
CAPTCHA_PROVIDER=disabled
```

или просто оставить значение по умолчанию:

```env
# По умолчанию папча отключена
CAPTCHA_PROVIDER=disabled
```

## API структура

### GET `/api/auth/captcha-config`

Получить конфигурацию капчи для фронтенда.

**Ответ:**
```json
{
  "enabled": true,
  "provider": "cloudflare-turnstile",
  "site_key": "xxxxxxxxxxxxxxxxxxxxx"
}
```

### POST `/api/auth/register/initiate`

Инициировать регистрацию с опциональным токеном капчи.

**Запрос:**
```json
{
  "name": "Иван Петров",
  "email": "user@example.com",
  "password": "SecurePassword123!",
  "language": "rus",
  "captcha_token": "0.xxxxx"
}
```

**Параметры:**
- `name` (обязательно): Имя пользователя
- `email` (обязательно): Email адрес
- `password` (обязательно): Пароль (минимум 8 символов)
- `language` (опционально): rus или eng
- `captcha_token` (опционально): Токен капчи (требуется если капча включена)

**Ошибка при неудачной верификации капчи:**
```json
{
  "message": "Captcha verification failed. Please try again."
}
```

## Тестирование

### Тесты без капчи

Капча автоматически отключается в тестовой среде, поэтому тесты работают без проблем:

```bash
php artisan test tests/Feature/RegistrationVerificationTest.php
```

### Тестирование с Turnstile (development)

Cloudflare предоставляет тестовые токены для development:

```javascript
// Frontend (в development режиме)
const testToken = "1x00000000000000000000AA";
```

## Troubleshooting

### Капча не отображается

1. Проверьте что скрипт загружен: `https://challenges.cloudflare.com/turnstile/v0/api.js`
2. Проверьте что site_key правильный
3. Проверьте консоль браузера на ошибки

### "Captcha verification failed"

1. Проверьте что secret_key правильный
2. Убедитесь что IP адрес сервера имеет доступ в интернет
3. Проверьте логи: `storage/logs/laravel.log`

### Капча всегда не валидна

- Убедитесь что домены зарегистрированы в Cloudflare/Google
- Проверьте время на сервере (может быть рассинхронизировано)

## Миграция с одного сервиса на другой

```env
# Было указано
CAPTCHA_PROVIDER=recaptcha

# Меняем на
CAPTCHA_PROVIDER=cloudflare-turnstile
TURNSTILE_ENABLED=true
TURNSTILE_SITE_KEY=xxxxxxxxxxxxxxxxxxxxx
TURNSTILE_SECRET_KEY=xxxxxxxxxxxxxxxxxxxxx

# Отключить старую капчу
RECAPTCHA_ENABLED=false
```

## Безопасность

- Никогда не коммитьте secret_key в репозиторий
- Используйте переменные окружения для всех ключей
- Регулярно ротируйте ключи на продакшене
- Мониторьте логи на подозрительную активность
