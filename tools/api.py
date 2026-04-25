import requests

# Делаем запрос к публичному API (пример: курс биткоина)
response = requests.get('https://api.coindesk.com/v1/bpi/currentprice.json')

# Проверка статуса (200 — ОК)
if response.status_code == 200:
    data = response.json()  # Преобразуем JSON в словарь Python
    print(data['bpi']['USD']['rate'])
else:
    print(f"Ошибка: {response.status_code}")
