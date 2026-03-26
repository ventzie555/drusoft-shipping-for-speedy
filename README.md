# Speedy Modern Shipping for WooCommerce

> **⚖️ LICENSE / ЛИЦЕНЗ**
>
> **English:** This plugin is released under the **GNU General Public License v2 or later**. You are free to use, modify, and redistribute this software in accordance with the terms of the GPL.
>
> **Български:** Този плъгин се разпространява под **GNU General Public License v2 или по-късна версия**. Можете свободно да използвате, променяте и разпространявате този софтуер съгласно условията на GPL.
>
> ---

[![WooCommerce Compatibility](https://img.shields.io/badge/WooCommerce-8.0+-blue.svg)](https://woocommerce.com/)
[![HPOS Compatible](https://img.shields.io/badge/HPOS-Compatible-green.svg)](https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage)
[![PHP Version](https://img.shields.io/badge/PHP-7.4+-777bb4.svg)](https://www.php.net/)

**Speedy Modern Shipping** is a high-performance, conflict-free WooCommerce integration for Speedy delivery services in Bulgaria. Designed for speed, reliability, and ease of use, it provides a seamless shipping experience for both merchants and customers.

> **❗ IMPORTANT COMPATIBILITY NOTE**
>
> This plugin is currently **NOT COMPATIBLE** with the WooCommerce **Block Cart** and **Block Checkout** pages. To use this plugin, please ensure your store is using the classic **Shortcode-based** Cart and Checkout pages. 
>
> If your site is using the Block Checkout, you must replace the [Checkout Block](https://woocommerce.com/document/cart-checkout-blocks-status/) with the `[woocommerce_checkout]` shortcode.

---

## 🚀 Key Features

### 🛒 For Your Customers
- **Dynamic Checkout Experience:** Real-time city and office selection directly on the checkout page.
- **Multiple Delivery Types:** Choose between delivery to **Address**, **Speedy Office**, or **Speedy Automat (APS)**.
- **Smart Street Search:** Built-in autocomplete for Bulgarian street names with intelligent prefix handling (e.g., stripping "ул.", "бул.").
- **Live Service Selection:** Customers can choose between available services (Economy, Express, etc.) with real-time price updates.
- **Region Mapping:** Automated city filtering based on the selected Bulgarian province.

### 🛠️ For Merchants
- **HPOS Compatible:** Fully supports WooCommerce High-Performance Order Storage for maximum performance.
- **Automated Data Sync:** Uses Action Scheduler to keep Bulgarian cities and Speedy offices up-to-date in the background.
- **Credential Validation:** Validates API credentials in real-time before saving settings to prevent configuration errors.
- **Custom Pricing:** Support for custom pricing CSV files (`fileceni`) for specialized shipping rates.
- **Advanced Order Management:** 
    - Dedicated metabox in the order edit screen to manage shipping details.
    - Integrated waybill generation.
    - Bulk actions for managing multiple Speedy orders at once.
- **Clean Codebase:** Built with modern PHP standards and conflict-free architecture.

---

## 📦 Installation

1. Upload the `speedy-modern-shipping` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure **WooCommerce** is installed and active.

---

## ⚙️ Configuration

1. Navigate to **WooCommerce > Settings > Shipping > Shipping Zones**.
2. Add a new shipping zone (e.g., "Bulgaria") or edit an existing one.
3. Click **Add shipping method** and select **Speedy Modern**.
4. Enter your **Speedy API Username** and **Password** in the method settings.
5. Click **Save Changes**. The plugin will automatically validate your credentials and unlock additional configuration options.
6. The background sync for cities and offices will start automatically using the WooCommerce Action Scheduler.

### Detailed Settings Overview

The plugin settings are divided into several key sections:

#### 📡 Speedy API Connection
- **Username & Password:** Your official Speedy API credentials.
- **Module Status:** Quickly enable or disable the shipping method.
- **Method Title:** How the shipping method appears to customers at checkout.

#### 👤 Sender Information
- **Sender (Object):** Select your Speedy client profile.
- **Contact Details:** Set the sender name, email, and phone number for the waybills.
- **Shipping from Office:** Option to specify if you are shipping from a Speedy office and which one.
- **Working Day End Time:** Set your business hours to help Speedy schedule pickups.

#### 📦 Shipment Settings
- **Active Services:** Choose which Speedy services (Economy, Express, etc.) to offer.
- **Packaging & Weight:** Define default packaging types (e.g., BOX) and default item weight.
- **Additional Options:** Enable Declared Value, Fragile handling, or Saturday Delivery.
- **Special Requirements:** Select from Speedy's special handling requirements.

#### 💰 Pricing & Payment
- **Pricing Methods:**
    - **Speedy Calculator:** Real-time API calculation based on weight, destination, and selected service.
    - **Fixed Price:** Uniform price for all orders, configurable per delivery type (Address, Office, Automat).
    - **Free Shipping:** Fully subsidized by the merchant. Can be always free or triggered by a minimum order amount.
    - **Custom Prices (CSV):** Upload a CSV for complex pricing rules based on weight and order total.
    - **Calculator + Surcharge:** API price plus a fixed additional fee.

### 📈 Detailed Pricing Logic

#### 📌 Fixed Price
You can set different fixed prices for each delivery method:
*   **To Address:** A flat rate for all home/work deliveries.
*   **To Office:** A flat rate for all Speedy office pickups.
*   **To Automat:** A flat rate for all Speedy APS (automat) pickups.

#### 🎁 Free Shipping
Free shipping can be configured in two ways:
1.  **Always Free:** Set the pricing method to "Free Shipping".
2.  **Threshold-based:** Enable "Free Shipping Threshold" and set minimum order amounts for Address, Office, and Automat deliveries independently.

#### 📄 Custom Prices (CSV)
For advanced scenarios, you can upload a CSV file (`fileceni`). The plugin matches the best price based on the following columns:
*   **Format:** `service_id, delivery_type, max_weight, max_order_total, price`
*   **Delivery Type Mapping:** `0` for Address, `1` for Office, `2` for Automat.
*   **Matching:** The plugin finds all rows matching the delivery type where the order's weight and subtotal are less than or equal to the CSV values, then selects the one with the lowest `max_order_total`.

#### 💳 Payment Options
- **COD & Money Transfer:** Configure Cash on Delivery (COD) vs. Postal Money Transfer.
- **Fiscal Receipts:** Manage handling of fiscal receipts for Speedy deliveries.

#### ⚙️ Workflow & Options
- **Automatic Waybill:** Option to automatically generate a waybill when an order reaches "Completed" status.
- **Label Printer:** Configure label size and format for printing.

---

## 🛠️ Technical Details

### Requirements
- **PHP:** 7.4 or higher.
- **WooCommerce:** 8.0+ (recommended for HPOS support).
- **Database:** Creates two custom tables: `wp_speedy_cities` and `wp_speedy_offices`.

### Background Jobs
The plugin schedules a `speedy_modern_sync_locations_event` action. You can monitor this in **WooCommerce > Status > Scheduled Actions**.

---

## 📄 License

This plugin is developed and maintained by **DRUSOFT LTD**.

---

*For support or custom feature requests, please contact DRUSOFT LTD.*

---

# Speedy Modern Shipping за WooCommerce

[![WooCommerce Compatibility](https://img.shields.io/badge/WooCommerce-8.0+-blue.svg)](https://woocommerce.com/)
[![HPOS Compatible](https://img.shields.io/badge/HPOS-Compatible-green.svg)](https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage)
[![PHP Version](https://img.shields.io/badge/PHP-7.4+-777bb4.svg)](https://www.php.net/)

**Speedy Modern Shipping** е високопроизводителна и безконфликтна интеграция на Speedy услуги за доставка за WooCommerce магазини в България. Проектиран за бързина, надеждност и лесна употреба, той осигурява безпроблемно изживяване при доставка както за търговците, така и за клиентите.

> **❗ ВАЖНА ЗАБЕЛЕЖКА ЗА СЪВМЕСТИМОСТ**
>
> Този плъгин в момента **НЕ Е СЪВМЕСТИМ** със страниците **Block Cart** и **Block Checkout** на WooCommerce. За да използвате този плъгин, моля, уверете се, че вашият магазин използва класическите страници за Количка и Плащане, базирани на **Shortcode**.
>
> Ако вашият сайт използва Block Checkout, трябва да замените [Checkout Block](https://woocommerce.com/document/cart-checkout-blocks-status/) с краткия код `[woocommerce_checkout]`.

---

## 🚀 Основни функции

### 🛒 За вашите клиенти
- **Динамично изживяване при завършване на поръчката:** Избор на град и офис в реално време директно на страницата за плащане.
- **Няколко вида доставка:** Избор между доставка до **Адрес**, **Офис на Спиди** или **Автомат на Спиди (APS)**.
- **Интелигентно търсене на улици:** Вградено автоматично довършване на български имена на улици с интелигентна обработка на префикси (напр. премахване на „ул.“, „бул.“).
- **Избор на услуга в реално време:** Клиентите могат да избират между наличните услуги (Икономична, Експресна и др.) с актуализация на цената в реално време.
- **Картографиране на региони:** Автоматизирано филтриране на градове въз основа на избраната българска област.

### 🛠️ За търговци
- **HPOS съвместим:** Напълно поддържа WooCommerce High-Performance Order Storage за максимална производителност.
- **Автоматизирано синхронизиране на данни:** Използва Action Scheduler, за да поддържа българските градове и офисите на Спиди актуални във фонов режим.
- **Валидиране на идентификационни данни:** Валидира API ключовете в реално време преди запазване на настройките, за да предотврати конфигурационни грешки.
- **Персонализирано ценообразуване:** Поддръжка за CSV файлове с персонализирани цени (`fileceni`) за специализирани тарифи за доставка.
- **Разширено управление на поръчки:** 
    - Специализиран метабокс в екрана за редактиране на поръчка за управление на детайлите за доставка.
    - Интегрирано генериране на товарителници.
    - Масови действия за управление на няколко поръчки на Спиди едновременно.
- **Чист код:** Изграден със съвременни PHP стандарти и архитектура без конфликти.

---

## 📦 Инсталация

1. Качете папката `speedy-modern-shipping` в директорията `/wp-content/plugins/`.
2. Активирайте плъгина чрез менюто „Разширения“ (Plugins) в WordPress.
3. Уверете се, че **WooCommerce** е инсталиран и активен.

---

## ⚙️ Конфигурация

1. Отидете на **WooCommerce > Настройки > Доставка > Зони за доставка**.
2. Добавете нова зона за доставка (напр. „България“) или редактирайте съществуваща.
3. Кликнете върху **Добавяне на метод за доставка** и изберете **Speedy Modern**.
4. Въведете вашето **API потребителско име** и **парола** на Спиди в настройките на метода.
5. Кликнете върху **Запазване на промените**. Плъгинът автоматично ще валидира вашите данни и ще отключи допълнителни опции за конфигурация.
6. Фоновото синхронизиране на градове и офиси ще започне автоматично чрез WooCommerce Action Scheduler.

### Подробен преглед на настройките

Настройките на плъгина са разделени на няколко ключови секции:

#### 📡 Спиди API връзка
- **Потребителско име и парола:** Вашите официални API данни за достъп до Спиди.
- **Статус на модула:** Бързо активиране или деактивиране на метода за доставка.
- **Заглавие на метода:** Как методът за доставка се появява пред клиентите при завършване на поръчка.

#### 👤 Информация за подателя
- **Подател (Обект):** Изберете вашия клиентски профил в Спиди.
- **Данни за контакт:** Задайте име, имейл и телефонен номер на подателя за товарителниците.
- **Изпращане от офис:** Опция за указване дали изпращате от офис на Спиди и кой точно.
- **Край на работния ден:** Задайте вашето работно време, за да помогнете на Спиди при планирането на вземанията.

#### 📦 Настройки на пратката
- **Активни услуги:** Изберете кои услуги на Спиди (Икономична, Експресна и др.) да предлагате.
- **Опаковка и тегло:** Дефинирайте видовете опаковки по подразбиране (напр. BOX) и теглото на артикулите.
- **Допълнителни опции:** Активирайте Обявена стойност, Чупливост или Доставка в събота.
- **Специални изисквания:** Изберете от специалните изисквания за обработка на Спиди.

#### 💰 Ценообразуване и плащане
- **Методи за ценообразуване:**
    - **Калкулатор на Спиди:** Изчисляване чрез API в реално време въз основа на тегло, дестинация и избрана услуга.
    - **Фиксирана цена:** Еднаква цена за всички поръчки, конфигурируема според типа доставка (Адрес, Офис, Автомат).
    - **Безплатна доставка:** Напълно субсидирана от търговеца. Може да бъде винаги безплатна или приложена при минимална сума на поръчката.
    - **Персонализирани цени (CSV):** Качете CSV файл за сложни ценови правила въз основа на тегло и обща сума.
    - **Калкулатор + Надбавка:** API цена плюс фиксирана допълнителна такса.

### 📈 Детайлна логика на ценообразуване

#### 📌 Фиксирана цена
Можете да зададете различни фиксирани цени за всеки метод на доставка:
*   **До Адрес:** Фиксирана такса за всички доставки до дома или офиса.
*   **До Офис:** Фиксирана такса за всички пратки до офис на Спиди.
*   **До Автомат:** Фиксирана такса за всички пратки до автомат (APS) на Спиди.

#### 🎁 Безплатна доставка
Безплатната доставка може да бъде конфигурирана по два начина:
1.  **Винаги безплатна:** Задайте метода на ценообразуване на „Безплатна доставка“.
2.  **Базирана на праг:** Активирайте „Праг за безплатна доставка“ и задайте минимални суми на поръчката за Адрес, Офис и Автомат поотделно.

#### 📄 Персонализирани цени (CSV)
За напреднали сценарии можете да качете CSV файл (`fileceni`). Плъгинът намира най-добрата цена въз основа на следните колони:
*   **Формат:** `service_id, delivery_type, max_weight, max_order_total, price`
*   **Mapping на типа доставка:** `0` за Адрес, `1` за Офис, `2` за Автомат.
*   **Намиране на цена:** Плъгинът намира всички редове, съответстващи на типа доставка, където теглото и междинната сума на поръчката са по-малки или равни на стойностите в CSV, след което избира този с най-ниската „max_order_total“.

#### 💳 Опции за плащане
- **COD и Пощенски превод:** Конфигурирайте Наложен платеж (COD) спрямо Пощенски паричен превод.
- **Фискални бонове:** Управление на обработката на фискални бонове при доставки със Спиди.

#### ⚙️ Работен процес и опции
- **Автоматична товарителница:** Опция за автоматично генериране на товарителница, когато поръчката достигне статус „Завършена“ (Completed).
- **Етикети:** Конфигурирайте размера и формата на етикетите за печат.

---

## 🛠️ Технически подробности

### Изисквания
- **PHP:** 7.4 или по-висока версия.
- **WooCommerce:** 8.0+ (препоръчително за поддръжка на HPOS).
- **База данни:** Създава две потребителски таблици: `wp_speedy_cities` и `wp_speedy_offices`.

### Фонови задачи
Плъгинът планира действие `speedy_modern_sync_locations_event`. Можете да го следите в **WooCommerce > Статус > Планирани действия**.
---

## 📄 Лиценз

Този плъгин се разпространява под **GNU General Public License v2 или по-късна версия**.
Разработен и се поддържа от **[ДРУСОФТ ЕООД](https://drusoft.dev/)**.

---

*За поддръжка или заявки за персонализирани функции, моля, свържете се с [ДРУСОФТ ЕООД](https://drusoft.dev/).*

---
